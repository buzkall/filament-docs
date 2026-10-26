<?php

namespace Arzcode\FilamentDocs\Commands;

use Arzcode\FilamentDocs\FilamentDocsServiceProvider;
use Arzcode\FilamentDocs\Support\DocsConfig;
use Arzcode\FilamentDocs\Support\DocScreenshots;
use Filament\Facades\Filament;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Screenshots every documented screen and embeds the images into its doc.
 *
 * PHP only builds the manifest and writes the markdown; Playwright, in
 * bin/screenshot-docs.mjs, drives the browser. The browser logs in like a
 * person, through a local-only URL or the login form, and nothing about that
 * login is in the repository. `--only` and `--screens` re-shoot one doc while
 * writing it. Never against production: it walks the whole panel.
 */
#[Signature('docs:screenshot
    {--only=* : Only these doc slugs (repeatable)}
    {--screens= : Comma-separated screens: list,create,view,edit (default: all)}
    {--panel= : Id of the panel to capture (default: the default panel)}
    {--base-url= : Base URL of the running app (default: config app.url)}
    {--login-path= : Local-only path or URL that signs the browser in, not the login form (default: env DOCS_SCREENSHOT_LOGIN_PATH)}')]
#[Description('Capture Playwright screenshots of every documented screen and embed them into the docs')]
class ScreenshotDocsCommand extends Command
{
    private const array ALL_SCREENS = ['list', 'create', 'view', 'edit'];

    public function handle(DocScreenshots $documentation): int
    {
        if (app()->isProduction()) {
            $this->error('Run this against a local or staging copy, never production.');

            return self::FAILURE;
        }

        $screens = filled($this->stringOption('screens'))
            ? array_values(array_filter(array_map(trim(...), explode(',', $this->stringOption('screens')))))
            : self::ALL_SCREENS;

        if (array_diff($screens, self::ALL_SCREENS) !== []) {
            $this->error('Unknown screen(s): ' . implode(', ', array_diff($screens, self::ALL_SCREENS)) . '.');

            return self::FAILURE;
        }

        $panel = filled($this->stringOption('panel')) ? Filament::getPanel($this->stringOption('panel')) : Filament::getDefaultPanel();
        $only = array_values(array_filter((array)$this->option('only'), is_string(...)));

        // The docs read and written are the ones in this panel's folder, with
        // its image URL.
        Filament::setCurrentPanel($panel);

        $targets = array_values(array_filter(
            $documentation->screenshotTargets($panel->getId()),
            fn(array $target): bool => ($only === [] || in_array($target['slug'], $only, true)) && in_array($target['screen'], $screens, true),
        ));

        $shots = array_map(fn(array $target): array => [
            'slug'         => $target['slug'],
            'screen'       => $target['screen'],
            'url'          => $target['path'],
            'out'          => "{$target['slug']}/{$target['screen']}.webp",
            'interactions' => DocsConfig::interactions($target['slug'], $target['screen']),
        ], $targets);

        if ($shots === []) {
            $this->warn('No screenshot targets matched the given filters.');

            return self::SUCCESS;
        }

        // The runner shoots PNG and hands each one to cwebp: a WebP of a panel
        // screen is about half the bytes of a JPEG of it.
        if (! Process::run(['cwebp', '-version'])->successful()) {
            $this->error('cwebp is not installed; it converts the screenshots to WebP (macOS: brew install webp).');

            return self::FAILURE;
        }

        $manifestPath = storage_path('app/docs-screenshots-manifest.json');
        $resultsPath = storage_path('app/docs-screenshots-results.json');

        $baseUrl = rtrim($this->stringOption('base-url') ?: config()->string('app.url'), '/');
        $loginPath = $this->stringOption('login-path') ?: config('filament-docs.screenshots.login_path');

        File::ensureDirectoryExists(dirname($manifestPath));
        File::delete($resultsPath);
        File::put($manifestPath, (string)json_encode([
            'baseUrl'   => $baseUrl,
            'loginUrl'  => is_string($loginPath) && $loginPath !== '' ? $this->absoluteUrl($baseUrl, $loginPath) : null,
            'panelPath' => '/' . trim($panel->getPath(), '/'),
            'imageRoot' => Storage::disk(FilamentDocsServiceProvider::DISK)->path(''),
            // The app's own node_modules, where the runner finds Playwright: a
            // symlinked package would otherwise look next to its real path.
            'appRoot'     => base_path(),
            'viewport'    => [...config()->array('filament-docs.screenshots.viewport'), 'deviceScaleFactor' => 1],
            'perPage'     => config('filament-docs.screenshots.per_page'),
            'quality'     => config('filament-docs.screenshots.quality'),
            'resultsPath' => $resultsPath,
            'shots'       => $shots,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info('Capturing ' . count($shots) . ' page(s)...');

        $result = Process::timeout(600)->run(
            ['node', dirname(__DIR__, 2) . '/bin/screenshot-docs.mjs', $manifestPath],
            fn(string $type, string $buffer) => $this->output->write($buffer),
        );

        $produced = $this->producedImages($resultsPath);

        // A run that captured nothing (a failed login, no server) must not
        // rewrite the docs: every image they hold would be dropped.
        if ($produced->isEmpty()) {
            $this->error('Nothing was captured, so the docs were left as they were.');

            return self::FAILURE;
        }

        $unmatched = [];

        foreach (array_unique(array_column($targets, 'slug')) as $slug) {
            // Only the screens shot again this time are replaced: a run narrowed
            // with --screens, or a shot that failed, keeps the images it had.
            $reshot = $produced->where('slug', $slug)->pluck('screen')->all();
            $kept = array_filter($documentation->embeddedImages($slug), fn(array $image): bool => ! in_array($image['screen'], $reshot, true));
            $images = [];

            foreach ([...$kept, ...$produced->where('slug', $slug)->all()] as $image) {
                $images[] = ['screen' => $image['screen'], 'file' => $image['file'], 'label' => $image['label']];
            }

            usort($images, fn(array $a, array $b): int => array_search($a['screen'], self::ALL_SCREENS, true) <=> array_search($b['screen'], self::ALL_SCREENS, true));

            foreach ($documentation->injectScreenshots($slug, $images) as $label) {
                $unmatched[] = "{$slug}: {$label}";
            }
        }

        $this->info("Captured {$produced->count()} screenshot(s) of " . count($shots) . ' page(s).');

        if ($unmatched !== []) {
            $this->warn('Tabs with no matching heading (kept in the bottom block). Rename the doc heading to place them inline:');
            collect($unmatched)->each(fn(string $entry) => $this->line("  - {$entry}"));
        }

        return $result->successful() ? self::SUCCESS : self::FAILURE;
    }

    /** A path is taken as relative to the app; a full URL is used as it is. */
    private function absoluteUrl(string $baseUrl, string $pathOrUrl): string
    {
        return Str::startsWith($pathOrUrl, ['http://', 'https://']) ? $pathOrUrl : $baseUrl . '/' . ltrim($pathOrUrl, '/');
    }

    /**
     * The images the runner wrote, from its results file. A row that is not
     * the expected shape is dropped rather than written into a doc.
     *
     * @return Collection<int, array{slug: string, screen: string, file: string, label: string|null}>
     */
    private function producedImages(string $resultsPath): Collection
    {
        $rows = File::exists($resultsPath) ? json_decode(File::get($resultsPath), true) : [];

        return collect(is_array($rows) ? $rows : [])
            ->filter(fn(mixed $row): bool => is_array($row)
                && is_string($row['slug'] ?? null)
                && is_string($row['screen'] ?? null)
                && is_string($row['file'] ?? null)
                && in_array($row['screen'], self::ALL_SCREENS, true))
            ->map(fn(array $row): array => [
                'slug'   => $row['slug'],
                'screen' => $row['screen'],
                'file'   => $row['file'],
                'label'  => is_string($row['label'] ?? null) ? $row['label'] : null,
            ])
            ->values();
    }

    /** A value option, or '' when it was not given. */
    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : '';
    }
}

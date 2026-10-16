<?php

namespace Arzcode\FilamentDocs;

use Arzcode\FilamentDocs\Commands\ScreenshotDocsCommand;
use Arzcode\FilamentDocs\Commands\UninstallCommand;
use Arzcode\FilamentDocs\Support\DocsConfig;
use Arzcode\FilamentDocs\Support\DocsSlug;
use Arzcode\FilamentDocs\Support\PanelProviders;
use Arzcode\FilamentDocs\Support\Themes;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class FilamentDocsServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-docs';

    /** The private disk the screenshots are read from and written to. */
    public const string DISK = 'filament-docs';

    /** Whether this install changed a theme, so the build has to run again. */
    protected bool $themesChanged = false;

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile()
            ->hasTranslations()
            ->hasViews()
            ->hasCommands([ScreenshotDocsCommand::class, UninstallCommand::class])
            ->hasInstallCommand(fn(InstallCommand $command): InstallCommand => $command
                ->startWith(function(InstallCommand $cmd): void {
                    // The provider outlives a run, so a second one in the same process starts clean.
                    $this->themesChanged = false;

                    intro('Installing Filament Docs');
                })
                ->endWith(function(InstallCommand $cmd): void {
                    $steps = [
                        fn() => $this->publishConfigFile($cmd),
                        $this->patchPanelProviders(...),
                        $this->patchThemes(...),
                        fn() => $this->installTypography($cmd),
                        $this->createStarterDoc(...),
                        fn() => $this->runNpmBuild($cmd),
                        $this->finish(...),
                    ];

                    foreach ($steps as $step) {
                        $cmd->newLine();
                        $step();
                    }
                })
                // Spatie hides install commands from `artisan list`.
                ->setHidden(false)
                ->setDescription('Publish the config, register the docs plugin in your panels and add its styles to their themes'));
    }

    /**
     * The screenshots disk, unless the app defines its own. Never under
     * public/: the images show the panel, so they are only streamed to
     * readers of the doc they belong to.
     */
    public function packageBooted(): void
    {
        if (config('filesystems.disks.' . self::DISK) !== null) {
            return;
        }

        config(['filesystems.disks.' . self::DISK => [
            'driver' => 'local',
            'root'   => DocsConfig::path() . '/images',
            'throw'  => false,
        ]]);
    }

    /**
     * Replaces Spatie's shallow merge, which drops every default of a nested
     * array (`screenshots`) as soon as the app sets one of its keys.
     */
    public function registerPackageConfigs(): self
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return $this;
        }

        /** @var array<mixed> $defaults */
        $defaults = require __DIR__ . '/../config/filament-docs.php';
        $published = config('filament-docs', []);

        config(['filament-docs' => $this->deepMergeConfig($defaults, is_array($published) ? $published : [])]);

        return $this;
    }

    /**
     * Associative arrays merge key by key; lists and scalars are replaced
     * wholesale. `screens` and `concepts` are maps the app owns, so they are
     * replaced too: a published list of screens is the whole list.
     *
     * @param  array<mixed>  $defaults
     * @param  array<mixed>  $overrides
     * @return array<mixed>
     */
    protected function deepMergeConfig(array $defaults, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (
                is_array($value) && ! array_is_list($value)
                && isset($defaults[$key]) && is_array($defaults[$key]) && ! array_is_list($defaults[$key])
                && ! in_array($key, ['screens', 'concepts', 'interactions'], true)
            ) {
                $defaults[$key] = $this->deepMergeConfig($defaults[$key], $value);
            } else {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }

    protected function publishConfigFile(InstallCommand $command): void
    {
        if (file_exists(config_path('filament-docs.php'))) {
            note('config/filament-docs.php already present — leaving as-is.');

            return;
        }

        if (! confirm(label: 'Would you like to publish the config file?', default: true)) {
            note('Skipped — the package defaults apply. Publish it to list your screens.');

            return;
        }

        $command->callSilently('vendor:publish', ['--tag' => 'filament-docs-config']);
        info('Config file published.');
    }

    /**
     * Each panel is asked about on its own: a manual is rarely wanted in a
     * client-facing panel. When `docs` is already taken in a panel, the
     * install asks for another slug instead of registering a page Filament
     * would give no route.
     */
    protected function patchPanelProviders(): void
    {
        $files = PanelProviders::files();

        if ($files === []) {
            warning('No app/Providers/Filament/*PanelProvider.php found — register FilamentDocsPlugin manually in your panel provider.');

            return;
        }

        foreach ($files as $file) {
            $contents = (string)file_get_contents($file);
            $relative = $this->relativePath($file);

            if (PanelProviders::hasPlugin($contents)) {
                note(sprintf('FilamentDocsPlugin already present in %s — leaving as-is.', $relative));

                continue;
            }

            if (! confirm(label: sprintf('Register FilamentDocsPlugin in %s?', $relative), default: true)) {
                note(sprintf('Skipped %s.', $relative));

                continue;
            }

            $patched = PanelProviders::addPlugin($contents, $this->pluginEntry(PanelProviders::panelPath($contents)));

            if ($patched === null || ! PanelProviders::parses($patched)) {
                warning(sprintf('Could not patch %s — add ->plugins([FilamentDocsPlugin::make()]) manually.', $relative));

                continue;
            }

            file_put_contents($file, $patched);
            info(sprintf('Patched %s to register FilamentDocsPlugin.', $relative));
        }
    }

    /**
     * `FilamentDocsPlugin::make()`, with a `->slug()` when the default one is
     * already served in the panel.
     */
    protected function pluginEntry(?string $panelPath): string
    {
        try {
            if ($panelPath === null || ! DocsSlug::isTaken($panelPath, 'docs')) {
                return PanelProviders::ENTRY;
            }

            $panel = trim($panelPath, '/');
            $suggested = DocsSlug::suggest($panelPath);

            while (true) {
                $slug = (string)str(text(
                    label: "/{$panel}/docs is already taken. URL path for the docs",
                    default: $suggested,
                    hint: 'The segment after the panel path.',
                ))->trim()->trim('/')->lower();

                if (preg_match('#^[a-z0-9-]+(/[a-z0-9-]+)*$#', $slug) === 1 && ! DocsSlug::isTaken($panelPath, $slug)) {
                    return PanelProviders::ENTRY . "->slug('{$slug}')";
                }

                warning("/{$panel}/{$slug} is taken or not a valid path — pick another.");
            }
        } catch (Throwable) {
            warning('Could not check the panel routes — change the slug later with ->slug() if the docs do not show up.');

            return PanelProviders::ENTRY;
        }
    }

    /**
     * The page is built from Tailwind classes and needs the typography
     * plugin and its own stylesheet, so each panel theme gets them.
     */
    protected function patchThemes(): void
    {
        $files = Themes::files();

        if ($files === []) {
            warning('No resources/css/filament/*/theme.css found. Create one with `php artisan make:filament-theme`, then run this install again: the docs need a custom theme.');

            return;
        }

        foreach ($files as $file) {
            $contents = (string)file_get_contents($file);
            $relative = $this->relativePath($file);

            if (Themes::isPatched($contents)) {
                note(sprintf('%s already loads the docs styles — leaving as-is.', $relative));

                continue;
            }

            if (! confirm(label: sprintf('Add the docs styles to %s?', $relative), default: true)) {
                note(sprintf('Skipped %s.', $relative));

                continue;
            }

            $patched = Themes::patch($contents);

            if ($patched === null) {
                warning(sprintf("%s does not import Filament's theme — add these lines to it by hand:\n%s\n%s\n%s", $relative, Themes::TYPOGRAPHY, Themes::IMPORT, Themes::SOURCE));

                continue;
            }

            file_put_contents($file, $patched);
            $this->themesChanged = true;
            info(sprintf('Patched %s.', $relative));
        }
    }

    protected function installTypography(InstallCommand $command): void
    {
        $packageJson = base_path('package.json');

        if (! file_exists($packageJson) || str_contains((string)file_get_contents($packageJson), '"@tailwindcss/typography"')) {
            return;
        }

        if (! confirm(label: 'The docs need @tailwindcss/typography. Install it with npm now?', default: true)) {
            note('Skipped — run `npm install -D @tailwindcss/typography` before building the theme.');

            return;
        }

        $this->run($command, 'npm install -D @tailwindcss/typography');
    }

    /**
     * An introduction, so the page has something to open on.
     */
    protected function createStarterDoc(): void
    {
        $path = DocsConfig::path() . '/index.md';

        if (file_exists($path)) {
            note(sprintf('%s already present — leaving as-is.', $this->relativePath($path)));

            return;
        }

        if (! confirm(label: sprintf('Create a starter %s?', $this->relativePath($path)), default: true)) {
            note('Skipped — the docs page answers 404 until index.md exists.');

            return;
        }

        File::ensureDirectoryExists(dirname($path));
        File::copy(__DIR__ . '/../stubs/index.md', $path);
        info(sprintf('Created %s.', $this->relativePath($path)));
    }

    protected function runNpmBuild(InstallCommand $command): void
    {
        if (! $this->themesChanged) {
            return;
        }

        if (! confirm(label: 'Rebuild the theme with `npm run build` now?', default: true)) {
            note('Skipped — run `npm run build` before opening the docs.');

            return;
        }

        $this->run($command, 'npm run build');
    }

    protected function finish(): void
    {
        note(implode("\n", [
            'Next: write one markdown file per screen in ' . $this->relativePath(DocsConfig::path()) . ' and list it under `screens` in config/filament-docs.php.',
            'Screenshots are optional: `npm install -D playwright && npx playwright install chromium`, install cwebp (macOS: `brew install webp`), then `php artisan docs:screenshot`.',
        ]));

        outro('Filament Docs install complete. Reload your Filament panel.');
    }

    protected function run(InstallCommand $command, string $commandLine): void
    {
        $result = Process::path(base_path())
            ->forever()
            ->run($commandLine, fn(string $type, string $output) => $command->getOutput()->write($output));

        if (! $result->successful()) {
            error(sprintf('`%s` failed — run it by hand.', $commandLine));
        }
    }

    protected function relativePath(string $absolutePath): string
    {
        $base = base_path() . DIRECTORY_SEPARATOR;

        return str_starts_with($absolutePath, $base) ? substr($absolutePath, strlen($base)) : $absolutePath;
    }
}

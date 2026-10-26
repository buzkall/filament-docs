<?php

namespace Arzcode\FilamentDocs\Support;

use Arzcode\FilamentDocs\FilamentDocsPlugin;
use Arzcode\FilamentDocs\FilamentDocsServiceProvider;
use Closure;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource as FilamentResource;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * Builds the screenshot targets from the documented screens and embeds the
 * produced images into each doc.
 *
 * Embedded images sit between HTML comment markers, so a re-run removes its own
 * output first and the hand-written prose is never touched. The sanitizer
 * drops the comments from the rendered page.
 */
class DocScreenshots
{
    private const array SCREENS = ['index' => 'list', 'create' => 'create', 'view' => 'view', 'edit' => 'edit'];

    /**
     * One entry per page to capture, for every slug of `config('filament-docs.screens')`
     * that has a markdown file. Resources give list/create/view/edit (the last
     * two need a record); a plain page gives one `list` shot.
     *
     * @return list<array{slug: string, screen: string, path: string}>
     */
    public function screenshotTargets(string $panelId): array
    {
        // Filament URL generation needs a current panel; restore it afterwards.
        $previousPanel = Filament::getCurrentPanel();
        Filament::setCurrentPanel(Filament::getPanel($panelId));

        try {
            $targets = [];

            foreach (DocsConfig::screensIn(Filament::getPanel($panelId)) as $slug => $class) {
                if (! File::exists($this->docPath($slug))) {
                    continue;
                }

                $targets = [...$targets, ...(is_a($class, FilamentResource::class, true)
                    ? $this->resourceTargets($class, $slug)
                    : $this->pageTargets($class, $slug))];
            }

            return $targets;
        } finally {
            Filament::setCurrentPanel($previousPanel);
        }
    }

    /**
     * The images an earlier run embedded in the doc, so a run that re-shoots
     * only some screens can put the others back. The screen is the file
     * name's first word and the tab label the caption after the dash.
     *
     * @return list<array{screen: string, file: string, label: string|null}>
     */
    public function embeddedImages(string $slug): array
    {
        $path = $this->docPath($slug);

        if (! File::exists($path)) {
            return [];
        }

        preg_match_all('/<!-- (?:screenshot:[^\n]*?|screenshots:start) -->.*?<!-- (?:\/screenshot:[^\n]*?|screenshots:end) -->/s', File::get($path), $blocks);

        $prefix = preg_quote($this->imagesUrl() . '/', '/');
        $images = [];

        foreach ($blocks[0] as $block) {
            preg_match_all("/!\\[([^\\]]*)\\]\\({$prefix}({$slug}\\/([a-z0-9]+)[a-z0-9-]*\\.webp)\\)/", $block, $matches, PREG_SET_ORDER);

            foreach ($matches as [, $caption, $file, $screen]) {
                $images[] = ['screen' => $screen, 'file' => $file, 'label' => Str::after($caption, ' — ') === $caption ? null : Str::after($caption, ' — ')];
            }
        }

        return $images;
    }

    /**
     * Embeds the images: a tab image goes at the end of the section whose
     * heading matches the tab label, anything else in a "Capturas de pantalla"
     * block at the bottom. Images whose file is missing are skipped.
     *
     * @param  list<array{screen: string, file: string, label: string|null}>  $images
     * @return list<string> Tab labels without a matching heading.
     */
    public function injectScreenshots(string $slug, array $images): array
    {
        $path = $this->docPath($slug);

        if (! File::exists($path)) {
            return [];
        }

        $lines = explode("\n", $this->stripInjected(File::get($path)));
        $headings = $this->headings($lines);
        $existing = array_filter($images, fn(array $image): bool => Storage::disk(FilamentDocsServiceProvider::DISK)->exists($image['file']));

        $inline = [];
        $block = [];
        $unmatched = [];
        $used = [];

        foreach ($existing as $image) {
            $label = $image['label'] ?? null;
            $caption = $this->caption($image['screen'], $label);
            $index = filled($label) ? $this->matchHeading($label, $headings, $used) : null;

            if ($index !== null) {
                $used[$index] = true;
                $key = pathinfo($image['file'], PATHINFO_FILENAME);
                $inline[$index][] = "<!-- screenshot:{$key} -->\n![{$caption}]({$this->imageUrl($image['file'])})\n<!-- /screenshot:{$key} -->";

                continue;
            }

            if (filled($label)) {
                $unmatched[] = $label;
            }

            $block[] = "**{$caption}**\n![{$caption}]({$this->imageUrl($image['file'])})";
        }

        File::put($path, $this->appendBlock($this->insertInline($lines, $headings, $inline), $block));

        return $unmatched;
    }

    /**
     * @param  class-string<Page>  $class
     * @return list<array{slug: string, screen: string, path: string}>
     */
    private function pageTargets(string $class, string $slug): array
    {
        $path = $this->urlPath(fn(): string => $class::getUrl());

        return $path === null ? [] : [['slug' => $slug, 'screen' => 'list', 'path' => $path]];
    }

    /**
     * @param  class-string<FilamentResource>  $class
     * @return list<array{slug: string, screen: string, path: string}>
     */
    private function resourceTargets(string $class, string $slug): array
    {
        $pages = array_keys($class::getPages());
        $recordId = $this->firstRecordId($class);
        $targets = [];

        foreach (self::SCREENS as $page => $screen) {
            $needsRecord = in_array($screen, ['view', 'edit'], true);

            if (! in_array($page, $pages, true) || ($needsRecord && $recordId === null)) {
                continue;
            }

            $path = $this->urlPath(fn(): string => $class::getUrl($page, $needsRecord ? ['record' => $recordId] : []));

            if ($path !== null) {
                $targets[] = ['slug' => $slug, 'screen' => $screen, 'path' => $path];
            }
        }

        return $targets;
    }

    /**
     * Root-relative path of a Filament URL, or null when it cannot be built.
     *
     * @param  Closure(): string  $resolver
     */
    private function urlPath(Closure $resolver): ?string
    {
        try {
            $path = parse_url($resolver(), PHP_URL_PATH);
        } catch (Throwable) {
            return null;
        }

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * Route key of the first record of the resource's model, ignoring global
     * scopes, or null when the table is empty (view/edit are then skipped).
     *
     * @param  class-string<FilamentResource>  $class
     */
    private function firstRecordId(string $class): int|string|null
    {
        try {
            $query = $class::getModel()::query()->withoutGlobalScopes();

            // The route key, not the primary key: a model may route by its slug.
            $key = $query->value($query->getModel()->getRouteKeyName());

            return is_int($key) || is_string($key) ? $key : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function caption(string $screen, ?string $tabLabel): string
    {
        $label = __("filament-docs::docs.screenshots.{$screen}");

        return blank($tabLabel) ? $label : "{$label} — {$tabLabel}";
    }

    private function imageUrl(string $file): string
    {
        return $this->imagesUrl() . '/' . $file;
    }

    /**
     * The configured prefix, or the image route of the current panel, or of
     * the first panel that registers the plugin.
     */
    public function imagesUrl(): string
    {
        $configured = config('filament-docs.images_url');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        $current = Filament::getCurrentPanel();

        foreach (array_filter([$current, ...Filament::getPanels()]) as $panel) {
            $plugin = FilamentDocsPlugin::get($panel);

            if ($plugin instanceof FilamentDocsPlugin) {
                return $plugin->getImagesUrl($panel);
            }
        }

        throw new LogicException('No panel registers the FilamentDocsPlugin; set filament-docs.images_url.');
    }

    /** Removes everything a previous run injected, inline and bottom block. */
    private function stripInjected(string $markdown): string
    {
        // The blank lines around an inline image go with it, so a re-run leaves the prose as it found it.
        $markdown = (string)preg_replace('/\n*<!-- screenshot:[^\n]*?-->.*?<!-- \/screenshot:[^\n]*?-->\n*/s', "\n\n", $markdown);

        return (string)preg_replace('/\n*<!-- screenshots:start -->.*?<!-- screenshots:end -->/s', '', $markdown);
    }

    /**
     * @param  list<string>  $lines
     * @return list<array{index: int, text: string}>
     */
    private function headings(array $lines): array
    {
        $headings = [];

        foreach ($lines as $index => $line) {
            if (preg_match('/^#{1,6}\s+(.+?)\s*$/', $line, $matches) === 1) {
                $headings[] = ['index' => $index, 'text' => $matches[1]];
            }
        }

        return $headings;
    }

    /**
     * The first unused heading equal to, or containing, the tab label (accent-
     * and case-insensitive), or null.
     *
     * @param  list<array{index: int, text: string}>  $headings
     * @param  array<int, bool>  $used
     */
    private function matchHeading(string $label, array $headings, array $used): ?int
    {
        $needle = SearchText::fold(trim($label));
        $contained = null;

        foreach ($headings as $heading) {
            if (isset($used[$heading['index']])) {
                continue;
            }

            $text = SearchText::fold($heading['text']);

            if ($text === $needle) {
                return $heading['index'];
            }

            if ($contained === null && $text !== '' && (str_contains($needle, $text) || str_contains($text, $needle))) {
                $contained = $heading['index'];
            }
        }

        return $contained;
    }

    /**
     * Puts each heading's images at the end of its section: after its last
     * line of text, before the blank line that leads to the next heading.
     *
     * @param  list<string>  $lines
     * @param  list<array{index: int, text: string}>  $headings
     * @param  array<int, list<string>>  $inline
     */
    private function insertInline(array $lines, array $headings, array $inline): string
    {
        $after = [];

        foreach ($inline as $headingIndex => $snippets) {
            $last = (collect($headings)->first(fn(array $heading): bool => $heading['index'] > $headingIndex)['index'] ?? count($lines)) - 1;

            while ($last > $headingIndex && trim($lines[$last]) === '') {
                $last--;
            }

            $after[$last] = implode("\n\n", $snippets);
        }

        $out = [];

        foreach ($lines as $index => $line) {
            $out[] = $line;

            if (isset($after[$index])) {
                $out[] = '';
                $out[] = $after[$index];
            }
        }

        return rtrim(implode("\n", $out));
    }

    /**
     * @param  list<string>  $references
     */
    private function appendBlock(string $markdown, array $references): string
    {
        if ($references === []) {
            return rtrim($markdown) . "\n";
        }

        return rtrim($markdown) . "\n\n<!-- screenshots:start -->\n## " . __('filament-docs::docs.screenshots.title') . "\n\n"
            . implode("\n\n", $references) . "\n<!-- screenshots:end -->\n";
    }

    /** The doc in the folder of the current panel, the one being captured. */
    private function docPath(string $slug): string
    {
        return FilamentDocsPlugin::get()?->getDocPath($slug) ?? DocsConfig::docPath($slug);
    }
}

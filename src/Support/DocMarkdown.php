<?php

namespace Arzcode\FilamentDocs\Support;

use Arzcode\FilamentDocs\FilamentDocsPlugin;
use Arzcode\FilamentDocs\FilamentDocsServiceProvider;
use Arzcode\FilamentDocs\Pages\Documentation;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * The whole manual as markdown: a zip with one `{slug}.md` per doc the reader
 * may open, the screenshots those docs show under `images/`, and a
 * `README.md` that lists the docs in the sidebar's order and groups.
 *
 * Built from the sidebar, like the PDF, so a reader never gets a doc for a
 * screen they cannot open. The screenshot links point at `images/`, and the
 * links between docs are already relative (`posts.md#roles`), so the copy
 * reads the same in any markdown viewer, offline.
 */
class DocMarkdown
{
    public const string README = 'README.md';

    /**
     * The index's name when a doc is called `readme`, which would overwrite
     * README.md wherever file names ignore case. A slug never starts with `_`.
     */
    public const string README_FALLBACK = '_README.md';

    /**
     * Whether the reader may download it: they can open the documentation,
     * the plugin allows it, and PHP can write a zip.
     */
    public static function canExport(?Authenticatable $user = null): bool
    {
        return class_exists(ZipArchive::class)
            && Documentation::canAccess()
            && (FilamentDocsPlugin::get()?->hasMarkdownExport($user) ?? false);
    }

    /**
     * Writes the zip to a temporary file and returns its path. The caller
     * deletes it once sent; a zip that fails to build is deleted here.
     *
     * Screenshots on a local disk are added from their files, not read into
     * memory, so a manual with many of them does not exhaust the request.
     */
    public function zip(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'filament-docs-');

        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the markdown export.');
        }

        try {
            $zip = new ZipArchive;

            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not open the markdown export for writing.');
            }

            ['docs' => $docs, 'images' => $images] = $this->build();
            $disk = $this->disk();
            $local = $this->diskIsLocal();

            foreach ($docs as $name => $contents) {
                $zip->addFromString($name, $contents);
            }

            foreach ($images as $image) {
                if ($local) {
                    $zip->addFile($disk->path($image), "images/{$image}");
                } elseif (is_string($contents = $disk->get($image))) {
                    $zip->addFromString("images/{$image}", $contents);
                }
            }

            if (! $zip->close()) {
                throw new RuntimeException('Could not write the markdown export.');
            }
        } catch (Throwable $exception) {
            File::delete($path);

            throw $exception;
        }

        return $path;
    }

    /**
     * Every file of the zip, by its name in it: the README, each doc, then
     * each screenshot those docs show and the disk holds.
     *
     * @return array<string, string>
     */
    public function files(): array
    {
        ['docs' => $files, 'images' => $images] = $this->build();
        $disk = $this->disk();

        foreach ($images as $image) {
            $contents = $disk->get($image);

            if (is_string($contents)) {
                $files["images/{$image}"] = $contents;
            }
        }

        return $files;
    }

    /**
     * The README and the docs, by their names in the zip, and the screenshots
     * they show, by their paths on the disk.
     *
     * @return array{docs: array<string, string>, images: list<string>}
     */
    private function build(): array
    {
        $documentation = app(Documentation::class);
        $groups = $this->sidebarGroups();
        $slugs = array_merge(...array_map(fn(array $group): array => array_column($group['items'], 'slug'), $groups));
        $readme = in_array('readme', $slugs, true) ? self::README_FALLBACK : self::README;
        $docs = [$readme => $this->readme($groups)];
        $images = [];

        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $markdown = $this->localizeImages($documentation->getMarkdown($item['slug']), $images);
                $docs["{$item['slug']}.md"] = rtrim($markdown) . "\n";
            }
        }

        return ['docs' => $docs, 'images' => array_keys(array_filter($images))];
    }

    /**
     * Points the screenshot links at `images/` and records each one, so the
     * zip carries the files they name. Only the generated `{slug}/{name}.webp`
     * shape is taken, so nothing outside the screenshots is read, and a link
     * to an image the disk lacks is left as it was rather than broken.
     *
     * @param  array<string, bool>  $images  whether each image seen is on the disk
     */
    private function localizeImages(string $markdown, array &$images): string
    {
        $prefix = preg_quote(app(DocScreenshots::class)->imagesUrl() . '/', '#');
        $disk = $this->disk();

        return (string)preg_replace_callback("#{$prefix}([a-z0-9-]+/[a-z0-9-]+\\.webp)#", function(array $matches) use (&$images, $disk): string {
            $images[$matches[1]] ??= $disk->exists($matches[1]);

            return $images[$matches[1]] ? "images/{$matches[1]}" : $matches[0];
        }, $markdown);
    }

    /**
     * The manual's title and its docs, grouped as in the sidebar.
     *
     * @param  list<array{label: string|null, items: list<array{slug: string, label: string}>}>  $groups
     */
    private function readme(array $groups): string
    {
        $lines = ['# ' . __('filament-docs::docs.pdf.title', ['app' => config()->string('app.name')]), ''];

        foreach ($groups as $group) {
            if ($group['items'] === []) {
                continue;
            }

            if ($group['label'] !== null) {
                $lines[] = "## {$group['label']}";
                $lines[] = '';
            }

            foreach ($group['items'] as $item) {
                $lines[] = '- [' . addcslashes($item['label'], '\\[]') . "]({$item['slug']}.md)";
            }

            $lines[] = '';
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }

    /**
     * The sidebar as the reader sees it, with the labels as plain text.
     *
     * @return list<array{label: string|null, items: list<array{slug: string, label: string}>}>
     */
    private function sidebarGroups(): array
    {
        $groups = [];

        foreach (app(Documentation::class)->getSidebarGroups() as $group) {
            $groups[] = [
                'label' => $group['label'] === null ? null : $this->plain($group['label']),
                'items' => array_map(
                    fn(array $item): array => ['slug' => $item['slug'], 'label' => $this->plain($item['label'])],
                    $group['items'],
                ),
            ];
        }

        return $groups;
    }

    /**
     * A label as one line of plain text, so it cannot break a heading or a list item.
     */
    private function plain(string|Htmlable $text): string
    {
        $text = strip_tags($text instanceof Htmlable ? $text->toHtml() : $text);

        return trim((string)preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5)));
    }

    private function disk(): Filesystem
    {
        return Storage::disk(FilamentDocsServiceProvider::DISK);
    }

    private function diskIsLocal(): bool
    {
        return config('filesystems.disks.' . FilamentDocsServiceProvider::DISK . '.driver') === 'local';
    }
}

<?php

namespace Arzcode\FilamentDocs\Support;

use Arzcode\FilamentDocs\FilamentDocsPlugin;
use Arzcode\FilamentDocs\FilamentDocsServiceProvider;
use Arzcode\FilamentDocs\Pages\Documentation;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfWrapper;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;
use DOMElement;
use Dompdf\Canvas;
use Dompdf\Frame;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds the PDF of one doc or of the whole manual from the docs the reader
 * may open, rendered by DocRenderer so the PDF shows what the page shows.
 *
 * dompdf has no CSS variables, no oklch() and no remote images, and it
 * ignores `max-height` on images, so the work here is to turn the page's HTML
 * into something it lays out well: concrete colors, screenshots read from the
 * disk and sized in millimeters, and headings kept with the block after them.
 *
 * @phpstan-type PdfItem array{slug: string, label: string, showLabel: bool, html: string}
 * @phpstan-type PdfGroup array{label: string|null, items: list<PdfItem>}
 * @phpstan-type PdfBook array{title: string, logo: string|null, contents: bool, groups: list<PdfGroup>}
 */
class DocPdf
{
    /**
     * Bounding box in millimeters for a screenshot on an A4 portrait page.
     * The page leaves 251 mm between its margins, and a figure also carries
     * its caption and the headings kept with it (up to about 45 mm). A figure
     * taller than the page cannot be kept whole, so dompdf would leave the
     * headings at the bottom of one page and the screenshot on the next.
     */
    private const float IMAGE_MAX_WIDTH_MM = 165.0;

    private const float IMAGE_MAX_HEIGHT_MM = 200.0;
    private const array HEADINGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    /**
     * JPEG quality of the copies embedded in the PDF. dompdf decodes a WebP
     * into an uncompressed bitmap plus an alpha mask, about six times the
     * file's size; a JPEG goes into the PDF as it is.
     */
    private const int JPEG_QUALITY = 82;

    /**
     * The Filament color each callout type takes, as on the page.
     */
    private const array CALLOUT_COLORS = [
        'note'      => 'info',
        'tip'       => 'success',
        'important' => 'primary',
        'warning'   => 'warning',
        'caution'   => 'danger',
    ];

    /**
     * The whole manual: every doc in the reader's sidebar, in its order and
     * groups, with a cover and a contents list that link to each section.
     *
     * @return PdfBook
     */
    public function forManual(): array
    {
        $groups = $this->sidebarGroups();
        $slugs = array_merge(...array_map(fn(array $group): array => array_column($group['items'], 'slug'), $groups));
        $pdfGroups = [];

        foreach ($groups as $group) {
            $single = count($group['items']) === 1;

            $items = array_map(
                fn(array $item): array => $this->item(
                    $item['slug'],
                    $item['label'],
                    // A group of one doc named like the group would print the title twice.
                    showLabel: ! $single || $group['label'] !== $item['label'],
                    manualSlugs: $slugs,
                ),
                $group['items'],
            );

            if ($items !== []) {
                $pdfGroups[] = ['label' => $group['label'], 'items' => $items];
            }
        }

        return [
            'title'    => __('filament-docs::docs.pdf.title', ['app' => config()->string('app.name')]),
            'logo'     => FilamentDocsPlugin::get()?->getPdfLogo(),
            'contents' => true,
            'groups'   => $pdfGroups,
        ];
    }

    /**
     * One doc, if the reader may open it: no cover and no contents list, and
     * its links to other docs point at the panel.
     *
     * @return PdfBook|null
     */
    public function forDoc(string $slug): ?array
    {
        foreach ($this->sidebarGroups() as $group) {
            foreach ($group['items'] as $item) {
                if ($item['slug'] === $slug) {
                    return [
                        'title'    => $item['label'],
                        'logo'     => null,
                        'contents' => false,
                        'groups'   => [['label' => null, 'items' => [$this->item($slug, $item['label'], showLabel: true, manualSlugs: null)]]],
                    ];
                }
            }
        }

        return null;
    }

    /**
     * The PDF file, with the page number in the footer and, in the manual,
     * the page of each doc in the contents.
     *
     * @param  PdfBook  $book
     */
    public function output(array $book): string
    {
        $pdf = $this->pdf($book, $this->docPages($book));
        $pdf->render();

        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $font = $metrics->getFont('DejaVu Sans') ?? 'helvetica';
        $size = 8.0;
        $width = $metrics->getTextWidth('00 / 00', $font, $size);

        $canvas->page_text(($canvas->get_width() - $width) / 2, $canvas->get_height() - 28, '{PAGE_NUM} / {PAGE_COUNT}', $font, $size, [0.42, 0.45, 0.5]);

        return $pdf->output();
    }

    /**
     * The page each doc starts on, read from a first layout of the manual:
     * dompdf cannot number a link's target, so the manual is laid out twice.
     * The page numbers sit in a fixed-width column, so printing them in the
     * second layout moves nothing. A single doc has no contents, so it is
     * laid out once.
     *
     * @param  PdfBook  $book
     * @return array<string, int>
     */
    public function docPages(array $book): array
    {
        if (! $book['contents']) {
            return [];
        }

        $pages = [];
        $pdf = $this->pdf($book);

        $pdf->getDomPDF()->setCallbacks([[
            'event' => 'begin_frame',
            'f'     => function(Frame $frame, Canvas $canvas) use (&$pages): void {
                $node = $frame->get_node();
                $id = $node instanceof DOMElement ? $node->getAttribute('id') : '';

                if (str_starts_with($id, 'doc-')) {
                    $pages[substr($id, 4)] ??= $canvas->get_page_number();
                }
            },
        ]]);

        $pdf->render();

        return $pages;
    }

    /**
     * @param  PdfBook  $book
     * @param  array<string, int>  $pages
     */
    private function pdf(array $book, array $pages = []): PdfWrapper
    {
        return Pdf::loadView('filament-docs::pdf', [
            'book'          => $book,
            'pages'         => $pages,
            'accent'        => $this->accentColor(),
            'calloutStyles' => $this->calloutStyles(),
        ])
            ->setPaper('a4')
            ->setOption('chroot', $this->chroots($book['logo']));
    }

    /**
     * Whether the reader may export at all: they can open the documentation
     * and the plugin allows the export.
     */
    public static function canExport(?Authenticatable $user = null): bool
    {
        return Documentation::canAccess() && (FilamentDocsPlugin::get()?->hasPdfExport($user) ?? false);
    }

    /**
     * Fits a screenshot of the plugin's images URL into the page: the source
     * becomes the file's absolute path (dompdf has no base URL) and the size
     * is stamped in millimeters, contained in a one-page box. Anything that is
     * not a screenshot on the disk is left as it was.
     */
    public function inlineImages(string $html): string
    {
        $prefix = app(DocScreenshots::class)->imagesUrl() . '/';

        return $this->transform($html, function(HTMLDocument $document) use ($prefix): void {
            foreach ($document->querySelectorAll('img[src]') as $image) {
                $source = (string)$image->getAttribute('src');

                if (! str_starts_with($source, $prefix)) {
                    continue;
                }

                $absolute = $this->screenshotPath(substr($source, strlen($prefix)));
                $size = $absolute === null ? false : @getimagesize($absolute);

                if ($absolute === null || $size === false || $size[0] <= 0 || $size[1] <= 0) {
                    continue;
                }

                $ratio = $size[1] / $size[0];
                $width = min(self::IMAGE_MAX_WIDTH_MM, self::IMAGE_MAX_HEIGHT_MM / $ratio);

                $image->setAttribute('src', $this->asJpeg($absolute));
                $image->setAttribute('style', sprintf('width:%.1fmm;height:%.1fmm;', $width, $width * $ratio));
            }
        });
    }

    /**
     * Wraps each heading, with any heading right after it and the block that
     * follows, in a `pdf-keep` container, and each screenshot with its bold
     * caption in a `pdf-figure`, so a page never ends on a heading or a
     * caption. A heading before a figure joins the figure's container instead
     * of nesting a second one: dompdf strands the heading when two avoid-break
     * containers are nested.
     */
    public function keepTogether(string $html): string
    {
        return $this->transform($html, function(HTMLDocument $document, Element $body): void {
            $this->groupFigures($document, $body);

            $blocks = $this->childElements($body);
            $count = count($blocks);
            $index = 0;

            while ($index < $count) {
                if (! in_array($blocks[$index]->localName, self::HEADINGS, true)) {
                    $index++;

                    continue;
                }

                $group = [$blocks[$index]];
                $next = $index + 1;

                while ($next < $count && in_array($blocks[$next]->localName, self::HEADINGS, true)) {
                    $group[] = $blocks[$next++];
                }

                $content = $blocks[$next++] ?? null;

                if ($content instanceof Element) {
                    if ($this->hasClass($content, 'pdf-figure')) {
                        $content->prepend(...$group);
                    } else {
                        $wrapper = $document->createElement('div');
                        $wrapper->setAttribute('class', 'pdf-keep');
                        $group[0]->before($wrapper);
                        $wrapper->append(...$group, ...[$content]);
                    }
                }

                $index = $next;
            }
        });
    }

    /**
     * Concrete rgb() rules for the callouts, from the panel's live palette:
     * dompdf reads neither CSS variables nor oklch().
     */
    public function calloutStyles(): string
    {
        $colors = FilamentColor::getColors();
        $css = '';

        foreach (self::CALLOUT_COLORS as $type => $name) {
            $border = $this->rgb($colors[$name][500] ?? null);
            $background = $this->rgb($colors[$name][50] ?? null);
            $title = $this->rgb($colors[$name][700] ?? null);

            if ($border === null || $background === null || $title === null) {
                continue;
            }

            $css .= ".fi-docs-callout-{$type}{border-left:4px solid {$border};background-color:{$background}}"
                . ".fi-docs-callout-{$type} .fi-docs-callout-title{color:{$title}}";
        }

        return $css;
    }

    /**
     * The panel's primary color for the headings and links, or a dark gray
     * when it has none.
     */
    public function accentColor(): string
    {
        return $this->rgb(FilamentColor::getColors()['primary'][700] ?? null) ?? '#374151';
    }

    /**
     * A palette shade as rgb(), or null when the panel has no such shade.
     */
    private function rgb(mixed $shade): ?string
    {
        return is_string($shade) ? Color::convertToRgb($shade) : null;
    }

    /**
     * @param  list<string>|null  $manualSlugs  the docs in the manual, or null for a single doc
     * @return PdfItem
     */
    private function item(string $slug, string $label, bool $showLabel, ?array $manualSlugs): array
    {
        $rendered = app(DocRenderer::class)->render(
            File::get(DocsConfig::docPath($slug)),
            fn(string $target): string => $manualSlugs !== null && in_array($target, $manualSlugs, true)
                ? "#doc-{$target}"
                : Documentation::getUrl(['doc' => $target]),
        );

        $lead = $showLabel
            ? '<h2 class="section-title">' . e($label)
                . ($manualSlugs === null ? '' : '<a class="section-back" href="#toc">↑ ' . e(__('filament-docs::docs.pdf.back_to_contents')) . '</a>')
                . '</h2>'
            : '';

        $body = $this->inlineImages($this->prepare($rendered['html'], $slug, $label, $manualSlugs !== null));

        return ['slug' => $slug, 'label' => $label, 'showLabel' => $showLabel, 'html' => $this->keepTogether($lead . $body)];
    }

    /**
     * Drops the "#" anchors and the title the section heading already prints
     * and, in the manual, prefixes the heading ids and the links to them with
     * the doc's slug (`posts--roles`) so two docs cannot collide.
     */
    private function prepare(string $html, string $slug, string $label, bool $manual): string
    {
        return $this->transform($html, function(HTMLDocument $document, Element $body) use ($slug, $label, $manual): void {
            foreach ($document->querySelectorAll('a.fi-docs-anchor') as $anchor) {
                $anchor->remove();
            }

            $first = $body->firstElementChild;

            if ($first?->localName === 'h1' && Str::lower(trim((string)$first->textContent)) === Str::lower(trim($label))) {
                $first->remove();
            }

            if (! $manual) {
                return;
            }

            foreach ($document->querySelectorAll('[id]') as $element) {
                $element->setAttribute('id', "{$slug}--{$element->getAttribute('id')}");
            }

            foreach ($document->querySelectorAll('a[href^="#"]') as $link) {
                $href = (string)$link->getAttribute('href');

                if (preg_match('/^#doc-([a-z0-9-]+)#(.+)$/', $href, $matches) === 1) {
                    $link->setAttribute('href', "#{$matches[1]}--{$matches[2]}");
                } elseif (! str_starts_with($href, '#doc-')) {
                    $link->setAttribute('href', "#{$slug}--" . substr($href, 1));
                }
            }
        });
    }

    /**
     * Parses an HTML fragment, lets the callback change it, and serializes it.
     *
     * @param  callable(HTMLDocument, Element): void  $callback
     */
    private function transform(string $html, callable $callback): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = HTMLDocument::createFromString("<!DOCTYPE html><body>{$html}</body>", LIBXML_NOERROR, 'UTF-8');
        $body = $document->body;

        if (! $body instanceof Element) {
            return $html;
        }

        $callback($document, $body);

        return $body->innerHTML;
    }

    /**
     * Screenshots are written as a bold caption followed by the image, which
     * markdown renders into one paragraph when no blank line separates them,
     * or two when one does. Either way the caption is lifted above the image
     * and both go into a `pdf-figure`.
     */
    private function groupFigures(HTMLDocument $document, Element $body): void
    {
        $blocks = $this->childElements($body);

        foreach ($blocks as $index => $block) {
            if (! $this->isImageParagraph($block)) {
                continue;
            }

            $figure = $document->createElement('div');
            $figure->setAttribute('class', 'pdf-figure');
            $block->before($figure);

            $caption = $this->liftInlineCaption($document, $block);

            if (! $caption instanceof Element) {
                $previous = $blocks[$index - 1] ?? null;
                $caption = $previous instanceof Element && $this->isCaptionParagraph($previous) ? $previous : null;
            }

            if ($caption instanceof Element) {
                $figure->append($caption);
            }

            // dompdf ignores `margin: auto` on an image with a fixed width, so the paragraph centers it.
            $block->setAttribute('class', 'pdf-image');
            $figure->append($block);
        }
    }

    private function liftInlineCaption(HTMLDocument $document, Element $paragraph): ?Element
    {
        foreach ($this->childElements($paragraph) as $child) {
            if ($child->localName === 'strong') {
                $caption = $document->createElement('p');
                $caption->append($child);

                return $caption;
            }
        }

        return null;
    }

    /**
     * The element children of a node. `Element::$children` is PHP 8.5 only.
     *
     * @return list<Element>
     */
    private function childElements(Element $parent): array
    {
        $children = [];

        for ($child = $parent->firstElementChild; $child instanceof Element; $child = $child->nextElementSibling) {
            $children[] = $child;
        }

        return $children;
    }

    /**
     * A `<p>` holding an image and, at most, a bold caption.
     */
    private function isImageParagraph(Element $element): bool
    {
        if ($element->localName !== 'p') {
            return false;
        }

        $hasImage = false;

        foreach ($element->childNodes as $child) {
            if ($child instanceof Element && $child->localName === 'img') {
                $hasImage = true;
            } elseif (! $this->isCaptionPart($child)) {
                return false;
            }
        }

        return $hasImage;
    }

    /**
     * A `<p>` that holds a bold caption and nothing else.
     */
    private function isCaptionParagraph(Element $element): bool
    {
        if ($element->localName !== 'p') {
            return false;
        }

        $hasStrong = false;

        foreach ($element->childNodes as $child) {
            if ($child instanceof Element && $child->localName === 'strong') {
                $hasStrong = true;
            } elseif (! $child instanceof Text || trim((string)$child->textContent) !== '') {
                return false;
            }
        }

        return $hasStrong;
    }

    private function isCaptionPart(Node $node): bool
    {
        return ($node instanceof Element && $node->localName === 'strong')
            || ($node instanceof Text && trim((string)$node->textContent) === '');
    }

    private function hasClass(Element $element, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', (string)$element->getAttribute('class')) ?: [], true);
    }

    /**
     * The file of a screenshot on the docs disk, only for the generated
     * `{slug}/{name}.webp` shape and only when the disk is a local one.
     */
    private function screenshotPath(string $relative): ?string
    {
        if (preg_match('#^[a-z0-9-]+/[a-z0-9-]+\.webp$#', $relative) !== 1 || ! $this->diskIsLocal()) {
            return null;
        }

        $disk = Storage::disk(FilamentDocsServiceProvider::DISK);

        if (! $disk->exists($relative)) {
            return null;
        }

        $path = realpath($disk->path($relative));

        return $path === false ? null : $path;
    }

    /**
     * A JPEG copy of a screenshot, made once and kept until the screenshot
     * changes. Screenshots have no transparency, so the alpha channel is
     * flattened onto white. Without WebP support in GD the original is used.
     */
    private function asJpeg(string $path): string
    {
        if (! function_exists('imagecreatefromwebp') || ! function_exists('imagejpeg')) {
            return $path;
        }

        $target = $this->jpegFolder() . '/' . sha1($path . '|' . filemtime($path)) . '.jpg';

        if (is_file($target)) {
            return $target;
        }

        $source = @imagecreatefromwebp($path);

        if ($source === false) {
            return $path;
        }

        $canvas = imagecreatetruecolor(imagesx($source), imagesy($source));
        imagefill($canvas, 0, 0, (int)imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $source, 0, 0, 0, 0, imagesx($source), imagesy($source));

        File::ensureDirectoryExists(dirname($target));
        $written = imagejpeg($canvas, $target, self::JPEG_QUALITY);

        return $written ? $target : $path;
    }

    private function jpegFolder(): string
    {
        return storage_path('framework/cache/filament-docs-pdf');
    }

    private function diskIsLocal(): bool
    {
        return config('filesystems.disks.' . FilamentDocsServiceProvider::DISK . '.driver') === 'local';
    }

    /**
     * The folders dompdf may read files from: the app, as it defaults to, plus
     * the screenshots disk and the logo's folder, which may lie outside it.
     *
     * @return list<string>
     */
    private function chroots(?string $logo): array
    {
        $folders = [base_path()];

        if ($this->diskIsLocal()) {
            try {
                $folders[] = Storage::disk(FilamentDocsServiceProvider::DISK)->path('');
            } catch (Throwable) {
                // A disk that cannot name its root has no screenshots to read.
            }
        }

        if ($logo !== null) {
            $folders[] = dirname($logo);
        }

        File::ensureDirectoryExists($this->jpegFolder());
        $folders[] = $this->jpegFolder();

        return array_values(array_unique(array_filter(array_map(realpath(...), $folders))));
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

    private function plain(string|Htmlable $text): string
    {
        return trim(strip_tags($text instanceof Htmlable ? $text->toHtml() : $text));
    }
}

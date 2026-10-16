<?php

namespace Arzcode\FilamentDocs\Pages;

use Arzcode\FilamentDocs\FilamentDocsPlugin;
use Arzcode\FilamentDocs\Support\DocPdf;
use Arzcode\FilamentDocs\Support\DocRenderer;
use Arzcode\FilamentDocs\Support\DocsConfig;
use Arzcode\FilamentDocs\Support\SearchText;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Resources\Resource as FilamentResource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * The user manual: one markdown file per screen in the docs folder. Its
 * sidebar is the panel's own navigation with each screen of
 * `config('filament-docs.screens')` replaced by its doc, so the two read
 * alike and a reader only finds the docs for the screens in their navigation.
 *
 * @phpstan-import-type Heading from DocRenderer
 * @phpstan-import-type RenderedDoc from DocRenderer
 *
 * @phpstan-type DocItem array{slug: string, label: string|Htmlable, icon: string|BackedEnum|Htmlable, url: string}
 * @phpstan-type SearchResult array{slug: string, label: string|Htmlable, icon: string|BackedEnum|Htmlable, url: string, snippet: string}
 */
class Documentation extends Page
{
    protected string $view = 'filament-docs::documentation';

    /** Locked: it was checked in mount(), and is read from disk on every render. */
    #[Locked]
    public string $docSlug = 'index';

    public ?string $search = null;

    #[Locked]
    public ?string $highlight = null;

    /**
     * Built once per request: the navigation it comes from asks every screen
     * whether the reader may open it.
     *
     * @var list<array{label: string|Htmlable|null, items: list<DocItem>}>|null
     */
    private ?array $sidebarGroups = null;

    /**
     * Search weights per word, after Laradocs' title-over-body ranking, with a
     * tier for headings in between.
     */
    private const int TITLE_WEIGHT = 3;

    private const int HEADING_WEIGHT = 2;
    private const int BODY_WEIGHT = 1;

    /**
     * Below this many headings a contents list only repeats what is on screen.
     */
    private const int MIN_TOC_HEADINGS = 2;

    private const array ACCENT_CLASSES = [
        'a' => '[aáàâä]', 'e' => '[eéèêë]', 'i' => '[iíìîï]',
        'o' => '[oóòôö]', 'u' => '[uúùûü]', 'n' => '[nñ]', 'c' => '[cç]',
    ];

    /**
     * Everyone who works in the panel: the docs they may not read are
     * filtered out one by one, see canReadDoc().
     */
    public static function canAccess(): bool
    {
        return FilamentDocsPlugin::get()?->isAuthorized() ?? false;
    }

    /** The plugin's slug, with the optional doc after it. */
    public static function getSlug(?Panel $panel = null): string
    {
        return (FilamentDocsPlugin::get($panel)?->getSlug() ?? 'docs') . '/{doc?}';
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-docs::docs.navigation_label');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return FilamentDocsPlugin::get()?->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        return FilamentDocsPlugin::get()?->getNavigationSort();
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return FilamentDocsPlugin::get()?->getNavigationIcon();
    }

    public function getTitle(): string|Htmlable
    {
        return __('filament-docs::docs.title');
    }

    /**
     * One "Export PDF" menu: the open doc, or the whole manual. The open doc
     * is not offered over search results, where none is open.
     *
     * @return array<Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('exportDocPdf')
                    ->label(__('filament-docs::docs.pdf.this_doc'))
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->visible(fn(): bool => blank($this->search))
                    ->action(fn(): StreamedResponse => $this->exportDocPdf()),
                Action::make('exportManualPdf')
                    ->label(__('filament-docs::docs.pdf.manual'))
                    ->icon(Heroicon::OutlinedBookOpen)
                    ->action(fn(): StreamedResponse => $this->exportManualPdf()),
            ])
                ->label(__('filament-docs::docs.pdf.export'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->button()
                ->visible(fn(): bool => DocPdf::canExport()),
        ];
    }

    public function exportDocPdf(): StreamedResponse
    {
        abort_unless(DocPdf::canExport(), Response::HTTP_FORBIDDEN);

        $pdf = app(DocPdf::class);
        $book = $pdf->forDoc($this->docSlug);

        // The same 404 as the page gives for a doc the reader may not open.
        abort_if($book === null, Response::HTTP_NOT_FOUND);

        return $this->downloadPdf($pdf->output($book), __('filament-docs::docs.pdf.doc_filename', [
            'app' => config()->string('app.name'),
            'doc' => $book['title'],
        ]));
    }

    public function exportManualPdf(): StreamedResponse
    {
        abort_unless(DocPdf::canExport(), Response::HTTP_FORBIDDEN);

        $pdf = app(DocPdf::class);

        return $this->downloadPdf($pdf->output($pdf->forManual()), __('filament-docs::docs.pdf.manual_filename', [
            'app' => config()->string('app.name'),
        ]));
    }

    /**
     * Streams the PDF as `{name}.pdf`, with the characters no file system
     * accepts in a file name replaced by a dash.
     */
    private function downloadPdf(string $content, string $name): StreamedResponse
    {
        $filename = trim((string)preg_replace('#[\\\\/:*?"<>|]+#', '-', $name)) . '.pdf';

        return response()->streamDownload(function() use ($content): void {
            echo $content;
        }, $filename, ['Content-Type' => 'application/pdf']);
    }

    public function mount(?string $doc = null): void
    {
        $this->docSlug = $doc ?? 'index';
        $this->highlight = request()->query('highlight');

        // Same 404 as an unknown slug: a 403 would confirm the doc exists to
        // someone who may not open the screen it describes.
        abort_unless($this->canReadDoc($this->docSlug) && File::exists($this->docPath($this->docSlug)), Response::HTTP_NOT_FOUND);
    }

    /**
     * The sidebar as the current reader sees it: the concept docs, then the
     * panel's navigation group by group, keeping each screen that has a doc
     * with its label and icon. Filament only puts in the navigation what the
     * reader may open, so the rendered sidebar, the search scope, the pager
     * and the slug the page serves, all built from this, cannot disagree
     * with the panel. The ungrouped screens join the concept docs at the top,
     * without a heading, as they sit in the panel.
     *
     * @return list<array{label: string|Htmlable|null, items: list<DocItem>}>
     */
    public function getSidebarGroups(): array
    {
        if ($this->sidebarGroups !== null) {
            return $this->sidebarGroups;
        }

        $slugsByUrl = [];

        foreach ($this->screens() as $slug => $screen) {
            $slugsByUrl[$screen::getUrl()] = $slug;
        }

        $groups = [['label' => null, 'items' => array_map($this->conceptItem(...), array_keys($this->concepts()), $this->concepts())]];

        foreach (Filament::getNavigation() as $navigationGroup) {
            $items = [];

            foreach (collect($navigationGroup->getItems())->whereInstanceOf(NavigationItem::class) as $navigationItem) {
                $slug = $slugsByUrl[(string)$navigationItem->getUrl()] ?? null;

                if ($slug !== null) {
                    $items[] = [
                        'slug'  => $slug,
                        'label' => $navigationItem->getLabel(),
                        'icon'  => $navigationItem->getIcon() ?? Heroicon::OutlinedDocumentText,
                        'url'   => static::getUrl(['doc' => $slug]),
                    ];
                }
            }

            $label = $navigationGroup->getLabel();

            if ($label === null) {
                $groups[0]['items'] = [...$groups[0]['items'], ...$items];
            } elseif ($items !== []) {
                $groups[] = ['label' => $label, 'items' => $items];
            }
        }

        return $this->sidebarGroups = $groups;
    }

    /**
     * Where the screen the open doc describes lives, for the "open in the
     * panel" link. Null for a doc about a concept.
     */
    public function getScreenUrl(): ?string
    {
        $screen = $this->screens()[$this->docSlug] ?? null;

        return $screen === null ? null : $screen::getUrl();
    }

    public function getRenderedContent(): string
    {
        $html = $this->document($this->docSlug)['html'];
        $terms = SearchText::terms((string)$this->highlight);

        if ($terms === []) {
            return $html;
        }

        $pattern = implode('|', array_map(
            fn(string $term): string => strtr(preg_quote($term, '/'), self::ACCENT_CLASSES),
            $terms,
        ));

        // Only outside tags, so an attribute that contains the term is left alone.
        return (string)preg_replace("/({$pattern})(?![^<]*>)/iu", '<mark>$1</mark>', $html);
    }

    /**
     * @return list<Heading>
     */
    public function getTableOfContents(): array
    {
        $headings = $this->document($this->docSlug)['headings'];

        return count($headings) >= self::MIN_TOC_HEADINGS ? $headings : [];
    }

    /**
     * The docs either side of the open one, in sidebar order and among those
     * the reader may open, so a reader is never led to a hidden doc.
     *
     * @return array{previous: ?DocItem, next: ?DocItem}
     */
    public function getPager(): array
    {
        $items = array_merge(...array_column($this->getSidebarGroups(), 'items'));
        $position = array_search($this->docSlug, array_column($items, 'slug'), true);

        if ($position === false) {
            return ['previous' => null, 'next' => null];
        }

        return ['previous' => $items[$position - 1] ?? null, 'next' => $items[$position + 1] ?? null];
    }

    /**
     * Every word must turn up somewhere in a doc for it to match; a word in
     * the title weighs more than one in a heading, which weighs more than one
     * in the body. Ties keep the sidebar's order.
     *
     * @return list<SearchResult>
     */
    public function getSearchResults(): array
    {
        $terms = SearchText::terms((string)$this->search);

        if (mb_strlen(implode('', $terms)) < 2) {
            return [];
        }

        $scored = [];

        foreach ($this->getSidebarGroups() as $group) {
            foreach ($group['items'] as $item) {
                $document = $this->document($item['slug']);
                $score = $this->score($terms, $item, $document);

                if ($score === null) {
                    continue;
                }

                $match = $this->findMatch($document['text'], $terms);
                $anchor = $match === null ? null : $this->sectionAt($document, $match['offset']);

                $scored[] = ['score' => $score, 'result' => [
                    ...$item,
                    'url'     => $item['url'] . '?highlight=' . urlencode((string)$this->search) . ($anchor === null ? '' : "#{$anchor}"),
                    'snippet' => $this->buildSnippet($document['text'], $match),
                ]];
            }
        }

        // usort is stable, so equal scores keep the sidebar's order.
        usort($scored, fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_column($scored, 'result');
    }

    /**
     * Only a doc in the reader's sidebar is readable, which also keeps
     * anything but a known file name away from the filesystem. Public so the
     * screenshot route applies the same check to a doc's images.
     */
    public function canReadDoc(string $slug): bool
    {
        return in_array($slug, array_column(array_merge(...array_column($this->getSidebarGroups(), 'items')), 'slug'), true);
    }

    /**
     * @return array<string, class-string<Page>|class-string<FilamentResource>>
     */
    protected function screens(): array
    {
        return DocsConfig::screens();
    }

    /**
     * @return array<string, string>
     */
    protected function concepts(): array
    {
        return DocsConfig::concepts();
    }

    protected function docPath(string $slug): string
    {
        return DocsConfig::docPath($slug);
    }

    /**
     * Rendered once per version of the file and of the renderer: the key
     * carries both, so editing a doc or changing how docs render is enough to
     * refresh it. Nothing in it depends on who is reading; the highlight is
     * applied after.
     *
     * Kept for a day rather than forever, so the entries an edit leaves behind
     * expire, and a translated callout title or a panel URL baked into the
     * render is never stale for longer than that.
     *
     * @return RenderedDoc
     */
    private function document(string $slug): array
    {
        $path = $this->docPath($slug);
        $key = implode(':', ['filament-docs', 'v' . DocRenderer::VERSION, app()->getLocale(), $slug, File::lastModified($path)]);

        return Cache::remember($key, now()->addDay(), fn(): array => app(DocRenderer::class)->render(
            File::get($path),
            fn(string $target): string => static::getUrl(['doc' => $target]),
        ));
    }

    /**
     * @param  list<string>  $terms
     * @param  DocItem  $item
     * @param  RenderedDoc  $document
     */
    private function score(array $terms, array $item, array $document): ?int
    {
        $label = $item['label'] instanceof Htmlable ? strip_tags($item['label']->toHtml()) : $item['label'];
        $title = SearchText::fold($label);
        $headings = SearchText::fold(implode("\n", array_column($document['headings'], 'text')));
        $body = SearchText::fold($document['text']);
        $score = 0;

        foreach ($terms as $term) {
            $weight = (str_contains($title, $term) ? self::TITLE_WEIGHT : 0)
                + (str_contains($headings, $term) ? self::HEADING_WEIGHT : 0)
                + (str_contains($body, $term) ? self::BODY_WEIGHT : 0);

            if ($weight === 0) {
                return null;
            }

            $score += $weight;
        }

        return $score;
    }

    /**
     * Where the search lands in a doc's text: the whole phrase when it is
     * there, otherwise the first word found, in the order they were typed.
     *
     * @param  list<string>  $terms
     * @return array{offset: int, length: int}|null
     */
    private function findMatch(string $text, array $terms): ?array
    {
        $folded = SearchText::fold($text);

        foreach (array_unique([implode(' ', $terms), ...$terms]) as $needle) {
            $offset = mb_strpos($folded, $needle);

            if ($offset !== false) {
                return ['offset' => $offset, 'length' => mb_strlen($needle)];
            }
        }

        return null;
    }

    /**
     * The section the text at this offset belongs to: the last heading that
     * starts at or before it. The renderer records where each heading sits in
     * the text, so a heading's words used earlier in the prose do not count.
     *
     * @param  RenderedDoc  $document
     */
    private function sectionAt(array $document, int $offset): ?string
    {
        $section = null;

        foreach ($document['headings'] as $heading) {
            if ($heading['offset'] > $offset) {
                break;
            }

            $section = $heading['id'];
        }

        return $section;
    }

    /**
     * @return DocItem
     */
    private function conceptItem(string $slug, string $label): array
    {
        return [
            'slug'  => $slug,
            'label' => $this->translate($label),
            'icon'  => Heroicon::OutlinedDocumentText,
            'url'   => static::getUrl(['doc' => $slug]),
        ];
    }

    /**
     * A concept's label may be a translation key or plain text; a key that
     * points at a whole group is shown as written rather than as an array.
     */
    private function translate(string $key): string
    {
        $line = __($key);

        return is_string($line) ? $line : $key;
    }

    /**
     * About 60 characters either side of the match, or the opening of the doc
     * when the words were only in its title.
     *
     * @param  array{offset: int, length: int}|null  $match
     */
    private function buildSnippet(string $content, ?array $match): string
    {
        $padding = 60;

        if ($match === null) {
            return e(mb_substr($content, 0, $padding * 2)) . '…';
        }

        ['offset' => $offset, 'length' => $length] = $match;
        $start = max(0, $offset - $padding);

        $before = mb_substr($content, $start, $offset - $start);
        $matched = mb_substr($content, $offset, $length);
        $after = mb_substr($content, $offset + $length, $padding);

        return ($start > 0 ? '…' : '') . e(ltrim($before)) . '<mark>' . e($matched) . '</mark>' . e(rtrim($after)) . '…';
    }
}

<?php

use Arzcode\FilamentDocs\FilamentDocsPlugin;
use Arzcode\FilamentDocs\Support\DocPdf;
use Arzcode\FilamentDocs\Tests\Fixtures\User;
use Illuminate\Support\Facades\File;

beforeEach(function(): void {
    $this->pdf = app(DocPdf::class);
});

/**
 * Writes a WebP of the given size to the docs disk and returns its URL.
 */
function screenshot(string $path, int $width, int $height): string
{
    $file = config()->string('filament-docs.path') . "/images/{$path}";
    File::ensureDirectoryExists(dirname($file));

    $image = imagecreatetruecolor($width, $height);
    imagewebp($image, $file);

    return "/admin/manual/img/{$path}";
}

/**
 * @return list<string>
 */
function manualSlugs(): array
{
    return collect(app(DocPdf::class)->forManual()['groups'])
        ->flatMap(fn(array $group): array => array_column($group['items'], 'slug'))
        ->all();
}

it('wraps a heading together with the block that follows it', function(): void {
    expect($this->pdf->keepTogether('<h2>Fields</h2><p>Text</p><p>More</p>'))
        ->toBe('<div class="pdf-keep"><h2>Fields</h2><p>Text</p></div><p>More</p>');
});

it('groups stacked headings with their first block', function(): void {
    expect($this->pdf->keepTogether('<h2>A</h2><h3>B</h3><ul><li>x</li></ul>'))
        ->toBe('<div class="pdf-keep"><h2>A</h2><h3>B</h3><ul><li>x</li></ul></div>');
});

it('leaves a trailing heading alone', function(): void {
    expect($this->pdf->keepTogether('<p>Text</p><h2>Alone</h2>'))->not->toContain('pdf-keep');
});

it('keeps a caption with its screenshot', function(string $html): void {
    expect($this->pdf->keepTogether($html))
        ->toMatch('#<div class="pdf-figure"><p><strong>List</strong></p><p class="pdf-image">\s*<img[^>]*></p></div>#');
})->with([
    'separate paragraphs' => '<p><strong>List</strong></p><p><img src="/x.webp"></p>',
    'one paragraph'       => "<p><strong>List</strong>\n<img src=\"/x.webp\"></p>",
]);

it('leaves a paragraph of prose before a screenshot outside its figure', function(): void {
    expect($this->pdf->keepTogether('<p>Before</p><p><img src="/x.webp"></p>'))
        ->toMatch('#<p>Before</p><div class="pdf-figure"><p class="pdf-image"><img[^>]*></p></div>#');
});

it('moves a heading into the figure that follows it instead of nesting containers', function(): void {
    $html = $this->pdf->keepTogether('<h2>Screens</h2><p><strong>List</strong></p><p><img src="/x.webp"></p>');

    expect($html)
        ->toMatch('#<div class="pdf-figure"><h2>Screens</h2><p><strong>List</strong></p><p class="pdf-image"><img[^>]*></p></div>#')
        ->not->toContain('pdf-keep');
});

it('embeds a screenshot as a JPEG copy and sizes it to one page', function(): void {
    $url = screenshot('posts/tall.webp', 200, 600);

    $html = $this->pdf->inlineImages("<img src=\"{$url}\">");
    preg_match('/src="([^"]+)"/', $html, $matches);

    expect($html)->toContain('width:66.7mm;height:200.0mm;')
        ->and($matches[1])->toStartWith(storage_path('framework/cache/filament-docs-pdf/'))->toEndWith('.jpg')
        ->and(getimagesize($matches[1]))->toMatchArray([0 => 200, 1 => 600, 'mime' => 'image/jpeg']);
});

it('makes a new JPEG copy when the screenshot is re-shot', function(): void {
    $url = screenshot('posts/shot.webp', 100, 100);
    $first = $this->pdf->inlineImages("<img src=\"{$url}\">");

    touch(config()->string('filament-docs.path') . '/images/posts/shot.webp', time() + 60);
    clearstatcache();

    expect($this->pdf->inlineImages("<img src=\"{$url}\">"))->not->toBe($first)
        ->and($this->pdf->inlineImages("<img src=\"{$url}\">"))->toBe($this->pdf->inlineImages("<img src=\"{$url}\">"));
});

it('caps a wide screenshot at the page width', function(): void {
    $url = screenshot('posts/wide.webp', 800, 400);

    expect($this->pdf->inlineImages("<img src=\"{$url}\">"))->toContain('width:165.0mm;height:82.5mm;');
});

it('leaves alone an image that is missing, remote or outside the screenshots', function(string $source): void {
    screenshot('posts/real.webp', 10, 10);

    expect($this->pdf->inlineImages("<img src=\"{$source}\">"))->toBe("<img src=\"{$source}\">");
})->with([
    'missing'           => '/admin/manual/img/posts/missing.webp',
    'remote'            => 'https://example.com/shot.webp',
    'outside the route' => '/storage/posts/real.webp',
    'path traversal'    => '/admin/manual/img/../../secret.webp',
]);

it('gives the callouts concrete colors dompdf can read', function(): void {
    $css = $this->pdf->calloutStyles();

    expect($css)
        ->toContain('.fi-docs-callout-warning')
        ->toContain('.fi-docs-callout-caution')
        ->not->toContain('var(')
        ->not->toContain('oklch(');
});

it('lists the docs of the reader in the manual, in sidebar order', function(): void {
    $this->actingAs(User::factory()->editor()->create());

    expect(manualSlugs())->toBe(['index', 'posts', 'reports']);
});

it('never puts a doc the reader cannot open in the manual', function(): void {
    $this->actingAs(User::factory()->editor()->create());

    $html = collect($this->pdf->forManual()['groups'])->flatMap(fn(array $group): array => array_column($group['items'], 'html'))->implode('');

    expect(manualSlugs())->not->toContain('settings')
        ->and($html)->not->toContain('The secret settings of the panel');

    expect($this->pdf->forDoc('settings'))->toBeNull();
});

it('prefixes the heading ids with the doc and points the links at them', function(): void {
    $this->actingAs(User::factory()->create());
    File::put(config()->string('filament-docs.path') . '/posts.md', "# Posts\n\n## Roles\n\nSee [roles](#roles) and [the index](index.md#roles).\n");

    $html = collect($this->pdf->forManual()['groups'])->flatMap(fn(array $group): array => $group['items'])->firstWhere('slug', 'posts')['html'];

    expect($html)
        ->toContain('id="posts--roles"')
        ->toContain('href="#posts--roles"')
        ->toContain('href="#index--roles"')
        ->not->toContain('fi-docs-anchor');
});

it('links to another doc inside the manual and to the panel from a single doc', function(): void {
    $this->actingAs(User::factory()->create());
    File::put(config()->string('filament-docs.path') . '/posts.md', "# Posts\n\nSee [the reports](reports.md).\n");

    $manual = collect($this->pdf->forManual()['groups'])->flatMap(fn(array $group): array => $group['items'])->firstWhere('slug', 'posts')['html'];
    $single = $this->pdf->forDoc('posts')['groups'][0]['items'][0]['html'];

    expect($manual)->toContain('href="#doc-reports"')
        ->and($single)->toContain('/admin/manual/reports')
        ->and($single)->not->toContain('#doc-');
});

it('drops a title that repeats the section heading', function(): void {
    $this->actingAs(User::factory()->create());

    $html = $this->pdf->forDoc('posts')['groups'][0]['items'][0]['html'];

    expect(substr_count($html, 'Posts'))->toBe(1);
});

it('renders a real PDF of the manual with its contents and a page of the doc', function(): void {
    $this->actingAs(User::factory()->create());

    $output = $this->pdf->output($this->pdf->forManual());

    expect($output)->toStartWith('%PDF-');
});

it('embeds the screenshots of a doc in the PDF', function(): void {
    $this->actingAs(User::factory()->create());
    $url = screenshot('posts/list.webp', 400, 200);
    File::put(config()->string('filament-docs.path') . '/posts.md', "# Posts\n\n**List**\n![List]({$url})\n");

    $output = $this->pdf->output($this->pdf->forDoc('posts') ?? []);

    expect($output)->toStartWith('%PDF-')->toContain('/Subtype /Image');
});

it('shows the logo only when the file exists', function(): void {
    $logo = tempnam(sys_get_temp_dir(), 'logo');

    FilamentDocsPlugin::get()?->pdfLogo($logo);
    expect(FilamentDocsPlugin::get()?->getPdfLogo())->toBe($logo);

    FilamentDocsPlugin::get()?->pdfLogo('/nowhere/logo.png');
    expect(FilamentDocsPlugin::get()?->getPdfLogo())->toBeNull();

    File::delete($logo);
});

it('starts every doc on a new page and keeps a group heading with its first doc', function(): void {
    $item = fn(string $slug): array => ['slug' => $slug, 'label' => ucfirst($slug), 'showLabel' => true, 'html' => '<p>Body</p>'];

    $html = view('filament-docs::pdf', [
        'book' => [
            'title'    => 'Manual',
            'logo'     => null,
            'contents' => true,
            'groups'   => [
                ['label' => 'Content', 'items' => [$item('posts'), $item('pages')]],
                ['label' => 'Settings', 'items' => [$item('users')]],
            ],
        ],
        'pages'         => [],
        'accent'        => '#374151',
        'calloutStyles' => '',
    ])->render();

    expect($html)
        ->toContain('<div class="section" id="doc-posts">')
        ->toContain('<div class="section page-start" id="doc-pages">')
        ->toContain('<div class="group page-start">')
        ->toContain('<div class="section" id="doc-users">')
        ->and(substr_count($html, 'class="group"'))->toBe(1);
});

it('numbers the page of every doc in the contents of the manual', function(): void {
    $this->actingAs(User::factory()->editor()->create());
    $book = $this->pdf->forManual();

    $pages = $this->pdf->docPages($book);

    expect(array_keys($pages))->toBe(manualSlugs())
        ->and($pages['index'])->toBe(3)
        ->and($pages['posts'])->toBeGreaterThan($pages['index'])
        ->and($pages['reports'])->toBeGreaterThan($pages['posts']);

    $html = view('filament-docs::pdf', ['book' => $book, 'pages' => $pages, 'accent' => '#374151', 'calloutStyles' => ''])->render();

    expect($html)->toContain("<td class=\"toc-page\"><a href=\"#doc-posts\">{$pages['posts']}</a></td>")
        ->and($this->pdf->docPages($this->pdf->forDoc('posts') ?? []))->toBe([]);
});

<?php

use Arzcode\FilamentDocs\FilamentDocsPlugin;
use Arzcode\FilamentDocs\Pages\Documentation;
use Arzcode\FilamentDocs\Support\DocPdf;
use Arzcode\FilamentDocs\Support\DocRenderer;
use Arzcode\FilamentDocs\Tests\Fixtures\Pages\Reports;
use Arzcode\FilamentDocs\Tests\Fixtures\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

use function Pest\Livewire\livewire;

/**
 * @return list<string>
 */
function visibleSlugs(): array
{
    return collect(livewire(Documentation::class)->instance()->getSidebarGroups())
        ->flatMap(fn(array $group): array => array_column($group['items'], 'slug'))
        ->all();
}

it('sends a guest to the login', function(): void {
    $this->get('/admin/manual')->assertRedirect('/admin/login');
});

it('lives at the slug the plugin was given', function(): void {
    expect(Documentation::getUrl(['doc' => 'posts'], panel: 'admin'))->toEndWith('/admin/manual/posts');
});

it('is out of reach of a user the plugin does not authorize', function(): void {
    $this->actingAs(User::factory()->reader()->create())
        ->get('/admin/manual')
        ->assertForbidden();
});

it('opens on the introduction', function(): void {
    $this->actingAs(User::factory()->editor()->create())
        ->get('/admin/manual')
        ->assertOk()
        ->assertSee('This guide explains the panel.');
});

it('lays the docs out as the panel lays out its navigation', function(): void {
    $this->actingAs(User::factory()->create());

    $groups = collect(livewire(Documentation::class)->instance()->getSidebarGroups())
        ->map(fn(array $group): array => [$group['label'], array_column($group['items'], 'slug')])
        ->all();

    expect($groups)->toBe([
        [null, ['index', 'settings']],
        ['Content', ['posts', 'reports']],
    ]);
});

it('takes the label and icon of the screen from the navigation', function(): void {
    $this->actingAs(User::factory()->create());

    $item = collect(livewire(Documentation::class)->instance()->getSidebarGroups()[1]['items'])->firstWhere('slug', 'reports');

    expect($item['label'])->toBe(Reports::getNavigationLabel());
});

it('hides the doc of a screen the reader cannot open, with a 404', function(): void {
    $this->actingAs(User::factory()->editor()->create());

    expect(visibleSlugs())->toBe(['index', 'posts', 'reports']);

    $this->get('/admin/manual/settings')->assertNotFound();
});

it('answers 404 for an unknown or unsafe slug', function(string $slug): void {
    $this->actingAs(User::factory()->create())
        ->get("/admin/manual/{$slug}")
        ->assertNotFound();
})->with(['nope', 'INDEX', '..%2Fsecret']);

it('links to the screen the doc describes', function(): void {
    $this->actingAs(User::factory()->create());

    expect(livewire(Documentation::class, ['doc' => 'reports'])->instance()->getScreenUrl())->toBe(Reports::getUrl())
        ->and(livewire(Documentation::class)->instance()->getScreenUrl())->toBeNull();
});

it('shows a contents list for a doc with several sections', function(): void {
    $this->actingAs(User::factory()->create())
        ->get('/admin/manual')
        ->assertSee(__('filament-docs::docs.on_this_page'))
        ->assertSee('href="#how-to-use-it"', false);
});

it('leaves the contents list out of a doc with a single section', function(): void {
    $this->actingAs(User::factory()->create());

    expect(livewire(Documentation::class, ['doc' => 'reports'])->instance()->getTableOfContents())->toBe([]);
});

it('leads to the docs either side in sidebar order', function(): void {
    $this->actingAs(User::factory()->create());

    $pager = livewire(Documentation::class, ['doc' => 'settings'])->instance()->getPager();

    expect($pager['previous']['slug'])->toBe('index')
        ->and($pager['next']['slug'])->toBe('posts')
        ->and(livewire(Documentation::class)->instance()->getPager()['previous'])->toBeNull();
});

it('never leads a reader on to a doc they may not read', function(): void {
    $this->actingAs(User::factory()->editor()->create());

    expect(livewire(Documentation::class)->instance()->getPager()['next']['slug'])->toBe('posts');
});

it('only finds the docs that contain every word, ignoring accents', function(): void {
    $this->actingAs(User::factory()->create());

    $search = fn(string $term): array => array_column(
        livewire(Documentation::class)->set('search', $term)->instance()->getSearchResults(),
        'slug',
    );

    expect($search('spreadsheet'))->toBe(['reports'])
        ->and($search('SPRÉADSHEET'))->toBe(['reports'])
        ->and($search('spreadsheet backups'))->toBeEmpty();
});

it('ranks a doc with the word in its title above one that only mentions it', function(): void {
    $this->actingAs(User::factory()->create());

    $slugs = array_column(livewire(Documentation::class)->set('search', 'settings')->instance()->getSearchResults(), 'slug');

    expect($slugs)->toBe(['settings', 'index']);
});

it('never searches a doc the reader cannot open', function(): void {
    $this->actingAs(User::factory()->editor()->create());

    expect(livewire(Documentation::class)->set('search', 'backups')->instance()->getSearchResults())->toBeEmpty();
});

it('opens a search result at the section that holds the match, highlighted', function(): void {
    $this->actingAs(User::factory()->create());

    $result = livewire(Documentation::class)->set('search', 'goes live')->instance()->getSearchResults()[0];

    expect($result['slug'])->toBe('posts')
        ->and($result['url'])->toEndWith('/admin/manual/posts?highlight=goes+live#publishing')
        ->and($result['snippet'])->toContain('<mark>goes live</mark>');
});

it('marks the words searched for in the doc it opens', function(): void {
    $this->actingAs(User::factory()->create())
        ->get('/admin/manual/reports?highlight=chart')
        ->assertSee('<mark>chart</mark>', false);
});

it('serves a doc from the cache until its file changes', function(): void {
    $this->actingAs(User::factory()->create());
    $path = config('filament-docs.path') . '/reports.md';
    $key = 'filament-docs:v' . DocRenderer::VERSION . ':en:admin:' . md5($path) . ':' . File::lastModified($path);

    Cache::put($key, ['html' => '<p>From the cache</p>', 'headings' => [], 'text' => 'From the cache']);

    expect(livewire(Documentation::class, ['doc' => 'reports'])->instance()->getRenderedContent())->toBe('<p>From the cache</p>');

    touch($path, time() + 10);
    clearstatcache();

    expect(livewire(Documentation::class, ['doc' => 'reports'])->instance()->getRenderedContent())->toContain('Monthly figures');
});

it('sits in the navigation where the plugin puts it', function(): void {
    $this->actingAs(User::factory()->create());

    expect(Documentation::getNavigationGroup())->toBe('Help')
        ->and(Documentation::getNavigationSort())->toBe(1000)
        ->and(Filament::getCurrentPanel()?->getPlugin('filament-docs')->getImagesUrl(Filament::getPanel('admin')))->toBe('/admin/manual/img');
});

it('downloads the open doc as a PDF named after the site and the doc', function(): void {
    config(['app.name' => 'Acme']);
    $this->actingAs(User::factory()->editor()->create());

    livewire(Documentation::class, ['doc' => 'posts'])
        ->callAction('exportDocPdf')
        ->assertFileDownloaded('Documentation Acme - Posts.pdf');
});

it('downloads the whole manual as a PDF named after the site', function(): void {
    config(['app.name' => 'Acme']);
    $this->actingAs(User::factory()->editor()->create());

    livewire(Documentation::class)
        ->callAction('exportManualPdf')
        ->assertFileDownloaded('Documentation Acme.pdf');
});

it('keeps characters a file name cannot hold out of the PDF name', function(): void {
    config(['app.name' => 'Acme: Shop/Blog']);
    $this->actingAs(User::factory()->editor()->create());

    livewire(Documentation::class)
        ->callAction('exportManualPdf')
        ->assertFileDownloaded('Documentation Acme- Shop-Blog.pdf');
});

it('offers no open doc to export over search results', function(): void {
    $this->actingAs(User::factory()->create());

    livewire(Documentation::class)
        ->set('search', 'panel')
        ->assertActionHidden('exportDocPdf')
        ->assertActionVisible('exportManualPdf');
});

it('hides the exports when the plugin turns them off', function(): void {
    $this->actingAs(User::factory()->create());
    FilamentDocsPlugin::get()?->pdfExport(false);

    livewire(Documentation::class)
        ->assertActionHidden('exportDocPdf')
        ->assertActionHidden('exportManualPdf');
});

it('turns the exports off per user', function(): void {
    FilamentDocsPlugin::get()?->pdfExport(fn(User $user): bool => $user->role === 'admin');

    $this->actingAs(User::factory()->editor()->create());

    expect(DocPdf::canExport())->toBeFalse();

    $this->actingAs(User::factory()->create());

    expect(DocPdf::canExport())->toBeTrue();
});

<?php

use Arzcode\FilamentDocs\Pages\Documentation;
use Arzcode\FilamentDocs\Tests\Fixtures\User;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;

use function Pest\Livewire\livewire;

/**
 * @return list<array{0: mixed, 1: list<string>}>
 */
function sidebarOf(string $panel): array
{
    Filament::setCurrentPanel(Filament::getPanel($panel));

    return collect(livewire(Documentation::class)->instance()->getSidebarGroups())
        ->map(fn(array $group): array => [$group['label'], array_column($group['items'], 'slug')])
        ->all();
}

// One panel per test: Filament builds the navigation once per request.
it('gives a panel its own concepts and only the screens it registers', function(): void {
    $this->actingAs(User::factory()->create());

    expect(sidebarOf('website'))->toBe([
        [null, ['style-guide']],
        ['Site', ['announcements']],
    ]);
});

it('leaves the other panel’s screens out of a panel’s docs', function(): void {
    $this->actingAs(User::factory()->create());

    expect(sidebarOf('admin'))->toBe([
        [null, ['index', 'settings']],
        ['Content', ['posts', 'reports']],
    ]);
});

it('shows a concept with the icon the plugin gave it', function(): void {
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel(Filament::getPanel('website'));

    expect(livewire(Documentation::class)->instance()->getSidebarGroups()[0]['items'][0]['icon'])->toBe(Heroicon::OutlinedSwatch);
});

it('reads the docs from the folder the plugin was given', function(): void {
    $this->actingAs(User::factory()->create())
        ->get('/website/docs/announcements')
        ->assertOk()
        ->assertSee('News for the front page.');
});

it('opens on the first doc of a panel without an introduction', function(): void {
    $this->actingAs(User::factory()->create())
        ->get('/website/docs')
        ->assertOk()
        ->assertSee('the way the logo does');
});

it('never serves another panel’s docs', function(string $url): void {
    $this->actingAs(User::factory()->create())
        ->get($url)
        ->assertNotFound();
})->with(['/website/docs/index', '/website/docs/posts', '/admin/manual/style-guide']);

it('renders, searches and exports the markdown as preprocessMarkdown() leaves it', function(): void {
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel(Filament::getPanel('website'));

    $page = livewire(Documentation::class, ['doc' => 'style-guide'])->set('search', 'acme')->instance();

    expect($page->getRenderedContent())->toContain('<strong')->toContain('Acme</strong>')->not->toContain('[brand]')
        ->and(array_column($page->getSearchResults(), 'slug'))->toBe(['style-guide'])
        ->and($page->getMarkdown('style-guide'))->toContain('Acme');
});

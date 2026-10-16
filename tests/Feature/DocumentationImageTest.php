<?php

use Arzcode\FilamentDocs\Tests\Fixtures\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function(): void {
    Storage::fake('filament-docs');
    Storage::disk('filament-docs')->put('reports/list.webp', 'fake-webp');
    Storage::disk('filament-docs')->put('settings/list.webp', 'fake-webp');
});

it('sends a guest to the login instead of serving the screenshot', function(): void {
    $this->get('/admin/manual/img/reports/list.webp')->assertRedirect('/admin/login');
});

it('forbids a user the plugin does not authorize', function(): void {
    $this->actingAs(User::factory()->reader()->create())
        ->get('/admin/manual/img/reports/list.webp')
        ->assertForbidden();
});

it('serves a screenshot to a reader of its doc', function(): void {
    $response = $this->actingAs(User::factory()->editor()->create())
        ->get('/admin/manual/img/reports/list.webp');

    $response->assertOk()
        ->assertHeader('content-type', 'image/webp')
        ->assertHeader('cache-control', 'no-cache, private')
        ->assertHeader('last-modified');

    expect($response->streamedContent())->toBe('fake-webp');
});

it('answers a browser that already holds the screenshot with an empty 304', function(): void {
    $this->actingAs(User::factory()->editor()->create());

    $lastModified = $this->get('/admin/manual/img/reports/list.webp')->headers->get('last-modified');

    $this->get('/admin/manual/img/reports/list.webp', ['If-Modified-Since' => $lastModified])
        ->assertStatus(304)
        ->assertContent('');
});

it('sends a re-shot screenshot even to a browser that holds the old one', function(): void {
    $this->actingAs(User::factory()->editor()->create())
        ->get('/admin/manual/img/reports/list.webp', ['If-Modified-Since' => 'Mon, 01 Jan 2024 00:00:00 GMT'])
        ->assertOk();
});

it('hides the screenshot of a doc the reader cannot open', function(): void {
    $this->actingAs(User::factory()->editor()->create())
        ->get('/admin/manual/img/settings/list.webp')
        ->assertNotFound();
});

it('serves an admin the screenshots of every doc', function(): void {
    $this->actingAs(User::factory()->create())
        ->get('/admin/manual/img/settings/list.webp')
        ->assertOk();
});

it('returns 404 for a screenshot that does not exist', function(): void {
    $this->actingAs(User::factory()->create())
        ->get('/admin/manual/img/reports/nope.webp')
        ->assertNotFound();
});

it('refuses to read anything outside the screenshot set', function(string $path): void {
    $this->actingAs(User::factory()->create())
        ->get("/admin/manual/img/{$path}")
        ->assertNotFound();
})->with([
    'traversal'         => '../../.env',
    'encoded traversal' => '..%2F..%2F.env',
    'nested traversal'  => 'reports/../../../.env',
    'no directory'      => 'list.webp',
    'not a webp'        => 'reports/list.php',
]);

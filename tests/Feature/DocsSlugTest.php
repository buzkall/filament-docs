<?php

use Arzcode\FilamentDocs\Support\DocsSlug;
use Illuminate\Support\Facades\Route;

it('sees a path, or a path under it, another route serves', function(): void {
    Route::get('admin/docs', fn(): string => 'x');
    Route::get('admin/help/faq', fn(): string => 'x');

    expect(DocsSlug::isTaken('admin', 'docs'))->toBeTrue()
        ->and(DocsSlug::isTaken('/admin/', 'help'))->toBeTrue()
        ->and(DocsSlug::isTaken('admin', 'documentation'))->toBeFalse();
});

it('does not count the docs\' own routes', function(): void {
    expect(DocsSlug::isTaken('admin', 'manual'))->toBeFalse();
});

it('suggests the first free slug', function(): void {
    Route::get('admin/docs', fn(): string => 'x');
    Route::get('admin/documentation', fn(): string => 'x');
    Route::get('admin/help', fn(): string => 'x');

    expect(DocsSlug::suggest('admin'))->toBe('docs-2');
});

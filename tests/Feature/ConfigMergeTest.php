<?php

use Arzcode\FilamentDocs\FilamentDocsServiceProvider;

function mergePublishedConfig(array $published): void
{
    config()->set('filament-docs', $published);

    new FilamentDocsServiceProvider(app())->registerPackageConfigs();
}

it('falls back to the defaults for the keys the app leaves out', function(): void {
    mergePublishedConfig(['screens' => []]);

    expect(config('filament-docs.screenshots.quality'))->toBe(80)
        ->and(config('filament-docs.concepts'))->toBe(['index' => 'filament-docs::docs.pages.index']);
});

it('keeps the sibling defaults of a nested key the app overrides', function(): void {
    mergePublishedConfig(['screenshots' => ['quality' => 60]]);

    expect(config('filament-docs.screenshots.quality'))->toBe(60)
        ->and(config('filament-docs.screenshots.per_page'))->toBe(10)
        ->and(config('filament-docs.screenshots.viewport'))->toBe(['width' => 1440, 'height' => 800]);
});

it('takes the app\'s concepts as the whole list', function(): void {
    mergePublishedConfig(['concepts' => ['welcome' => 'Welcome']]);

    expect(config('filament-docs.concepts'))->toBe(['welcome' => 'Welcome']);
});

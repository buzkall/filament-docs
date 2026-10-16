<?php

use Illuminate\Support\Arr;

it('translates every key in every locale', function(string $locale): void {
    $keys = fn(string $locale): array => array_keys(Arr::dot(
        require __DIR__ . "/../../resources/lang/{$locale}/docs.php"
    ));

    expect($keys($locale))->toBe($keys('en'));
})->with(['es']);

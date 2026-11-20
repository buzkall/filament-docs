<?php

use Arzcode\FilamentDocs\Support\DocScreenshots;
use Arzcode\FilamentDocs\Tests\Fixtures\Post;

/*
 | A resource may refuse the edit page of some records, e.g. one a sync writes,
 | so an edit shot carries the next records' pages for the runner to fall back
 | on when the first answers 403 or 404.
 */
it('gives an edit shot the next records as fallbacks', function(): void {
    Post::create(['title' => 'First', 'slug' => 'first']);
    Post::create(['title' => 'Second', 'slug' => 'second']);
    Post::create(['title' => 'Third', 'slug' => 'third']);

    $edit = collect(app(DocScreenshots::class)->screenshotTargets('admin'))
        ->firstWhere(fn(array $target): bool => $target['slug'] === 'posts' && $target['screen'] === 'edit');

    expect($edit['path'])->toBe('/admin/posts/first/edit')
        ->and($edit['fallbacks'])->toBe(['/admin/posts/second/edit', '/admin/posts/third/edit']);
});

it('gives a page with no record no fallbacks', function(): void {
    Post::create(['title' => 'Only', 'slug' => 'only']);

    $targets = collect(app(DocScreenshots::class)->screenshotTargets('admin'))->where('slug', 'posts');

    expect($targets->firstWhere('screen', 'list'))->not->toHaveKey('fallbacks')
        ->and($targets->firstWhere('screen', 'edit'))->not->toHaveKey('fallbacks');
});

<?php

use Arzcode\FilamentDocs\FilamentDocsPlugin;
use Arzcode\FilamentDocs\FilamentDocsServiceProvider;
use Arzcode\FilamentDocs\Pages\Documentation;
use Arzcode\FilamentDocs\Support\DocMarkdown;
use Arzcode\FilamentDocs\Tests\Fixtures\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

use function Pest\Livewire\livewire;

it('holds a markdown file for every doc the reader may open, listed in a README', function(): void {
    $this->actingAs(User::factory()->editor()->create());

    $files = app(DocMarkdown::class)->files();

    expect($files)->toHaveKeys([DocMarkdown::README, 'index.md', 'posts.md'])
        ->not->toHaveKey('settings.md')
        ->and($files['posts.md'])->toBe(File::get("{$this->docsPath}/posts.md"))
        ->and($files[DocMarkdown::README])->toContain('[Posts](posts.md)')
        ->not->toContain('settings.md');
});

it('points the screenshots at a local images folder and carries them', function(): void {
    $this->actingAs(User::factory()->create());

    Storage::disk(FilamentDocsServiceProvider::DISK)->put('posts/list.webp', 'webp bytes');
    File::append("{$this->docsPath}/posts.md", "\n![List](/admin/manual/img/posts/list.webp)\n![Missing](/admin/manual/img/posts/edit.webp)\n");

    $files = app(DocMarkdown::class)->files();

    expect($files['posts.md'])->toContain('![List](images/posts/list.webp)')
        ->and($files['images/posts/list.webp'])->toBe('webp bytes')
        ->and($files)->not->toHaveKey('images/posts/edit.webp')
        // A link to an image the disk lacks is left alone, not pointed at a file the zip lacks.
        ->and($files['posts.md'])->toContain('![Missing](/admin/manual/img/posts/edit.webp)');
});

it('carries the screenshots in the zip', function(): void {
    $this->actingAs(User::factory()->create());

    Storage::disk(FilamentDocsServiceProvider::DISK)->put('posts/list.webp', 'webp bytes');
    File::append("{$this->docsPath}/posts.md", "\n![List](/admin/manual/img/posts/list.webp)\n");

    $path = app(DocMarkdown::class)->zip();
    $zip = new ZipArchive;
    $zip->open($path);

    expect($zip->getFromName('images/posts/list.webp'))->toBe('webp bytes');

    $zip->close();
    File::delete($path);
});

it('downloads the manual as a zip', function(): void {
    $this->actingAs(User::factory()->create());

    livewire(Documentation::class)
        ->callAction(TestAction::make('exportManualMarkdown'))
        ->assertFileDownloaded(contentType: 'application/zip');
});

it('writes a zip that opens with every file in it', function(): void {
    $this->actingAs(User::factory()->create());

    $path = app(DocMarkdown::class)->zip();
    $zip = new ZipArchive;
    $zip->open($path);

    expect($zip->getFromName('posts.md'))->toBe(File::get("{$this->docsPath}/posts.md"))
        ->and($zip->locateName(DocMarkdown::README))->not->toBeFalse();

    $zip->close();
    File::delete($path);
});

it('hides the markdown export when the plugin turns it off', function(): void {
    $this->actingAs(User::factory()->create());

    FilamentDocsPlugin::get()?->markdownExport(false);

    livewire(Documentation::class)->assertActionHidden(TestAction::make('exportManualMarkdown'));
});

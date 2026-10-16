<?php

use Arzcode\FilamentDocs\Support\DocScreenshots;
use Arzcode\FilamentDocs\Tests\Fixtures\Post;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function(): void {
    Storage::fake('filament-docs');
    Storage::disk('filament-docs')->put('posts/list.webp', 'x');
    Storage::disk('filament-docs')->put('posts/edit-general.webp', 'x');

    $this->docPath = config('filament-docs.path') . '/posts.md';
    $this->manifestPath = storage_path('app/docs-screenshots-manifest.json');
    $this->resultsPath = storage_path('app/docs-screenshots-results.json');
});

afterEach(fn() => File::delete([$this->manifestPath, $this->resultsPath]));

/**
 * The runner, faked: cwebp is installed, and the browser "captures" the given
 * rows by writing them to the results file the command reads.
 *
 * @param  list<array{slug: string, screen: string, file: string, label: string|null}>  $rows
 */
function fakeRunner(array $rows, int $exitCode = 0): void
{
    Process::fake(['*cwebp*' => Process::result(), '*' => function() use ($rows, $exitCode): ProcessResult {
        if ($rows !== []) {
            File::put(storage_path('app/docs-screenshots-results.json'), (string)json_encode($rows));
        }

        return Process::result(exitCode: $exitCode);
    }]);
}

it('targets every screen of a resource, by the route key of its first record', function(): void {
    Post::create(['slug' => 'hello-world', 'title' => 'Hello']);

    $targets = collect(app(DocScreenshots::class)->screenshotTargets('admin'))->where('slug', 'posts')->pluck('path', 'screen')->all();

    expect($targets)->toBe([
        'list'   => '/admin/posts',
        'create' => '/admin/posts/create',
        'edit'   => '/admin/posts/hello-world/edit',
    ]);
});

it('skips the screens that need a record when there is none', function(): void {
    expect(collect(app(DocScreenshots::class)->screenshotTargets('admin'))->where('slug', 'posts')->pluck('screen')->all())
        ->toBe(['list', 'create']);
});

it('gives a page one shot, and skips a screen with no markdown file', function(): void {
    File::delete(config('filament-docs.path') . '/settings.md');

    $targets = collect(app(DocScreenshots::class)->screenshotTargets('admin'));

    expect($targets->where('slug', 'reports')->pluck('screen')->all())->toBe(['list'])
        ->and($targets->where('slug', 'settings')->all())->toBeEmpty();
});

it('puts a tab image at the end of the section whose heading matches the label', function(): void {
    $unmatched = app(DocScreenshots::class)->injectScreenshots('posts', [
        ['screen' => 'edit', 'file' => 'posts/edit-general.webp', 'label' => 'General'],
    ]);

    $markdown = File::get($this->docPath);

    expect($unmatched)->toBeEmpty()
        ->and($markdown)->not->toContain('screenshots:start')
        ->and($markdown)->toContain('![Edit — General](/admin/manual/img/posts/edit-general.webp)')
        ->and(strpos($markdown, 'edit-general.webp'))->toBeLessThan(strpos($markdown, '### Publishing'));
});

it('puts an untabbed image, and a tab with no matching heading, in the bottom block', function(): void {
    $unmatched = app(DocScreenshots::class)->injectScreenshots('posts', [
        ['screen' => 'list', 'file' => 'posts/list.webp', 'label' => null],
        ['screen' => 'edit', 'file' => 'posts/edit-general.webp', 'label' => 'Nothing like it'],
    ]);

    expect($unmatched)->toBe(['Nothing like it'])
        ->and(File::get($this->docPath))
        ->toContain("<!-- screenshots:start -->\n## Screenshots")
        ->toContain('![List](/admin/manual/img/posts/list.webp)');
});

it('is idempotent and keeps the prose', function(): void {
    $images = [
        ['screen' => 'list', 'file' => 'posts/list.webp', 'label' => null],
        ['screen' => 'edit', 'file' => 'posts/edit-general.webp', 'label' => 'General'],
    ];
    $screenshots = app(DocScreenshots::class);

    $screenshots->injectScreenshots('posts', $images);
    $once = File::get($this->docPath);
    $screenshots->injectScreenshots('posts', $images);

    expect(File::get($this->docPath))->toBe($once)
        ->toContain('The title and the slug of a post.')
        ->and(substr_count($once, '<!-- screenshots:start -->'))->toBe(1)
        ->and(substr_count($once, '<!-- screenshot:edit-general -->'))->toBe(1);
});

it('skips images whose file does not exist', function(): void {
    app(DocScreenshots::class)->injectScreenshots('posts', [
        ['screen' => 'create', 'file' => 'posts/create.webp', 'label' => null],
    ]);

    expect(File::get($this->docPath))->not->toContain('create.webp')->not->toContain('screenshots:start');
});

it('reads back the images it embedded, with their screen and tab', function(): void {
    $images = [
        ['screen' => 'list', 'file' => 'posts/list.webp', 'label' => null],
        ['screen' => 'edit', 'file' => 'posts/edit-general.webp', 'label' => 'General'],
    ];
    $screenshots = app(DocScreenshots::class);

    $screenshots->injectScreenshots('posts', $images);

    expect($screenshots->embeddedImages('posts'))->toEqualCanonicalizing($images);
});

it('links the images through the configured prefix when one is set', function(): void {
    config(['filament-docs.images_url' => '/static/docs/']);

    app(DocScreenshots::class)->injectScreenshots('posts', [['screen' => 'list', 'file' => 'posts/list.webp', 'label' => null]]);

    expect(File::get($this->docPath))->toContain('](/static/docs/posts/list.webp)');
});

it('refuses to run in production', function(): void {
    app()->detectEnvironment(fn(): string => 'production');
    Process::fake();

    $this->artisan('docs:screenshot')->expectsOutputToContain('never production')->assertFailed();

    Process::assertNothingRan();
});

it('rejects an unknown screen', function(): void {
    $this->artisan('docs:screenshot', ['--screens' => 'list,gallery'])
        ->expectsOutputToContain('Unknown screen(s): gallery.')
        ->assertFailed();
});

it('stops before opening a browser when cwebp is missing', function(): void {
    Process::fake(['*cwebp*' => Process::result(exitCode: 127)]);

    $this->artisan('docs:screenshot', ['--only' => ['reports']])
        ->expectsOutputToContain('cwebp is not installed')
        ->assertFailed();

    Process::assertNotRan(fn(PendingProcess $process): bool => str_contains(implode(' ', (array)$process->command), 'screenshot-docs.mjs'));
});

it('hands the runner a manifest built from the panel and the config', function(): void {
    config(['filament-docs.screenshots.interactions' => ['reports' => ['list' => ['.open-me']]]]);
    fakeRunner([['slug' => 'reports', 'screen' => 'list', 'file' => 'reports/list.webp', 'label' => null]]);
    Storage::disk('filament-docs')->put('reports/list.webp', 'x');

    $this->artisan('docs:screenshot', ['--only' => ['reports'], '--login-path' => '/dev-login'])->assertSuccessful();

    $manifest = json_decode(File::get($this->manifestPath), true);

    expect($manifest)
        ->toMatchArray([
            'loginUrl'  => rtrim((string)config('app.url'), '/') . '/dev-login',
            'panelPath' => '/admin',
            'appRoot'   => base_path(),
            'perPage'   => 10,
            'quality'   => 80,
            'viewport'  => ['width' => 1440, 'height' => 800, 'deviceScaleFactor' => 1],
        ])
        ->and($manifest['shots'])->toBe([[
            'slug'         => 'reports',
            'screen'       => 'list',
            'url'          => '/admin/reports',
            'out'          => 'reports/list.webp',
            'interactions' => ['.open-me'],
        ]]);

    Process::assertRan(fn(PendingProcess $process): bool => str_ends_with((string)((array)$process->command)[1], '/bin/screenshot-docs.mjs'));
});

it('leaves the docs alone when the browser captured nothing', function(): void {
    app(DocScreenshots::class)->injectScreenshots('posts', [['screen' => 'list', 'file' => 'posts/list.webp', 'label' => null]]);
    $before = File::get($this->docPath);
    fakeRunner([], exitCode: 1);

    $this->artisan('docs:screenshot', ['--only' => ['posts']])
        ->expectsOutputToContain('Nothing was captured')
        ->assertFailed();

    expect(File::get($this->docPath))->toBe($before);
});

it('keeps the image of a screen whose shot failed', function(): void {
    Storage::disk('filament-docs')->put('posts/create.webp', 'x');
    app(DocScreenshots::class)->injectScreenshots('posts', [['screen' => 'list', 'file' => 'posts/list.webp', 'label' => null]]);

    // The create shot worked; the list shot failed.
    fakeRunner([['slug' => 'posts', 'screen' => 'create', 'file' => 'posts/create.webp', 'label' => null]]);

    $this->artisan('docs:screenshot', ['--only' => ['posts']])->assertSuccessful();

    expect(File::get($this->docPath))
        ->toContain('/admin/manual/img/posts/list.webp')
        ->toContain('/admin/manual/img/posts/create.webp');
});

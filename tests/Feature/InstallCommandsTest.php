<?php

use Arzcode\FilamentDocs\Support\PanelProviders;
use Arzcode\FilamentDocs\Support\Themes;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;

const REGISTER_IN_TEST_PANEL = 'Register FilamentDocsPlugin in app/Providers/Filament/TestPanelProvider.php?';
const PATCH_TEST_THEME = 'Add the docs styles to resources/css/filament/test/theme.css?';
const INSTALL_TYPOGRAPHY = 'The docs need @tailwindcss/typography. Install it with npm now?';
const RUN_BUILD = 'Rebuild the theme with `npm run build` now?';

/**
 * Both commands patch files of the testbench skeleton, so each test backs up
 * and restores what it touches.
 */
beforeEach(function(): void {
    $this->providersPath = app_path('Providers/Filament');
    $this->providerFile = $this->providersPath . '/TestPanelProvider.php';
    $this->themesPath = resource_path('css/filament');
    $this->themeFile = $this->themesPath . '/test/theme.css';
    $this->configFile = config_path('filament-docs.php');
    $this->packageJson = base_path('package.json');
    $this->originalPackageJson = file_exists($this->packageJson) ? file_get_contents($this->packageJson) : null;

    File::ensureDirectoryExists($this->providersPath);
    file_put_contents($this->providerFile, <<<'PHP'
        <?php

        namespace App\Providers\Filament;

        use Filament\Panel;
        use Filament\PanelProvider;

        class TestPanelProvider extends PanelProvider
        {
            public function panel(Panel $panel): Panel
            {
                return $panel
                    ->id('test')
                    ->path('test')
                    ->plugins([
                        SomeOtherPlugin::make(),
                    ]);
            }
        }
        PHP);

    File::ensureDirectoryExists(dirname($this->themeFile));
    file_put_contents($this->themeFile, <<<'CSS'
        @import '../../../../vendor/filament/filament/resources/css/theme.css';

        @source '../../../../app/Filament/**/*';
        CSS . "\n");

    file_put_contents($this->packageJson, '{"devDependencies": {"vite": "^7.0"}}');

    $this->install = fn() => $this->artisan('filament-docs:install')
        ->expectsConfirmation('Would you like to publish the config file?', 'yes')
        ->expectsConfirmation(REGISTER_IN_TEST_PANEL, 'yes')
        ->expectsConfirmation(PATCH_TEST_THEME, 'yes')
        ->expectsConfirmation(INSTALL_TYPOGRAPHY, 'yes')
        ->expectsConfirmation(RUN_BUILD, 'yes');
});

afterEach(function(): void {
    File::deleteDirectory($this->providersPath);
    File::deleteDirectory($this->themesPath);
    File::delete($this->configFile);
    File::deleteDirectory(lang_path('vendor/filament-docs'));

    $this->originalPackageJson === null ? File::delete($this->packageJson) : file_put_contents($this->packageJson, $this->originalPackageJson);
});

it('installs the package: config, plugin, theme lines, typography and the build', function(): void {
    Process::fake();

    ($this->install)()->assertSuccessful();

    $provider = file_get_contents($this->providerFile);
    $theme = file_get_contents($this->themeFile);

    expect($this->configFile)->toBeFile()
        ->and($provider)->toContain('use Arzcode\FilamentDocs\FilamentDocsPlugin;')
        ->and($provider)->toContain("SomeOtherPlugin::make(),\n                FilamentDocsPlugin::make(),\n            ]);")
        ->and(PanelProviders::parses($provider))->toBeTrue()
        ->and($theme)->toContain(Themes::TYPOGRAPHY)
        ->and($theme)->toContain(Themes::IMPORT)
        ->and($theme)->toContain("@source '../../../../app/Filament/**/*';\n" . Themes::SOURCE);

    Process::assertRan('npm install -D @tailwindcss/typography');
    Process::assertRan('npm run build');
});

it('changes nothing twice when the install runs again', function(): void {
    Process::fake();
    ($this->install)()->assertSuccessful();
    file_put_contents($this->packageJson, '{"devDependencies": {"@tailwindcss/typography": "^0.5"}}');
    $theme = file_get_contents($this->themeFile);

    $this->artisan('filament-docs:install')
        ->expectsOutputToContain('config/filament-docs.php already present')
        ->expectsOutputToContain('FilamentDocsPlugin already present in app/Providers/Filament/TestPanelProvider.php')
        ->expectsOutputToContain('resources/css/filament/test/theme.css already loads the docs styles')
        ->assertSuccessful();

    expect(substr_count(file_get_contents($this->providerFile), PanelProviders::ENTRY))->toBe(1)
        ->and(file_get_contents($this->themeFile))->toBe($theme);
});

it('asks for another slug when the panel already serves /docs', function(): void {
    Process::fake();
    Route::get('test/docs', fn(): string => 'taken');
    Route::get('test/help', fn(): string => 'taken too');

    $this->artisan('filament-docs:install')
        ->expectsConfirmation('Would you like to publish the config file?', 'no')
        ->expectsConfirmation(REGISTER_IN_TEST_PANEL, 'yes')
        ->expectsQuestion('/test/docs is already taken. URL path for the docs', 'help')
        ->expectsOutputToContain('/test/help is taken or not a valid path')
        ->expectsQuestion('/test/docs is already taken. URL path for the docs', '/Manual/')
        ->expectsConfirmation(PATCH_TEST_THEME, 'no')
        ->expectsConfirmation(INSTALL_TYPOGRAPHY, 'no')
        ->assertSuccessful();

    expect(file_get_contents($this->providerFile))->toContain("FilamentDocsPlugin::make()->slug('manual'),");
});

it('creates a starter introduction when the docs have none', function(): void {
    Process::fake();
    File::delete(config('filament-docs.path') . '/index.md');

    $this->artisan('filament-docs:install')
        ->expectsConfirmation('Would you like to publish the config file?', 'no')
        ->expectsConfirmation(REGISTER_IN_TEST_PANEL, 'no')
        ->expectsConfirmation(PATCH_TEST_THEME, 'no')
        ->expectsConfirmation(INSTALL_TYPOGRAPHY, 'no')
        ->expectsConfirmation('Create a starter ' . config('filament-docs.path') . '/index.md?', 'yes')
        ->assertSuccessful();

    expect(config('filament-docs.path') . '/index.md')->toBeFile();
    Process::assertNothingRan();
});

it('leaves every file alone when each step is declined', function(): void {
    Process::fake();
    $provider = file_get_contents($this->providerFile);
    $theme = file_get_contents($this->themeFile);

    $this->artisan('filament-docs:install')
        ->expectsConfirmation('Would you like to publish the config file?', 'no')
        ->expectsConfirmation(REGISTER_IN_TEST_PANEL, 'no')
        ->expectsConfirmation(PATCH_TEST_THEME, 'no')
        ->expectsConfirmation(INSTALL_TYPOGRAPHY, 'no')
        ->assertSuccessful();

    expect(file_get_contents($this->providerFile))->toBe($provider)
        ->and(file_get_contents($this->themeFile))->toBe($theme)
        ->and($this->configFile)->not->toBeFile();

    Process::assertNothingRan();
});

it('uninstalls the package, reversing the install', function(): void {
    Process::fake();
    ($this->install)()->assertSuccessful();
    File::ensureDirectoryExists(lang_path('vendor/filament-docs/en'));

    $this->artisan('filament-docs:uninstall')
        ->expectsConfirmation('Do you want to continue?', 'yes')
        ->expectsConfirmation('Delete the published config file config/filament-docs.php?', 'yes')
        ->expectsConfirmation('Delete the published translations in lang/vendor/filament-docs?', 'yes')
        ->expectsConfirmation('Delete the docs in ' . config('filament-docs.path') . '?', 'no')
        ->assertSuccessful();

    $provider = file_get_contents($this->providerFile);
    $theme = file_get_contents($this->themeFile);

    expect($provider)->not->toContain('FilamentDocsPlugin')
        ->and($provider)->toContain('SomeOtherPlugin::make(),')
        ->and($theme)->not->toContain('filament-docs')
        ->and($theme)->toContain(Themes::TYPOGRAPHY)
        ->and($this->configFile)->not->toBeFile()
        ->and(lang_path('vendor/filament-docs'))->not->toBeDirectory()
        ->and(config('filament-docs.path') . '/index.md')->toBeFile();

    Process::assertRan('composer remove arzcode/filament-docs');
    Process::assertRan('npm run build');
});

it('deletes the docs only when told to', function(): void {
    Process::fake();

    $this->artisan('filament-docs:uninstall')
        ->expectsConfirmation('Do you want to continue?', 'yes')
        ->expectsConfirmation('Delete the docs in ' . config('filament-docs.path') . '?', 'yes')
        ->assertSuccessful();

    expect(config('filament-docs.path'))->not->toBeDirectory();
    Process::assertNotRan('npm run build');
});

it('changes nothing when the uninstall is not confirmed', function(): void {
    Process::fake();
    file_put_contents($this->providerFile, PanelProviders::addPlugin(file_get_contents($this->providerFile)));

    $this->artisan('filament-docs:uninstall')
        ->expectsConfirmation('Do you want to continue?', 'no')
        ->assertSuccessful();

    expect(file_get_contents($this->providerFile))->toContain(PanelProviders::ENTRY);
    Process::assertNothingRan();
});

it('tells the user to finish by hand when composer remove fails', function(): void {
    Process::fake(['*' => Process::result(exitCode: 1)]);

    $this->artisan('filament-docs:uninstall')
        ->expectsConfirmation('Do you want to continue?', 'yes')
        ->expectsConfirmation('Delete the docs in ' . config('filament-docs.path') . '?', 'no')
        ->expectsOutputToContain('`composer remove arzcode/filament-docs` failed')
        ->assertSuccessful();
});

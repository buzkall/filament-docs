<?php

namespace Arzcode\FilamentDocs\Tests;

use Arzcode\FilamentDocs\FilamentDocsServiceProvider;
use Arzcode\FilamentDocs\Tests\Fixtures\AdminPanelProvider;
use Arzcode\FilamentDocs\Tests\Fixtures\Pages\Announcements;
use Arzcode\FilamentDocs\Tests\Fixtures\Pages\Reports;
use Arzcode\FilamentDocs\Tests\Fixtures\Pages\Settings;
use Arzcode\FilamentDocs\Tests\Fixtures\Resources\Posts\PostResource;
use Arzcode\FilamentDocs\Tests\Fixtures\User;
use Arzcode\FilamentDocs\Tests\Fixtures\WebsitePanelProvider;
use Barryvdh\DomPDF\ServiceProvider as DomPdfServiceProvider;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;

class TestCase extends Orchestra
{
    use RefreshDatabase;

    /** A copy of the fixture docs per test, so a test may write to them. */
    protected string $docsPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Host applications commonly enable strict mode outside production, so
        // the package has to hold up under it.
        Model::shouldBeStrict();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->docsPath);

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ActionsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            // Must be registered before Livewire.
            SupportServiceProvider::class,
            LivewireServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            DomPdfServiceProvider::class,
            FilamentDocsServiceProvider::class,
            AdminPanelProvider::class,
            WebsitePanelProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        $this->docsPath = sys_get_temp_dir() . '/filament-docs-tests-' . getmypid() . '-' . bin2hex(random_bytes(4));
        File::copyDirectory(__DIR__ . '/Fixtures/docs', $this->docsPath);

        $app['config']->set('app.key', 'base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10=');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('view.compiled', sys_get_temp_dir() . '/filament-docs-views');
        $app['config']->set('filament-docs.path', $this->docsPath);
        $app['config']->set('filament-docs.screens', [
            'posts'         => PostResource::class,
            'reports'       => Reports::class,
            'settings'      => Settings::class,
            'announcements' => Announcements::class,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/Fixtures/migrations');
    }
}

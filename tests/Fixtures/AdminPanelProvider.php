<?php

namespace Arzcode\FilamentDocs\Tests\Fixtures;

use Arzcode\FilamentDocs\FilamentDocsPlugin;
use Arzcode\FilamentDocs\Tests\Fixtures\Pages\Reports;
use Arzcode\FilamentDocs\Tests\Fixtures\Pages\Settings;
use Arzcode\FilamentDocs\Tests\Fixtures\Resources\Posts\PostResource;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The docs live at /admin/manual, for everyone but a reader.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->resources([PostResource::class])
            ->pages([Reports::class, Settings::class])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class])
            ->plugin(
                FilamentDocsPlugin::make()
                    ->slug('manual')
                    ->navigationGroup('Help')
                    ->authorize(fn(User $user): bool => $user->role !== 'reader')
            );
    }
}

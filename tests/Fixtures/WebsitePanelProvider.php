<?php

namespace Arzcode\FilamentDocs\Tests\Fixtures;

use Arzcode\FilamentDocs\FilamentDocsPlugin;
use Arzcode\FilamentDocs\Tests\Fixtures\Pages\Announcements;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * A second panel with its own docs folder, its own concepts and no
 * introduction, at /website/docs.
 */
class WebsitePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('website')
            ->path('website')
            ->login()
            ->pages([Announcements::class])
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
                    ->path(fn(): string => config('filament-docs.path') . '/website')
                    ->concepts(['style-guide' => ['label' => 'Style guide', 'icon' => Heroicon::OutlinedSwatch]])
                    ->preprocessMarkdown(fn(string $markdown): string => str_replace('[brand]', '<strong class="brand">Acme</strong>', $markdown))
            );
    }
}

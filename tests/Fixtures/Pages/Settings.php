<?php

namespace Arzcode\FilamentDocs\Tests\Fixtures\Pages;

use Arzcode\FilamentDocs\Tests\Fixtures\User;
use Filament\Pages\Page;

/**
 * Ungrouped, and only for admins, so its doc is hidden from an editor.
 */
class Settings extends Page
{
    protected string $view = 'filament-panels::pages.dashboard';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->role === 'admin';
    }
}

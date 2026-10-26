<?php

namespace Arzcode\FilamentDocs\Tests\Fixtures\Pages;

use Filament\Pages\Page;
use UnitEnum;

/**
 * The website panel's only screen. Its navigation item links somewhere other
 * than its URL, as a resource with a single record links to its edit page.
 */
class Announcements extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'Site';
    protected string $view = 'filament-panels::pages.dashboard';

    public static function getNavigationUrl(): string
    {
        return static::getUrl(['tab' => 'latest']);
    }
}

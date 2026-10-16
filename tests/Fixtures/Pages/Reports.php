<?php

namespace Arzcode\FilamentDocs\Tests\Fixtures\Pages;

use Filament\Pages\Page;
use UnitEnum;

/**
 * Open to everyone in the panel.
 */
class Reports extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'Content';
    protected static ?int $navigationSort = 2;
    protected string $view = 'filament-panels::pages.dashboard';
}

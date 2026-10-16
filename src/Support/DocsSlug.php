<?php

namespace Arzcode\FilamentDocs\Support;

use Arzcode\FilamentDocs\Http\Controllers\DocumentationImageController;
use Arzcode\FilamentDocs\Pages\Documentation;
use Illuminate\Support\Facades\Route;

/**
 * Whether the URL the docs would take in a panel is already served. Filament
 * gives a page whose path is taken no route, so it would silently drop out of
 * the navigation.
 */
class DocsSlug
{
    /**
     * Whether a route other than the docs' own serves /{panelPath}/{slug} or
     * a path under it. The docs' own routes are skipped, so re-running the
     * install does not flag them.
     */
    public static function isTaken(string $panelPath, string $slug): bool
    {
        $target = trim(trim($panelPath, '/') . '/' . trim($slug, '/'), '/');

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $action = ltrim($route->getActionName(), '\\');

            if (str_starts_with($action, Documentation::class) || str_starts_with($action, DocumentationImageController::class)) {
                continue;
            }

            $uri = trim($route->uri(), '/');

            if ($uri === $target || str_starts_with($uri, $target . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first of docs, documentation, help, docs-2, docs-3… that is free.
     */
    public static function suggest(string $panelPath): string
    {
        foreach (['docs', 'documentation', 'help'] as $slug) {
            if (! self::isTaken($panelPath, $slug)) {
                return $slug;
            }
        }

        for ($suffix = 2; ; $suffix++) {
            if (! self::isTaken($panelPath, "docs-{$suffix}")) {
                return "docs-{$suffix}";
            }
        }
    }
}

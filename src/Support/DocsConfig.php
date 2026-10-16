<?php

namespace Arzcode\FilamentDocs\Support;

use Filament\Pages\Page;
use Filament\Resources\Resource as FilamentResource;
use InvalidArgumentException;

/**
 * The package config, read with its types checked: a wrong entry fails
 * loudly here rather than as a confusing error deep in a render.
 */
class DocsConfig
{
    /**
     * @return array<string, class-string<Page>|class-string<FilamentResource>>
     */
    public static function screens(): array
    {
        $screens = [];

        foreach (config()->array('filament-docs.screens') as $slug => $class) {
            if (! is_string($slug) || ! is_string($class) || ! is_a($class, Page::class, true) && ! is_a($class, FilamentResource::class, true)) {
                throw new InvalidArgumentException('Every filament-docs.screens entry must map a doc slug to a Filament Page or Resource class.');
            }

            $screens[$slug] = $class;
        }

        return $screens;
    }

    /**
     * @return array<string, string>
     */
    public static function concepts(): array
    {
        $concepts = [];

        foreach (config()->array('filament-docs.concepts') as $slug => $label) {
            if (! is_string($slug) || ! is_string($label)) {
                throw new InvalidArgumentException('Every filament-docs.concepts entry must map a doc slug to a label or translation key.');
            }

            $concepts[$slug] = $label;
        }

        return $concepts;
    }

    /** The folder the markdown lives in, without a trailing slash. */
    public static function path(): string
    {
        return rtrim(config()->string('filament-docs.path'), '/');
    }

    /**
     * Only a plain slug ever becomes a file name, so nothing outside the docs
     * folder can be reached through one.
     */
    public static function docPath(string $slug): string
    {
        if (preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
            throw new InvalidArgumentException("Invalid documentation slug [{$slug}].");
        }

        return self::path() . "/{$slug}.md";
    }

    /**
     * The CSS selectors to click before the shot of a doc's screen.
     *
     * @return list<string>
     */
    public static function interactions(string $slug, string $screen): array
    {
        $selectors = config("filament-docs.screenshots.interactions.{$slug}.{$screen}", []);

        return is_array($selectors) ? array_values(array_filter($selectors, is_string(...))) : [];
    }
}

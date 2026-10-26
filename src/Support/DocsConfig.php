<?php

namespace Arzcode\FilamentDocs\Support;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Resources\Resource as FilamentResource;
use InvalidArgumentException;

/**
 * The package config, read with its types checked: a wrong entry fails
 * loudly here rather than as a confusing error deep in a render.
 *
 * @phpstan-type Concept array{label: string, icon: string|BackedEnum|null}
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
     * The screens that belong to the given panel: one `screens` list may
     * cover several panels, and the URL of a screen outside the panel cannot
     * be built in it.
     *
     * @return array<string, class-string<Page>|class-string<FilamentResource>>
     */
    public static function screensIn(Panel $panel): array
    {
        $registered = [...$panel->getPages(), ...$panel->getResources()];

        return array_filter(self::screens(), fn(string $class): bool => in_array($class, $registered, true));
    }

    /**
     * @return array<string, Concept>
     */
    public static function concepts(): array
    {
        return self::normalizeConcepts(config()->array('filament-docs.concepts'));
    }

    /**
     * Each concept as a label (a translation key or plain text), or as
     * `['label' => …, 'icon' => …]` to give it its own sidebar icon.
     *
     * @param  array<mixed>  $concepts
     * @return array<string, Concept>
     */
    public static function normalizeConcepts(array $concepts): array
    {
        $normalized = [];

        foreach ($concepts as $slug => $concept) {
            $label = is_array($concept) ? $concept['label'] ?? null : $concept;
            $icon = is_array($concept) ? $concept['icon'] ?? null : null;

            if (! is_string($slug) || ! is_string($label) || ($icon !== null && ! is_string($icon) && ! $icon instanceof BackedEnum)) {
                throw new InvalidArgumentException('Every docs concept must map a doc slug to a label or translation key, or to an array with a label and an optional icon.');
            }

            $normalized[$slug] = ['label' => $label, 'icon' => $icon];
        }

        return $normalized;
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
    public static function docPath(string $slug, ?string $folder = null): string
    {
        if (preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
            throw new InvalidArgumentException("Invalid documentation slug [{$slug}].");
        }

        return ($folder === null ? self::path() : rtrim($folder, '/')) . "/{$slug}.md";
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

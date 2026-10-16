<?php

use Arzcode\FilamentDocs\Support\Themes;

$theme = "@import '../../../../vendor/filament/filament/resources/css/theme.css';\n\n@source '../../../../app/Filament/**/*';\n@source '../../../../resources/views/filament/**/*';\n\n.custom {\n    color: red;\n}\n";

it('adds the typography plugin, the stylesheet and the source to a theme', function() use ($theme): void {
    expect(Themes::patch($theme))->toBe(
        "@import '../../../../vendor/filament/filament/resources/css/theme.css';\n\n"
        . Themes::TYPOGRAPHY . "\n\n" . Themes::IMPORT . "\n\n"
        . "@source '../../../../app/Filament/**/*';\n@source '../../../../resources/views/filament/**/*';\n" . Themes::SOURCE
        . "\n\n.custom {\n    color: red;\n}\n"
    );
});

it('keeps a typography plugin the theme already loads', function() use ($theme): void {
    $withTypography = str_replace("theme.css';\n", "theme.css';\n\n" . Themes::TYPOGRAPHY . "\n", $theme);

    expect(substr_count((string)Themes::patch($withTypography), '@tailwindcss/typography'))->toBe(1);
});

it('is idempotent', function() use ($theme): void {
    $once = (string)Themes::patch($theme);

    expect(Themes::patch($once))->toBe($once)
        ->and(Themes::isPatched($once))->toBeTrue();
});

it('takes out exactly what it added, keeping the typography plugin', function() use ($theme): void {
    expect(Themes::unpatch((string)Themes::patch($theme)))
        ->toBe(str_replace("theme.css';\n", "theme.css';\n\n" . Themes::TYPOGRAPHY . "\n", $theme));
});

it('refuses a file that does not import the Filament theme', function(): void {
    expect(Themes::patch("@import 'tailwindcss';\n"))->toBeNull();
});

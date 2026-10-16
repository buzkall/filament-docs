<?php

namespace Arzcode\FilamentDocs\Support;

/**
 * Adds or removes the lines a Filament custom theme needs for the docs page,
 * for the install and uninstall commands: the typography plugin the prose
 * relies on, the package's stylesheet and an `@source` for its views.
 *
 * Whole lines are added and whole lines removed, so the uninstall undoes
 * exactly what the install wrote. The typography plugin is left in place on
 * uninstall: the app may use it for its own pages.
 */
class Themes
{
    public const string TYPOGRAPHY = "@plugin '@tailwindcss/typography';";
    public const string IMPORT = "@import '../../../../vendor/arzcode/filament-docs/resources/css/docs.css';";
    public const string SOURCE = "@source '../../../../vendor/arzcode/filament-docs/resources/views/**/*.blade.php';";

    /**
     * Every panel theme made by `php artisan make:filament-theme`.
     *
     * @return list<string>
     */
    public static function files(): array
    {
        return glob(resource_path('css/filament') . '/*/theme.css') ?: [];
    }

    public static function isPatched(string $contents): bool
    {
        return str_contains($contents, self::IMPORT) && str_contains($contents, self::SOURCE);
    }

    /**
     * The theme with the missing lines added: the typography plugin and the
     * stylesheet after Filament's own theme import, the `@source` after the
     * last `@source`. Null when the file does not import Filament's theme,
     * which is not a theme this can patch safely.
     */
    public static function patch(string $contents): ?string
    {
        if (preg_match("/^@import\\s+['\"][^'\"]*filament\\/resources\\/css\\/theme\\.css['\"];[ \\t]*$/m", $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $head = [];

        if (! str_contains($contents, '@tailwindcss/typography')) {
            $head[] = self::TYPOGRAPHY;
        }

        if (! str_contains($contents, self::IMPORT)) {
            $head[] = self::IMPORT;
        }

        if ($head !== []) {
            $at = $match[0][1] + strlen($match[0][0]);
            $contents = substr($contents, 0, $at) . "\n\n" . implode("\n\n", $head) . substr($contents, $at);
        }

        if (str_contains($contents, self::SOURCE)) {
            return $contents;
        }

        if (preg_match_all('/^@source\s[^\n]*$/m', $contents, $sources, PREG_OFFSET_CAPTURE) > 0) {
            [$line, $offset] = $sources[0][count($sources[0]) - 1];
            $at = $offset + strlen($line);

            return substr($contents, 0, $at) . "\n" . self::SOURCE . substr($contents, $at);
        }

        $at = strpos($contents, self::IMPORT);

        return $at === false ? $contents : substr($contents, 0, $at + strlen(self::IMPORT)) . "\n\n" . self::SOURCE . substr($contents, $at + strlen(self::IMPORT));
    }

    public static function unpatch(string $contents): string
    {
        foreach ([self::IMPORT, self::SOURCE] as $line) {
            $contents = (string)preg_replace('/^' . preg_quote($line, '/') . '[ \t]*\r?\n?/m', '', $contents);
        }

        return (string)preg_replace("/\n{3,}/", "\n\n", $contents);
    }
}

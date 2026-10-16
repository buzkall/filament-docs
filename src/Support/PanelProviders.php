<?php

namespace Arzcode\FilamentDocs\Support;

use Arzcode\FilamentDocs\FilamentDocsPlugin;
use ParseError;
use PhpToken;

/**
 * Adds or removes `FilamentDocsPlugin::make()` in the host application's
 * `app/Providers/Filament/*PanelProvider.php` files, for the install and
 * uninstall commands.
 */
class PanelProviders
{
    public const ENTRY = 'FilamentDocsPlugin::make()';

    /**
     * @return list<string>
     */
    public static function files(): array
    {
        return glob(app_path('Providers/Filament') . '/*PanelProvider.php') ?: [];
    }

    public static function hasPlugin(string $contents): bool
    {
        return str_contains($contents, 'FilamentDocsPlugin');
    }

    /**
     * Registers the plugin in an existing ->plugins([…]) call, or adds one to the
     * `return $panel…;` chain. Null when neither could be found.
     */
    public static function addPlugin(string $contents, string $entry = self::ENTRY): ?string
    {
        $contents = self::addUseImport($contents, FilamentDocsPlugin::class);

        return self::injectIntoPluginsArray($contents, $entry . ',')
            ?? self::addPluginsArray($contents, $entry);
    }

    /**
     * The path the provider gives its panel with a literal `->path('…')`, or
     * null when it is computed or missing.
     */
    public static function panelPath(string $contents): ?string
    {
        return preg_match('/->path\(\s*([\'"])([^\'"]*)\1\s*\)/', $contents, $matches) === 1 ? $matches[2] : null;
    }

    /**
     * Removes only the plugin entry, with any call chained to it, leaving the
     * other plugins of the array untouched. An array left empty is removed too,
     * and so is a `->plugin(…)` call registering it on its own.
     */
    public static function removePlugin(string $contents): string
    {
        while (preg_match('/\\\\?(?:[\w\\\\]+\\\\)?FilamentDocsPlugin::make\(/', $contents, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1];
            $end = self::findClosing($contents, $start, [',', ']', ')', ';']);

            if ($end === null) {
                break;
            }

            if ($contents[$end] === ')' && preg_match('/->plugin\(\s*$/', substr($contents, 0, $start), $call, PREG_OFFSET_CAPTURE)) {
                // registered with ->plugin(…): the whole call goes
                [$start, $end] = [$call[0][1], $end + 1];
            } elseif ($contents[$end] === ',') {
                $end++;
            } else {
                // the last entry, on the line of the previous one: the comma separating them goes too
                $before = rtrim(substr($contents, 0, $start), " \t");

                if (str_ends_with($before, ',')) {
                    $start = strlen($before) - 1;
                }
            }

            $lineStart = strrpos(substr($contents, 0, $start), "\n");
            $lineStart = $lineStart === false ? 0 : $lineStart + 1;
            $lineEnd = strpos($contents, "\n", $end);
            $lineEnd = $lineEnd === false ? strlen($contents) : $lineEnd + 1;

            // an entry on lines of its own takes those lines with it
            if (trim(substr($contents, $lineStart, $start - $lineStart)) === ''
                && trim(substr($contents, $end, $lineEnd - $end)) === '') {
                [$start, $end] = [$lineStart, $lineEnd];
            } else {
                $end += strspn($contents, " \t", $end);
            }

            $contents = substr($contents, 0, $start) . substr($contents, $end);
        }

        $contents = preg_replace('/\s*->plugins\(\s*\[\s*\]\s*\)/', '', $contents) ?? $contents;

        $pattern = '/^use\s+' . preg_quote(FilamentDocsPlugin::class, '/') . ';[ \t]*\r?\n/m';

        return preg_replace($pattern, '', $contents) ?? $contents;
    }

    /**
     * Whether the patched file is still valid PHP, checked before writing it.
     */
    public static function parses(string $contents): bool
    {
        try {
            PhpToken::tokenize($contents, TOKEN_PARSE);

            return true;
        } catch (ParseError) {
            return false;
        }
    }

    public static function addUseImport(string $contents, string $fqcn): string
    {
        if (preg_match('/^use\s+' . preg_quote($fqcn, '/') . ';/m', $contents)) {
            return $contents;
        }

        if (preg_match_all('/^use\s+[^;]+;\n/m', $contents, $matches, PREG_OFFSET_CAPTURE)) {
            $last = end($matches[0]);
            $insertAt = $last[1] + strlen($last[0]);

            return substr($contents, 0, $insertAt) . 'use ' . $fqcn . ";\n" . substr($contents, $insertAt);
        }

        if (preg_match('/^namespace\s+[^;]+;\n/m', $contents, $m, PREG_OFFSET_CAPTURE)) {
            $insertAt = $m[0][1] + strlen($m[0][0]);

            return substr($contents, 0, $insertAt) . "\nuse " . $fqcn . ";\n" . substr($contents, $insertAt);
        }

        return preg_replace('/^<\?php\s*\n/', "<?php\n\nuse {$fqcn};\n\n", $contents, 1) ?? $contents;
    }

    protected static function injectIntoPluginsArray(string $contents, string $entry): ?string
    {
        // Match ->plugins( … [ tolerating whitespace/newlines before the array,
        // so both `->plugins([` and `->plugins(\n    [` are detected.
        if (! preg_match('/->plugins\(\s*\[/', $contents, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $bracketAt = $m[0][1] + strlen($m[0][0]) - 1;

        $closeAt = self::findClosing($contents, $bracketAt + 1, [']']);
        if ($closeAt === null) {
            return null;
        }

        $lineStart = strrpos(substr($contents, 0, $closeAt), "\n");
        $closeIndent = $lineStart === false ? '' : substr($contents, $lineStart + 1, $closeAt - $lineStart - 1);
        $closeIndent = preg_replace('/[^\s].*$/', '', $closeIndent);

        // the comma goes right after the last entry, not after a comment that follows it
        $lastToken = self::lastTokenBefore($contents, $closeAt);

        if ($lastToken instanceof PhpToken && ! in_array($lastToken->text, [',', '['], true)) {
            $afterToken = $lastToken->pos + strlen($lastToken->text);
            $contents = substr($contents, 0, $afterToken) . ',' . substr($contents, $afterToken);
            $closeAt++;
        }

        $before = rtrim(substr($contents, 0, $closeAt));

        return $before . "\n{$closeIndent}    {$entry}\n{$closeIndent}" . substr($contents, $closeAt);
    }

    protected static function addPluginsArray(string $contents, string $entry): ?string
    {
        // Add a ->plugins([…]) call at the bottom of the panel configuration
        // chain, just before the terminating `;` of `return $panel->…;`.
        if (! preg_match('/return\s+\$panel\b/', $contents, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $semicolonAt = self::findClosing($contents, $m[0][1] + strlen($m[0][0]), [';']);
        if ($semicolonAt === null) {
            return null;
        }

        $insertion = "\n            ->plugins([\n                " . $entry . ",\n            ])";

        return substr($contents, 0, $semicolonAt) . $insertion . substr($contents, $semicolonAt);
    }

    protected static function lastTokenBefore(string $contents, int $position): ?PhpToken
    {
        $last = null;

        foreach (PhpToken::tokenize($contents) as $token) {
            if ($token->pos >= $position) {
                break;
            }

            if (! $token->is([T_COMMENT, T_DOC_COMMENT, T_WHITESPACE])) {
                $last = $token;
            }
        }

        return $last;
    }

    /**
     * Position of the first of $targets found at the nesting level of $start.
     * Works on PHP tokens, so strings and comments of any kind are skipped.
     *
     * @param  list<string>  $targets
     */
    protected static function findClosing(string $contents, int $start, array $targets): ?int
    {
        $depth = 0;

        foreach (PhpToken::tokenize($contents) as $token) {
            if ($token->pos < $start || $token->is([T_COMMENT, T_DOC_COMMENT, T_WHITESPACE])) {
                continue;
            }

            if ($depth === 0 && in_array($token->text, $targets, true)) {
                return $token->pos;
            }

            if (in_array($token->text, ['(', '[', '{', '#[', '${'], true)) {
                $depth++;
            } elseif (in_array($token->text, [')', ']', '}'], true)) {
                $depth--;
            }
        }

        return null;
    }
}

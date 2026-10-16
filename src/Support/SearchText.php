<?php

namespace Arzcode\FilamentDocs\Support;

/**
 * Text folded so a reader need not type the accents a doc was written with.
 */
class SearchText
{
    /**
     * The accents of Spanish, Catalan, French and German, spelled out rather
     * than done with `Str::ascii()`, a general transliterator with a large
     * table behind it.
     */
    private const array ACCENTS = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
        'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
        'ñ' => 'n', 'ç' => 'c',
    ];

    /** Lowercase and unaccented. */
    public static function fold(string $text): string
    {
        return strtr(mb_strtolower($text), self::ACCENTS);
    }

    /**
     * The words of a query, folded, with the punctuation between them dropped.
     *
     * @return list<string>
     */
    public static function terms(string $query): array
    {
        return array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', self::fold($query)) ?: [],
            fn(string $term): bool => $term !== '',
        ));
    }
}

<?php

use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\SafeDeclareStrictTypesRector;
use RectorLaravel\Rector\Coalesce\ApplyDefaultInsteadOfNullCoalesceRector;
use RectorLaravel\Rector\FuncCall\AppToResolveRector;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/config',
        __DIR__ . '/src',
        __DIR__ . '/tests'
    ])
    ->withCache(__DIR__ . '/.rector.cache')
    ->withPhpSets(php84: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    )
    ->withSets([
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION
    ])
    ->withSkip([
        // Pint's config keeps strict types off.
        SafeDeclareStrictTypesRector::class,
        AppToResolveRector::class,
        // Config keys that ship as null (images_url) must fall back with ??: config()'s default only applies to a missing key.
        ApplyDefaultInsteadOfNullCoalesceRector::class
    ])
    ->withImportNames(importShortClasses: false, removeUnusedImports: true);

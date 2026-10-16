<?php

use Arzcode\FilamentDocs\Support\PanelProviders;

function providerWith(string $chain, bool $withImport = true): string
{
    $import = $withImport ? "use Arzcode\\FilamentDocs\\FilamentDocsPlugin;\n" : '';

    return "<?php\n\nnamespace App\\Providers\\Filament;\n\nuse Filament\\Panel;\n{$import}\nclass AdminPanelProvider\n{\n    public function panel(Panel \$panel): Panel\n    {\n        return \$panel\n{$chain};\n    }\n}\n";
}

it('removes only the plugin entry, keeping the other plugins', function(string $before, string $after): void {
    $result = PanelProviders::removePlugin(providerWith($before));

    expect($result)->toBe(str_replace("use Arzcode\\FilamentDocs\\FilamentDocsPlugin;\n", '', providerWith($after)))
        ->and(PanelProviders::parses($result))->toBeTrue();
})->with([
    'single line, last'  => ['->plugins([A::make(), FilamentDocsPlugin::make()])', '->plugins([A::make()])'],
    'single line, first' => ['->plugins([FilamentDocsPlugin::make(), A::make()])', '->plugins([A::make()])'],
    'own line, chained'  => ["->plugins([\n    A::make(),\n    FilamentDocsPlugin::make()\n        ->slug('manual'),\n    B::make(),\n])",
        "->plugins([\n    A::make(),\n    B::make(),\n])"],
    'only plugin'         => ["->id('a')\n->plugins([\n    FilamentDocsPlugin::make(),\n])", "->id('a')"],
    'plugin call'         => ["->id('a')\n->plugin(FilamentDocsPlugin::make())\n->path('a')", "->id('a')\n->path('a')"],
    'plugin call, inline' => ["->id('a')->plugin(FilamentDocsPlugin::make()->slug('x'))->path('a')", "->id('a')->path('a')"],
]);

it('adds a plugins call when the panel has none', function(): void {
    $result = PanelProviders::addPlugin(providerWith("->id('a')", withImport: false));

    expect($result)->toContain("->id('a')\n            ->plugins([\n                FilamentDocsPlugin::make(),\n            ]);")
        ->toContain('use Arzcode\FilamentDocs\FilamentDocsPlugin;')
        ->and(PanelProviders::parses((string)$result))->toBeTrue();
});

it('is not fooled by brackets in comments', function(): void {
    $result = (string)PanelProviders::addPlugin(providerWith("->plugins([\n    /* ]; */\n    A::make(), // ]\n    # ']'\n])", withImport: false));

    expect(PanelProviders::parses($result))->toBeTrue()
        ->and($result)->toContain("A::make(), // ]\n    # ']'\n    FilamentDocsPlugin::make(),\n])");
});

it('reads a literal panel path', function(): void {
    expect(PanelProviders::panelPath(providerWith("->id('a')->path('backoffice')")))->toBe('backoffice')
        ->and(PanelProviders::panelPath(providerWith("->path(config('x'))")))->toBeNull();
});

it('reports a broken file', function(): void {
    expect(PanelProviders::parses('<?php function ('))->toBeFalse();
});

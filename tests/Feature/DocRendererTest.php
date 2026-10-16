<?php

use Arzcode\FilamentDocs\Support\DocRenderer;

function renderDoc(string $markdown): array
{
    return app(DocRenderer::class)->render($markdown, fn(string $slug): string => "/admin/manual/{$slug}");
}

it('gives every section heading an id and an anchor, and lists them in order', function(): void {
    $doc = renderDoc("# Título\n\n## Qué puedes hacer\n\n### Más ñandú\n\n## Qué puedes hacer");

    expect($doc['headings'])->toBe([
        ['level' => 2, 'id' => 'que-puedes-hacer', 'text' => 'Qué puedes hacer', 'offset' => 7],
        ['level' => 3, 'id' => 'mas-nandu', 'text' => 'Más ñandú', 'offset' => 24],
        ['level' => 2, 'id' => 'que-puedes-hacer-2', 'text' => 'Qué puedes hacer', 'offset' => 34],
    ])
        ->and($doc['html'])->toContain('<h2 id="que-puedes-hacer">Qué puedes hacer<a href="#que-puedes-hacer" class="fi-docs-anchor"')
        ->and($doc['html'])->not->toContain('<h1 id=');
});

it('keeps the anchors out of the text that is searched', function(): void {
    expect(renderDoc("## Roles\n\nTexto.")['text'])->toBe('Roles Texto.');
});

it('places a heading where it stands, not where the prose first uses its words', function(): void {
    $doc = renderDoc("Mira el retrato de la autora.\n\n## Retrato\n\nSube una foto.");

    expect($doc['text'])->toBe('Mira el retrato de la autora. Retrato Sube una foto.')
        ->and($doc['headings'][0]['offset'])->toBe(mb_strpos($doc['text'], 'Retrato'))
        ->and($doc['html'])->not->toContain("\u{E000}");
});

it('turns a GitHub alert into a callout with its translated default title', function(): void {
    $html = renderDoc("> [!WARNING]\n> No borres nada.")['html'];

    expect($html)->toContain('<div class="fi-docs-callout fi-docs-callout-warning" role="note"><p class="fi-docs-callout-title">Warning</p>')
        ->and($html)->toContain('<p>No borres nada.</p>')
        ->and($html)->not->toContain('[!WARNING]')
        ->and($html)->not->toContain('<blockquote>');
});

it('takes a callout title written after the alert', function(): void {
    expect(renderDoc("> [!TIP] Atajo\n> Pulsa intro.")['html'])
        ->toContain('<p class="fi-docs-callout-title">Atajo</p>');
});

it('keeps the inline markup of a callout title', function(): void {
    $html = renderDoc("> [!NOTE] Usa **negrita** aquí\n> El resto.")['html'];

    expect($html)->toContain('<p class="fi-docs-callout-title">Usa <strong>negrita</strong> aquí</p>')
        ->and($html)->toContain('<p>El resto.</p>');
});

it('leaves a plain quote as a quote', function(): void {
    expect(renderDoc('> Una cita.')['html'])->toContain('<blockquote>')
        ->not->toContain('fi-docs-callout');
});

it('points a link to another doc at that doc in the panel', function(): void {
    $html = renderDoc('[Autoras](autoras.md#retrato), [Libros](./libros.md), [web](https://example.com/x.md), [arriba](#roles)')['html'];

    expect($html)->toContain('href="/admin/manual/autoras#retrato"')
        ->and($html)->toContain('href="/admin/manual/libros"')
        ->and($html)->toContain('href="https://example.com/x.md"')
        ->and($html)->toContain('href="#roles"');
});

it('still sanitizes the markdown before adding anything', function(): void {
    expect(renderDoc("<script>alert(1)</script>\n\n<p onclick=\"x()\">Hola</p>")['html'])
        ->not->toContain('<script>')
        ->not->toContain('onclick');
});

it('keeps a screenshot embedded from the private image route', function(): void {
    expect(renderDoc('![Listado](/admin/manual/img/editoriales/list.webp)')['html'])
        ->toContain('<img src="/admin/manual/img/editoriales/list.webp" alt="Listado"');
});

it('titles a callout in the reader\'s language', function(): void {
    app()->setLocale('es');

    expect(renderDoc("> [!WARNING]\n> No borres nada.")['html'])->toContain('<p class="fi-docs-callout-title">Advertencia</p>');
});

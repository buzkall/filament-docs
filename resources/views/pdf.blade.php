<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $book['title'] }}</title>
    <style>
        @page { margin: 22mm 18mm 24mm; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1f2937; font-size: 12px; line-height: 1.6; }
        a { color: {{ $accent }}; }
        .cover { text-align: center; padding-top: 220px; page-break-after: always; }
        .cover img { width: 300px; margin: 0 auto 36px; }
        .cover h1 { font-size: 32px; margin: 0; }
        .group-title { font-size: 18px; color: {{ $accent }}; border-bottom: 2px solid {{ $accent }}; padding-bottom: 4px; margin: 0 0 12px; }
        .toc { page-break-after: always; }
        .toc-title { font-size: 24px; color: {{ $accent }}; border-bottom: 2px solid {{ $accent }}; padding-bottom: 6px; margin: 0 0 20px; }
        .toc-hint { font-size: 10px; font-style: italic; color: #6b7280; margin: 0 0 18px; }
        .toc table { width: 100%; border-collapse: collapse; }
        .toc td { padding: 1px 0; vertical-align: bottom; }
        .toc .toc-group td { font-size: 11px; font-weight: bold; padding-top: 10px; }
        .toc .toc-item td { font-size: 10px; }
        .toc .toc-item .toc-label { padding-left: 12px; }
        {{-- A fixed width, so the numbers printed in the second layout move nothing. --}}
        .toc td.toc-page { width: 14mm; text-align: right; }
        .toc a { text-decoration: none; }
        .section { margin-bottom: 24px; }
        {{-- Every doc opens a page; a group's heading stays on the page of its first doc. --}}
        .page-start { page-break-before: always; }
        .content h1 { font-size: 16px; }
        .content h2 { font-size: 14px; }
        .content h3 { font-size: 13px; }
        .content h2.section-title { font-size: 15px; margin: 0 0 6px; color: {{ $accent }}; }
        .content h2.section-title .section-back { float: right; font-size: 10px; font-weight: normal; color: #9ca3af; text-decoration: none; }
        .content p, .content ul, .content ol { margin: 6px 0; }
        .content pre { background: #f3f4f6; padding: 8px; border-radius: 4px; white-space: pre-wrap; word-wrap: break-word; }
        .content code { background: #f3f4f6; padding: 1px 4px; border-radius: 3px; }
        .content blockquote { margin: 8px 0; padding: 2px 10px; border-left: 3px solid #d1d5db; color: #4b5563; }
        .content table { border-collapse: collapse; width: 100%; }
        .content thead { display: table-header-group; }
        .content tr { page-break-inside: avoid; }
        .content th, .content td { border: 1px solid #d1d5db; padding: 4px 8px; text-align: left; }
        .content img { max-width: 100%; border: 1px solid #e5e7eb; }
        .pdf-figure p.pdf-image { text-align: center; }
        .fi-docs-callout { margin: 10px 0; padding: 6px 10px; border-left: 4px solid #9ca3af; background-color: #f3f4f6; }
        .fi-docs-callout p { margin: 3px 0; }
        .fi-docs-callout-title { font-weight: bold; }
        {!! $calloutStyles !!}
        .pdf-keep, .pdf-figure, .fi-docs-callout { page-break-inside: avoid; }
        {{-- A callout kept with its heading already sits in an avoid-break container; nesting a second one makes dompdf strand the heading. --}}
        .pdf-keep .fi-docs-callout { page-break-inside: auto; }
        .pdf-figure p { margin: 6px 0 2px; }
    </style>
</head>
<body>
    @if ($book['contents'])
        <div class="cover">
            @if ($book['logo'])
                <img src="{{ $book['logo'] }}" alt="{{ config('app.name') }}">
            @endif
            <h1>{{ $book['title'] }}</h1>
        </div>

        <div class="toc" id="toc">
            <h1 class="toc-title">{{ __('filament-docs::docs.pdf.contents') }}</h1>
            <p class="toc-hint">{{ __('filament-docs::docs.pdf.contents_hint') }}</p>
            {{-- One row per doc, under a row for its group; a group of one unlabeled doc is a single linked row. --}}
            @php($rows = collect($book['groups'])->flatMap(fn (array $group): array => count($group['items']) === 1 && ! $group['items'][0]['showLabel']
                ? [['class' => 'toc-group', 'label' => $group['label'], 'slug' => $group['items'][0]['slug'], 'linked' => true]]
                : [
                    ...$group['label'] === null ? [] : [['class' => 'toc-group', 'label' => $group['label'], 'slug' => $group['items'][0]['slug'], 'linked' => false]],
                    ...array_map(fn (array $item): array => ['class' => 'toc-item', 'label' => $item['label'], 'slug' => $item['slug'], 'linked' => true], $group['items']),
                ]))
            <table>
                @foreach ($rows as $row)
                    <tr class="{{ $row['class'] }}">
                        <td class="toc-label">
                            @if ($row['linked'])
                                <a href="#doc-{{ $row['slug'] }}">{{ $row['label'] }}</a>
                            @else
                                {{ $row['label'] }}
                            @endif
                        </td>
                        {{-- 0 holds the line in the first layout, which only reads the pages. --}}
                        <td class="toc-page"><a href="#doc-{{ $row['slug'] }}">{{ $pages[$row['slug']] ?? 0 }}</a></td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    @foreach ($book['groups'] as $group)
        <div @class(['group', 'page-start' => ! $loop->first])>
            @if ($group['label'] !== null && $book['contents'])
                <h1 class="group-title">{{ $group['label'] }}</h1>
            @endif

            @foreach ($group['items'] as $item)
                <div @class(['section', 'page-start' => ! $loop->first]) id="doc-{{ $item['slug'] }}">
                    <div class="content">{!! $item['html'] !!}</div>
                </div>
            @endforeach
        </div>
    @endforeach
</body>
</html>

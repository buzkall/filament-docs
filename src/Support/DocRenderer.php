<?php

namespace Arzcode\FilamentDocs\Support;

use Closure;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Support\Str;

/**
 * Turns one doc into what the Documentation page shows: the
 * HTML, the headings for its contents list, and the plain text for search.
 *
 * Markdown is rendered and sanitized first; every pass after that works on our
 * own trusted output, so the ids, classes and roles it adds are not stripped
 * by the sanitizer's allowlist.
 *
 * @phpstan-type Heading array{level: int, id: string, text: string, offset: int}
 * @phpstan-type RenderedDoc array{html: string, headings: list<Heading>, text: string}
 */
class DocRenderer
{
    /**
     * GitHub's alert types, as in `> [!NOTE]`.
     */
    private const array CALLOUTS = ['note', 'tip', 'important', 'warning', 'caution'];

    /**
     * Bump it whenever what a render returns changes shape or content, so a doc
     * cached by an older version is rendered again instead of read.
     */
    public const int VERSION = 3;

    /**
     * A private-use character dropped in front of each heading while the text
     * is read, so a heading's place in it is known rather than searched for.
     */
    private const string HEADING_MARK = "\u{E000}";

    /**
     * @param  Closure(string): string  $docUrl  the panel URL of another doc, by slug
     * @return RenderedDoc
     */
    public function render(string $markdown, Closure $docUrl): array
    {
        $html = (string)str($markdown)->markdown()->sanitizeHtml();

        $document = HTMLDocument::createFromString("<!DOCTYPE html><body>{$html}</body>", LIBXML_NOERROR, 'UTF-8');
        $body = $document->body;

        if (! $body instanceof Element) {
            return ['html' => $html, 'headings' => [], 'text' => ''];
        }

        $headings = $this->identifyHeadings($document);
        $this->buildCallouts($document);
        $this->rewriteDocLinks($document, $docUrl);

        // Before the anchors go in, so their "#" never turns up in a search.
        ['text' => $text, 'headings' => $headings] = $this->readText($document, $body, $headings);

        $this->addAnchors($document, $headings);

        return ['html' => $body->innerHTML, 'headings' => $headings, 'text' => $text];
    }

    /**
     * Gives every h2 and h3 an id from its text, so the contents list and a
     * search result can link straight to a section.
     *
     * @return list<array{level: int, id: string, text: string}>
     */
    private function identifyHeadings(HTMLDocument $document): array
    {
        $headings = [];
        $taken = [];

        foreach ($document->querySelectorAll('h2, h3') as $heading) {
            $text = trim((string)$heading->textContent);
            $base = Str::slug($text) ?: 'seccion';
            $id = $base;

            for ($suffix = 2; isset($taken[$id]); $suffix++) {
                $id = "{$base}-{$suffix}";
            }

            $taken[$id] = true;
            $heading->setAttribute('id', $id);
            $headings[] = ['level' => $heading->localName === 'h2' ? 2 : 3, 'id' => $id, 'text' => $text];
        }

        return $headings;
    }

    /**
     * The doc's plain text for search, and where in it each heading starts.
     *
     * Looking a heading's words up in the text would find them wherever the
     * prose uses them first, so each heading is marked, the text is read, and
     * the marks are counted out of it and taken back off the page.
     *
     * @param  list<array{level: int, id: string, text: string}>  $headings
     * @return array{text: string, headings: list<Heading>}
     */
    private function readText(HTMLDocument $document, Element $body, array $headings): array
    {
        $marks = [];

        foreach ($headings as $heading) {
            $mark = $document->createTextNode(self::HEADING_MARK);
            $document->getElementById($heading['id'])?->prepend($mark);
            $marks[] = $mark;
        }

        $text = trim((string)preg_replace(
            ['/\s*\x{E000}\s*/u', '/\s+/u'],
            [' ' . self::HEADING_MARK, ' '],
            (string)$body->textContent,
        ));

        foreach ($marks as $mark) {
            $mark->remove();
        }

        $parts = explode(self::HEADING_MARK, $text);
        $offset = mb_strlen(array_shift($parts));
        $located = [];

        foreach ($headings as $index => $heading) {
            $located[] = [...$heading, 'offset' => $offset];
            $offset += mb_strlen($parts[$index] ?? '');
        }

        return ['text' => str_replace(self::HEADING_MARK, '', $text), 'headings' => $located];
    }

    /**
     * @param  list<Heading>  $headings
     */
    private function addAnchors(HTMLDocument $document, array $headings): void
    {
        foreach ($headings as $heading) {
            $anchor = $document->createElement('a');
            $anchor->setAttribute('href', "#{$heading['id']}");
            $anchor->setAttribute('class', 'fi-docs-anchor');
            $anchor->setAttribute('aria-hidden', 'true');
            $anchor->setAttribute('tabindex', '-1');
            $anchor->textContent = '#';

            $document->getElementById($heading['id'])?->append($anchor);
        }
    }

    /**
     * `> [!WARNING]` (with an optional title after it) becomes a callout box;
     * any other blockquote stays a blockquote.
     */
    private function buildCallouts(HTMLDocument $document): void
    {
        foreach ($document->querySelectorAll('blockquote') as $blockquote) {
            $first = $blockquote->firstElementChild;

            if ($first?->localName !== 'p'
                || preg_match('/^\s*\[!(' . implode('|', self::CALLOUTS) . ')\][ \t]*(.*?)[ \t]*(?:\n|<br\s*\/?>|$)(.*)$/is', $first->innerHTML, $matches) !== 1) {
                continue;
            }

            [, $type, $title, $rest] = $matches;
            $type = Str::lower($type);

            $callout = $document->createElement('div');
            $callout->setAttribute('class', "fi-docs-callout fi-docs-callout-{$type}");
            $callout->setAttribute('role', 'note');

            $heading = $document->createElement('p');
            $heading->setAttribute('class', 'fi-docs-callout-title');
            $heading->innerHTML = trim($title) !== '' ? trim($title) : e(__("filament-docs::docs.callouts.{$type}"));
            $callout->append($heading);

            if (trim($rest) === '') {
                $first->remove();
            } else {
                $first->innerHTML = $rest;
            }

            $callout->append(...iterator_to_array($blockquote->childNodes));
            $blockquote->replaceWith($callout);
        }
    }

    /**
     * `[texto](autoras.md#retrato)` links to that doc in the panel, the way
     * the same link works when the file is read on GitHub.
     *
     * @param  Closure(string): string  $docUrl
     */
    private function rewriteDocLinks(HTMLDocument $document, Closure $docUrl): void
    {
        foreach ($document->querySelectorAll('a[href]') as $link) {
            if (preg_match('/^(?:\.\/)?([a-z0-9-]+)\.md(#[\w-]*)?$/i', (string)$link->getAttribute('href'), $matches) === 1) {
                $link->setAttribute('href', $docUrl(Str::lower($matches[1])) . ($matches[2] ?? ''));
            }
        }
    }
}

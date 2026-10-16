<?php

namespace Arzcode\FilamentDocs\Http\Controllers;

use Arzcode\FilamentDocs\FilamentDocsServiceProvider;
use Arzcode\FilamentDocs\Pages\Documentation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams a doc's screenshot from the private `filament-docs` disk.
 *
 * It is a route of the panel, so the panel has already turned away guests
 * and users who may not enter it. The path shape is checked before anything else, so
 * nothing outside the generated `{slug}/{screen}[-{tab}].webp` set can be
 * read, and then the doc itself: an image is served only to a reader whose
 * sidebar holds its doc, so nobody can fetch a picture of a screen they
 * cannot open by guessing its path.
 */
class DocumentationImageController
{
    public function __invoke(Request $request, string $path): Response
    {
        abort_unless(Documentation::canAccess(), Response::HTTP_FORBIDDEN);
        abort_unless(preg_match('#^([a-z0-9-]+)/[a-z0-9-]+\.webp$#', $path, $matches) === 1, Response::HTTP_NOT_FOUND);

        // Same 404 as a missing file, as the page does for a doc they may not read.
        abort_unless(app(Documentation::class)->canReadDoc($matches[1]), Response::HTTP_NOT_FOUND);

        $disk = Storage::disk(FilamentDocsServiceProvider::DISK);

        abort_unless($disk->exists($path), Response::HTTP_NOT_FOUND);

        /* A re-shot screen keeps its URL, so the browser may keep the image but
           has to ask before using it: `no-cache` with the file's date answers
           an unchanged image with an empty 304 and a new one at once. `private`
           keeps the screenshots out of any shared proxy cache. */
        $headers = [
            'Cache-Control' => 'private, no-cache',
            'Last-Modified' => gmdate('D, d M Y H:i:s', $disk->lastModified($path)) . ' GMT',
        ];

        $notModified = new Response(headers: $headers);

        if ($notModified->isNotModified($request)) {
            return $notModified;
        }

        return $disk->response($path, headers: $headers);
    }
}

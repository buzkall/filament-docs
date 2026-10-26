<?php

return [

    /*
     | Where the markdown lives: one `{slug}.md` per doc. Screenshots go in an
     | `images` folder inside it, on the private `filament-docs` disk.
     */
    'path' => resource_path('docs'),

    /*
     | Docs about a concept rather than a screen, at the top of the sidebar and
     | readable by anyone who may open the documentation, by slug => label, or
     | slug => ['label' => …, 'icon' => …]. The label goes through `__()`, so
     | it may be a translation key. A panel may set its own with ->concepts().
     */
    'concepts' => [
        'index' => 'filament-docs::docs.pages.index',
    ],

    /*
     | The screen each doc describes, by the slug of its markdown file:
     | slug => Resource or Page class. The docs sidebar is the panel's own
     | navigation with these docs in place of the screens: same groups, same
     | order, same label and icon. A doc is listed, searched and served only
     | while its screen is in the reader's navigation. One list serves every
     | panel: each panel keeps only the screens it registers.
     */
    'screens' => [],

    /*
     | URL prefix the screenshots are linked with from the markdown. Null takes
     | the image route of the panel the plugin is registered in,
     | `/{panel}/{slug}/img`; `docs:screenshot` writes it into the docs.
     */
    'images_url' => null,

    'screenshots' => [

        /*
         | Path or full URL of a local-only route that logs the screenshot
         | browser in (never the login form). Never set it anywhere but a
         | developer's own .env; without it the runner fills the login form
         | from DOCS_SCREENSHOT_EMAIL and DOCS_SCREENSHOT_PASSWORD.
         */
        'login_path' => env('DOCS_SCREENSHOT_LOGIN_PATH'),

        /*
         | Rows per page set on every table before a shot, so a long list makes
         | a short picture. Only the screenshot browser's session sees it.
         */
        'per_page' => 10,

        /*
         | Browser window, at 1x: the doc's column is narrower than the shot,
         | so 2x only adds bytes. WebP quality for cwebp.
         */
        'viewport' => ['width' => 1440, 'height' => 800],

        'quality' => 80,

        /*
         | CSS selectors clicked before capturing a doc slug + screen (list,
         | create, view, edit), so collapsed UI such as an action-group
         | dropdown shows up in the shot.
         */
        'interactions' => [
            // 'books' => ['list' => ['.fi-header-actions-ctn .fi-ac-icon-btn-group']],
        ],

    ],

];

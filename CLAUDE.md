# CLAUDE.md — contract for working on `arzcode/filament-docs`

Read this before touching code.

## What this package is

An in-panel user manual for Filament 5: one markdown file per screen, shown in a page whose sidebar is the panel's own navigation with each documented screen replaced by its doc. Optional Playwright screenshots are embedded into the docs by `docs:screenshot`.

- Composer name: `arzcode/filament-docs`
- PHP namespace: `Arzcode\FilamentDocs`
- Config file: `config/filament-docs.php` (`config('filament-docs.*')`), read through `Support\DocsConfig`
- Translation namespace: `filament-docs::docs.*`, in `resources/lang/{en,es}`
- Plugin id: `filament-docs` (`FilamentDocsPlugin::ID`)

Targets: **PHP 8.4+, Laravel 12 or 13, Filament 5.8+, Livewire 4**.

## Structure

| Where | What |
|---|---|
| `src/FilamentDocsPlugin.php` | Registers the page and the screenshot route in a panel; holds the fluent options (slug, authorize, navigation) |
| `src/FilamentDocsServiceProvider.php` | Config (deep merge), translations, views, commands, the `filament-docs` disk, the install steps |
| `src/Pages/Documentation.php` | The page: sidebar from the navigation, rendering, search, contents list, pager |
| `src/Http/Controllers/DocumentationImageController.php` | Streams a screenshot to a reader of its doc |
| `src/Support/DocRenderer.php` | Markdown → sanitized HTML → anchors, callouts, `.md` links, plain text for search |
| `src/Support/DocPdf.php` | The PDF of a doc or of the manual: images from the disk, headings kept with their block, callout colors; the "Export PDF" actions are on the page |
| `src/Support/DocScreenshots.php` | Screenshot targets, and embedding the images into the markdown |
| `src/Support/DocsConfig.php` | The config, read with its types checked |
| `src/Support/{PanelProviders,Themes,DocsSlug}.php` | Install helpers: patch panel providers and themes, probe the slug |
| `src/Commands/` | `ScreenshotDocsCommand` (`docs:screenshot`), `UninstallCommand` |
| `bin/screenshot-docs.mjs` | The Playwright runner. It loads `playwright` from the app's root, never from the package |
| `resources/css/docs.css` | Anchor, callout and image styles, imported by the host's theme |
| `stubs/index.md` | The starter introduction the install offers |

## Rules

1. **The navigation is the access check.** Never keep a separate list of who may read what: a doc is listed, searched, paged to and served (images included) only while its screen is in the reader's navigation. A hidden or unknown doc answers 404, never 403.
2. **Sanitize first, then add.** `DocRenderer` runs `Str::markdown()->sanitizeHtml()` before any DOM pass, so the ids, classes and roles it adds survive the allowlist. Keep new passes after it.
3. **Nothing per reader is cached.** A rendered doc is cached by renderer version, locale, slug and file mtime; the highlight is applied after. Bump `DocRenderer::VERSION` whenever a render changes.
4. **The screenshot command never wipes a doc.** A run that captured nothing changes nothing; a narrowed or partly failed run replaces only the screens it shot.
5. **Nothing about the host is assumed.** No `App\` class, no role, no panel id: the arch test fails on an `App` import.
6. **The PDF is built from the sidebar.** `DocPdf` reads only the docs `Documentation::getSidebarGroups()` returns, never the docs folder, and dompdf may read files only from its chroot (the app, the screenshots disk and the logo's folder). dompdf has no CSS variables or oklch(), so colors in `resources/views/pdf.blade.php` are concrete values from `FilamentColor`.
7. **Every user-facing string is a translation key** in both `en` and `es`; `tests/Unit/TranslationsTest.php` fails on a missing key.

## How it works

### The sidebar

`Documentation::getSidebarGroups()` walks `Filament::getNavigation()` group by group and keeps every item whose URL belongs to a screen in `filament-docs.screens`, with that item's label and icon. The `concepts` docs go at the top, followed by the panel's ungrouped screens with no heading, as the panel shows them. Moving, renaming or re-sorting a screen therefore moves its doc, with nothing to change in the docs.

Several panels can show docs. `DocsConfig::screensIn()` keeps the screens the current panel registers, because the URL of another panel's screen cannot be built. The plugin's `->path()` and `->concepts()` override the config for that panel. Everything that reads a doc goes through the plugin of the current panel: `Documentation::docPath()`, `getMarkdown()` (which applies `->preprocessMarkdown()`), `DocPdf` and `DocScreenshots`. The screenshot command sets the panel it shoots as the current one. Images stay on the single `filament-docs` disk, so slugs are unique across panels.

Filament only puts in the navigation what the reader may open, so a screen that hides itself (`canAccess()`, `shouldRegisterNavigation()`) hides its doc too. The search scope, the pager and the slugs the page and the image route serve are all built from this one sidebar, so they cannot disagree with the panel. The slug is checked against the sidebar before the filesystem is touched, so a markdown file that is not configured is never served.

### Rendering

`DocRenderer` runs `Str::markdown()`, then `sanitizeHtml()`, then its passes on a `Dom\HTMLDocument`:

- every `##` and `###` gets an id from its text (`Str::slug`; duplicates get `-2`, `-3`) and a `#` anchor. They feed the contents list, which needs at least two, and the anchor a search result opens at;
- `> [!NOTE|TIP|IMPORTANT|WARNING|CAUTION]` becomes a callout; text after the marker, inline markup included, replaces the default title (`filament-docs::docs.callouts.*`);
- a relative `something.md#frag` link becomes that doc's panel URL, so the same link works when the file is read on GitHub;
- the plain text is read for search, and each heading records its `offset` in it. A search result opens at the last heading before the match by that offset, never by looking the heading's words up, which would match wherever the prose used them first.

A render is cached for a day under `filament-docs:v{VERSION}:{locale}:{panel}:{md5(path)}:{mtime}`. The `?highlight=` marks are added after it is read.

### Search

Every word must appear somewhere in the doc. Per word, a match in the title scores 3, in a heading 2, in the body 1; ties keep the sidebar's order. `SearchText` folds case and accents.

### Screenshots

- Targets come from `screens`: a page gives one `list` shot; a resource gives list, create, view and edit, the last two with its first record **by route key** (a model may route by slug).
- The command writes a manifest (`storage/app/docs-screenshots-manifest.json`) and runs `bin/screenshot-docs.mjs`, which loads `playwright` from the manifest's `appRoot` so it works when the package is a symlink. The runner logs in (a local-only URL, or the login form from `DOCS_SCREENSHOT_EMAIL`/`_PASSWORD`), fails any shot redirected to the login, sets every table to `per_page` rows, hides debug and environment badges, sizes the shot to the taller of the content and the full sidebar, shoots a PNG at 1x and converts it with `cwebp`. Panels whose schema tabs sit near the top get one shot per tab.
- Images go in `{path}/images/{slug}/{screen}[-{tab}].webp` on the `filament-docs` disk. A tab image goes at the end of the section whose heading matches the tab label, between `<!-- screenshot:… -->` markers; everything else goes in a `<!-- screenshots:start/end -->` block at the bottom. A re-run strips and rewrites only those blocks, and their blank lines, so the prose is untouched and the file is byte-for-byte stable.
- `DocumentationImageController` serves an image only to a reader whose sidebar holds its doc, with `Cache-Control: private, no-cache` and `Last-Modified`: a re-shot screen keeps its URL, so the browser revalidates and gets a 304 until the file changes.

## Install and uninstall

`filament-docs:install` is built in the service provider as small idempotent steps; `filament-docs:uninstall` mirrors it. Every install step needs an uninstall step, in the same commit.

| Install | Uninstall | Asks on uninstall? |
|---|---|---|
| publish the config (asks) | delete the published config | yes |
| register the plugin in each panel (asks per panel, probes the slug) | remove it from every panel | no |
| add the typography plugin, stylesheet and `@source` to each theme (asks per theme) | remove the stylesheet and `@source` lines; the typography plugin stays | no |
| `npm install -D @tailwindcss/typography` (asks) | — the app may use it | — |
| create `resources/docs/index.md` (asks) | delete the docs folder | yes, default no |
| `npm run build` (asks, only after a theme changed) | `npm run build` after a theme changed | no |
| published translations/views (by the user) | delete them | yes |
| `composer require` (by the user) | `composer remove` | no |

## Tests

`composer test` runs Pest against Testbench with a fixture panel at `/admin` (`tests/Fixtures`): a resource routed by slug, a page open to all and one for admins only, and a copy of `tests/Fixtures/docs` per test in a temporary folder, so tests may write to the docs. Users are `admin`, `editor` (no settings) and `reader` (not authorized by the plugin).

Run `composer analyse` (PHPStan level 10), `composer refactor` and `composer format` before committing.

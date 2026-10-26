# Changelog

## 1.1.1 - Unreleased

- The search highlight no longer marks a word inside another, nor short words such as "de" or "a" on their own: it
  marks the whole phrase as one, then each word where a word starts. Searching "copia de seguridad" used to mark every
  "de" in the doc, "descargarlos" included.

## 1.1.0 - 2026-10-26

- Docs in several panels: `->path()` and `->concepts()` on the plugin give a panel its own markdown folder and concept
  docs, and each panel keeps only the `screens` it registers, so a screen of another panel no longer breaks the sidebar.
  `docs:screenshot --panel` reads and writes that panel's folder and image URL.
- A panel without an `index` doc opens on the first doc of its sidebar.
- `->preprocessMarkdown()` rewrites a doc's markdown before it is rendered, searched or exported.
- A concept may have its own sidebar icon: `['label' => …, 'icon' => …]`.
- Catalan translations.
- The render cache is keyed by panel and file instead of slug.
- Fix: a screen whose navigation item links elsewhere than its index (`getNavigationUrl()`, e.g. a single-record
  resource opening its edit page) lost its doc. The sidebar and the "open in the panel" link now use that URL.

## 1.0.1 - 2026-10-19

- Fix the PDF export on PHP 8.4: it read `Dom\Element::$children`, which only exists in PHP 8.5.
- PHPStan checks against PHP 8.4, the lowest supported version.

## 1.0.0 - 2026-10-16

- First version, extracted from the docs page of a Filament app: the plugin, the page, the screenshot route and the
  `docs:screenshot` command.
- `filament-docs:install` and `filament-docs:uninstall`.
- Config merged deeply, so an app that overrides one screenshot setting keeps the other defaults.
- PDF export of the open doc and of the whole manual (`barryvdh/laravel-dompdf`), with `pdfExport()` and `pdfLogo()` on the plugin; the manual's contents list shows the page of each doc.

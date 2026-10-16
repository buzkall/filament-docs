# Changelog

## 1.0.0 - 2026-10-16

- First version, extracted from the docs page of a Filament app: the plugin, the page, the screenshot route and the
  `docs:screenshot` command.
- `filament-docs:install` and `filament-docs:uninstall`.
- Config merged deeply, so an app that overrides one screenshot setting keeps the other defaults.
- PDF export of the open doc and of the whole manual (`barryvdh/laravel-dompdf`), with `pdfExport()` and `pdfLogo()` on the plugin; the manual's contents list shows the page of each doc.

# Screenshots

Generated from `src/data/screenshots.ts` by `npm run screenshots` — edit that file, not this one.

Which feature and screen each image shows, where it appears, and the plugin files that, when changed, mean it must be captured again. After changing a feature:

```bash
npm run demo-sites                 # refresh the demo Studio sites with the current plugin
npm run screenshots -- --changed   # recapture what the change touches (and says if the video needs it)
npm run video                      # when the video's scenes changed (see video/STORYBOARD.md)
```

Images: `src/assets/screenshots/<id>.webp` (the site makes AVIF/WebP sizes). WordPress.org: `.wordpress-org/screenshot-N.png` and readme.txt's `== Screenshots ==`. `npm run check` fails when this list and the site disagree.

| Image | Feature | Screen and state | Viewport | Shown in | Depends on | Captured |
| --- | --- | --- | --- | --- | --- | --- |
| `export-screen` | One list for every content type, attachments, product reviews and options; what each row exports, suggestions | Tools → Export — Pages: 1 item (Home); Products and Options bring what the Home suggests; Details open | desktop | Home (`/`)<br>Docs `/docs/getting-started/introduction/`<br>Docs `/docs/exporting/export-screen/`<br>WordPress.org #1 | `routes/export/` `src/` `includes/admin-app.php` `includes/abilities.php` `includes/selection.php` `includes/options.php` | 2026-10-13 |
| `items-panel` | Choosing items by selection; badges for the homepage and WooCommerce pages | Tools → Export → Pages → Choose items — Home checked, the export state behind it | desktop | Home (`/`)<br>Docs `/docs/exporting/choosing-items/`<br>Docs `/docs/getting-started/your-first-export/`<br>WordPress.org #2 | `routes/export/` `src/` `includes/admin-app.php` `includes/abilities.php` `includes/selection.php` `includes/compat/` | 2026-10-13 |
| `options-panel` | Options one by one; the settings the items chosen suggest | Tools → Export → Options → Choose items — Selection is Selected: page_on_front and show_on_front, brought by the homepage | desktop | Home (`/`)<br>Docs `/docs/exporting/options/`<br>WordPress.org #3 | `routes/export/` `src/` `includes/admin-app.php` `includes/abilities.php` `includes/options.php` `includes/compat/` | 2026-10-13 |
| `playground-options` | WordPress Playground .zip: themes and plugins to install | Tools → Export → Format: Playground → Themes and plugins — Playground themes and plugins modal with the active theme | desktop | Home (`/`)<br>Docs `/docs/formats/playground-zip/`<br>WordPress.org #4 | `routes/export/` `src/` `includes/admin-app.php` `includes/abilities.php` `includes/playground.php` | 2026-10-13 |
| `bundle-plugin` | Export as plugin: the plugin installed on the other site checks what it needs and imports its bundle with one click | Tools → Import Eiz Coffee (the plugin made with "As plugin"), on the other site — Imported, with "Deactivate and delete this plugin" | desktop | Home (`/`)<br>Docs `/docs/exporting/as-plugin/` | `includes/bundle-plugin.php` `templates/bundle-plugin.php` `assets/importer.css` `assets/bundle-plugin.js` `includes/import.php` | 2026-10-13 |
| `settings-general` | General settings: the Export and Import screens, saved defaults, how long copies are kept | The plugin’s Settings → General — Defaults | desktop | Docs `/docs/settings/general/` | `routes/settings-general/` `routes/settings-home/` `src/` `includes/settings.php` | 2026-10-13 |
| `settings-export` | Export settings: attached media only, linked settings, .zip bundles, content as .html, defaults scope | The plugin’s Settings → Export — Defaults | desktop | Home (`/`)<br>Docs `/docs/settings/export-settings/`<br>WordPress.org #5 | `routes/settings-export/` `routes/settings-home/` `src/` `includes/settings.php` | 2026-10-13 |
| `settings-copies` | Copies of exports: download, open in Playground, import; expiry | The plugin’s Settings → Copies — Three copies: a .wxrz, a .zip bundle and a Playground .zip | desktop | Home (`/`)<br>Docs `/docs/settings/copies/`<br>WordPress.org #6 | `routes/settings-copies/` `routes/settings-home/` `src/` `includes/settings.php` `includes/copies.php` | 2026-10-13 |
| `export-mobile` | The Export screen on a phone | Tools → Export (390 px) — Pages: 1 item; Products and Options suggested | phone | Home (`/`) | `routes/export/` `src/` `includes/admin-app.php` `includes/abilities.php` | 2026-10-13 |
| `classic-mobile` | The classic Export screen with the plugin’s additions, on a phone | Tools → Export, classic (export.php?classic=1, 390 px) — Defaults | phone | Home (`/`)<br>Docs `/docs/exporting/classic-screen/` | `includes/export.php` `includes/selection.php` | 2026-10-13 |
| `demo` video | The whole flow: export, import, Playground, AI agents | See `video/STORYBOARD.md` | 1920×1080 | Home (`/`)<br>Apoia.se (YouTube) | `routes/export/` `src/` `includes/admin-app.php` `includes/abilities.php` `includes/import.php` `includes/export.php` `includes/playground.php` `includes/copies.php` `includes/options.php` | 2026-10-13 |

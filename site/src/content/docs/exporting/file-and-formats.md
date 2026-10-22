---
title: "File and formats"
description: "Choose between WordPress's plain WXR, a .wxrz or .zip bundle, a Playground .zip and a plugin that imports itself; file names and what each one holds."
---

The **Format** toggle, in the **Details** panel above the list, has two choices; the first one's name follows what goes in the file.

| Shown as | File | When | Holds |
| --- | --- | --- | --- |
| **WXR XML** | `.xml` | Media files off and no options | WordPress's own export, unchanged |
| **WXR ZIP** | `.zip` | Media files or options (the default) | A [bundle as a plain .zip](../../formats/zip-bundle/): the WXR, media, `post_content/`, `options.json` |
| **WXRZ** | `.wxrz` | Same, with *Bundle as .zip* off | The same [bundle as a gzipped tar](../../formats/wxrz-bundle/) |
| **Playground** | `.zip` | Chosen | A [Playground blueprint bundle](../../formats/playground-zip/) with themes and plugins |
| **As plugin** | `.zip` | *As plugin* on (not with Playground) | A plugin holding the bundle, which imports it on the other site with one click ([as plugin](../as-plugin/)) |

## As plugin

The **As plugin** toggle, next to *Media files* (its ⓘ explains it), wraps the bundle in a plugin `.zip`. Install it on the other site like any plugin and click **Run import** on its screen under Tools: no file to upload. See [as plugin](../as-plugin/).

## Update existing

**Update existing**, in the **Details** panel, asks the import to update the pages, posts and products that already exist on the other site (same type and slug) instead of adding copies. It's saved in the bundle (`update_existing` in the manifest), and the importer's box comes checked. Not with WXR XML or Playground. See [updating items that already exist](../../getting-started/importing/#updating-items-that-already-exist).

## File name

Leave it empty for WordPress's usual name, `<site name>.WordPress.<date>` — the placeholder shows the exact one for today. A name you type is sanitized and the extension is added.

## Content as .html files

With **Settings → Export → Content as .html files** (on by default), each post's content goes to `post_content/<slug>.html` instead of inside the WXR — easy to read, diff and keep under version control. On import it is put back before the importer runs.

## Copies

Every export made on the new screen or by the ability is kept as a **copy** for a while (a day by default), with an unguessable name, and listed in [Settings → Copies](../../settings/copies/).

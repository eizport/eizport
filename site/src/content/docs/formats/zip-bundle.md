---
title: "ZIP bundle"
description: "The same bundle as a plain .zip, which any computer opens — how to turn it on and how it is read."
---

By default (**Settings → Export → Bundle as .zip**, on), bundles are plain `.zip` files instead of `.wxrz` — inside a plugin made as plugin too. The entries are **exactly the same** — `manifest.json`, the WXR, `options.json`, `post_content/`, `uploads/` — so everything on the [WXRZ page](../wxrz-bundle/) applies.

## Why

A `.zip` opens with a double click on any computer; `.wxrz` needs a tool that reads `.tar.gz`. Choose `.zip` when people will look inside the file; keep `.wxrz` when it only travels between sites.

## Per export

The setting is the default. The [`eizport/export`](../../developers/abilities/#eizportexport) ability takes `zip: true` or `zip: false` for one export.

## How it is written and read

The plugin writes the tar bundle and turns it into a `.zip` (with `ZipArchive`, or core's PclZip), and on import turns a `.zip` back into a tar before reading it — one code path for both, with the same checks:

- only bundle entries are taken (the manifest, a top-level `.xml`, `options.json`, `post_content/*.html`, safe `uploads/` files);
- the uncompressed size counts against the same 512 MB limit.

## Telling bundles from Playground files

Both end in `.zip`. A bundle has `manifest.json` and no `blueprint.json`; a Playground `.zip` has `blueprint.json`. The importer and the Copies list tell them apart that way.

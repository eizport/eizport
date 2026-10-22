---
title: "Appearance"
description: "The site's look on rows of its own, item by item: Customizer theme mods and CSS rules, the Site Editor's style groups and CSS rules, and widgets — with a preview of each, the media they use, and how the other site takes them."
---

The site's look is exported on rows of its own, next to the content types. A row shows only when the active theme has something in it, and its **Choose items** panel lists what it holds, each with a preview:

| Row | Each item | Preview | Shows with |
| --- | --- | --- | --- |
| **Customizer theme mods** | A setting of *Appearance → Customize*: Logo, Header image, Background image and color, Header text color, the theme's own settings | The image, the color, or the value | A theme with Customizer settings |
| **Customizer custom CSS** | A rule of *Customize → Additional CSS* (an `@media` block counts as one; a comment right before goes with it) | The rule, and which one it is: *Rule 3 of 3 (the last)* | A theme with Additional CSS |
| **Editor global styles** | A group of *Appearance → Editor → Styles*: Color palette, Site colors, Typography, Spacing and layout, Elements, each block with styles of its own | Its colors, or a summary | Block themes with saved styles |
| **Editor global CSS** | A rule of the Site Editor's *Additional CSS* | The rule | Block themes with Additional CSS there |
| **Widgets** | A widget — classic (text, image, menu…) or block — with its sidebar | Its text or image | Classic themes with widgets in their sidebars |

Check a row to take all it has (every item is suggested), or open **Choose items** and check only some: the last CSS rule, the logo and the background color, one widget. They need a bundle (`.wxrz`, `.zip` or a Playground `.zip`): they travel in its `options.json`.

## What comes along

The images the items chosen use are exported with them, even when the Attachments row isn't checked: the logo, the header and background images, the media of image widgets and block widgets, and the uploads the styles and the CSS point to (*Only attached media* in [Settings → Export](../../settings/export-settings/) turns it off, as for content).

## On the other site

- **CSS rules** (the Customizer's and the Site Editor's) are **added** to the other site's CSS, where **Details → CSS rules on import** says: at the end (the default) or at the start. They go between two comments named after the file:

  ```css
  .their-own { color: purple; }

  /* head COMB-584 */
  .site-footer { background: #ffeedd; }
  /* tail COMB-584 */
  ```

  The name is the file's name without extension — `COMB-584.wxrz` here, named after a task: a mark of where the rules came from. Each import adds its own block — blocks aren't unique, and the same name can show up more than once. Without the comments ([Settings → Export](../../settings/export-settings/), or the importer), rules the other site already has (same text) aren't repeated.
- Whoever imports starts from the export's choice and can change it on the importer's step 1 (**Additional CSS**), with `css_mode`/`css_markers` in [`eizport/import`](../../developers/abilities/#eizportimport), or with `--css-mode`/`--[no-]css-markers` in [WP-CLI](../../developers/wp-cli/). Two choices exist only there:
  - **Replace the "COMB-584" block this site already has** — offered when the other site's CSS has a head/tail block of the same name (an earlier delivery of a file with that name): the new block takes its place, the rest of the CSS staying (`replace`);
  - **Replace all of this site's CSS** — only the file's rules remain (`replace_all`).
- **Theme mods** chosen go to the active theme and are **merged** into its own: what the file doesn't hold stays (the other settings, the menus' locations). The logo and the header image point to the imported attachments, the header and background images' addresses to the imported files.
- **Style groups** chosen replace the same groups on the other site's styles (a block theme); its other groups stay.
- **Widgets** chosen go at the **end of the same sidebar**, next to the other site's own widgets (to *Inactive widgets* when its theme has no such sidebar), each with a free number. Importing the same file again with *Update existing* updates them in place instead of adding them twice. A Navigation Menu widget points to the imported menu (export it on the [Menus](../menus/) row), an image widget to the imported image, and the addresses in block widgets to the imported files.

## From WP-CLI and the ability

```bash
wp eizport export --types=page --appearance=theme_mods:custom_logo+background_color,custom_css,widgets:text-3
wp eizport export --appearance=all --css-mode=start --no-css-markers
wp eizport import delivery.wxrz --css-mode=replace
```

A row alone takes all of it; a CSS rule's key (`r:…`) comes from [`eizport/get-export-options`](../../developers/abilities/#eizportget-export-options), which lists each row's items. The [`eizport/export`](../../developers/abilities/#eizportexport) ability takes `appearance` (row → `"all"` or keys), `css_mode` and `css_markers`.

## Limits

- The look goes to the **active theme** of the other site. Exported from one theme and imported with another, theme mods only mean something if that theme uses the same settings.
- Inside block widgets, an image keeps its ID from this site (its address is updated); see the [issues](https://github.com/eizport/eizport/issues) about IDs in blocks.
- Fonts installed with the Font Library aren't exported: styles that use them fall back to the other site's fonts.
- The theme mods no longer come with the Options row's *Basics* group: check **Customizer theme mods**. A block theme's site logo is an option, in *Basics*.

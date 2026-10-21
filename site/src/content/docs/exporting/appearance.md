---
title: "Appearance"
description: "The site's look on rows of its own: the Customizer's theme mods and custom CSS, the Site Editor's global styles and global CSS, and widgets — with the media they use."
---

The site's look is exported on rows of its own, next to the content types. Each row is one thing, in or out, and shows only when the active theme has it:

| Row | What it is | Shows with |
| --- | --- | --- |
| **Customizer theme mods** | *Appearance → Customize*: logo, header image, background image and color, the theme's own settings | Classic themes (or any theme with Customizer settings) |
| **Customizer custom CSS** | *Customize → Additional CSS* | A theme with Additional CSS |
| **Editor global styles** | *Appearance → Editor → Styles*: colors, typography, layout | Block themes with saved styles |
| **Editor global CSS** | *Styles → Additional CSS* | Block themes with Additional CSS there |
| **Widgets** | *Appearance → Widgets*: classic widgets (text, image, menu…) and block widgets, with their sidebars | Classic themes with widgets in their sidebars |

Every row the site has is suggested. They need a bundle (`.wxrz`, `.zip` or a Playground `.zip`): they travel in its `options.json`.

## What comes along

The images the look uses are exported with it, even when the Attachments row isn't checked: the logo, the header and background images, the media of image widgets and block widgets, and the uploads the styles and the CSS point to (*Only attached media* in [Settings → Export](../../settings/export-settings/) turns it off, as for content).

## On the other site

- **Theme mods** go to the active theme and are **merged** into its own: what the file doesn't hold stays (like the menus' locations). The logo and the header image point to the imported attachments, and the header and background images' addresses to the imported files.
- **Custom CSS** becomes the active theme's Additional CSS.
- **Global styles** and **global CSS** go to the active theme's styles, when it's a block theme. Each changes only its own part: importing the styles keeps the other site's Additional CSS, and the other way round.
- **Widgets** go back to their sidebars; a sidebar the other theme doesn't have sends its widgets to *Inactive widgets*, as WordPress does. A Navigation Menu widget points to the imported menu (export it on the [Menus](../menus/) row), an image widget to the imported image, and the addresses in block widgets to the imported files.

## From WP-CLI and the ability

```bash
wp eizport export --types=page --appearance=theme_mods,custom_css,widgets
wp eizport export --appearance=all --menus=all
```

The [`eizport/export`](../../developers/abilities/#eizportexport) ability takes `appearance`; [`eizport/get-export-options`](../../developers/abilities/#eizportget-export-options) says what the site has.

## Limits

- The look goes to the **active theme** of the other site. Exported from one theme and imported with another, theme mods only mean something if that theme uses the same settings.
- Inside block widgets, an image keeps its ID from this site (its address is updated); see the [issues](https://github.com/eizport/eizport/issues) about IDs in blocks.
- Fonts installed with the Font Library aren't exported: styles that use them fall back to the other site's fonts.
- The theme mods no longer come with the Options row's *Basics* group: check **Customizer theme mods**. A block theme's site logo is an option, in *Basics*.

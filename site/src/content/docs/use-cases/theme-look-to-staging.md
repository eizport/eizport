---
title: "Theme look to staging"
description: "A theme customized locally — logo, background, Additional CSS, widgets, or a block theme's styles — repeated on staging without redoing it in the Customizer or the Site Editor."
---

## The scenario

A classic theme was customized locally: the logo, a background image and color in the Customizer, a few lines of Additional CSS, and the footer sidebar with an image, a menu and a block of text. Staging has the same theme, freshly installed. Redoing all of it by hand — and uploading the images again — is the part everyone forgets something in.

The same goes for a block theme customized in the Site Editor: its colors and typography (*Styles*) and its Additional CSS.

## The steps

1. On the local site, **Tools → Export**. The rows **Customizer theme mods**, **Customizer custom CSS** and **Widgets** say *Suggested* (with a block theme: **Editor global styles** and **Editor global CSS**). Check them.
2. Check **Menus** too if a widget shows a menu, and the pages the site needs.
3. **Export**, and on staging **Tools → Import → WordPress** with the file (with Eizport active there, which sets the look when the import ends).

## What the file carries

- The theme mods, the Additional CSS, and the widgets with their sidebars — or the block theme's styles and CSS.
- The images they use: the logo, the background, the image widget's image.

On staging, the logo and the background are the imported images, the widgets are back in the footer, the menu widget shows the imported menu, and the CSS is the theme's Additional CSS. Staging's own settings that the file doesn't hold stay as they were.

## What it doesn't

- Fonts installed with the Font Library.
- A sidebar staging's theme doesn't have: its widgets go to *Inactive widgets*.

## Only the CSS

To bring only a block theme's Additional CSS, check just **Editor global CSS**: staging keeps its own colors and typography.

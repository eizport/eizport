---
title: "Theme look to staging"
description: "A change to a theme's look made locally — the last CSS rule, the logo, one widget, or a block theme's palette — taken to staging without touching what staging has of its own."
---

## The scenario

Staging already has its look: its own Additional CSS, its widgets, its colors. Locally, a last CSS rule was added for the footer, the logo changed, and an *Hours* widget went into the footer. Only those should reach staging — copying the whole look over would wipe what staging has of its own, and redoing it by hand is where something gets forgotten.

The same goes for a block theme customized in the Site Editor: a new color palette, one more rule in its Additional CSS.

## The steps

1. On the local site, **Tools → Export**. Open **Choose items** on **Customizer custom CSS** and check only the last rule (*Rule 3 of 3 (the last)*), each with its preview; on **Customizer theme mods**, the Logo; on **Widgets**, *Hours*. With a block theme: the *Color palette* group of **Editor global styles** and a rule of **Editor global CSS**.
2. **Details → CSS rules on import**: *Add at the end of the site's CSS* (the default).
3. **Export**, and on staging **Tools → Import → WordPress** with the file (with Eizport active there). Step 1 shows **Additional CSS**, as the file asks; change it there if needed.

## What staging gets

- Its own CSS, then the rule, between `/* head … */` and `/* tail … */` comments named after the file.
- The logo (the image is imported with it); its other theme mods as they were.
- *Hours* at the end of its footer, after its own widgets.
- With a block theme: the palette; its own colors and typography as they were.

## Next time

Name the exports after the task — `COMB-584.wxrz` — and the rules on staging sit between `/* head COMB-584 */` and `/* tail COMB-584 */`. Another change for the same task, another export with the same name: the importer's step 1 now offers **Replace the "COMB-584" block this site already has**, which swaps the old rules for the new ones and leaves the rest of staging's CSS as it is. Leave it at the export's choice instead, and a second block is added after the first. With *Update existing* on, the *Hours* widget is updated in place instead of added again.

## What it doesn't

- Fonts installed with the Font Library.
- A sidebar staging's theme doesn't have: its widgets go to *Inactive widgets*.

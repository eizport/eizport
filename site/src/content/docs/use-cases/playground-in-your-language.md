---
title: "Playground in your language"
description: "A demo of an English site shown in Portuguese in WordPress Playground: the dashboard, the theme and the plugins translated, from one link."
---

## The scenario

The site is built in English, but the client is Brazilian and wants to see the new page and its admin in Portuguese. A Playground link opens in English, and switching the language there means downloading translations by hand on a throwaway site.

## The steps

1. In **Users → Profile**, your language is *Português do Brasil* (the site's stays English).
2. **Tools → Export**: check the page and what it needs.
3. **Details → Format: Playground**, then **Playground options**:
   - **Language → Your language (Português do Brasil)**;
   - **Themes and plugins**: the site's theme and the plugins the page uses.
4. **Export**, then **Open in Playground** on the copy in [Copies](../../settings/copies/).

## What the client sees

Playground installs the themes and plugins, then sets the site's language to Portuguese and fetches the translations of WordPress and of everything from the WordPress.org directory. A custom plugin's translations come from this site, inside the file. The dashboard, the theme's texts and the plugins' screens open in Portuguese.

## What it doesn't

- Only languages installed on this site are offered (Settings → General or your profile installs them).
- A theme or plugin without a translation stays in English.

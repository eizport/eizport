---
title: "Client demo in Playground"
description: "A new page shown to a client in WordPress Playground: one link that opens right on that page, with the site's plugins as they are."
---

## The scenario

A new landing page is ready locally and the client wants to see it before it goes to staging. Sending a link to a local site doesn't work, and a Playground that opens on the dashboard, with every plugin switched on (including the ones the site keeps off), isn't what the client should see.

## The steps

1. On the local site, **Tools → Export**: check the page (and what it needs: menus, the look…).
2. **Details → Format: Playground**, then **Playground options**:
   - **Landing page → A page**: the new landing page;
   - **Themes and plugins**: the site's theme and the plugins the page uses.
3. **Export**, then **Open in Playground** on the copy in the plugin's [Copies](../../settings/copies/): that's the link to send, on playground.wordpress.net.

## What the client sees

Playground builds the site in the browser and opens **on the landing page** — the page goes in the blueprint as `/?pagename=<its path>`, so it's found whatever ID it gets there. The plugins are on or off as they are locally: a plugin the site keeps inactive is installed but stays off.

## What it doesn't

- The link opens a throwaway site: what the client changes there is lost when the tab closes.
- Premium plugins go inside the file: share it only where their licenses allow.

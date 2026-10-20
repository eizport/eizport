# Eizport — site

The product site: landing page and docs, at <https://eizport.github.io/>. [Astro](https://astro.build) 7, static, no client JavaScript; docs in Markdown (as [Bootstrap’s site](https://github.com/twbs/bootstrap/tree/main/site) does). Not part of the plugin: `site/` is left out of its zip.

```bash
cd site
npm ci
npm run dev          # http://localhost:4321/
npm run build        # dist/
npm run preview      # serves dist/
npm run check        # astro check (types)
npm run lighthouse   # 4 categories × mobile/desktop on the page templates (needs: npx astro preview --host 127.0.0.1)
npm run brand        # rebuilds the brand files (see BRAND.md)
```

`SCHEME=light npm run lighthouse` (or `dark`) forces the color scheme; `npm run lighthouse -- https://eizport.github.io/` checks the live site.

## Where things are

| Path | What |
| --- | --- |
| `src/content/docs/<group>/<page>.md` | Docs pages (frontmatter: `title`, `description`, `toc`) |
| `src/data/sidebar.ts` | Docs navigation: order, groups, slugs (the build fails if a page is missing) |
| `src/data/landing.ts` | The landing page’s content (also its Markdown version) |
| `src/data/donate.ts` | Donation options (GitHub Sponsors, Pix, PayPal, Ko-fi, Apoia.se): each shows on /donate/ — and “Support” in the header — only once filled in |
| `src/libs/site.ts` | Name, links, and what `../readme.txt` says: version, requirements, FAQ, changelog |
| `src/pages/` | Landing, docs routes, `llms.txt`, `llms-full.txt`, Markdown versions, `robots.txt`, 404 |
| `src/components/`, `src/layouts/`, `src/styles/` | See [DESIGN.md](DESIGN.md) |
| `src/assets/screenshots/` | Screenshots (WebP sources; Astro makes AVIF/WebP sizes) |
| `brand/`, `scripts/brand.mjs` | Logo files and their builder — see [BRAND.md](BRAND.md) |

## Writing docs

Add the page to `src/data/sidebar.ts` and create `src/content/docs/<group-slug>/<title-slug>.md`. Link other pages relatively (`../../formats/wxrz-bundle/`) and images from `src/assets/screenshots/`. FAQ and Changelog come from `readme.txt`: edit them there.

## Screenshots and the demo video

Captured by scripts, never by hand, on two WordPress Studio sites made for it (not the development site):

```bash
npm run demo-sites                 # creates/refreshes eizport-demo (Eiz Coffee) and eizport-demo-target (empty)
npm run screenshots                # all screenshots → src/assets/screenshots/*.webp + .wordpress-org/screenshot-N.png
npm run screenshots -- --changed   # only those whose plugin files changed since they were captured
npm run video                      # the demo video → public/video/demo.* and video/out/ (for YouTube)
```

[`SCREENSHOTS.md`](SCREENSHOTS.md) maps each feature and screen to its image, where it appears and which plugin files it depends on (from `src/data/screenshots.ts`); `npm run check` fails when they disagree with the site. The video follows [`video/STORYBOARD.md`](video/STORYBOARD.md): English captions are `public/video/demo.en.vtt`; translations live outside the repo, in `video/local/captions.<lang>.txt`. Needs the Studio CLI and Google Chrome (Playwright drives the installed Chrome).

## Deploy

`.github/workflows/pages.yml` builds and publishes to GitHub Pages on every push to `main` that touches `site/` or `readme.txt`.

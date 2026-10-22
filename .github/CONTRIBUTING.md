# Contributing to Eizport

How Eizport's branches and releases work.

## Branches

| Branch | What goes in | Version in `eizport.php` |
|---|---|---|
| `trunk` | every change, squash-merged from a feature or fix branch | the next `X.Y.0-dev` |
| `feature/<issue>-<name>`, `fix/<issue>-<name>` | one change, branched from `trunk` (e.g. `feature/7-menus`) | — |
| `release/X.Y` | fixes only, after the release is cut | `X.Y.0-rc.N`, `X.Y.0`, then `X.Y.1`… |

readme.txt's `Stable tag` is always the last final release, also on `trunk`.

## Day to day

```sh
git switch trunk && git pull
git switch -c feature/7-menus
# work, add changelog/7-menus (below) and commit; before each commit, from the plugin folder:
composer check && npm run readme && bin/plugin-check.sh studio wp && (cd site && npm run check)
git push -u origin feature/7-menus
gh pr create --base trunk           # "Fixes #7"; squash-merged: one commit per change
```

## Changelog entries

Every change to what ships (`includes/`, `src/`, `routes/`, `templates/`,
`eizport.php`, `uninstall.php`, `readme.txt`) adds one file to `changelog/`,
named after its branch without the prefix (`changelog/7-menus`); the CI checks
it. Nobody edits readme.txt's Changelog by hand, so branches never conflict
there.

```
Significance: minor
Type: add

Export navigation menus: classic menus with their theme locations, and block navigations.
```

- `Significance`: `patch` (a fix), `minor` (a feature) or `major` (a breaking change). The release command warns when the version is smaller than the entries need.
- `Type`: `add`, `fix`, `update` (readme's *Added:*, *Fixed:*, *Changed:*) or `dev` (tooling, tests, docs: left out of the readme).
- The entry: one sentence for users, starting with a verb ("Export navigation menus…").

`eizport release` adds the entries to the version's Changelog in readme.txt
(a pre-release adds them, the release adds them and deletes the files).

## Releasing X.Y.0

1. **Cut the release branch** when the version's changes are on `trunk`, and
   move `trunk` to the next dev version:
   ```sh
   git switch -c release/1.1 trunk && git push -u origin release/1.1
   git switch trunk
   studio wp --require=wp-content/plugins/eizport/bin/release.php eizport release 1.2.0-dev
   ```
2. **Fixes** land on `trunk` first, then on the release branch with
   `git cherry-pick -x <commit>`. A fix that only makes sense on the release
   branch is committed there and cherry-picked to `trunk`. Release branches
   are never merged back.
3. **Screenshots and the demo video**, once per version on the release
   branch (features don't recapture them: they all touch the same screens).
   From `site/`: `npm run demo-sites`, `npm run screenshots` (and
   `-- --locale br=<eizport-ptb>`), `npm run video` when the scenes changed.
   A feature PR only updates the docs and `src/data/screenshots.ts` when a
   screen shows something new.
   Screenshots live in git (small; the site and the WordPress.org assets
   need them in the checkout). The demo video doesn't: `npm run video`
   uploads `demo.mp4` and `demo.webm` to a `media-1.1.0` pre-release (never
   "latest", it isn't a plugin release) in this repository, or in the
   translation's for `--locale`, and points `site/media.json` at it; the
   site downloads it (`npm run media`, and `npm run translations` for each
   language). To upload again without recording: `npm run media -- --upload`.
   **Versions checked**, on the release branch too: `IMPORTER_TESTED` in
   `includes/import.php` is the newest WordPress Importer the tests passed
   with (CI installs the latest); `Bundle\REQUIRES` in `includes/bundle.php`
   rises, with `FORMAT_VERSION`, only when the bundle layout changes in a way
   an older Eizport would read wrong. The manifest's JSON Schema is per
   version, as schemas.wp.org does with theme.json: `eizport release X.Y.0-dev`
   copies the newest into `schemas/X.Y/manifest.json` (the manifest's
   `$schema`, `Bundle\schema_url()`), which changes only until X.Y.0 is
   released; the site publishes them all, and the newest as
   `schemas/trunk/manifest.json`.
4. **Optional pre-release** for testing, on GitHub only (not the latest
   release, not on WordPress.org): write the `= 1.1.0 =` Upgrade Notice in
   readme.txt (the Changelog comes from `changelog/`), then
   `eizport release 1.1.0-rc.1` from `release/1.1`.
5. **The release**, from `release/1.1`: `eizport release 1.1.0` (or `patch`
   from `1.1.0-dev`/`-rc.N`). It bumps the version and Stable tag, runs the
   checks, commits, tags `1.1.0`, moves `latest` and `stable`, and pushes. The
   tag runs `.github/workflows/release.yml`: the GitHub release and the
   WordPress.org deploy. It also moves the `site` branch onto `1.1.0`, which
   publishes the site (below), and tags each translation repository (eizport-ptb)
   `1.1.0` and moves its `site` branch to its `trunk`: translate the docs of
   `1.1.0` there before releasing.
6. **Back to `trunk`:** cherry-pick the `chore: release 1.1.0` commit, keeping
   `trunk`'s dev version and taking readme.txt's Changelog, Upgrade Notice and
   Stable tag.

Patch releases (`1.1.1`) come from the same `release/1.1`, for bugs that break
something for users; everything else waits for the next minor.

## Fixing the site between releases

The site shows the released plugin: `.github/workflows/pages.yml` publishes
the `site` branch, the latest release plus the site fixes made after it
(`trunk`'s docs describe what isn't released yet). A fix to the site alone
(a typo, a link, a button) doesn't wait for a release:

1. A PR into `trunk` that changes only `site/`, as usual (no changelog entry).
2. Once it's squashed: `git switch site && git cherry-pick <commit> && git push`.
   The push publishes the site; the workflow refuses to if anything but
   `site/` changed on `site` since the release.

A release moves `site` onto itself and replays the fixes it doesn't bring
yet (those it already has, from `trunk`, drop out). If one no longer
applies, `eizport release` says what to run by hand.

Translations work the same way. The published site downloads each
translation repository's `site` branch (the translation of the released
version; `npm run translations -- --ref site`), everything else its `trunk`.
A fix to a published translation goes into its `trunk`, then is
cherry-picked onto its `site`; pushing its `site` rebuilds the site.

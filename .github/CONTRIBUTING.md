# Contributing to Eizport

How Eizport's branches and releases work.

## Branches

| Branch | What goes in | Version in `eizport.php` |
|---|---|---|
| `trunk` | every change, squash-merged from a feature or fix branch | the next `X.Y.0-dev` |
| `feature/<name>`, `fix/<name>` | one change, branched from `trunk` | — |
| `release/X.Y` | fixes only, after the release is cut | `X.Y.0-rc.N`, `X.Y.0`, then `X.Y.1`… |

readme.txt's `Stable tag` is always the last final release, also on `trunk`.

## Day to day

```sh
git switch trunk && git pull
git switch -c feature/menus
# work and commit; before each commit, from the plugin folder:
composer check && npm run readme && bin/plugin-check.sh studio wp && (cd site && npm run check)
git push -u origin feature/menus
gh pr create --base trunk           # squash-merged: one commit per change
```

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
3. **Optional pre-release** for testing, on GitHub only (not the latest
   release, not on WordPress.org): write the `= 1.1.0 =` Changelog and
   Upgrade Notice in readme.txt, then
   `eizport release 1.1.0-rc.1` from `release/1.1`.
4. **The release**, from `release/1.1`: `eizport release 1.1.0` (or `patch`
   from `1.1.0-dev`/`-rc.N`). It bumps the version and Stable tag, runs the
   checks, commits, tags `1.1.0`, moves `latest` and `stable`, and pushes. The
   tag runs `.github/workflows/release.yml`: the GitHub release and the
   WordPress.org deploy.
5. **Back to `trunk`:** cherry-pick the `chore: release 1.1.0` commit, keeping
   `trunk`'s dev version and taking readme.txt's Changelog, Upgrade Notice and
   Stable tag.

Patch releases (`1.1.1`) come from the same `release/1.1`, for bugs that break
something for users; everything else waits for the next minor.

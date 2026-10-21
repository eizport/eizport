# Demo video — storyboard

A short, narrated, end-to-end demo (about 2 minutes) for the site's hero,
YouTube and Apoia.se. `npm run video` records it for real on the demo Studio
sites (`npm run demo-sites`): **Eiz Coffee** (the source), **New Site** (empty,
the target) and **Client Site** (WooCommerce only: the "as plugin" .zip
installs Eizport and the importer there). Nothing is mocked: the screens, the
files, the imports and Playground are the plugin's own. Waits (exporting,
installing, importing, Playground booting) are sped up. The "as plugin"
scene's Install Now takes Eizport from its GitHub release: the .zip is made
with the local Install Now from GitHub patch (see `scripts/video.mjs`) until
Eizport is in the WordPress.org directory.

- **One video, captions apart.** No text is burned into the picture: captions
  are WebVTT files, shown by the site's player and uploaded to YouTube as
  subtitle tracks. English lives here; another language lives entirely in
  its translation's repository (`video/captions.txt`, one `id: text` per
  line, and `video/voice.json` for the narration), and is recorded as its
  own video there (`npm run video -- --locale <code>=<repo>`).
- **Sound:** each caption is narrated (macOS `say`) over a soft synthesized
  ambient track, ducked under the voice.
- **Output:** `public/video/demo.{mp4,webm}`, `demo.en.vtt` and
  `demo-poster.{webp,jpg}` (the site); `video/out/` (git-ignored) has the files
  to upload to YouTube: the same `demo.mp4`, the `.vtt` files and a
  thumbnail.
- **Format:** 1920×1080, 30 fps, H.264 MP4 and VP9 WebM.
- **Same aspect everywhere:** every screen captured for the video — the
  browser, a terminal scene, a still — has the output's aspect (16:9,
  recorded at 1280×720 and scaled 1.5×), so nothing is stretched or
  letterboxed. A new scene keeps that viewport; changing the output means
  changing the capture with it (`OUT`, `W`, `H` in `scripts/video.mjs`). The
  recorder stops with an error when a frame has another aspect. Headless
  Chrome captures its window, not the page: the browser is launched with a
  window of the viewport's size (`--window-size`) and the scale factor, or
  the frames come out 1280×633.

**Editing:** change a caption or a pause here and run `npm run video`. The
recorder reads each `## N. id — Title` scene: `Caption:` is the subtitle and
`Hold:` the seconds kept still after the actions so it can be read. Changing
what a scene *does* means changing the scene with the same `id` in
`scripts/video.mjs`. When a feature shown here changes, record again (see
`SCREENSHOTS.md`).

## 1. intro — Brand

- On screen: the Z mark and "Eizport" on the brand blue.
- Caption: Move your WordPress content. Not your mess.
- Hold: 3

## 2. choose — Pick what moves

- On screen: Eiz Coffee → Tools → Export. Pages → Choose items → check Home (it wears the Homepage badge) → Export 1 item. Options opens filtered to Basics → Export Basics.
- Caption: Pick exactly what moves: the homepage, and the settings that go with it.
- Hold: 2.5

## 3. download — One file

- On screen: Format WXRZ with Media files on → Export → "… is ready." → Download.
- Caption: One file with the content, its images and its settings.
- Hold: 2.5

## 4. import — Import it anywhere

- On screen: New Site → Tools → Import → WordPress → choose the .wxrz → Upload file and import → Submit → "All done."
- Caption: On another site, the WordPress Importer you already know takes it.
- Hold: 2.5

## 5. result — It arrives complete

- On screen: New Site's front page: Home is the homepage, with its images.
- Caption: The homepage is still the homepage — images included. No search-and-replace.
- Hold: 3.5

## 6. as-plugin — Or as a plugin

- On screen: Eiz Coffee → Export → Details → **As plugin** → Export → Download. Client Site → Plugins → Add Plugin → Upload Plugin → the .zip → Install Now → Activate Plugin → "open the import screen". **Install Now** installs and activates Eizport on the same screen → Continue → **Install Now** installs and activates the WordPress Importer → **Run import** → "Imported".
- Caption: Or send it as a plugin: installed on the other site, it brings what it needs and imports itself.
- Hold: 2.5

## 7. playground-link — Playground, from a local site

- On screen: Eiz Coffee → Export → Format Playground → Export. Settings → Eizport → Copies → Open in Playground: playground.wordpress.net loads the copy straight from localhost and the site runs in the browser.
- Caption: Or the whole site as a WordPress Playground .zip — a copy made on localhost opens on playground.wordpress.net.
- Hold: 3

## 8. playground-cli — Or offline

- On screen: a terminal runs `npx @wp-playground/cli server --blueprint=eiz-coffee.zip` (the default port, 9400: it must be free while recording); the same site opens on 127.0.0.1:9400.
- Caption: Or run it offline with one command.
- Hold: 2.5

## 9. outro — Free

- On screen: the Z mark, "Free and open source", eizport.github.io.
- Caption: Free and open source — eizport.github.io
- Hold: 3.5

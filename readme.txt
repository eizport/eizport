=== Eizport ===
Contributors: eizport, camaleaun
Donate link: https://eizport.github.io/donate/
Tags: export, import, wxr, migration, media
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export content with its media files in one .wxrz bundle and import it with the WordPress Importer, without downloading the media again.

== Description ==

Eizport gives Tools → Export a new screen, built like WordPress's newest admin screens, and extends the WordPress Importer. Prefer WordPress's Export screen untouched? One setting moves the new one to its own Tools item; another brings back the classic Export screen with the same choices added.

**The Export screen**

Built with DataViews and DataForm, it runs on the plugin's abilities:

* **One list:** the content types, Attachments, Menus, the site's look and Options. Check what to export; nothing checked exports all content.
* **The site's look:** the Customizer's theme mods (logo, header and background images, colors) and custom CSS, the Site Editor's global styles, global CSS, templates and template parts (block themes; a template replaces the same one on the other site, with its navigations and images) and the widgets (classic and block), each on its own row, shown only when the active theme has them. The images they use come along, and on import they point to the imported ones; theme mods are merged into the other site's.
* **Menus:** classic menus with their items and theme locations, and block navigations, on their own row (the menus in theme locations and the navigations the theme uses are suggested; the pages a menu links to too). On import, menu items point to the imported pages, each menu goes back to its location, and navigation blocks point to the imported navigations.
* **Items in a side panel:** each type's items with search, filters and a checkbox per item; what you check joins the export at once, and a Selection filter shows the ones chosen. Items show their roles (Homepage, Posts page, Shop page…).
* **Options:** one by one in a side panel (Basics suggested). Settings that point to the items chosen (homepage, posts page, privacy policy page, site icon, WooCommerce pages) are suggested in the Options row and go with the export once it's checked.
* **The file in a panel above the list,** summed up in a line until opened: format (WXR XML, or WXRZ once media files or settings go with it, or a Playground .zip), file name, media files, Playground themes and plugins.
* **Save as default, Reset and Clear defaults:** start with the same choices next time (yours, or the site's), or go back to them.

**On Tools → Import → WordPress**

* .wxrz and .zip bundles (and gzipped WXR files) are accepted, with their media and content files.
* The importer looks like the Export screen — a header with the step's main button, the steps in cards, a file button in the admin's language — unless Settings → General turns it off.

**On the classic Tools → Export**

* **Several content types at once:** check pages and products, say, without posts — or keep "All content". Posts, pages, media, products and other custom post types.
* **By filters or by selection, per type:** WordPress's filters for posts, pages and media; for products and other custom post types, the same kind (dates, status, author, and each taxonomy, like product categories). Or check the exact items in a list (a tree for pages), with a box to filter it. Selected items bring their attachments and their children of other types, like a product's variations; product reviews come with their product.
* **Bundle (.zip, or .wxrz):** download one compressed file with the WXR and, optionally, more. Without it, the export is WordPress's usual .xml file, named as usual.
* **Media used in content:** items exported bring their attached media and featured images, as WordPress does, and also the images, galleries, covers and files their content uses without being attached (Settings → Export → "Only attached media" turns it off).
* **Save these choices as default,** and start with them next time.

**Bundle options**

* **File name:** of your choice; left empty, it's WordPress's usual `<site name>.WordPress.<date>`.
* **Include media:** the files of the exported attachments (images, PDFs…), so the importer doesn't download them from the old site.
* **Content as .html files** (on by default, in Settings → Export): each post's content in its own `post_content/<slug>.html` file instead of inside the WXR — easy to read, edit and keep under version control. A page built with Elementor goes as `<slug>.json`, its design in Elementor's format.
* **Include settings:** groups of options (Basics, suggested; Reading, General, Permalinks, Discussion, Media, privacy page, site logo and icon, WooCommerce pages) and any other option by name, in an `options.json`. When the homepage or the posts page is exported, they can be set as such on import. IDs in the settings (homepage, logo, menus…) are mapped to the imported items. Site URLs, the admin email, keys, active plugins, roles and transients are never exported.
* **Include a Playground blueprint:** the download becomes a .zip ready for [WordPress Playground](https://wordpress.org/playground/): `blueprint.json`, the plain WXR (.xml), the media and content files (as checked above) and the themes, plugins and must-use plugins you check that aren't in the WordPress.org directory (a child theme brings its parent; the directory's are installed from there, in the version this site has). Playground installs them, activates the theme, sets the language you choose (the site's by default, yours or English) and imports the content. After the download, the screen shows the command that runs it (`npx @wp-playground/cli@latest server --blueprint=./<file>.zip`).
* **Keep a copy to open by link:** with the blueprint, a copy of the .zip stays on the site for a while (a day by default), and a link opens it on playground.wordpress.net.

**Settings**, from the plugin's row in Plugins (built with DataViews and DataForm)

* **General:** replace Tools → Export (on by default; off, WordPress's Export screen stays as it is and the new one is at Tools → Eizport), or use the classic Export screen; whose saved defaults are (each user's, or the site's), and clearing them; how long copies are kept (1 hour, 12 hours, 1 day, 1 week or until deleted).
* **Export:** only attached media; when linked settings come along (when items are checked, on export, both, never); bundles as .zip instead of .wxrz; content as .html files.
* **Copies:** the copies kept (bundles, WXR files and Playground .zip files), listed like the Media Library — search, sort, open in Playground, copy the link, download, import, and delete one, many or all.

**On the WordPress Importer**

Upload a .wxrz (or .zip) bundle like any WXR file. Its attachment files are taken from the bundle, so nothing is downloaded from the old site, which doesn't even need to be online. Attachments, image sizes and featured images are created by the importer as usual. Content files are put back into the WXR before the import starts. Gzipped WXR files (.xml.gz) are accepted too.

**The .wxrz format**

A .wxrz file is a gzipped tar archive:

* `manifest.json` — format version, the WXR file name and which attachment URL each media file belongs to;
* the WXR file;
* `uploads/…` — the attachment files, in the same folders as on the exporting site;
* `post_content/<slug>.html` — post contents, when exported as files;
* `options.json` — settings, set when the import ends.

By default (Settings → Export → "Bundle as .zip") bundles are plain .zip files, which any computer opens; turned off, they are .wxrz files with the same entries, which any tool that reads `.tar.gz` files opens.

**Abilities**

The same export and import, for AI agents and scripts, through the WordPress Abilities API (`/wp-json/wp-abilities/v1/abilities/<name>/run`, and as tools with the MCP adapter):

* `eizport/get-export-options` — what can be exported: content types with their terms, statuses and counts, settings groups, themes and plugins, the homepage and posts page.
* `eizport/get-items` — a page of one type's items (search, sort, the same filters as the export), to pick what to export.
* `eizport/get-options` — the site's options one by one (search, `group`, pages), with their size and whether they hold post IDs; options never exported are left out.
* `eizport/export` — exports to a temporary copy (a .wxrz or .zip bundle, WordPress's plain .xml with `bundle: false`, or a Playground .zip with `blueprint`) and returns its link: `types` (post type => `ids` or `filters`), `media`, `media_used`, `post_content`, `zip`, `options` (`groups`, `names`, `front_pages`, `linked`, or `all`), `blueprint` (`themes`, `plugins`, `mu_plugins`, `landing_page`, `language`), `filename`.
* `eizport/get-export-defaults`, `eizport/save-export-defaults` and `eizport/clear-export-defaults` — the choices saved as default (the site's need `manage_options`).
* `eizport/get-copies` — the copies kept, with their links and expiry.
* `eizport/delete-copies` — deletes copies by `names`, or `all`.
* `eizport/import` — imports a `copy` kept on this site, or an `attachment_id` of a .wxrz, .zip or .xml file, with media and settings.
* `eizport/get-settings` and `eizport/update-settings` — the plugin's settings (see Settings above); `manage_options`.

They follow the same rules as the screens: the `export` capability for exporting and copies, `import` for importing. Only one export runs per request.

**For developers**

* `eizport_post_content` — filter the content an imported item gets from a bundle (`$content`, `$item` with original `id`, `type` and `slug`, `$dir`).
* `eizport_wxrz_max_bytes` — the largest size a .wxrz may expand to when imported (default 512 MB).
* `eizport_playground_blueprint` — filter the blueprint of a Playground .zip (`$blueprint`, `$wxr_name`).
* `eizport_playground_mu_plugins` — filter the must-use plugins a Playground .zip can take (`$choices`, file => plugin data).
* `eizport_linked_options` — options that point to a post, with its role: `option => array( 'label' => 'Shop page', 'with' => array( … ), 'when' => array( option => value ) )`. They get the imported post's ID on import, show on the items, and come with them.
* `eizport_option_groups` — the groups of options offered (`key => array( 'label' => 'Name: what it holds', 'options' => array( … ) )`).
* `eizport_denied_options` — more options never exported (a plugin's keys, tokens or version numbers); WordPress's own can't be removed.
* `eizport_media_used` — attachments a post uses beyond its content (`$used`, `$post`), like a product gallery.
* `eizport_content_types` — the post types the Export screen offers as rows (a plugin takes out its own internal types).
* `eizport_look_items`, `eizport_look_data`, `eizport_look_suggested` — a row of a plugin's own next to the look's: its items, its entry in the options file, and what the posts chosen suggest.
* `eizport_look_media` — the attachments a plugin's look row uses.
* `eizport_content_file` — a post's content file: .html, or a page builder's design as .json (`$file`, `$post`).
* `eizport_advanced_custom_fields_id_types` — the ACF field types whose values are IDs an import maps (type => post, term or user).
* `eizport_register_compat` — action, after all plugins load, where compatibilities hook those filters. The WooCommerce, Advanced Custom Fields and Elementor ones ship with the plugin (`includes/compat/woocommerce.php`: pages, a group, version options, product galleries; `includes/compat/advanced-custom-fields.php`: the ACF row; `includes/compat/elementor.php`: the Elementor row and designs); a plugin can replace one with its own: `remove_action( 'eizport_register_compat', 'Eizport\Compat\WooCommerce\register' );`.
* `eizport_post_id_options` — options that hold a post ID (by default the linked options), mapped to the imported post on import.
* `eizport_selection_limit` — how many items each "By selection" list shows (default 1000, the latest).
* `eizport_copy_lifetime` — how long a Playground copy lasts, in seconds (default: the one chosen in the settings; 0 keeps copies until deleted).
* `wp eizport import <file>` — WP-CLI command that imports a .wxrz (or .xml.gz, or .xml) with the WordPress Importer, attachments included.

== External services ==

Eizport doesn't run a service of its own and sends nothing to its authors. A few features rely on WordPress.org services, as WordPress itself does, or on links you choose to open:

* **WordPress.org plugin and theme API** (api.wordpress.org). When you build a Playground .zip, Eizport asks WordPress for the update information it already keeps about your plugins and themes, to know which ones come from the directory: those are installed from there instead of being copied into the .zip. If WordPress doesn't know one of them yet, it runs its usual update check, which sends the list of installed plugins and themes with their versions, the WordPress and PHP versions, the site's language and its address. On the other site, a plugin exported "as plugin" asks the same API whether Eizport is in the directory, when its screen is opened and Eizport isn't installed: the request carries the name "eizport" and what WordPress sends with every such request (its version, the site's language and address). WordPress.org privacy policy: https://wordpress.org/about/privacy/
* **WordPress.org downloads** (downloads.wordpress.org). "Install Now" installs Eizport or the WordPress Importer from there, through WordPress's own installer, only when you click it. Inside WordPress Playground, the blueprint installs the directory's plugins and themes, in the versions your site has, from there too, and, in a language other than English, Playground fetches the translations of WordPress and of those plugins and themes from there. Same privacy policy.
* **WordPress Playground** (playground.wordpress.net), a WordPress.org project that runs WordPress in the browser: https://wordpress.org/playground/. "Open in WordPress Playground" opens it in your browser, only when you click it, with the link of a copy kept on your site; Playground then downloads that .zip, with the content you exported, from your site into your browser. The `$schema` address in blueprint.json is only a reference for editors; nothing is requested from it. Same privacy policy.
* **The site the content came from.** Like the WordPress Importer, an import downloads the media files a plain WXR points to, or that a bundle doesn't include, from their original addresses. The files a bundle includes are served from your own server.
* **GitHub** (github.com/eizport/eizport). When Eizport isn't installed, a plugin exported "as plugin" also shows a "Download Eizport" link to its releases there; nothing is sent unless you click it. Terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service — privacy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

== Installation ==

1. Install and activate Eizport. Exporting works right away, from **Tools → Export**.
2. To import, install the **WordPress Importer**: on **Tools → Import**, click "Install Now" under WordPress, then "Run Importer".

== Frequently Asked Questions ==

= Is it safe to import a bundle from someone else? =

Only import files you trust, as with any WXR file. When a bundle is opened, only the files its manifest lists are written, only under a private folder, only with file types WordPress allows to be uploaded (never PHP), and never outside that folder. The folder is deleted when the import ends or is abandoned.

= Which plugins are supported? =

WooCommerce, with its own support: products, orders and coupons, its pages as linked settings, product galleries, the products its blocks show (suggested) and product reviews. Advanced Custom Fields (ACF), with its own support too: its post types, taxonomies and field groups on a row of their own, in ACF's export format, imported before the content, and the fields that store IDs (image, file, post object, relationship, page link, taxonomy, user) remapped on import. Elementor, with its own support as well, through Elementor's own exporter and importer: its site settings (the Kit) and templates on a row of their own, each page's design as a .json file, and on import the images, templates and links inside them pointing to the items on the other site. The docs explain what to expect: https://eizport.github.io/docs/developers/compatibilities/

= What is an "as plugin" file? =

A plugin .zip that holds the bundle. Install it on the other site (Plugins → Add Plugin → Upload Plugin) and click **Run import** on its row on the Plugins screen: it checks that Eizport and the WordPress Importer are there, imports the bundle with one click, and then offers to delete itself.

= Does it work on multisite? =

Yes. Activate it on the network or on each site: every site exports and imports on its own (its files in its own uploads folder, its own saved defaults), links are mapped between subsites, and uninstalling cleans every site. Site administrators export and import; WordPress filters imported content for those who can't post unfiltered HTML. Docs: https://eizport.github.io/docs/getting-started/multisite/

= Is there a size limit? =

Uploads are limited by your server's PHP settings (`upload_max_filesize` and `post_max_size`), which apply to the compressed file. A bundle may expand to 512 MB by default; developers can change it with the `eizport_wxrz_max_bytes` filter.

= Does it send data anywhere? =

Not to its authors: Eizport has no service of its own. It uses WordPress.org's plugin and theme information and downloads, as WordPress does, and opens WordPress Playground only when you click to; see "External services" for what is sent and when. When importing a bundle, the importer's attachment downloads are answered from the bundle on your own server.

= What does the Playground blueprint do? =

It installs the plugins and themes in the .zip, sets the language chosen (with its translations), writes the must-use plugins chosen (with the folders they load) and imports the WXR with Playground's own importer. With media or content files, they are written into Playground first and a temporary must-use plugin answers the importer's downloads from the media files and puts each `.html` file into its post, and a second one sets the settings when the import ends, with their IDs mapped; all of it is removed after the import. The WordPress Importer is installed by Playground itself, from WordPress.org.

= Who can download a Playground copy? =

Anyone with its link: the file name ends in a random 16-character token, so it can't be guessed, and it is served only until it expires (a day by default; see the plugin's Settings, where it can also be "until deleted"), then deleted. On a site on your computer (like `localhost`), the link only works in your own browser. Don't keep copies of content that must stay private.

= Where are the files kept? =

In `wp-content/uploads/eizport/tmp/`: Playground copies, and the folders of bundles being imported. Everything there is temporary, and the `tmp` folder itself is deleted once it is empty. Uninstalling the plugin deletes `uploads/eizport/` and the plugin's settings.

= Where is the source code? =

The PHP is in the plugin, readable. The Export and Settings screens' JavaScript in `build/` is compiled; each file ships next to its unminified version, and its source (TypeScript, in `routes/` and `src/`) is at https://github.com/eizport/eizport — build it with `npm ci && npm run build` (@wordpress/build).

== Screenshots ==

1. Tools → Export: choose content types or exact items; the products and settings they use are suggested.
2. Pick the exact items to export; the homepage, shop and checkout wear badges.
3. Settings travel too: the ones your pages point to are suggested; add any other one by one.
4. Export a WordPress Playground .zip with the theme and plugins you choose.
5. Set it once: media, linked settings, .zip bundles and saved defaults.
6. Every export is kept as a private copy for a while: download it again, open it in Playground or import it.

== Upgrade Notice ==

= 1.1.0 =
Menus, templates, the site's look, Elementor and ACF on rows of their own; multisite; Playground language and must-use plugins; .zip bundles by default. Safer imports: options outside the groups and updating existing items wait for your OK.

= 1.0.1 =
Fixes "As plugin: allow importing again", which wasn't saved, and documents the external services the plugin uses.

= 1.0.0 =
Initial release.

== Changelog ==

= 1.1.0 =
* Added: Playground options: a Language group — the Playground .zip opens in the site's language (the default), yours or English, with the translations of WordPress, the themes and the plugins (fetched from WordPress.org, or this site's inside the .zip).
* Added: Block themes: templates and template parts edited in the Site Editor on rows of their own (Editor templates, Editor template parts), item by item with a preview. They travel in the WXR with the images they use; on import each replaces the same template of the same theme instead of adding a copy ("Header 2"), and its navigations, images and links point to the ones on the other site.
* Added: Elementor, with its own exporter and importer: a row of its own exports the site settings (the Kit) and templates in Elementor's formats; pages built with Elementor carry their design as post_content/<slug>.json; on import, Elementor's own import brings them in, with the images the bundle brought, templates and links pointing to the items there, and a later delivery updates instead of adding copies; also in Playground.
* Added: Advanced Custom Fields (ACF): a row of its own exports its post types, taxonomies and field groups in ACF's own export format, imported before the content (updated by key); the fields that store IDs (image, file, post object, relationship, page link, taxonomy, user) are remapped on import, also in Playground; field groups, linked posts and the media of image and file fields are suggested or come along.
* Added: Bundles say which Eizport made them and the oldest that reads them: a bundle that needs a newer Eizport is refused with a message that says which version (on the upload, wp eizport import, the import ability and a bundle plugin's screen, which offers Update now); bundles from 1.0.x still import. The WordPress Importer must be 0.9.6 or newer; a version newer than the tested one imports with a warning. The manifest names its JSON Schema (https://eizport.github.io/schemas/1.1/manifest.json): editors check a manifest edited by hand, and the import checks it against the same schema.
* Added: Multisite: each site of a network exports and imports on its own — saved defaults per site, files in the site's uploads, links mapped between subsites — and uninstalling cleans every site.
* Added: Export the site's look on rows of its own, item by item — Customizer theme mods and custom CSS rules, the Site Editor's style groups and CSS rules, widgets (classic and block) — with a preview of each and the media they use. On import, CSS rules are added to the other site's CSS (at the end, at the start, or replacing it) between head/tail comments named after the file; theme mods, style groups and widgets join the other site's instead of replacing them.
* Added: Export navigation menus on their own row — classic menus with their items and theme locations, and block navigations — and bring them back on import, pointing to the imported pages.
* Added: Playground options: must-use plugins — the ones chosen go in the Playground .zip with the folders they load, and the blueprint writes them before the import (not the platform's loaders nor Eizport's own).
* Added: Playground options: the button (renamed from "Themes and plugins") also sets where the Playground .zip opens — the home page, the dashboard, a page of the site (as a relative path) or a path —, its two groups open one at a time.
* Changed: The plugin's row on the Plugins screen keeps only Settings: no "Import .wxrz" or "Install the WordPress Importer" link — importing starts from Tools → Import.
* Changed: A bundle plugin's screen is one screen, with or without Eizport: Eizport is one more line in Before importing (Install Now, Activate or Update now, done right there), and what needs it waits — What it holds dimmed, Update existing and Run import disabled. The screen is no longer an item under Tools: Run import, on the plugin's row on the Plugins screen, opens it. Tools → Import lists it too, set apart from the other importers, and shows the list while it waits. "Update existing" names the content types the export holds — on the Export screen, the importer's step 1 and a bundle plugin's screen — and isn't offered with none.
* Changed: The Options row's Basics and Appearance groups no longer carry the theme mods (they have their own row) and now carry the block themes' site logo; imported theme mods are merged into the site's instead of replacing them.
* Changed: What a file holds no longer decides on its own: an import sets the options of the groups, the theme mods and the widgets, and any other option in the file only when whoever imports checks it (step 1 of the importer, a bundle plugin's screen) or names it (--options, the ability's options); WP-CLI and the ability update existing items only when asked. Options, theme mods and widget fields named like secrets (passwords, tokens, API and license keys) are never exported nor listed. On a network, a site administrator's Playground .zip takes only the plugins active on that site and the themes it may use.
* Changed: Bundles are plain .zip files by default (Settings → Export → "Bundle as .zip" now on), inside a plugin made as plugin too; turned off, they are .wxrz as before.
* Fixed: Imported content keeps working with its media and pages: the IDs in Image, Gallery, Cover, Media & Text, File, Video and Audio blocks, in navigation links and in block widgets point to the imported items, not to the other site's.
* Fixed: Links to the source site in imported content — pages, products, categories, navigation links, menus' custom links, widgets — point to this site after an import, in new and updated items.
* Fixed: An attachment the site already has gets its URLs in the imported content pointed to the file here on every import, not only with "Update existing" on.
* Fixed: Checked with the WordPress Importer 0.9.7: an edited image's link to its original is mapped on import instead of dropped (or left pointing at the exporting site's ID with 0.9.6).
* Fixed: The Playground .zip installs a theme or plugin from the WordPress.org directory only in a version the directory has; a development, beta or RC copy goes inside the .zip. Without Eizport, a bundle plugin installs it from the plugin directory.
* Fixed: An export of chosen types carries its terms in full — description, parent and term meta (a product category's thumbnail and display type), with their ancestors — not only their names.
* Fixed: The Playground .zip installs plugins active or inactive as they are on the site, instead of activating them all.
* Fixed: A .zip bundle is read in blocks against its real size (an entry that says it is small but inflates to more is refused, and no entry is loaded whole in memory), and needs PHP's ZipArchive; "Run import" only runs for a bundle plugin active on the site; the Playground landing page only opens a path of the exported site; uninstall also removes the note of imported widgets.
* Fixed: Importing a file made elsewhere is safer for users who may not post unfiltered HTML (a site admin on a network): templates, template parts and widgets from the file go through the same HTML filter as other content, custom CSS needs the right to edit CSS, Advanced Custom Fields items need ACF's own capability and never bring PHP callbacks, an existing page keeps its Elementor design unless the import updates existing items, and a malformed source address in the file never rewrites links.

= 1.0.1 =
* Fixed: Settings → Export → "As plugin: allow importing again" is saved.
* Scripts and styles of the classic Export screen, the importer and the Export and Settings screens are enqueued files.
* Keeping a Playground copy, which writes a file, needs the Export form's nonce.
* readme: an "External services" section on what is sent to WordPress.org, WordPress Playground and GitHub, and when.

= 1.0.0 =
* Initial release.

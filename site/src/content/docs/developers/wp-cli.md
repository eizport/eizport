---
title: "WP-CLI"
description: "Export and import bundles from the command line with wp eizport export and wp eizport import, keep a delivery in a folder to compare, and run the abilities with wp eval."
---

## wp eizport export

Exports as the Export screen does, to a copy kept on the site, and prints its path. Each run is a process of its own, so a script can make as many exports as it needs.

```bash
wp eizport export --types=page:43,product --options=basics,page_on_front
wp eizport export --types=page:43+44 --as-plugin --update-existing --filename=delivery
wp eizport export --types=page:43 --playground --themes=twentytwentyfive --plugins=woocommerce/woocommerce.php --landing=page:43 --language=pt_BR
wp eizport export --types=page --menus=classic:18 --filename=header
```

| Argument | |
| --- | --- |
| `--types=<types>` | Content types, comma-separated: `type:ID+ID` for some items, the type alone for all of it. None: all content |
| `--options=<options>` | Option groups (like `basics`) and option names, comma-separated |
| `--appearance=<parts>` | The site's [look](../../exporting/appearance/): `all`, or rows comma-separated, each whole or with its items' keys after a colon joined by `+` (`theme_mods:custom_logo+background_color,custom_css:r:1a2b3c4d5e`) |
| `--css-mode=<mode>` | How the CSS rules are added on import: `end` (default), `start`, `replace` (the block of the same name; at the end with none) or `replace_all` |
| `--[no-]css-markers` | Head/tail comments around the CSS rules (default: Settings → Export) |
| `--menus=<menus>` | [Menus](../../exporting/menus/): `all`, or `classic:ID+ID` (menu term IDs) and `navigation:ID+ID` (`wp_navigation` IDs), comma-separated. None: no menus (all content already has them) |
| `--[no-]media` | Media files inside; default: yes |
| `--zip` | A `.zip` bundle instead of `.wxrz` |
| `--as-plugin` | A [plugin](../../exporting/as-plugin/) `.zip` that imports its bundle |
| `--update-existing` | Ask the import to [update the items that already exist](../../getting-started/importing/#updating-items-that-already-exist) |
| `--playground` | A [Playground .zip](../../formats/playground-zip/) instead of a bundle (not with `--as-plugin`, `--update-existing` or `--dir`) |
| `--themes=<stylesheets>` | With `--playground`: themes to install, comma-separated (a child theme brings its parent) |
| `--plugins=<files>` | With `--playground`: plugin files to install, comma-separated (`woocommerce/woocommerce.php`), active or inactive as on this site |
| `--landing=<where>` | With `--playground`: where it opens — `home` (default), `admin`, `page:ID` (saved as `/?pagename=<its path>`) or a path (`/shop/`) |
| `--language=<language>` | With `--playground`: the language it opens in — `site` (default), `user`, `en_US` or an installed locale (`pt_BR`) |
| `--filename=<name>` | File name, without extension |
| `--dir=<folder>` | Also write the bundle's files to a folder (see below) |

### A delivery in a folder

`--dir` writes the bundle's files to a folder: `manifest.json`, the WXR **formatted line by line**, `post_content/*.html`, `uploads/…` and `options.json`. The bundle files a previous export wrote there are replaced; other files (`.git`, notes) stay. Keep the folder under version control and `git diff` shows what changed since the last delivery — a title, a price — besides the export's dates.

```bash
wp eizport export --types=page:43,product --filename=delivery --dir=deliveries/site
git -C deliveries diff
```

The WXR in the folder keeps one name from one delivery to the next — `<--filename>.xml`, else `<site>.WordPress.xml`, without the date — so `git diff` shows what changed in it. The folder imports back with `wp eizport import <folder>`.

## wp eizport import

Imports a `.wxrz` or `.zip` bundle, a bundle folder (from `--dir`), a gzipped WXR (`.xml.gz`) or a plain `.xml` with the WordPress Importer — media from the bundle, `post_content/` files back in, options set at the end.

```bash
wp eizport import site.wxrz --user=admin
```

| Argument | |
| --- | --- |
| `<file>` | The file or bundle folder to import |
| `--user=<login>` | Who the posts go to; default: the first administrator |
| `--[no-]update-existing` | Update the items that already exist (same type and slug) instead of adding copies; default: what the bundle asks |
| `--css-mode=<mode>` | How the bundle's CSS rules are added: `end`, `start`, `replace` (the head/tail blocks of the same name this site has; with none, at the end) or `replace_all`; default: what the bundle asks |
| `--[no-]css-markers` | Head/tail comments around them; default: what the bundle asks |

It needs the WordPress Importer plugin installed (it doesn't have to be active).

## Exporting with the ability

`wp eizport export` runs the [`eizport/export`](../abilities/#eizportexport) ability; to pass what it has no flag for (filters, reviews), run the ability itself:

```bash
wp eval '
wp_set_current_user( 1 );
$copy = wp_get_ability( "eizport/export" )->execute( array(
	"types"   => array( "page" => array( "ids" => array( 43 ) ) ),
	"options" => array( "groups" => array( "basics" ) ),
) );
echo is_wp_error( $copy ) ? $copy->get_error_message() : $copy["download_url"];
'
```

The copy is in `wp-content/uploads/eizport/tmp/`; one export per `wp` process.

## Other abilities

Any ability works the same way: `wp_get_ability( 'eizport/get-copies' )->execute()`, `wp_get_ability( 'eizport/delete-copies' )->execute( array( 'all' => true ) )`…

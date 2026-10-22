#!/usr/bin/env bash
# Creates (or refreshes) the three WordPress Studio sites the screenshots and
# the demo video are recorded on — never the development site:
#
#   eizport-demo         the source: WooCommerce, the importer, this plugin,
#                        and the development site's pages, products, media
#                        and settings (moved with the plugin itself).
#   eizport-demo-target  a clean site to import into, with the same plugins.
#   eizport-demo-client  a clean site with WooCommerce only: the "as plugin"
#                        .zip installs Eizport and the importer there.
#
# Run from site/: `npm run demo-sites` (needs the Studio CLI and the
# development site running). Prints the URLs at the end.
set -euo pipefail

SITE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_DIR="$(cd "$SITE_DIR/.." && pwd)"
DEV_SITE="$(cd "$PLUGIN_DIR/../../.." && pwd)"
SOURCE="${EIZPORT_DEMO_SOURCE:-$HOME/Studio/eizport-demo}"
TARGET="${EIZPORT_DEMO_TARGET:-$HOME/Studio/eizport-demo-target}"
CLIENT="${EIZPORT_DEMO_CLIENT:-$HOME/Studio/eizport-demo-client}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

wp() { local path="$1"; shift; studio wp --path="$path" "$@"; }
# "Could not update option" only means the value was already there.
set_option() { wp "$1" option update "${@:2}" --quiet 2>/dev/null || true; }

# The plugin as its release zip has it: tracked files that ship, plus build/.
package_plugin() {
	( cd "$PLUGIN_DIR" && npm run build --silent >/dev/null && git archive --format=tar --prefix=eizport/ HEAD ) | tar -x -C "$TMP"
	cp -R "$PLUGIN_DIR/build" "$TMP/eizport/"
}

create_site() {
	local path="$1" name="$2" with_plugin="${3:-yes}"
	# Studio's list names it after the project; the site's own title (blogname, seen in the video) stays "$name".
	local studio_name="Eizport demo: $name"
	if [ ! -f "$path/wp-config.php" ]; then
		studio site create --path "$path" --name "$studio_name" --skip-browser
	else
		# Exits 1 when the name is already set ("No changes to apply").
		studio site set --path "$path" --name "$studio_name" >/dev/null 2>&1 || true
		studio site start --path "$path" --skip-browser >/dev/null 2>&1 || true
	fi
	# A fresh copy of the plugin each time (none on the client: the video installs it).
	rm -rf "$path/wp-content/plugins/eizport"
	wp "$path" plugin install woocommerce --activate --quiet
	if [ "$with_plugin" = yes ]; then
		cp -R "$TMP/eizport" "$path/wp-content/plugins/"
		wp "$path" plugin install wordpress-importer --activate --quiet
		wp "$path" plugin activate eizport --quiet
	else
		wp "$path" plugin delete wordpress-importer --quiet 2>/dev/null || true
	fi
	# Studio's inactive extras would show up as (translation) updates in the admin bar.
	wp "$path" plugin delete hello akismet --quiet 2>/dev/null || true
	set_option "$path" blogname "$name"
	# No onboarding, no "coming soon" banner, a neutral admin name in the admin bar.
	set_option "$path" woocommerce_coming_soon no
	set_option "$path" woocommerce_onboarding_profile '{"skipped":true}' --format=json
	set_option "$path" woocommerce_task_list_hidden yes
	wp "$path" user update 1 --display_name=Admin --first_name=Admin --quiet
	wp "$path" rewrite structure '/%postname%/' --quiet
}

package_plugin
create_site "$SOURCE" "Eiz Coffee"
create_site "$TARGET" "New Site"
create_site "$CLIENT" "Client Site" no

# Seed the source with the development site's content: an export made by
# the plugin (read-only on the development site; its copy is deleted).
copy=$(wp "$DEV_SITE" eval '
	wp_set_current_user( 1 );
	$out = wp_get_ability( "eizport/export" )->execute( array(
		"types"    => array( "post" => (object) array(), "page" => (object) array(), "product" => (object) array(), "attachment" => (object) array() ),
		"options"  => array( "groups" => array( "basics", "reading", "woocommerce" ), "front_pages" => true ),
		// The header menu: the navigations (the header block shows the newest one).
		"menus"    => array( "navigation" => get_posts( array( "post_type" => "wp_navigation", "numberposts" => -1, "fields" => "ids" ) ) ),
		// The store styles: colors, fonts and the Site Editor CSS.
		"appearance" => array( "global_styles", "global_css" ),
		"filename" => "eizport-demo-seed",
	) );
	echo is_wp_error( $out ) ? "ERROR " . $out->get_error_message() : $out["name"];
')
case "$copy" in ERROR*) echo "$copy" >&2; exit 1 ;; esac
cp "$DEV_SITE/wp-content/uploads/eizport/tmp/$copy" "$TMP/"
wp "$DEV_SITE" eval 'wp_set_current_user( 1 ); wp_get_ability( "eizport/delete-copies" )->execute( array( "names" => array( "'"$copy"'" ) ) );' >/dev/null
# Start from empty sites: no default or WooCommerce-made pages to duplicate.
# The target stays empty until the video imports into it.
wp "$SOURCE" site empty --uploads --yes --quiet
# A store kept aside by useLocale() (demo.mjs) belongs to the content just emptied.
rm -f "$SOURCE/wp-content/eizport-demo-store-en.json"
wp "$TARGET" site empty --uploads --yes --quiet
wp "$CLIENT" site empty --uploads --yes --quiet
cp "$TMP/$copy" "$SOURCE/"
wp "$SOURCE" eizport import "$SOURCE/$copy" --user=1
rm -f "$SOURCE/$copy"
set_option "$SOURCE" blogname "Eiz Coffee"
# The header's account link stays an icon: with avatars on, WooCommerce draws
# the logged-in user's Gravatar over it.
set_option "$SOURCE" show_avatars 0
# A US store, as the English content is: dollars (the import brings the
# prices, not the store settings) and ounces.
for option in "woocommerce_currency USD" "woocommerce_currency_pos left" "woocommerce_price_thousand_sep ," "woocommerce_price_decimal_sep ." "woocommerce_default_country US:WA" "woocommerce_weight_unit oz" "woocommerce_dimension_unit in"; do
	set_option "$SOURCE" $option
done
# Emptying the site keeps options: English product URLs, whatever a language left.
set_option "$SOURCE" woocommerce_permalinks '{"product_base":"product","category_base":"product-category","tag_base":"product-tag","attribute_base":"","use_verbose_page_rules":false}' --format=json

# The development site's content predates the plugin's name: the demo shows
# the name it has now, in the text and on the images.
wp "$SOURCE" search-replace 'Exporter Importer Z' 'Eizport' "$(wp "$SOURCE" db prefix)posts" --quiet
# The store's look: the theme's customized templates and template parts (the
# header with the logo) aren't exportable types; its styles come with the export.
wp "$DEV_SITE" eval '
$posts = get_posts( array( "post_type" => array( "wp_template", "wp_template_part" ), "post_status" => "publish", "numberposts" => -1, "tax_query" => array( array( "taxonomy" => "wp_theme", "field" => "slug", "terms" => get_stylesheet() ) ) ) );
echo wp_json_encode( array_map( function ( $post ) {
	return array( "post_type" => $post->post_type, "post_name" => $post->post_name, "post_title" => $post->post_title, "post_content" => $post->post_content, "area" => wp_get_object_terms( $post->ID, "wp_template_part_area", array( "fields" => "slugs" ) ) );
}, $posts ) );' > "$SOURCE/theme-posts.json"
wp "$SOURCE" eval '
kses_remove_filters();
foreach ( get_posts( array( "post_type" => array( "wp_template", "wp_template_part" ), "post_status" => "any", "numberposts" => -1, "fields" => "ids" ) ) as $old ) {
	wp_delete_post( $old, true );
}
foreach ( json_decode( file_get_contents( ABSPATH . "theme-posts.json" ), true ) as $post ) {
	$id = wp_insert_post( wp_slash( array_intersect_key( $post, array_flip( array( "post_type", "post_name", "post_title", "post_content" ) ) ) + array( "post_status" => "publish" ) ) );
	wp_set_object_terms( $id, get_stylesheet(), "wp_theme" );
	if ( $post["area"] ) {
		wp_set_object_terms( $id, $post["area"], "wp_template_part_area" );
	}
}'
rm -f "$SOURCE/theme-posts.json"
# An export of chosen types carries the products' categories by name only (core
# WXR; see BACKLOG): their descriptions come from the development site.
wp "$DEV_SITE" eval 'echo wp_json_encode( wp_list_pluck( get_terms( array( "taxonomy" => "product_cat", "hide_empty" => false ) ), "description", "slug" ) );' > "$SOURCE/terms.json"
wp "$SOURCE" eval '
foreach ( json_decode( file_get_contents( ABSPATH . "terms.json" ), true ) as $slug => $description ) {
	$term = get_term_by( "slug", $slug, "product_cat" );
	if ( $term ) {
		wp_update_term( $term->term_id, "product_cat", array( "description" => $description ) );
	}
}'
rm -f "$SOURCE/terms.json"
# The logo and the site icon: imported with the media, under the same IDs.
set_option "$SOURCE" site_logo "$(wp "$DEV_SITE" option get site_logo)"
set_option "$SOURCE" site_icon "$(wp "$DEV_SITE" option get site_icon)"
images=$(wp "$SOURCE" eval '
	foreach ( get_posts( array( "post_type" => "attachment", "post_mime_type" => "image", "numberposts" => -1 ) ) as $image ) {
		echo $image->ID, " ", get_attached_file( $image->ID ), "\n";
	}
')
node "$SITE_DIR/scripts/demo-images.mjs" $(cut -d' ' -f2- <<< "$images")
wp "$SOURCE" media regenerate $(cut -d' ' -f1 <<< "$images") --yes --quiet

echo "Source: $(wp "$SOURCE" option get siteurl)"
echo "Target: $(wp "$TARGET" option get siteurl)"
echo "Client: $(wp "$CLIENT" option get siteurl)"

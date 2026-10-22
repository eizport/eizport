<?php
/**
 * Settings (options) in a bundle: options.json, set after the import with
 * the IDs they hold mapped to the imported items (see apply-options.php).
 *
 * Tools → Export offers groups of options (Reading, General, …), more
 * options by name, and — when the homepage or the posts page is exported —
 * setting them as such on import. Options that would break or expose the
 * other site (URLs, admin email, salts, active plugins, roles, transients)
 * are never exported.
 *
 * @package Eizport
 */

namespace Eizport\Options;

defined( 'ABSPATH' ) || exit;

const FILE          = 'options.json';
const VERSION       = 1;
const GROUPS_FIELD  = 'eizport_option_groups';
const EXTRA_FIELD   = 'eizport_options_extra';
const FRONT_FIELD   = 'eizport_front_pages';
const FRONT_OPTIONS = array( 'show_on_front', 'page_on_front', 'page_for_posts' );

/**
 * The groups of options offered.
 *
 * @return array<string, array{label: string, options: string[]}>
 */
function groups() {
	$groups = array(
		'reading'    => array(
			'label'   => __( 'Reading: homepage, posts page, posts per page', 'eizport' ),
			'options' => array( 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page', 'posts_per_rss', 'rss_use_excerpt', 'blog_public' ),
		),
		'general'    => array(
			'label'   => __( 'General: title, tagline, language, time zone, date and time formats', 'eizport' ),
			'options' => array( 'blogname', 'blogdescription', 'WPLANG', 'timezone_string', 'gmt_offset', 'date_format', 'time_format', 'start_of_week' ),
		),
		'permalinks' => array(
			'label'   => __( 'Permalinks', 'eizport' ),
			'options' => array( 'permalink_structure', 'category_base', 'tag_base' ),
		),
		'discussion' => array(
			'label'   => __( 'Discussion', 'eizport' ),
			'options' => array( 'default_comment_status', 'default_ping_status', 'require_name_email', 'comment_registration', 'close_comments_for_old_posts', 'close_comments_days_old', 'thread_comments', 'thread_comments_depth', 'page_comments', 'comments_per_page', 'default_comments_page', 'comment_order', 'comment_moderation', 'comment_previously_approved', 'show_avatars', 'avatar_rating', 'avatar_default' ),
		),
		'media'      => array(
			'label'   => __( 'Media: image sizes, uploads by month', 'eizport' ),
			'options' => array( 'thumbnail_size_w', 'thumbnail_size_h', 'thumbnail_crop', 'medium_size_w', 'medium_size_h', 'medium_large_size_w', 'medium_large_size_h', 'large_size_w', 'large_size_h', 'uploads_use_yearmonth_folders' ),
		),
		'privacy'    => array(
			'label'   => __( 'Privacy policy page', 'eizport' ),
			'options' => array( 'wp_page_for_privacy_policy' ),
		),
		// The theme mods have their own row (Customizer theme mods, includes/appearance.php).
		'appearance' => array(
			'label'   => __( 'Site logo and site icon', 'eizport' ),
			'options' => array( 'site_logo', 'site_icon' ),
		),
	);

	// What most sites want to carry over, suggested first.
	$groups = array(
		'basics' => array(
			'label'   => __( 'Basics (suggested): title, tagline, homepage, permalinks, logo and site icon', 'eizport' ),
			'options' => array_merge( $groups['general']['options'], $groups['reading']['options'], $groups['permalinks']['options'], $groups['appearance']['options'] ),
		),
	) + $groups;

	/**
	 * Filters the groups of options offered: a plugin adds its own, like
	 * the WooCommerce compatibility's "WooCommerce pages".
	 *
	 * @param array<string, array{label: string, options: string[]}> $groups Key => label ("Name: what it holds") and option names.
	 */
	return (array) apply_filters( 'eizport_option_groups', $groups );
}

/**
 * Options that point to a post (a page, an attachment…): its role on the
 * site, like "Homepage". They are mapped to the imported post on import,
 * shown on the items that hold the role, and can come with the items
 * exported (Settings → Eizport → Linked settings).
 *
 * @return array<string, array{label: string, with?: string[], when?: array<string, string>}>
 *         Option => `label` (the role), `with` (options that go with it),
 *         `when` (only while these options have these values).
 */
function linked_options() {
	$linked = array(
		'page_on_front'              => array(
			'label' => __( 'Homepage', 'eizport' ),
			'with'  => array( 'show_on_front' ),
			'when'  => array( 'show_on_front' => 'page' ),
		),
		'page_for_posts'             => array(
			'label' => __( 'Posts page', 'eizport' ),
			'with'  => array( 'show_on_front' ),
			'when'  => array( 'show_on_front' => 'page' ),
		),
		'wp_page_for_privacy_policy' => array(
			'label' => __( 'Privacy policy page', 'eizport' ),
		),
		'site_icon'                  => array(
			'label' => __( 'Site icon', 'eizport' ),
		),
		// The Site Logo block's image (block themes; classic ones keep it in the theme mods).
		'site_logo'                  => array(
			'label' => __( 'Site logo', 'eizport' ),
		),
	);

	/**
	 * Filters the options that point to a post, with the role they give
	 * it. A plugin registers its pages here (see the WooCommerce
	 * compatibility, includes/compat/woocommerce.php).
	 *
	 * @param array $linked Option => array( 'label' => role, 'with' => option names, 'when' => option => value ).
	 */
	return (array) apply_filters( 'eizport_linked_options', $linked );
}

/**
 * The posts that hold a role now: post ID => roles.
 *
 * @return array<int, array<int, array{option: string, label: string, with: string[]}>>
 */
function linked_roles() {
	$roles = array();
	foreach ( linked_options() as $option => $linked ) {
		foreach ( (array) ( $linked['when'] ?? array() ) as $name => $value ) {
			if ( (string) get_option( $name ) !== (string) $value ) {
				continue 2;
			}
		}
		$id = (int) get_option( $option );
		if ( $id > 0 ) {
			$roles[ $id ][] = array(
				'option' => (string) $option,
				'label'  => (string) ( $linked['label'] ?? $option ),
				'with'   => array_values( array_map( 'strval', (array) ( $linked['with'] ?? array() ) ) ),
			);
		}
	}

	return $roles;
}

/**
 * The options that go with posts: the ones pointing to them, and those
 * that go with these.
 *
 * @param int[] $ids Post IDs.
 * @return string[] Option names.
 */
function linked_names( $ids ) {
	$linked = linked_options();
	$roles  = linked_roles();
	$names  = array();
	foreach ( array_map( 'intval', (array) $ids ) as $id ) {
		foreach ( $roles[ $id ] ?? array() as $role ) {
			$names[] = $role['option'];
			$names   = array_merge( $names, (array) ( $linked[ $role['option'] ]['with'] ?? array() ) );
		}
	}

	return array_values( array_unique( $names ) );
}

/**
 * Options that hold a post ID, mapped to the imported post on import.
 *
 * @return string[]
 */
function post_id_options() {
	/**
	 * Filters the options that hold a post ID: on import they get the ID of
	 * the imported post, or are left out when it wasn't imported.
	 *
	 * @param string[] $names Option names.
	 */
	return (array) apply_filters( 'eizport_post_id_options', array_keys( linked_options() ) );
}

/**
 * Whether a name (an option, a theme mod, a widget's field) looks like it
 * holds a secret: a password, a token, an API or license key, a private
 * or client key — or is a plugin's known store of them (mail and payment
 * settings). Secrets are never exported nor listed: a bundle travels.
 *
 * @param string $name Name.
 * @return bool
 */
function is_secret( $name ) {
	$name   = (string) $name;
	$secret = (bool) preg_match( '/secret|passw|pwd|token|api[_-]?key|apikey|licen[cs]e|private|credential|access[_-]?key|auth[_-]?key|_auth$|webhook/i', $name )
		|| in_array( $name, array( 'wp_mail_smtp', 'woocommerce_stripe_settings', 'woocommerce_ppcp-gateway_settings', 'woocommerce_paypal_settings', 'woocommerce_bacs_accounts', 'woocommerce_square_credit_card_settings' ), true );

	/**
	 * Filters whether a name holds a secret, so it is never exported nor
	 * listed (options, theme mods, widget fields): a plugin marks its own,
	 * or lets one through.
	 *
	 * @param bool   $secret Whether it does.
	 * @param string $name   Option, theme mod or widget field name.
	 */
	return (bool) apply_filters( 'eizport_is_secret', $secret, $name );
}

/**
 * Whether an option may never be exported: it would break or expose the
 * other site. The same ones are never set by an import (apply-options.php).
 *
 * @param string $name Option name.
 * @return bool
 */
function is_denied( $name ) {
	require_once __DIR__ . '/apply-options.php';

	/**
	 * Filters the options that are never exported, on top of WordPress's
	 * own (URLs, keys, roles, active plugins…), which can't be removed: a
	 * plugin adds its version numbers, keys or tokens.
	 *
	 * @param string[] $names Option names.
	 */
	$denied = (array) apply_filters( 'eizport_denied_options', array() );

	return eizport_never_set_option( $name ) || in_array( $name, $denied, true ) || is_secret( $name );
}

/**
 * The options that can be exported, by name: no denied options and no
 * transients.
 *
 * @param string $search Text the name must hold.
 * @return object[] Rows with option_name, size and autoload.
 */
function exportable_rows( $search = '' ) {
	global $wpdb;

	// Core has no API to list every option (wp_load_alloptions() only has the autoloaded ones).
	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- listing options to pick, no cache needed.
		$wpdb->prepare(
			"SELECT option_name, LENGTH( option_value ) AS size, autoload FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s ORDER BY option_name",
			'%' . $wpdb->esc_like( $search ) . '%',
			$wpdb->esc_like( '_transient_' ) . '%',
			$wpdb->esc_like( '_site_transient_' ) . '%'
		)
	);

	return array_values(
		array_filter(
			(array) $rows,
			function ( $row ) {
				return ! is_denied( $row->option_name );
			}
		)
	);
}

/**
 * Every option that can be exported, by name.
 *
 * @return string[] Option names.
 */
function all_names() {
	return wp_list_pluck( exportable_rows(), 'option_name' );
}

/**
 * A page of the options that can be exported, one by one, by name: no
 * denied options and no transients.
 *
 * @param string     $search   Text the name must hold.
 * @param int        $page     Page, from 1.
 * @param int        $per_page Options per page (1–100).
 * @param string     $group    Only the options of this group (see groups()), if set.
 * @param array|null $names Only these options (the ones chosen), if given.
 * @return array{items: array<int, array{name: string, size: int, value: string, autoload: bool, post_id: bool}>, total: int, total_pages: int}
 */
function options_page( $search = '', $page = 1, $per_page = 20, $group = '', $names = null ) {
	$per_page = min( 100, max( 1, (int) $per_page ) );
	$page     = max( 1, (int) $page );
	$rows     = exportable_rows( $search );
	$groups   = groups();
	$in       = isset( $groups[ $group ] ) ? $groups[ $group ]['options'] : null;
	foreach ( array( $in, $names ) as $only ) {
		if ( null === $only ) {
			continue;
		}
		$only = array_map( 'strval', (array) $only );
		$rows = array_values(
			array_filter(
				$rows,
				function ( $row ) use ( $only ) {
					return in_array( $row->option_name, $only, true );
				}
			)
		);
	}

	$post_ids = post_id_options();
	$items    = array_map(
		function ( $row ) use ( $post_ids ) {
			return array(
				'name'     => $row->option_name,
				'size'     => (int) $row->size,
				'value'    => value_summary( get_option( $row->option_name ) ),
				'autoload' => in_array( $row->autoload, array( 'yes', 'on', 'auto-on', 'auto' ), true ),
				'post_id'  => in_array( $row->option_name, $post_ids, true ) || 0 === strpos( $row->option_name, 'theme_mods_' ),
			);
		},
		array_slice( $rows, ( $page - 1 ) * $per_page, $per_page )
	);

	return array(
		'items'       => $items,
		'total'       => count( $rows ),
		'total_pages' => (int) ceil( count( $rows ) / $per_page ),
	);
}

/**
 * An option's value in a line, for lists: text as it is, arrays and
 * objects as JSON, up to 200 characters.
 *
 * @param mixed $value Value (unserialized).
 * @return string
 */
function value_summary( $value ) {
	if ( is_bool( $value ) ) {
		$text = $value ? 'true' : 'false';
	} elseif ( is_scalar( $value ) || null === $value ) {
		$text = (string) $value;
	} else {
		$text = (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
	$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );

	return mb_strlen( $text ) > 200 ? mb_substr( $text, 0, 199 ) . '…' : $text;
}

/**
 * Option names typed by the user: separated by spaces, commas or lines.
 *
 * @param string $text Text.
 * @return string[]
 */
function parse_names( $text ) {
	$names = preg_split( '/[\s,]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY );

	return array_values(
		array_unique(
			array_filter(
				(array) $names,
				function ( $name ) {
					return (bool) preg_match( '/^[A-Za-z0-9_.:-]{1,191}$/', $name ) && ! is_denied( $name );
				}
			)
		)
	);
}

/**
 * The options file's data for a list of names: the ones that exist and may
 * be exported.
 *
 * @param string[] $names Option names.
 * @return array{format: string, version: int, options: array, post_ids: string[]}|null Null when none.
 */
function data( $names ) {
	$missing = new \stdClass();
	$options = array();
	foreach ( array_unique( $names ) as $name ) {
		if ( is_denied( $name ) ) {
			continue;
		}
		$value = get_option( $name, $missing );
		if ( $value !== $missing ) {
			$options[ $name ] = $value;
		}
	}
	if ( ! $options ) {
		return null;
	}

	return array(
		'format'   => 'eizport-options',
		'version'  => VERSION,
		'options'  => $options,
		'post_ids' => array_values( array_intersect( post_id_options(), array_keys( $options ) ) ),
	);
}

/**
 * The option names asked for on the export form.
 *
 * @return string[]
 */
function requested_names() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- core's export screen sends the form over GET without a nonce and checks the `export` capability itself.
	$groups = isset( $_GET[ GROUPS_FIELD ] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_GET[ GROUPS_FIELD ] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$extra  = isset( $_GET[ EXTRA_FIELD ] ) ? parse_names( sanitize_textarea_field( wp_unslash( $_GET[ EXTRA_FIELD ] ) ) ) : array();
	$front  = ! empty( $_GET[ FRONT_FIELD ] );
	// phpcs:enable

	return names_for( $groups, $extra, $front );
}

/**
 * The option names for groups, more names and the homepage choice. Unknown
 * groups and names that may not be exported are left out.
 *
 * @param string[] $groups Group keys (see groups()).
 * @param string[] $extra  More option names.
 * @param bool     $front  Whether to set the homepage and posts page.
 * @return string[]
 */
function names_for( $groups, $extra = array(), $front = false ) {
	$names     = array();
	$available = groups();
	foreach ( $groups as $group ) {
		if ( isset( $available[ $group ] ) ) {
			$names = array_merge( $names, $available[ $group ]['options'] );
		}
	}
	$names = array_merge( $names, parse_names( implode( ' ', array_map( 'strval', $extra ) ) ) );
	// Only a static homepage or posts page is worth setting on the other site.
	if ( $front && array_filter( front_pages() ) ) {
		$names = array_merge( $names, FRONT_OPTIONS );
	}

	return array_values( array_unique( $names ) );
}

/**
 * The homepage and posts page set in Reading, when the homepage is a page.
 *
 * @return array{front: int, posts: int}
 */
function front_pages() {
	$static = 'page' === get_option( 'show_on_front' );

	return array(
		'front' => $static ? (int) get_option( 'page_on_front' ) : 0,
		'posts' => $static ? (int) get_option( 'page_for_posts' ) : 0,
	);
}

/**
 * Prints the settings choices (inside the bundle options).
 */
function render() {
	$front = array_filter( front_pages() );
	?>
	<fieldset class="eizport-options">
		<legend><?php esc_html_e( 'Include settings:', 'eizport' ); ?></legend>
		<?php if ( $front ) : ?>
			<p id="eizport-front-pages" data-pages="<?php echo esc_attr( (string) wp_json_encode( array_values( $front ) ) ); ?>">
				<label>
					<input type="checkbox" name="<?php echo esc_attr( FRONT_FIELD ); ?>" value="1" checked="checked" />
					<?php esc_html_e( 'Homepage and posts page: on import, set the exported ones as such', 'eizport' ); ?>
				</label>
			</p>
		<?php endif; ?>
		<?php foreach ( groups() as $key => $group ) : ?>
			<?php
			if ( 'woocommerce' === $key && ! class_exists( 'WooCommerce' ) ) {
				continue;
			}
			?>
			<label><input type="checkbox" name="<?php echo esc_attr( GROUPS_FIELD ); ?>[]" value="<?php echo esc_attr( $key ); ?>" /> <?php echo esc_html( $group['label'] ); ?></label><br />
		<?php endforeach; ?>
		<p>
			<label for="eizport-options-extra"><?php esc_html_e( 'Other options, by name:', 'eizport' ); ?></label><br />
			<input type="text" class="regular-text" id="eizport-options-extra" name="<?php echo esc_attr( EXTRA_FIELD ); ?>" placeholder="my_plugin_settings, other_option" />
		</p>
		<p class="description"><?php esc_html_e( 'Pages, media and other items the settings point to get their new IDs on import; settings pointing to items not exported are left out. Site URLs, the admin email, keys, active plugins, roles, who may register and transients are never exported, nor set by an import.', 'eizport' ); ?></p>
	</fieldset>
	<?php
}

<?php
/**
 * Abilities (the WordPress Abilities API): export, import and the
 * temporary copies, for agents and scripts — at /wp-abilities/v1 and, with
 * the MCP adapter, as tools. Thin adapters over the same code the Export
 * screen and the importer use (Selection, Export\bundle_settings, Options,
 * Runner, Copies, Import).
 *
 * Abilities:
 *     eizport/get-export-options  what can be exported (read-only)
 *     eizport/get-items           a page of a type's items (read-only)
 *     eizport/get-options         a page of the options, one by one (read-only)
 *     eizport/export              writes a .wxrz or Playground .zip copy
 *     eizport/get-copies          the copies kept (read-only)
 *     eizport/delete-copies       deletes copies
 *     eizport/import              imports a copy or a library file
 *     eizport/get-settings        the plugin's settings (read-only)
 *     eizport/update-settings     changes them
 *
 * @package Eizport
 */

namespace Eizport\Abilities;

use Eizport\Copies;
use Eizport\Options;
use Eizport\Playground;
use Eizport\Selection;
use WP_Error;

defined( 'ABSPATH' ) || exit;

const CATEGORY = 'eizport';

/**
 * Registers the hooks.
 */
function bootstrap() {
	add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\register_category' );
	add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\register' );
}

/**
 * Registers the category.
 */
function register_category() {
	wp_register_ability_category(
		CATEGORY,
		array(
			'label'       => __( 'Eizport', 'eizport' ),
			'description' => __( 'Export and import content as .wxrz bundles or WordPress Playground .zip files.', 'eizport' ),
		)
	);
}

/**
 * Whether the current user can export.
 *
 * @return bool
 */
function can_export() {
	return current_user_can( 'export' );
}

/**
 * Whether the current user can manage the plugin's settings.
 *
 * @return bool
 */
function can_manage() {
	return current_user_can( 'manage_options' );
}

/**
 * Whether the current user can import.
 *
 * @return bool
 */
function can_import() {
	return current_user_can( 'import' );
}

/**
 * Registers the abilities.
 */
function register() {
	$meta = function ( $annotations ) {
		return array(
			'annotations'  => $annotations,
			'show_in_rest' => true,
		);
	};

	wp_register_ability(
		'eizport/get-export-options',
		array(
			'label'               => __( 'Get export options', 'eizport' ),
			'description'         => __( 'Lists what can be exported: content types with their item counts, taxonomy terms, statuses and months; the navigation menus (classic, with their theme locations, and block navigations); the site\'s look (Customizer theme mods and custom CSS, Site Editor global styles and CSS, widgets); the groups of settings; the default file name; the themes and plugins a Playground .zip can install; the homepage and posts page; how long copies last. Use it before eizport/export.', 'eizport' ),
			'category'            => CATEGORY,
			// No input. Not "properties => array()": PHP sends [] where JSON Schema needs {}.
			'input_schema'        => array(
				'type'                 => 'object',
				'default'              => (object) array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => __NAMESPACE__ . '\get_export_options',
			'permission_callback' => __NAMESPACE__ . '\can_export',
			'meta'                => $meta(
				array(
					'readonly'   => true,
					'idempotent' => true,
				)
			),
		)
	);

	wp_register_ability(
		'eizport/get-items',
		array(
			'label'               => __( 'Get items', 'eizport' ),
			'description'         => __( 'Lists a page of one content type\'s items (pages, posts, products, media…) that can be exported: searched, sorted and filtered like eizport/export filters them. Use the IDs in eizport/export "types".', 'eizport' ),
			'category'            => CATEGORY,
			'input_schema'        => items_input_schema(),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'items'       => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'         => array( 'type' => 'integer' ),
								'title'      => array( 'type' => 'string' ),
								'status'     => array( 'type' => 'string' ),
								'date'       => array( 'type' => 'string' ),
								'parent'     => array( 'type' => 'integer' ),
								'author'     => array( 'type' => 'integer' ),
								'file'       => array( 'type' => 'string' ),
								'homepage'   => array( 'type' => 'boolean' ),
								'posts_page' => array( 'type' => 'boolean' ),
								'roles'      => array(
									'type'        => 'array',
									'description' => __( 'The item\'s roles on the site, from the options pointing to it (eizport_linked_options).', 'eizport' ),
									'items'       => array(
										'type'       => 'object',
										'properties' => array(
											'option' => array( 'type' => 'string' ),
											'label'  => array( 'type' => 'string' ),
											'with'   => array(
												'type'  => 'array',
												'items' => array( 'type' => 'string' ),
											),
										),
									),
								),
							),
						),
					),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => __NAMESPACE__ . '\get_items',
			'permission_callback' => __NAMESPACE__ . '\can_export',
			'meta'                => $meta(
				array(
					'readonly'   => true,
					'idempotent' => true,
				)
			),
		)
	);

	wp_register_ability(
		'eizport/get-suggestions',
		array(
			'label'               => __( 'Get suggestions', 'eizport' ),
			'description'         => __( 'What the items chosen suggest exporting too: "items" (post type => IDs, like the products a block in their content shows, or the pages the menus chosen link to), "reviews" (comment IDs of the products\' approved reviews), "options" (settings pointing to them, like page_on_front, as Settings → Export → "Linked settings" says) and "menus" (the classic menus in theme locations and the navigations their content or the theme\'s templates use). Nothing is added by itself: pass them to eizport/export to include them.', 'eizport' ),
			'category'            => CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'default'              => (object) array(),
				'properties'           => array(
					'types' => export_input_schema()['properties']['types'],
					'whole' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Post types chosen whole (the same as {} in "types"; easier to send in a URL).', 'eizport' ),
					),
					'menus' => array(
						'type'        => 'object',
						'description' => __( 'Menus chosen: "classic" (menu term IDs); the pages, posts and products their items point to are suggested.', 'eizport' ),
						'properties'  => array(
							'classic' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'integer' ),
							),
						),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'items'      => array( 'type' => 'object' ),
					'reviews'    => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
					'options'    => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'appearance' => array( 'type' => array( 'object', 'array' ) ),
					'menus'      => array(
						'type'       => 'object',
						'properties' => array(
							'classic'    => array(
								'type'  => 'array',
								'items' => array( 'type' => 'integer' ),
							),
							'navigation' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'integer' ),
							),
						),
					),
				),
			),
			'execute_callback'    => __NAMESPACE__ . '\\get_suggestions',
			'permission_callback' => __NAMESPACE__ . '\\can_export',
			'meta'                => $meta(
				array(
					'readonly'   => true,
					'idempotent' => true,
				)
			),
		)
	);

	wp_register_ability(
		'eizport/get-reviews',
		array(
			'label'               => __( 'Get product reviews', 'eizport' ),
			'description'         => __( 'Lists a page of WooCommerce\'s approved product reviews (author, product, rating, excerpt), searched, or only the ones in "include". Use their IDs in eizport/export reviews.ids. Empty without WooCommerce.', 'eizport' ),
			'category'            => CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'default'              => (object) array(),
				'properties'           => array(
					'search'   => array( 'type' => 'string' ),
					'include'  => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'items'       => array( 'type' => 'array' ),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => __NAMESPACE__ . '\\get_reviews',
			'permission_callback' => __NAMESPACE__ . '\\can_export',
			'meta'                => $meta(
				array(
					'readonly'   => true,
					'idempotent' => true,
				)
			),
		)
	);

	wp_register_ability(
		'eizport/get-options',
		array(
			'label'               => __( 'Get options', 'eizport' ),
			'description'         => __( 'Lists a page of the site\'s options that can be exported, one by one (never site URLs, the admin email, keys, active plugins, roles or transients), with their size, whether they are autoloaded and whether they hold a post ID (mapped on import). Use the names in eizport/export options.names.', 'eizport' ),
			'category'            => CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'default'              => (object) array(),
				'properties'           => array(
					'search'   => array( 'type' => 'string' ),
					'names'    => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Only these options (for instance, the ones chosen).', 'eizport' ),
					),
					'group'    => array(
						'type'        => 'string',
						'enum'        => array_keys( Options\groups() ),
						'description' => __( 'Only the options of this group (eizport/get-export-options option_groups).', 'eizport' ),
					),
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'items'       => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name'     => array( 'type' => 'string' ),
								'size'     => array( 'type' => 'integer' ),
								'value'    => array(
									'type'        => 'string',
									'description' => __( 'The value in a line (arrays as JSON), up to 200 characters.', 'eizport' ),
								),
								'autoload' => array( 'type' => 'boolean' ),
								'post_id'  => array( 'type' => 'boolean' ),
							),
						),
					),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => __NAMESPACE__ . '\get_options',
			'permission_callback' => __NAMESPACE__ . '\\can_export',
			'meta'                => $meta(
				array(
					'readonly'   => true,
					'idempotent' => true,
				)
			),
		)
	);

	wp_register_ability(
		'eizport/export',
		array(
			'label'               => __( 'Export content', 'eizport' ),
			'description'         => __( 'Exports content to a temporary copy on this site and returns its link: a .wxrz bundle (WXR, media and content files, settings) for the WordPress Importer, or, with "blueprint", a WordPress Playground .zip that also installs themes and plugins. Without "types", everything is exported.', 'eizport' ),
			'category'            => CATEGORY,
			'input_schema'        => export_input_schema(),
			'output_schema'       => copy_schema(),
			'execute_callback'    => __NAMESPACE__ . '\export',
			'permission_callback' => __NAMESPACE__ . '\can_export',
			'meta'                => $meta(
				array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				)
			),
		)
	);

	wp_register_ability(
		'eizport/get-copies',
		array(
			'label'               => __( 'Get copies', 'eizport' ),
			'description'         => __( 'Lists the export copies kept on this site (.wxrz bundles and Playground .zip files), with their download link, Playground link and expiry.', 'eizport' ),
			'category'            => CATEGORY,
			// No input. Not "properties => array()": PHP sends [] where JSON Schema needs {}.
			'input_schema'        => array(
				'type'                 => 'object',
				'default'              => (object) array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => copy_schema(),
			),
			'execute_callback'    => __NAMESPACE__ . '\get_copies',
			'permission_callback' => __NAMESPACE__ . '\can_export',
			'meta'                => $meta(
				array(
					'readonly'   => true,
					'idempotent' => true,
				)
			),
		)
	);

	wp_register_ability(
		'eizport/delete-copies',
		array(
			'label'               => __( 'Delete copies', 'eizport' ),
			'description'         => __( 'Permanently deletes export copies, by name, or all of them with "all".', 'eizport' ),
			'category'            => CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'default'              => array(),
				'properties'           => array(
					'names' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Names of the copies to delete, as eizport/get-copies returns them.', 'eizport' ),
					),
					'all'   => array(
						'type'        => 'boolean',
						'description' => __( 'Delete every copy.', 'eizport' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'deleted' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => __NAMESPACE__ . '\delete_copies',
			'permission_callback' => __NAMESPACE__ . '\can_export',
			'meta'                => $meta(
				array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				)
			),
		)
	);

	$settings_schema = array(
		'type'       => 'object',
		'properties' => array(
			'copy_lifetime'          => array(
				'type'        => 'integer',
				'description' => __( 'How long copies are kept, in seconds; 0 keeps them until deleted.', 'eizport' ),
			),
			'replace_export'         => array(
				'type'        => 'boolean',
				'description' => __( 'Whether Tools → Export opens the plugin\'s Export screen. Off, WordPress\'s Export screen is left as it is, the plugin\'s is at Tools → Eizport, and classic_ui doesn\'t apply.', 'eizport' ),
			),
			'import_direct'          => array(
				'type'        => 'boolean',
				'description' => __( 'Whether Tools → Import opens the WordPress Importer directly (its screen links to all importers).', 'eizport' ),
			),
			'style_import'           => array(
				'type'        => 'boolean',
				'description' => __( 'Whether Tools → Import → WordPress looks like the plugin\'s Export screen. Off, the WordPress Importer looks as it is.', 'eizport' ),
			),
			'classic_ui'             => array(
				'type'        => 'boolean',
				'description' => __( 'Whether Tools → Export opens the classic screen instead of the new one (only with replace_export).', 'eizport' ),
			),
			'bundle_zip'             => array(
				'type'        => 'boolean',
				'description' => __( 'Whether bundles are plain .zip files instead of .wxrz (gzipped tar).', 'eizport' ),
			),
			'plugin_content'         => array(
				'type'        => 'string',
				'description' => __( 'What a plugin exported "as plugin" holds: wxrz (one .wxrz file), root (the bundle\'s files next to the plugin\'s) or folder (in a bundle/ folder).', 'eizport' ),
			),
			'css_markers'            => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the CSS rules of an export go between "head" and "tail" comments named after the file, to show where they came from. The default for exports and imports.', 'eizport' ),
			),
			'attached_only'          => array(
				'type'        => 'boolean',
				'description' => __( 'Whether exports of some items bring only their attached media and featured images, like WordPress, without the media they use in their content.', 'eizport' ),
			),
			'content_files'          => array(
				'type'        => 'boolean',
				'description' => __( 'Whether bundles keep each post\'s content in post_content/<slug>.html files instead of the WXR.', 'eizport' ),
			),
			'defaults_scope'         => array(
				'type'        => 'string',
				'description' => __( 'Who saved export defaults belong to: each user, or the whole site.', 'eizport' ),
			),
			'linked_options'         => array(
				'type'        => 'string',
				'description' => __( 'When settings that point to exported items (homepage, posts page, shop…) are added: both, check, export or off.', 'eizport' ),
			),
			'lifetime_choices'       => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'value' => array( 'type' => 'integer' ),
						'label' => array( 'type' => 'string' ),
					),
				),
			),
			'scope_choices'          => array( 'type' => 'array' ),
			'linked_choices'         => array( 'type' => 'array' ),
			'plugin_content_choices' => array( 'type' => 'array' ),
		),
	);

	wp_register_ability(
		'eizport/get-settings',
		array(
			'label'               => __( 'Get settings', 'eizport' ),
			'description'         => __( 'The plugin\'s settings, with their choices: how long export copies are kept, whether Tools → Export is the plugin\'s screen and whether it is the classic one, whether the WordPress Importer looks like it and whether Tools → Import opens it, who saved export defaults belong to, and when settings linked to exported items are added.', 'eizport' ),
			'category'            => CATEGORY,
			// No input. Not "properties => array()": PHP sends [] where JSON Schema needs {}.
			'input_schema'        => array(
				'type'                 => 'object',
				'default'              => (object) array(),
				'additionalProperties' => false,
			),
			'output_schema'       => $settings_schema,
			'execute_callback'    => __NAMESPACE__ . '\get_settings',
			'permission_callback' => __NAMESPACE__ . '\can_manage',
			'meta'                => $meta(
				array(
					'readonly'   => true,
					'idempotent' => true,
				)
			),
		)
	);

	$defaults_schema = array(
		'type'        => 'object',
		'description' => __( 'The Export screen\'s choices: "types" (post type => "ids" or "filters", as eizport/export takes them) and "settings" (format "wxr" or "playground", filename, media, option_names, option_groups, option_all, include_suggested (rows that bring what the items chosen suggest: post types, "__options", "__reviews", "__menus"), review_ids, review_all, menu_ids ("c:<menu term ID>", "n:<navigation ID>"), menu_all, appearance (theme_mods, custom_css, global_styles, global_css, widgets), themes, plugins).', 'eizport' ),
	);

	wp_register_ability(
		'eizport/get-export-defaults',
		array(
			'label'               => __( 'Get export defaults', 'eizport' ),
			'description'         => __( 'The export choices saved as default (the current user\'s, or the site\'s, as Settings → Saved defaults says), which the Export screens start with; empty when none.', 'eizport' ),
			'category'            => CATEGORY,
			// No input. Not "properties => array()": PHP sends [] where JSON Schema needs {}.
			'input_schema'        => array(
				'type'                 => 'object',
				'default'              => (object) array(),
				'additionalProperties' => false,
			),
			'output_schema'       => $defaults_schema,
			'execute_callback'    => __NAMESPACE__ . '\get_export_defaults',
			'permission_callback' => __NAMESPACE__ . '\can_export',
			'meta'                => $meta(
				array(
					'readonly'   => true,
					'idempotent' => true,
				)
			),
		)
	);

	wp_register_ability(
		'eizport/save-export-defaults',
		array(
			'label'               => __( 'Save export defaults', 'eizport' ),
			'description'         => __( 'Saves export choices as default (sanitized: unknown types, options never exported and themes or plugins not installed are dropped) and returns them. The site\'s defaults need manage_options.', 'eizport' ),
			'category'            => CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'default'              => (object) array(),
				'properties'           => array(
					'types'    => array( 'type' => 'object' ),
					'settings' => array( 'type' => 'object' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => $defaults_schema,
			'execute_callback'    => __NAMESPACE__ . '\save_export_defaults',
			'permission_callback' => 'Eizport\Defaults\can_save',
			'meta'                => $meta(
				array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				)
			),
		)
	);

	wp_register_ability(
		'eizport/clear-export-defaults',
		array(
			'label'               => __( 'Clear export defaults', 'eizport' ),
			'description'         => __( 'Forgets the export choices saved as default: the Export screens start empty again.', 'eizport' ),
			'category'            => CATEGORY,
			// No input. Not "properties => array()": PHP sends [] where JSON Schema needs {}.
			'input_schema'        => array(
				'type'                 => 'object',
				'default'              => (object) array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => __NAMESPACE__ . '\clear_export_defaults',
			'permission_callback' => 'Eizport\Defaults\can_save',
			'meta'                => $meta(
				array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				)
			),
		)
	);

	wp_register_ability(
		'eizport/update-settings',
		array(
			'label'               => __( 'Update settings', 'eizport' ),
			'description'         => __( 'Changes the plugin\'s settings given (copy_lifetime in seconds, replace_export, classic_ui, style_import, import_direct, content_files, attached_only, bundle_zip, plugin_content, plugin_import_again, defaults_scope, linked_options, css_markers: one of the choices eizport/get-settings lists) and returns them all.', 'eizport' ),
			'category'            => CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'default'              => (object) array(),
				'properties'           => array(
					'copy_lifetime'       => array(
						'type' => 'integer',
						'enum' => array_keys( \Eizport\Settings\lifetime_choices() ),
					),
					'replace_export'      => array( 'type' => 'boolean' ),
					'classic_ui'          => array( 'type' => 'boolean' ),
					'style_import'        => array( 'type' => 'boolean' ),
					'import_direct'       => array( 'type' => 'boolean' ),
					'content_files'       => array( 'type' => 'boolean' ),
					'attached_only'       => array( 'type' => 'boolean' ),
					'bundle_zip'          => array( 'type' => 'boolean' ),
					'css_markers'         => array( 'type' => 'boolean' ),
					'plugin_import_again' => array( 'type' => 'boolean' ),
					'plugin_content'      => array(
						'type' => 'string',
						'enum' => array_keys( \Eizport\Settings\plugin_content_choices() ),
					),
					'defaults_scope'      => array(
						'type' => 'string',
						'enum' => array_keys( \Eizport\Settings\defaults_scope_choices() ),
					),
					'linked_options'      => array(
						'type' => 'string',
						'enum' => array_keys( \Eizport\Settings\linked_options_choices() ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => $settings_schema,
			'execute_callback'    => __NAMESPACE__ . '\update_settings',
			'permission_callback' => __NAMESPACE__ . '\can_manage',
			'meta'                => $meta(
				array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				)
			),
		)
	);

	wp_register_ability(
		'eizport/import',
		array(
			'label'               => __( 'Import content', 'eizport' ),
			'description'         => __( 'Imports a copy kept on this site, or a WXR/.wxrz file in the media library, with the WordPress Importer: posts, media (from the bundle when it has them) and settings, with IDs mapped. Posts go to the current user.', 'eizport' ),
			'category'            => CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'default'              => array(),
				'properties'           => array(
					'copy'            => array(
						'type'        => 'string',
						'description' => __( 'Name of a copy, as eizport/get-copies returns it.', 'eizport' ),
					),
					'attachment_id'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'ID of a .wxrz, .xml or .xml.gz file in the media library.', 'eizport' ),
					),
					'update_existing' => array(
						'type'        => 'boolean',
						'description' => __( 'Update the posts that already exist (same type and slug) with the imported content instead of adding copies. Default: as the bundle asks (exported with update_existing), else no.', 'eizport' ),
					),
					'css_mode'        => array(
						'type'        => 'string',
						'enum'        => array( 'end', 'start', 'replace', 'replace_all' ),
						'description' => __( 'How the bundle\'s CSS rules are added: at the end of this site\'s CSS, at its start, replacing the head/tail blocks of the same name this site already has (replace; with none, at the end), or replacing all of this site\'s CSS (replace_all). Default: as the bundle asks.', 'eizport' ),
					),
					'css_markers'     => array(
						'type'        => 'boolean',
						'description' => __( 'Put the CSS rules between "head" and "tail" comments named after the file. Default: as the bundle asks.', 'eizport' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'posts'   => array( 'type' => 'integer' ),
					'options' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'log'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => __NAMESPACE__ . '\import',
			'permission_callback' => __NAMESPACE__ . '\can_import',
			'meta'                => $meta(
				array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				)
			),
		)
	);
}

/**
 * Input schema of eizport/get-items.
 *
 * @return array
 */
function items_input_schema() {
	$month = array(
		'type'    => 'string',
		'pattern' => '^\d{4}-\d{2}$',
	);

	return array(
		'type'                 => 'object',
		'required'             => array( 'type' ),
		'properties'           => array(
			'type'     => array(
				'type'        => 'string',
				'enum'        => array_keys( Selection\types() ),
				'description' => __( 'Post type, as eizport/get-export-options lists them.', 'eizport' ),
			),
			'search'   => array( 'type' => 'string' ),
			'include'  => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'description' => __( 'Only these items (for instance, the ones chosen).', 'eizport' ),
			),
			'page'     => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
			),
			'orderby'  => array(
				'type' => 'string',
				'enum' => array( 'date', 'title', 'menu_order' ),
			),
			'order'    => array(
				'type' => 'string',
				'enum' => array( 'asc', 'desc' ),
			),
			'filters'  => array(
				'type'                 => 'object',
				'properties'           => array(
					'author'     => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'start_date' => $month,
					'end_date'   => $month,
					'status'     => array( 'type' => 'string' ),
					'terms'      => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
				),
				'additionalProperties' => false,
			),
		),
		'additionalProperties' => false,
	);
}

/**
 * Input schema of eizport/export.
 *
 * @return array
 */
function export_input_schema() {
	$month = array(
		'type'    => 'string',
		'pattern' => '^\d{4}-\d{2}$',
	);

	return array(
		'type'                 => 'object',
		'default'              => array(),
		'properties'           => array(
			'types'           => array(
				'type'                 => 'object',
				'description'          => __( 'Post type => what to export of it: "ids", or "filters". Omit to export everything.', 'eizport' ),
				'additionalProperties' => array(
					'type'                 => 'object',
					'properties'           => array(
						'ids'     => array(
							'type'  => 'array',
							'items' => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
						),
						'filters' => array(
							'type'                 => 'object',
							'properties'           => array(
								'author'     => array(
									'type'    => 'integer',
									'minimum' => 0,
								),
								'start_date' => $month,
								'end_date'   => $month,
								'status'     => array( 'type' => 'string' ),
								'terms'      => array(
									'type'  => 'array',
									'items' => array( 'type' => 'integer' ),
								),
							),
							'additionalProperties' => false,
						),
					),
					'additionalProperties' => false,
				),
			),
			'update_existing' => array(
				'type'        => 'boolean',
				'description' => __( 'Ask the import to update the posts that already exist on the other site (same type and slug) instead of adding copies: the bundle\'s default, which the importer can change. Not with blueprint.', 'eizport' ),
			),
			'as_plugin'       => array(
				'type'        => 'boolean',
				'description' => __( 'A plugin .zip holding the bundle: installed on another site, it shows a screen (Tools) that checks the plugins needed and imports the bundle with one click. Not with blueprint.', 'eizport' ),
			),
			'reviews'         => array(
				'type'                 => 'object',
				'description'          => __( 'WooCommerce product reviews to export with their products: "ids" (comment IDs; none: no reviews) or "all". Default: all of the exported products\' reviews.', 'eizport' ),
				'properties'           => array(
					'ids' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
					'all' => array( 'type' => 'boolean' ),
				),
				'additionalProperties' => false,
			),
			'appearance'      => array(
				'type'        => array( 'object', 'array' ),
				'description' => __( 'The site\'s look, in the bundle\'s options file, row => "all" or the keys of the items chosen (eizport/get-export-options lists them): "theme_mods" (the Customizer\'s theme mods: logo, header and background images, colors), "custom_css" (the Customizer\'s Additional CSS, rule by rule), "global_styles" (the Site Editor\'s styles, group by group) and "global_css" (its Additional CSS, rule by rule) — block themes — and "widgets" (classic and block widgets). A list of rows means each of them whole. The media they use come along. Default: none.', 'eizport' ),
			),
			'css_mode'        => array(
				'type'        => 'string',
				'enum'        => array( 'end', 'start', 'replace', 'replace_all' ),
				'description' => __( 'How the CSS rules are added on import: at the end of the other site\'s CSS (default), at its start, replacing the head/tail blocks of the same name it already has (replace; with none, at the end), or replacing all its CSS (replace_all). Whoever imports can change it.', 'eizport' ),
			),
			'css_markers'     => array(
				'type'        => 'boolean',
				'description' => __( 'Put the CSS rules between "head" and "tail" comments named after the file. Default: Settings → Export.', 'eizport' ),
			),
			'menus'           => array(
				'type'                 => 'object',
				'description'          => __( 'Navigation menus to export: "classic" (menu term IDs, with their items and theme locations) and "navigation" (wp_navigation post IDs, the menus of navigation blocks), or "all". Default: none (all content already has them). eizport/get-export-options lists them; eizport/get-suggestions says which the items chosen use.', 'eizport' ),
				'properties'           => array(
					'classic'    => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
					'navigation' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
					'all'        => array( 'type' => 'boolean' ),
				),
				'additionalProperties' => false,
			),
			'media_used'      => array(
				'type'        => 'boolean',
				'description' => __( 'With types: also the media the exported items use in their content without being attached (images, galleries, covers, files). Default: true, unless the "Only attached media" setting is on.', 'eizport' ),
			),
			'zip'             => array(
				'type'        => 'boolean',
				'description' => __( 'The bundle as a plain .zip instead of a .wxrz (gzipped tar), with the same contents. Default: the "Bundle as .zip" setting.', 'eizport' ),
			),
			'bundle'          => array(
				'type'        => 'boolean',
				'description' => __( 'A .wxrz bundle (default true). False: core\'s plain WXR .xml, without media, content files or settings.', 'eizport' ),
			),
			'media'           => array(
				'type'        => 'boolean',
				'description' => __( 'Include the attachment files. Default true.', 'eizport' ),
			),
			'post_content'    => array(
				'type'        => 'boolean',
				'description' => __( 'Content as post_content/<slug>.html files instead of inside the WXR. Default: the "Content as .html files" setting (on unless changed).', 'eizport' ),
			),
			'options'         => array(
				'type'                 => 'object',
				'description'          => __( 'Settings to include: groups, option names or all, and whether to set the exported homepage and posts page on import.', 'eizport' ),
				'properties'           => array(
					'groups'      => array(
						'type'  => 'array',
						'items' => array(
							'type' => 'string',
							'enum' => array_keys( Options\groups() ),
						),
					),
					'names'       => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'front_pages' => array( 'type' => 'boolean' ),
					'linked'      => array(
						'type'        => 'boolean',
						'description' => __( 'Also the settings that point to the posts exported (homepage, posts page, shop…). Default: the "Linked settings" setting.', 'eizport' ),
					),
					'all'         => array(
						'type'        => 'boolean',
						'description' => __( 'Every option that can be exported (no transients, no denied options), instead of groups and names.', 'eizport' ),
					),
				),
				'additionalProperties' => false,
			),
			'blueprint'       => array(
				'type'                 => 'object',
				'description'          => __( 'Make a WordPress Playground .zip instead of a .wxrz, installing these themes (a child theme brings its parent) and plugins (active or inactive as on this site), opening on landing_page.', 'eizport' ),
				'properties'           => array(
					'themes'       => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'plugins'      => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'landing_page' => array(
						'type'        => array( 'string', 'object' ),
						'description' => __( 'Where Playground opens: "home" (default), "admin", { "page": ID } (a published page of this site) or a path ("/shop/").', 'eizport' ),
					),
				),
				'additionalProperties' => false,
			),
			'filename'        => array(
				'type'        => 'string',
				'description' => __( 'File name without extension. Default: "<site name>.WordPress.<date>".', 'eizport' ),
			),
		),
		'additionalProperties' => false,
	);
}

/**
 * Schema of a copy, as eizport/export and eizport/get-copies return it.
 *
 * @return array
 */
function copy_schema() {
	return array(
		'type'       => 'object',
		'properties' => array(
			'name'           => array( 'type' => 'string' ),
			'format'         => array(
				'type' => 'string',
				'enum' => array( 'wxrz', 'zip', 'wxr', 'playground', 'plugin' ),
			),
			'size'           => array( 'type' => 'integer' ),
			'created'        => array( 'type' => 'string' ),
			'expires'        => array(
				'type'        => 'string',
				'description' => __( 'When the copy is deleted (ISO 8601), or "" when it is kept until deleted.', 'eizport' ),
			),
			'download_url'   => array( 'type' => 'string' ),
			'playground_url' => array(
				'type'        => 'string',
				'description' => __( 'Opens a Playground .zip on playground.wordpress.net; "" for a .wxrz.', 'eizport' ),
			),
			'command'        => array(
				'type'        => 'string',
				'description' => __( 'Runs a downloaded Playground .zip with the Playground CLI; "" for a .wxrz.', 'eizport' ),
			),
		),
	);
}

/**
 * A copy's format: playground, plugin, zip, wxr or wxrz.
 *
 * @param string $name       Copy name.
 * @param bool   $playground Whether it is a Playground .zip.
 * @return string
 */
function format_of( $name, $playground ) {
	if ( $playground ) {
		return 'playground';
	}
	if ( Copies\is_plugin( $name ) ) {
		return 'plugin';
	}
	if ( Copies\is_zip_bundle( $name ) ) {
		return 'zip';
	}

	return 'xml' === Copies\extension_of( $name ) ? 'wxr' : 'wxrz';
}

/**
 * Describes a copy for the abilities.
 *
 * @param string $name Copy name.
 * @return array
 */
function describe_copy( $name ) {
	$path       = Copies\path( $name );
	$time       = (int) filemtime( $path );
	$playground = Copies\is_playground( $name );

	return array(
		'name'           => $name,
		'format'         => format_of( $name, $playground ),
		'size'           => (int) filesize( $path ),
		'created'        => gmdate( 'c', $time ),
		'expires'        => Copies\lifetime() ? gmdate( 'c', $time + Copies\lifetime() ) : '',
		'download_url'   => Copies\url( $name ),
		'playground_url' => $playground ? Copies\playground_url( $name ) : '',
		'command'        => $playground ? Copies\command( $name ) : '',
	);
}

/**
 * Executes eizport/get-export-options.
 *
 * @param array $input Input (none).
 * @return array
 */
function get_export_options( $input = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- takes no input.
	$types = array();
	foreach ( Selection\types() as $type => $content ) {
		$object     = get_post_type_object( $type );
		$taxonomies = array();
		foreach ( get_object_taxonomies( $type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->show_ui ) {
				continue;
			}
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy->name,
					'hide_empty' => false,
					'number'     => 100,
				)
			);
			if ( is_wp_error( $terms ) || ! $terms ) {
				continue;
			}
			$taxonomies[] = array(
				'taxonomy' => $taxonomy->name,
				'label'    => $taxonomy->labels->name,
				'terms'    => array_map(
					function ( $term ) {
						return array(
							'id'   => (int) $term->term_id,
							'name' => $term->name,
						);
					},
					$terms
				),
			);
		}
		$counts  = (array) wp_count_posts( $type );
		$types[] = array(
			'type'         => $type,
			// Media items are attachments: named so next to the other types.
			'label'        => 'attachment' === $type ? __( 'Attachments', 'eizport' ) : ( $object ? $object->labels->name : $type ),
			'count'        => array_sum( array_map( 'intval', $counts ) ) - (int) ( $counts['auto-draft'] ?? 0 ),
			'hierarchical' => is_post_type_hierarchical( $type ),
			'statuses'     => array_values( array_keys( Selection\statuses( $type ) ) ),
			'taxonomies'   => $taxonomies,
		);
	}

	$themes = array();
	foreach ( Playground\theme_choices() as $stylesheet => $theme ) {
		$themes[] = array(
			'stylesheet' => $stylesheet,
			'name'       => $theme->display( 'Name', false ),
			'parent'     => $theme->parent() ? $theme->parent()->get_stylesheet() : '',
			'active'     => get_stylesheet() === $stylesheet,
		);
	}
	$plugins = array();
	foreach ( Playground\plugin_choices() as $file => $data ) {
		$plugins[] = array(
			'plugin' => $file,
			'name'   => $data['Name'],
			// Installed active or inactive in Playground, as here.
			'active' => is_plugin_active( $file ),
		);
	}
	$groups = array();
	foreach ( Options\groups() as $key => $group ) {
		$groups[] = array(
			'group'   => $key,
			'label'   => $group['label'],
			'options' => $group['options'],
		);
	}

	return array(
		'types'         => $types,
		'option_groups' => $groups,
		'front_pages'   => Options\front_pages(),
		'themes'        => $themes,
		'plugins'       => $plugins,
		'copy_lifetime' => Copies\lifetime(),
		'filename'      => \Eizport\Export\default_filename(),
		'linked'        => \Eizport\Settings\get()['linked_options'],
		// Approved product reviews, for the Product reviews row (none without WooCommerce).
		'review_count'  => post_type_exists( 'product' ) ? (int) get_comments(
			array(
				'post_type' => 'product',
				'type'      => 'review',
				'status'    => 'approve',
				'count'     => true,
			)
		) : 0,
		'bundle_zip'    => \Eizport\Settings\get()['bundle_zip'],
		// Navigation menus, for the Menus row.
		'menus'         => \Eizport\Menus\all(),
		// The look's rows (theme mods, custom CSS, global styles and CSS, widgets): key => their items, none when the site has none.
		'appearance'    => array_combine(
			\Eizport\Appearance\KEYS,
			array_map( '\Eizport\Appearance\items', \Eizport\Appearance\KEYS )
		),
	);
}

/**
 * The menus of eizport/export's input, as the runner takes them.
 *
 * @param array|null $menus `classic`, `navigation`, or `all`; null: none.
 * @return array{classic: int[], navigation: int[]}|null
 */
function menus_input( $menus ) {
	if ( ! is_array( $menus ) ) {
		return null;
	}
	if ( ! empty( $menus['all'] ) ) {
		$all = \Eizport\Menus\all();

		return array(
			'classic'    => wp_list_pluck( $all['classic'], 'id' ),
			'navigation' => wp_list_pluck( $all['navigation'], 'id' ),
		);
	}

	return array(
		'classic'    => array_map( 'intval', (array) ( $menus['classic'] ?? array() ) ),
		'navigation' => array_map( 'intval', (array) ( $menus['navigation'] ?? array() ) ),
	);
}

/**
 * Executes eizport/get-items.
 *
 * @param array $input Input.
 * @return array|WP_Error
 */
function get_items( $input = array() ) {
	$input = is_array( $input ) ? $input : array();
	$type  = isset( $input['type'] ) && is_string( $input['type'] ) ? $input['type'] : '';
	if ( '' === $type ) {
		return new WP_Error( 'eizport_missing_type', __( 'Give the "type" of the items to list.', 'eizport' ) );
	}
	if ( ! isset( Selection\types()[ $type ] ) ) {
		return new WP_Error(
			'eizport_invalid_type',
			/* translators: %s: post type. */
			sprintf( __( '"%s" is not a content type that can be exported.', 'eizport' ), $type )
		);
	}

	return Selection\items_page( $type, $input );
}

/**
 * Executes eizport/export.
 *
 * @param array $input Input.
 * @return array|WP_Error
 */
function export( $input = array() ) {
	$input = is_array( $input ) ? $input : array();

	$specs = Selection\specs_from_input( (array) ( $input['types'] ?? array() ) );
	if ( is_wp_error( $specs ) ) {
		return $specs;
	}

	$options = (array) ( $input['options'] ?? array() );
	$names   = array_map( 'strval', (array) ( $options['names'] ?? array() ) );
	$denied  = array_values( array_filter( $names, 'Eizport\Options\is_denied' ) );
	if ( $denied ) {
		return new WP_Error(
			'eizport_invalid_options',
			/* translators: %s: option names. */
			sprintf( __( 'These options are never exported: %s.', 'eizport' ), implode( ', ', $denied ) )
		);
	}

	$blueprint = isset( $input['blueprint'] ) && is_array( $input['blueprint'] );
	$themes    = $blueprint ? array_map( 'strval', (array) ( $input['blueprint']['themes'] ?? array() ) ) : array();
	$plugins   = $blueprint ? array_map( 'strval', (array) ( $input['blueprint']['plugins'] ?? array() ) ) : array();
	$missing   = array_merge(
		array_diff( $themes, array_keys( Playground\theme_choices() ) ),
		array_diff( $plugins, array_keys( Playground\plugin_choices() ) )
	);
	if ( $missing ) {
		return new WP_Error(
			'eizport_invalid_blueprint',
			/* translators: %s: theme stylesheets or plugin files. */
			sprintf( __( 'Not installed on this site: %s.', 'eizport' ), implode( ', ', $missing ) )
		);
	}

	// Schema defaults are not applied to the input: the screen's defaults.
	$result = \Eizport\Runner\export(
		$specs,
		array(
			'bundle'          => array_key_exists( 'bundle', $input ) ? (bool) $input['bundle'] : true,
			'zip'             => array_key_exists( 'zip', $input ) ? (bool) $input['zip'] : \Eizport\Settings\get()['bundle_zip'],
			'media_used'      => array_key_exists( 'media_used', $input ) ? (bool) $input['media_used'] : null,
			// Product reviews: null exports them all, as WordPress does.
			'reviews'         => isset( $input['reviews'] ) && empty( $input['reviews']['all'] ) ? array_map( 'intval', (array) ( $input['reviews']['ids'] ?? array() ) ) : null,
			'menus'           => menus_input( $input['menus'] ?? null ),
			'appearance'      => \Eizport\Appearance\normalize( $input['appearance'] ?? array() ),
			'css_mode'        => (string) ( $input['css_mode'] ?? 'end' ),
			'css_markers'     => array_key_exists( 'css_markers', $input ) ? (bool) $input['css_markers'] : \Eizport\Settings\get()['css_markers'],
			'blueprint'       => $blueprint,
			'as_plugin'       => ! $blueprint && ! empty( $input['as_plugin'] ),
			'update_existing' => ! empty( $input['update_existing'] ),
			'media'           => array_key_exists( 'media', $input ) ? (bool) $input['media'] : true,
			'post_content'    => array_key_exists( 'post_content', $input ) ? (bool) $input['post_content'] : \Eizport\Settings\get()['content_files'],
			'linked'          => array_key_exists( 'linked', $options )
				? (bool) $options['linked']
				: in_array( \Eizport\Settings\get()['linked_options'], array( 'export', 'both' ), true ),
			'options'         => ! empty( $options['all'] )
				? Options\all_names()
				: Options\names_for( (array) ( $options['groups'] ?? array() ), $names, ! empty( $options['front_pages'] ) ),
			'themes'          => $themes,
			'plugins'         => $plugins,
			'landing'         => $blueprint ? ( $input['blueprint']['landing_page'] ?? 'home' ) : 'home',
			'filename'        => (string) ( $input['filename'] ?? '' ),
		)
	);

	return is_wp_error( $result ) ? $result : describe_copy( $result['name'] );
}

/**
 * Executes eizport/get-copies.
 *
 * @param array $input Input (none).
 * @return array
 */
function get_copies( $input = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- takes no input.
	Copies\cleanup();

	return array_map( __NAMESPACE__ . '\describe_copy', wp_list_pluck( Copies\copies(), 'name' ) );
}

/**
 * Executes eizport/delete-copies.
 *
 * @param array $input Input.
 * @return array|WP_Error
 */
function delete_copies( $input = array() ) {
	$input = is_array( $input ) ? $input : array();
	if ( ! empty( $input['all'] ) ) {
		return array( 'deleted' => Copies\delete_copies() );
	}
	if ( ! isset( $input['names'] ) || ! is_array( $input['names'] ) || array() === $input['names'] ) {
		return new WP_Error( 'eizport_missing_names', __( 'Give the names of the copies to delete, or "all".', 'eizport' ) );
	}

	return array( 'deleted' => Copies\delete_copies( array_map( 'strval', $input['names'] ) ) );
}

/**
 * The file an import input points to.
 *
 * @param array $input Input: `copy` or `attachment_id`.
 * @return string|WP_Error
 */
function import_source( $input ) {
	if ( isset( $input['copy'] ) && is_string( $input['copy'] ) && '' !== $input['copy'] ) {
		$path = Copies\path( $input['copy'] );
		if ( '' === $path || ! is_file( $path ) || Copies\is_expired( $path ) ) {
			return new WP_Error( 'eizport_invalid_copy', __( 'There is no such copy: see eizport/get-copies.', 'eizport' ) );
		}
		return $path;
	}
	if ( isset( $input['attachment_id'] ) && is_int( $input['attachment_id'] ) && $input['attachment_id'] > 0 ) {
		$path = get_post_type( $input['attachment_id'] ) === 'attachment' ? (string) get_attached_file( $input['attachment_id'] ) : '';
		if ( '' === $path || ! is_file( $path ) || ! preg_match( '/\.(wxrz|zip|xml|xml\.gz|gz)$/i', $path ) ) {
			return new WP_Error( 'eizport_invalid_attachment_id', __( 'The attachment is not a .wxrz, .zip, .xml or .xml.gz file.', 'eizport' ) );
		}
		return $path;
	}

	return new WP_Error( 'eizport_missing_copy', __( 'Give a "copy" name or an "attachment_id" to import.', 'eizport' ) );
}

/**
 * The file to import, refusing plugins made "as plugin" (installed as a
 * plugin, they import themselves).
 *
 * @param array $input `copy` or `attachment_id`.
 * @return string|WP_Error
 */
function import_file_of( $input ) {
	$path = import_source( $input );
	if ( ! is_wp_error( $path ) && \Eizport\BundlePlugin\is_plugin_zip( $path ) ) {
		return new WP_Error( 'eizport_plugin_zip', __( 'This .zip is a plugin: install it in Plugins → Add Plugin → Upload Plugin, then run its import screen under Tools.', 'eizport' ) );
	}

	return $path;
}

/**
 * Executes eizport/import.
 *
 * @param array $input Input.
 * @return array|WP_Error
 */
function import( $input = array() ) {
	$file = import_file_of( is_array( $input ) ? $input : array() );
	if ( is_wp_error( $file ) ) {
		return $file;
	}

	$GLOBALS['eizport_applied_options'] = array();
	$result                             = \Eizport\Import\import_file( $file, array_intersect_key( (array) $input, array_flip( array( 'update_existing', 'css_mode', 'css_markers' ) ) ) );
	if ( is_wp_error( $result ) ) {
		if ( 'eizport_no_importer' === $result->get_error_code() ) {
			return new WP_Error( 'eizport_not_initialized', $result->get_error_message() );
		}
		return $result;
	}

	return array(
		'posts'   => (int) $result['posts'],
		'options' => array_values( (array) $GLOBALS['eizport_applied_options'] ),
		'log'     => (string) $result['log'],
	);
}

/**
 * The settings as the settings abilities return them.
 *
 * @param array $settings Settings (Settings\get()).
 * @return array
 */
function describe_settings( $settings ) {
	$choices = array();
	foreach ( \Eizport\Settings\lifetime_choices() as $value => $label ) {
		$choices[] = array(
			'value' => (int) $value,
			'label' => $label,
		);
	}

	$pairs = function ( $choices_list ) {
		$out = array();
		foreach ( $choices_list as $value => $label ) {
			$out[] = array(
				'value' => (string) $value,
				'label' => $label,
			);
		}
		return $out;
	};

	return array_merge(
		$settings,
		array(
			'lifetime_choices'       => $choices,
			'scope_choices'          => $pairs( \Eizport\Settings\defaults_scope_choices() ),
			'linked_choices'         => $pairs( \Eizport\Settings\linked_options_choices() ),
			'plugin_content_choices' => $pairs( \Eizport\Settings\plugin_content_choices() ),
		)
	);
}

/**
 * Executes eizport/get-settings.
 *
 * @param array $input Input (none).
 * @return array
 */
function get_settings( $input = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- takes no input.
	return describe_settings( \Eizport\Settings\get() );
}

/**
 * Executes eizport/update-settings.
 *
 * @param array $input Settings to change.
 * @return array
 */
function update_settings( $input = array() ) {
	return describe_settings( \Eizport\Settings\update( is_array( $input ) ? $input : array() ) );
}

/**
 * Executes eizport/get-suggestions.
 *
 * @param array $input Input.
 * @return array|WP_Error
 */
function get_suggestions( $input = array() ) {
	$input = is_array( $input ) ? $input : array();
	$types = (array) ( $input['types'] ?? array() );
	foreach ( array_map( 'strval', (array) ( $input['whole'] ?? array() ) ) as $type ) {
		$types[ $type ] = $types[ $type ] ?? array();
	}
	$specs = Selection\specs_from_input( $types );
	if ( is_wp_error( $specs ) ) {
		return $specs;
	}
	$out = \Eizport\Suggestions\suggest( $specs );
	// The menus the items use (theme locations, navigation blocks), and what the menus chosen point to.
	$out['menus'] = \Eizport\Menus\suggested( array() === $out['chosen'] ? array() : array_merge( ...array_values( $out['chosen'] ) ) );
	unset( $out['chosen'] );
	// What the site has of its look: every item is suggested, row by row.
	$out['appearance'] = array();
	foreach ( \Eizport\Appearance\KEYS as $key ) {
		$keys = wp_list_pluck( \Eizport\Appearance\items( $key ), 'key' );
		if ( $keys ) {
			$out['appearance'][ $key ] = $keys;
		}
	}
	foreach ( \Eizport\Menus\targets( (array) ( $input['menus']['classic'] ?? array() ) ) as $type => $ids ) {
		if ( isset( Selection\content_types()[ $type ] ) ) {
			$out['items'][ $type ] = array_values( array_unique( array_merge( $out['items'][ $type ] ?? array(), $ids ) ) );
		}
	}
	// An object, also when empty.
	$out['items'] = (object) $out['items'];

	return $out;
}

/**
 * Executes eizport/get-reviews.
 *
 * @param array $input Input.
 * @return array
 */
function get_reviews( $input = array() ) {
	if ( ! post_type_exists( 'product' ) ) {
		return array(
			'items'       => array(),
			'total'       => 0,
			'total_pages' => 0,
		);
	}

	return \Eizport\Compat\WooCommerce\reviews_page( is_array( $input ) ? $input : array() );
}

/**
 * Executes eizport/get-options.
 *
 * @param array $input Input.
 * @return array
 */
function get_options( $input = array() ) {
	$input = is_array( $input ) ? $input : array();

	return Options\options_page( (string) ( $input['search'] ?? '' ), (int) ( $input['page'] ?? 1 ), (int) ( $input['per_page'] ?? 20 ), (string) ( $input['group'] ?? '' ), isset( $input['names'] ) ? (array) $input['names'] : null );
}

/**
 * Executes eizport/get-export-defaults.
 *
 * @param array $input Input (none).
 * @return object
 */
function get_export_defaults( $input = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- takes no input.
	return (object) \Eizport\Defaults\get();
}

/**
 * Executes eizport/save-export-defaults.
 *
 * @param array $input `types` and `settings`.
 * @return array
 */
function save_export_defaults( $input = array() ) {
	return \Eizport\Defaults\save( is_array( $input ) ? $input : array() );
}

/**
 * Executes eizport/clear-export-defaults.
 *
 * @param array $input Input (none).
 * @return object
 */
function clear_export_defaults( $input = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- takes no input.
	\Eizport\Defaults\clear();

	return (object) array();
}

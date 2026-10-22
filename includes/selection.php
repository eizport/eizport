<?php
/**
 * Tools → Export: choose one or more content types (posts, pages, media,
 * products and other custom post types) instead of core's single one, each
 * "By filters" or "By selection". Core's radios become checkboxes ("All
 * content" stays as it is); without JavaScript they stay radios and one
 * type is exported, still by filters or by selection.
 *
 * Posts, pages and media keep core's filters. Custom post types, which core
 * exports whole, get the same kind of filters here: author, dates, status
 * and one per taxonomy. "By selection" lists the items to check, as a tree
 * for hierarchical types; the items checked bring their children of other
 * types (a product's variations, for example), and core adds their
 * attachments and featured images as usual.
 *
 * export_wp() has no filter for the query that picks the posts, so both are
 * applied by a one-shot `query` filter added on `export_wp`, right before
 * that query runs.
 *
 * @package Eizport
 */

namespace Eizport\Selection;

defined( 'ABSPATH' ) || exit;

const MODE_FIELD    = 'eizport_mode';
const IDS_FIELD     = 'eizport_ids';
const FILTERS_FIELD = 'eizport_filters';
const TYPES_FIELD   = 'eizport_types';

/**
 * Registers the hooks.
 */
function bootstrap() {
	add_action( 'export_filters', __NAMESPACE__ . '\render' );
	add_filter( 'export_args', __NAMESPACE__ . '\args' );
	add_action( 'export_wp', __NAMESPACE__ . '\limit_query' );
}

/**
 * The types that can be exported by filters or by selection: post type =>
 * value of core's "content" radio. Custom post types are the ones core
 * lists that have an admin screen (product variations, for example, go
 * with their product instead).
 *
 * @return array<string, string>
 */
function types() {
	$types = array(
		'post' => 'posts',
		'page' => 'pages',
	);
	foreach ( get_post_types(
		array(
			'_builtin'   => false,
			'can_export' => true,
			'show_ui'    => true,
		)
	) as $type ) {
		$types[ $type ] = $type;
	}
	$types['attachment'] = 'attachment';

	/**
	 * Filters the post types the Export screen offers: post type =>
	 * value of core's "content" radio. A plugin takes out its own types
	 * that travel another way (ACF's field groups).
	 *
	 * @param array<string, string> $types Post type => value.
	 */
	return (array) apply_filters( 'eizport_content_types', $types );
}

/**
 * Every type core offers on its export screen: post type => value of its
 * "content" radio.
 *
 * @return array<string, string>
 */
function content_types() {
	$types = array(
		'post' => 'posts',
		'page' => 'pages',
	);
	foreach ( get_post_types(
		array(
			'_builtin'   => false,
			'can_export' => true,
		)
	) as $type ) {
		$types[ $type ] = $type;
	}
	$types['attachment'] = 'attachment';

	/**
	 * Filters the post types the Export screen offers: post type =>
	 * value of core's "content" radio. A plugin takes out its own types
	 * that travel another way (ACF's field groups).
	 *
	 * @param array<string, string> $types Post type => value.
	 */
	return (array) apply_filters( 'eizport_content_types', $types );
}

/**
 * Id of core's filters list of a type, or '' when core has none.
 *
 * @param string $type Post type.
 * @return string
 */
function core_filters_id( $type ) {
	$ids = array(
		'post'       => 'post-filters',
		'page'       => 'page-filters',
		'attachment' => 'attachment-filters',
	);

	return $ids[ $type ] ?? '';
}

/**
 * The post type of a value of core's "content" radio.
 *
 * @param mixed $content "posts", "pages", "attachment" or a post type.
 * @return string Post type, or ''.
 */
function post_type_of( $content ) {
	$type = array_search( $content, content_types(), true );

	return is_string( $type ) ? $type : '';
}

/**
 * Prints, for each type, the choice between filters and selection, the
 * filters of custom post types and the checklist. A small script moves
 * them into core's filters (or under the type's radio) and shows only the
 * chosen way; without JavaScript everything stays visible at the end of
 * the form and the choice still decides on the server. Nothing when Tools
 * → Export isn't replaced: WordPress's screen is left as it is.
 */
function render() {
	if ( ! \Eizport\AdminApp\replaces_export() ) {
		return;
	}
	foreach ( types() as $type => $content ) {
		render_type( $type, $content );
	}
	?>
	<?php
}

/**
 * Prints one type's choice, filters (custom post types) and checklist.
 *
 * @param string $type    Post type.
 * @param string $content Value of core's "content" radio.
 */
function render_type( $type, $content ) {
	$object = get_post_type_object( $type );
	$target = core_filters_id( $type );
	$items  = items( $type );
	$label  = $object ? $object->labels->name : $type;
	?>
	<ul id="eizport-<?php echo esc_attr( $type ); ?>-filters" class="eizport-type<?php echo $target ? '' : ' export-filters'; ?>" data-target="<?php echo esc_attr( $target ); ?>" data-content="<?php echo esc_attr( $content ); ?>">
		<li class="eizport-mode">
			<fieldset>
				<legend class="screen-reader-text">
					<?php
					/* translators: %s: post type name, like "Products". */
					echo esc_html( sprintf( __( 'Choose %s by', 'eizport' ), $label ) );
					?>
				</legend>
				<label><input type="radio" name="<?php echo esc_attr( MODE_FIELD . '[' . $type . ']' ); ?>" value="filters" checked="checked" /> <?php esc_html_e( 'By filters', 'eizport' ); ?></label>
				&nbsp;
				<label><input type="radio" name="<?php echo esc_attr( MODE_FIELD . '[' . $type . ']' ); ?>" value="select" /> <?php esc_html_e( 'By selection', 'eizport' ); ?></label>
			</fieldset>
		</li>
		<?php
		if ( ! $target ) {
			render_own_filters( $type );
		}
		?>
		<li class="eizport-picker" data-content="<?php echo esc_attr( $content ); ?>">
			<fieldset>
				<legend class="label-responsive"><?php echo esc_html( $label ); ?>:</legend>
				<?php if ( ! $items ) : ?>
					<p class="description"><?php esc_html_e( 'Nothing to select.', 'eizport' ); ?></p>
				<?php else : ?>
					<input type="search" class="eizport-list-search regular-text" placeholder="<?php esc_attr_e( 'Filter the list', 'eizport' ); ?>" aria-label="<?php esc_attr_e( 'Filter the list', 'eizport' ); ?>" />
					<div class="eizport-checklist-items">
						<?php checklist( $type, $items ); ?>
					</div>
					<p class="description">
						<?php
						esc_html_e( 'Only the checked items are exported, with their attachments and their children of other types (like a product\'s variations).', 'eizport' );
						if ( count( $items ) >= selection_limit() ) {
							echo ' ';
							/* translators: %d: number of items listed. */
							echo esc_html( sprintf( __( 'Only the latest %d are listed: use the filters for the others.', 'eizport' ), selection_limit() ) );
						}
						?>
					</p>
				<?php endif; ?>
			</fieldset>
		</li>
	</ul>
	<?php
}

/**
 * Prints a custom post type's filters, like core's for pages, plus one per
 * taxonomy.
 *
 * @param string $type Post type.
 */
function render_own_filters( $type ) {
	global $wpdb;

	$name = function ( $key ) use ( $type ) {
		return FILTERS_FIELD . '[' . $type . '][' . $key . ']';
	};
	$id   = function ( $key ) use ( $type ) {
		return 'eizport-' . $type . '-' . $key;
	};

	foreach ( get_object_taxonomies( $type, 'objects' ) as $taxonomy ) {
		// No terms, nothing to filter by (the dropdown would only say so).
		if ( ! $taxonomy->show_ui || ! wp_count_terms( array( 'taxonomy' => $taxonomy->name ) ) ) {
			continue;
		}
		?>
		<li class="eizport-own-filter">
			<label for="<?php echo esc_attr( $id( 'tax-' . $taxonomy->name ) ); ?>" class="label-responsive"><?php echo esc_html( $taxonomy->labels->singular_name ); ?>:</label>
			<?php
			wp_dropdown_categories(
				array(
					'taxonomy'        => $taxonomy->name,
					'name'            => $name( 'tax' ) . '[' . $taxonomy->name . ']',
					'id'              => $id( 'tax-' . $taxonomy->name ),
					'show_option_all' => __( 'All', 'eizport' ),
					'hierarchical'    => $taxonomy->hierarchical,
					'hide_empty'      => false,
					'value_field'     => 'term_id',
				)
			);
			?>
		</li>
		<?php
	}

	if ( post_type_supports( $type, 'author' ) ) {
		$authors = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type = %s", $type ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- core's export screen does the same.
		?>
		<li class="eizport-own-filter">
			<label for="<?php echo esc_attr( $id( 'author' ) ); ?>" class="label-responsive"><?php esc_html_e( 'Authors:', 'eizport' ); ?></label>
			<?php
			wp_dropdown_users(
				array(
					'include'         => $authors ? array_map( 'intval', $authors ) : array( 0 ),
					'name'            => $name( 'author' ),
					'id'              => $id( 'author' ),
					'multi'           => true,
					'show_option_all' => __( 'All', 'eizport' ),
					'show'            => 'display_name_with_login',
				)
			);
			?>
		</li>
		<?php
	}
	?>
	<li class="eizport-own-filter">
		<fieldset>
			<legend class="screen-reader-text"><?php esc_html_e( 'Date range:', 'eizport' ); ?></legend>
			<label for="<?php echo esc_attr( $id( 'start-date' ) ); ?>" class="label-responsive"><?php esc_html_e( 'Start date:', 'eizport' ); ?></label>
			<select name="<?php echo esc_attr( $name( 'start_date' ) ); ?>" id="<?php echo esc_attr( $id( 'start-date' ) ); ?>">
				<option value="0"><?php esc_html_e( '&mdash; Select &mdash;', 'eizport' ); ?></option>
				<?php date_options( $type ); ?>
			</select>
			<label for="<?php echo esc_attr( $id( 'end-date' ) ); ?>" class="label-responsive"><?php esc_html_e( 'End date:', 'eizport' ); ?></label>
			<select name="<?php echo esc_attr( $name( 'end_date' ) ); ?>" id="<?php echo esc_attr( $id( 'end-date' ) ); ?>">
				<option value="0"><?php esc_html_e( '&mdash; Select &mdash;', 'eizport' ); ?></option>
				<?php date_options( $type ); ?>
			</select>
		</fieldset>
	</li>
	<li class="eizport-own-filter">
		<label for="<?php echo esc_attr( $id( 'status' ) ); ?>" class="label-responsive"><?php esc_html_e( 'Status:', 'eizport' ); ?></label>
		<select name="<?php echo esc_attr( $name( 'status' ) ); ?>" id="<?php echo esc_attr( $id( 'status' ) ); ?>">
			<option value="0"><?php esc_html_e( 'All', 'eizport' ); ?></option>
			<?php foreach ( statuses( $type ) as $status ) : ?>
				<option value="<?php echo esc_attr( $status->name ); ?>"><?php echo esc_html( $status->label ); ?></option>
			<?php endforeach; ?>
		</select>
	</li>
	<?php
}

/**
 * The statuses a type's items have (not every registered one: products
 * shouldn't offer order statuses).
 *
 * @param string $type Post type.
 * @return \stdClass[] Status objects.
 */
function statuses( $type ) {
	global $wpdb;

	$used = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_status FROM {$wpdb->posts} WHERE post_type = %s", $type ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- like core's export screen.

	return array_filter(
		get_post_stati( array( 'internal' => false ), 'objects' ),
		function ( $status ) use ( $used ) {
			return in_array( $status->name, $used, true );
		}
	);
}

/**
 * Prints the months a type has items in, like core's export_date_options().
 *
 * @param string $type Post type.
 */
function date_options( $type ) {
	global $wpdb, $wp_locale;

	$months = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- core's export screen does the same.
		$wpdb->prepare(
			"SELECT DISTINCT YEAR( post_date ) AS year, MONTH( post_date ) AS month FROM {$wpdb->posts} WHERE post_type = %s AND post_status != 'auto-draft' ORDER BY post_date DESC",
			$type
		)
	);
	foreach ( $months as $date ) {
		if ( ! (int) $date->year ) {
			continue;
		}
		$month = zeroise( $date->month, 2 );
		printf( '<option value="%1$s">%2$s</option>', esc_attr( $date->year . '-' . $month ), esc_html( $wp_locale->get_month( $month ) . ' ' . $date->year ) );
	}
}

/**
 * How many items a checklist lists at most.
 *
 * @return int
 */
function selection_limit() {
	/**
	 * Filters how many items each "By selection" checklist lists at most
	 * (the latest ones; hierarchical types by menu order and title).
	 *
	 * @param int $limit Default 1000.
	 */
	return max( 1, (int) apply_filters( 'eizport_selection_limit', 1000 ) );
}

/**
 * The items a checklist lists.
 *
 * @param string $type Post type.
 * @return \WP_Post[]
 */
function items( $type ) {
	$hierarchical = is_post_type_hierarchical( $type );

	return get_posts(
		array(
			'post_type'              => $type,
			'post_status'            => 'any',
			'posts_per_page'         => selection_limit(),
			'orderby'                => $hierarchical ? array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			) : array( 'date' => 'DESC' ),
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);
}

/**
 * Prints a checklist: a tree for hierarchical types, else a flat list.
 *
 * @param string     $type  Post type.
 * @param \WP_Post[] $items Items.
 */
function checklist( $type, $items ) {
	if ( ! is_post_type_hierarchical( $type ) ) {
		checklist_level( $type, array( 0 => $items ), 0 );
		return;
	}

	$children = array();
	foreach ( $items as $item ) {
		$children[ $item->post_parent ][] = $item;
	}
	// Items whose parent is not listed (trashed, for example) go at the top level.
	$ids = wp_list_pluck( $items, 'ID' );
	foreach ( array_keys( $children ) as $parent_id ) {
		if ( $parent_id && ! in_array( $parent_id, $ids, true ) ) {
			$children[0] = array_merge( $children[0] ?? array(), $children[ $parent_id ] );
			unset( $children[ $parent_id ] );
		}
	}
	checklist_level( $type, $children, 0 );
}

/**
 * Prints one level of a checklist.
 *
 * @param string                 $type      Post type.
 * @param array<int, \WP_Post[]> $children  Items keyed by parent ID.
 * @param int                    $parent_id Parent of the level to print.
 */
function checklist_level( $type, $children, $parent_id ) {
	if ( empty( $children[ $parent_id ] ) ) {
		return;
	}

	echo '<ul>';
	foreach ( $children[ $parent_id ] as $item ) {
		printf(
			'<li><label><input type="checkbox" name="%1$s[]" value="%2$d" /> %3$s</label>',
			esc_attr( IDS_FIELD . '[' . $type . ']' ),
			(int) $item->ID,
			esc_html( item_label( $item ) )
		);
		if ( isset( $children[ $item->ID ] ) && $item->ID ) {
			checklist_level( $type, $children, $item->ID );
		}
		echo '</li>';
	}
	echo '</ul>';
}

/**
 * An item's label: title (or file name, for media), with its status when
 * not published, and whether it is the homepage or the posts page.
 *
 * @param \WP_Post $item Item.
 * @return string
 */
function item_label( $item ) {
	$label = '' === $item->post_title ? __( '(no title)', 'eizport' ) : $item->post_title;
	if ( 'attachment' === $item->post_type ) {
		$label .= ' — ' . wp_basename( $item->guid );
	} elseif ( 'publish' !== $item->post_status ) {
		$label .= ' — ' . ( get_post_statuses()[ $item->post_status ] ?? $item->post_status );
	}
	if ( 'page' === $item->post_type && 'page' === get_option( 'show_on_front' ) ) {
		if ( (int) get_option( 'page_on_front' ) === $item->ID ) {
			$label .= ' — ' . __( 'Homepage', 'eizport' );
		} elseif ( (int) get_option( 'page_for_posts' ) === $item->ID ) {
			$label .= ' — ' . __( 'Posts page', 'eizport' );
		}
	}

	return $label;
}

/**
 * The value sent for one type in a field of this form.
 *
 * @param string $field Field name.
 * @param string $type  Post type.
 * @return mixed
 */
function request_value( $field, $type ) {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- core's export screen sends the form over GET without a nonce and checks the `export` capability itself.
	if ( ! isset( $_GET[ $field ] ) || ! is_array( $_GET[ $field ] ) || ! isset( $_GET[ $field ][ $type ] ) ) {
		return null;
	}

	return wp_unslash( $_GET[ $field ][ $type ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by the callers.
	// phpcs:enable
}

/**
 * The types asked for: the checkboxes, or else core's single "content"
 * radio (without JavaScript).
 *
 * @param array $args Export args.
 * @return string[] Post types; none for all content.
 */
function requested_types( $args ) {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- core's export screen sends the form over GET without a nonce and checks the `export` capability itself.
	$values = isset( $_GET[ TYPES_FIELD ] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_GET[ TYPES_FIELD ] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	// phpcs:enable
	if ( ! $values ) {
		$content = $args['content'] ?? 'all';
		// export_args gets core's own names: "post", "page", "attachment" or a custom post type.
		return 'all' !== $content && post_type_exists( (string) $content ) && isset( content_types()[ $content ] ) ? array( (string) $content ) : array();
	}

	return array_values( array_unique( array_filter( array_map( __NAMESPACE__ . '\post_type_of', $values ) ) ) );
}

/**
 * Core's filters of posts, pages and media, read from the form as core
 * reads them, so they can apply next to other types.
 *
 * @param string $type "post", "page" or "attachment".
 * @return array{author: int, start_date: string, end_date: string, status: string, terms: int[]}
 */
function core_filters( $type ) {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- as above.
	$prefix  = array(
		'post'       => 'post',
		'page'       => 'page',
		'attachment' => 'attachment',
	)[ $type ];
	$get     = function ( $key ) {
		return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	};
	$filters = array(
		'author'     => 'attachment' === $type ? 0 : $get( $prefix . '_author' ),
		'start_date' => $get( $prefix . '_start_date' ),
		'end_date'   => $get( $prefix . '_end_date' ),
		'status'     => 'attachment' === $type ? '' : $get( $prefix . '_status' ),
		'tax'        => 'post' === $type ? array( 'category' => $get( 'cat' ) ) : array(),
	);
	// phpcs:enable

	return own_filters( $type, $filters );
}

/**
 * `export_args`: the types the form asks for, with their filters or
 * selection (see apply_specs()). Unchanged when Tools → Export isn't
 * replaced.
 *
 * @param array $args Export args.
 * @return array
 */
function args( $args ) {
	if ( ! \Eizport\AdminApp\replaces_export() ) {
		return $args;
	}

	return apply_specs( $args, specs_from_request( $args ) );
}

/**
 * What the export form asks for, per type: `ids` checked, or `filters`.
 *
 * @param array $args Export args (for core's single "content" radio).
 * @return array<string, array> Post type => spec; none for all content.
 */
function specs_from_request( $args ) {
	$specs = array();
	foreach ( requested_types( $args ) as $type ) {
		$mode = request_value( MODE_FIELD, $type );
		if ( is_string( $mode ) && 'select' === sanitize_key( $mode ) ) {
			$specs[ $type ] = array( 'ids' => valid_ids( $type, (array) request_value( IDS_FIELD, $type ) ) );
		} elseif ( core_filters_id( $type ) ) {
			$specs[ $type ] = array( 'filters' => core_filters( $type ) );
		} else {
			$specs[ $type ] = array( 'filters' => own_filters( $type, (array) request_value( FILTERS_FIELD, $type ) ) );
		}
	}

	return $specs;
}

/**
 * Specs from structured input (the export ability): post type => `ids`
 * or `filters` (`author`, `start_date` and `end_date` as "YYYY-MM",
 * `status`, `terms` as term IDs). Unknown types are an error.
 *
 * @param array $types Post type => spec.
 * @return array<string, array>|\WP_Error
 */
function specs_from_input( $types ) {
	$specs = array();
	foreach ( $types as $type => $spec ) {
		$type = (string) $type;
		if ( ! isset( content_types()[ $type ] ) ) {
			return new \WP_Error(
				'eizport_invalid_type',
				/* translators: %s: post type. */
				sprintf( __( '"%s" is not a content type that can be exported.', 'eizport' ), $type )
			);
		}
		$spec = is_array( $spec ) ? $spec : array();
		if ( isset( $spec['ids'] ) ) {
			$specs[ $type ] = array( 'ids' => valid_ids( $type, (array) $spec['ids'] ) );
			continue;
		}
		$filters = (array) ( $spec['filters'] ?? array() );
		$tax     = array();
		foreach ( (array) ( $filters['terms'] ?? array() ) as $term_id ) {
			$term = get_term( absint( $term_id ) );
			if ( $term && ! is_wp_error( $term ) ) {
				$tax[ $term->taxonomy ] = $term->term_id;
			}
		}
		$filters['tax'] = $tax;
		$specs[ $type ] = array( 'filters' => own_filters( $type, $filters ) );
	}

	return $specs;
}

/**
 * The IDs of a type among those given; none valid gives array( 0 ), so
 * nothing of the type is exported rather than everything.
 *
 * @param string $type Post type.
 * @param array  $ids  IDs.
 * @return int[]
 */
function valid_ids( $type, $ids ) {
	$valid = array_values(
		array_filter(
			array_unique( array_map( 'absint', $ids ) ),
			function ( $id ) use ( $type ) {
				return $id && get_post_type( $id ) === $type;
			}
		)
	);

	return $valid ? $valid : array( 0 );
}

/**
 * Puts specs in the export args (`eizport_types`). export_wp() is asked
 * for the first type, so it still adds the attachments and featured
 * images of what is exported, and core's own filters are left to the
 * query filter. No specs: the args stay as they are (all content).
 *
 * @param array $args  Export args.
 * @param array $specs Post type => spec.
 * @return array
 */
function apply_specs( $args, $specs ) {
	if ( ! $specs ) {
		// Only menus: export_wp() is asked for menu items, where() picks the rest.
		if ( ! empty( $args['eizport_menus'] ) && \Eizport\Menus\post_ids( $args ) ) {
			$args['content']       = 'nav_menu_item';
			$args['eizport_types'] = array();
		}

		return $args;
	}

	$others             = array_values( array_diff( array_keys( $specs ), array( 'attachment' ) ) );
	$args['content']    = $others ? $others[0] : 'attachment';
	$args['author']     = false;
	$args['category']   = false;
	$args['start_date'] = false;
	$args['end_date']   = false;
	$args['status']     = false;

	$args['eizport_types'] = $specs;

	return $args;
}

/**
 * Validates filters (custom post types', or core's read by core_filters()).
 *
 * @param string $type    Post type.
 * @param array  $filters Filters sent.
 * @return array{author: int, start_date: string, end_date: string, status: string, terms: int[]}
 */
function own_filters( $type, $filters ) {
	$month  = function ( $value ) {
		return is_string( $value ) && preg_match( '/^\d{4}-\d{2}$/', $value ) ? $value : '';
	};
	$status = isset( $filters['status'] ) && is_string( $filters['status'] ) ? sanitize_key( $filters['status'] ) : '';

	$terms = array();
	foreach ( (array) ( $filters['tax'] ?? array() ) as $taxonomy => $term_id ) {
		$term_id = absint( $term_id );
		if ( $term_id && is_object_in_taxonomy( $type, (string) $taxonomy ) && term_exists( $term_id, (string) $taxonomy ) ) {
			$terms[] = $term_id;
		}
	}

	return array(
		'author'     => absint( $filters['author'] ?? 0 ),
		'start_date' => $month( $filters['start_date'] ?? '' ),
		'end_date'   => $month( $filters['end_date'] ?? '' ),
		'status'     => get_post_status_object( $status ) ? $status : '',
		'terms'      => $terms,
	);
}

/**
 * Attachment IDs a content names: block IDs (image, cover, file, video,
 * audio, media & text), galleries, wp-image-N classes, and upload URLs
 * (sized ones too).
 *
 * @param string $content Post content.
 * @param string $base    Uploads base URL.
 * @return int[]
 */
function ids_in_content( $content, $base ) {
	$used = array();
	if ( preg_match_all( '/"(?:id|mediaId)":(\d+)/', $content, $match ) ) {
		$used = array_merge( $used, $match[1] );
	}
	// Gallery blocks and the gallery shortcode list their IDs.
	if ( preg_match_all( '/(?:"ids":\[|ids=")([\d,\s]+)/', $content, $match ) ) {
		foreach ( $match[1] as $list ) {
			$used = array_merge( $used, preg_split( '/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY ) );
		}
	}
	if ( preg_match_all( '/wp-image-(\d+)/', $content, $match ) ) {
		$used = array_merge( $used, $match[1] );
	}
	if ( '' !== $base && preg_match_all( '#' . preg_quote( $base, '#' ) . '/[^"\'\s)<>]+#', $content, $match ) ) {
		foreach ( array_unique( $match[0] ) as $url ) {
			$id = attachment_url_to_postid( preg_replace( '/-\d+x\d+(?=\.\w+$)/', '', $url ) );
			if ( $id ) {
				$used[] = $id;
			}
		}
	}

	return array_values( array_unique( array_map( 'absint', $used ) ) );
}

/**
 * Attachments the items use without being attached to them: images,
 * galleries, covers, files and media in their content (block IDs,
 * wp-image-N classes, [gallery] IDs, upload URLs). Core already adds the
 * attached ones and featured images.
 *
 * @param int[] $ids Items.
 * @return int[] Attachment IDs.
 */
function media_used( $ids ) {
	$found = array();
	$base  = wp_get_upload_dir()['baseurl'];
	foreach ( array_chunk( array_filter( array_map( 'absint', (array) $ids ) ), 50 ) as $chunk ) {
		foreach ( get_posts(
			array(
				'post__in'    => $chunk,
				'post_type'   => 'any',
				'post_status' => 'any',
				'numberposts' => -1,
			)
		) as $post ) {
			$used = ids_in_content( (string) $post->post_content, $base );

			/**
			 * Filters the attachments a post uses, beyond its content: a
			 * plugin adds the ones in its meta (like a product gallery).
			 *
			 * @param int[]    $used Attachment IDs.
			 * @param \WP_Post $post The post.
			 */
			$used  = (array) apply_filters( 'eizport_media_used', array_map( 'absint', $used ), $post );
			$found = array_merge( $found, $used );
		}
	}

	return array_values(
		array_filter(
			array_unique( array_map( 'absint', $found ) ),
			function ( $id ) {
				return $id && 'attachment' === get_post_type( $id );
			}
		)
	);
}

/**
 * Descendants of the items of other exportable types (a product's
 * variations, say); attachments are left to core.
 *
 * @param int[]  $ids  Items.
 * @param string $type Their post type.
 * @return int[]
 */
function children_of( $ids, $type ) {
	$types = array_diff( get_post_types( array( 'can_export' => true ) ), array( $type, 'attachment' ) );
	$found = array();
	$level = array_filter( array_map( 'absint', $ids ) );

	while ( $level && $types ) {
		$level = array_diff(
			get_posts(
				array(
					'post_type'       => array_values( $types ),
					'post_status'     => 'any',
					'post_parent__in' => $level,
					'posts_per_page'  => -1,
					'fields'          => 'ids',
				)
			),
			$found
		);
		$found = array_merge( $found, $level );
	}

	return array_map( 'intval', $found );
}

/**
 * WP_Query args for a type's items that match filters, as type_condition()
 * picks them: every status but auto-draft unless one is chosen.
 *
 * @param string $type    Post type.
 * @param array  $filters Filters, as own_filters() returns them.
 * @return array
 */
function query_args( $type, $filters ) {
	$query = array(
		'post_type'   => $type,
		'post_status' => empty( $filters['status'] ) ? array_values( array_diff( get_post_stati(), array( 'auto-draft' ) ) ) : $filters['status'],
	);
	if ( ! empty( $filters['author'] ) ) {
		$query['author'] = (int) $filters['author'];
	}
	$dates = array();
	if ( ! empty( $filters['start_date'] ) ) {
		$dates['after'] = gmdate( 'Y-m-d', strtotime( $filters['start_date'] ) );
	}
	if ( ! empty( $filters['end_date'] ) ) {
		$dates['before'] = gmdate( 'Y-m-d', strtotime( '+1 month', strtotime( $filters['end_date'] ) ) );
	}
	if ( $dates ) {
		$query['date_query'] = array(
			array_merge(
				$dates,
				array(
					'inclusive' => true,
					'column'    => 'post_date',
				)
			),
		);
	}
	$tax = array();
	foreach ( $filters['terms'] ?? array() as $term_id ) {
		$term = get_term( (int) $term_id );
		if ( $term && ! is_wp_error( $term ) ) {
			$tax[] = array(
				'taxonomy'         => $term->taxonomy,
				'terms'            => array( $term->term_id ),
				'include_children' => true,
			);
		}
	}
	if ( $tax ) {
		$query['tax_query'] = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- once per export or page of items.
	}

	return $query;
}

/**
 * The IDs of a type's items that match filters.
 *
 * @param string $type    Post type.
 * @param array  $filters Filters, as own_filters() returns them.
 * @return int[]
 */
function filtered_ids( $type, $filters ) {
	return array_map(
		'intval',
		get_posts(
			array_merge(
				query_args( $type, $filters ),
				array(
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			)
		)
	);
}

/**
 * A page of a type's items, for lists: searched, sorted and filtered.
 *
 * @param string $type  Post type.
 * @param array  $input `search`, `page`, `per_page` (1–100), `orderby`
 *                      (date, title, menu_order), `order`, `filters`,
 *                      `include` (only these IDs).
 * @return array{items: array, total: int, total_pages: int}
 */
function items_page( $type, $input ) {
	$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 20 ) ) );
	$orderby  = in_array( $input['orderby'] ?? '', array( 'date', 'title', 'menu_order' ), true ) ? $input['orderby'] : 'date';
	$order    = 'asc' === strtolower( (string) ( $input['order'] ?? '' ) ) ? 'ASC' : 'DESC';
	$filters  = specs_from_input( array( $type => array( 'filters' => (array) ( $input['filters'] ?? array() ) ) ) );
	$filters  = is_wp_error( $filters ) ? array() : $filters[ $type ]['filters'];

	$query = new \WP_Query(
		array_merge(
			query_args( $type, $filters ),
			array(
				's'                      => (string) ( $input['search'] ?? '' ),
				'paged'                  => max( 1, (int) ( $input['page'] ?? 1 ) ),
				'posts_per_page'         => $per_page,
				'orderby'                => array(
					$orderby => $order,
					'ID'     => $order,
				),
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'ignore_sticky_posts'    => true,
			),
			// Only the items asked for; none asked for, none listed (post__in ignores an empty list).
			isset( $input['include'] ) ? array( 'post__in' => array_merge( array( 0 ), array_map( 'absint', (array) $input['include'] ) ) ) : array()
		)
	);
	$front = \Eizport\Options\front_pages();
	$roles = \Eizport\Options\linked_roles();

	return array(
		'items'       => array_map(
			function ( $post ) use ( $front, $roles ) {
				return array(
					'id'         => (int) $post->ID,
					'title'      => '' === $post->post_title ? __( '(no title)', 'eizport' ) : $post->post_title,
					'status'     => $post->post_status,
					'date'       => mysql_to_rfc3339( $post->post_date ),
					'parent'     => (int) $post->post_parent,
					'author'     => (int) $post->post_author,
					'file'       => 'attachment' === $post->post_type ? wp_basename( $post->guid ) : '',
					'homepage'   => $front['front'] === (int) $post->ID,
					'posts_page' => $front['posts'] === (int) $post->ID,
					'roles'      => $roles[ (int) $post->ID ] ?? array(),
				);
			},
			$query->posts
		),
		'total'       => (int) $query->found_posts,
		'total_pages' => (int) $query->max_num_pages,
	);
}

/**
 * The SQL that picks one type's items.
 *
 * @param string $type Post type.
 * @param array  $spec `ids`, or `filters`.
 * @return string
 */
function type_condition( $type, $spec ) {
	global $wpdb;

	$where = array( $wpdb->prepare( "{$wpdb->posts}.post_type = %s", $type ) );
	if ( isset( $spec['ids'] ) ) {
		$where[] = "{$wpdb->posts}.ID IN (" . implode( ',', array_map( 'absint', $spec['ids'] ) ) . ')';
	}

	$filters = $spec['filters'] ?? array();
	if ( ! empty( $filters['author'] ) ) {
		$where[] = $wpdb->prepare( "{$wpdb->posts}.post_author = %d", $filters['author'] );
	}
	if ( ! empty( $filters['start_date'] ) ) {
		$where[] = $wpdb->prepare( "{$wpdb->posts}.post_date >= %s", gmdate( 'Y-m-d', strtotime( $filters['start_date'] ) ) );
	}
	if ( ! empty( $filters['end_date'] ) ) {
		$where[] = $wpdb->prepare( "{$wpdb->posts}.post_date < %s", gmdate( 'Y-m-d', strtotime( '+1 month', strtotime( $filters['end_date'] ) ) ) );
	}
	if ( ! empty( $filters['status'] ) ) {
		$where[] = $wpdb->prepare( "{$wpdb->posts}.post_status = %s", $filters['status'] );
	}
	foreach ( $filters['terms'] ?? array() as $term_id ) {
		$term   = get_term( (int) $term_id );
		$tt_ids = array();
		if ( $term && ! is_wp_error( $term ) ) {
			foreach ( array_merge( array( $term->term_id ), (array) get_term_children( $term->term_id, $term->taxonomy ) ) as $id ) {
				$child = get_term( (int) $id );
				if ( $child && ! is_wp_error( $child ) ) {
					$tt_ids[] = (int) $child->term_taxonomy_id;
				}
			}
		}
		$where[] = "{$wpdb->posts}.ID IN (SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN (" . implode( ',', $tt_ids ? $tt_ids : array( 0 ) ) . '))';
	}

	return '(' . implode( ' AND ', $where ) . ')';
}

/**
 * The WHERE of export_wp()'s post query for the types asked for: each
 * type with its own conditions, plus the children of the items selected.
 *
 * @param array $args Export args.
 * @return string '' when nothing is to change.
 */
function where( $args ) {
	global $wpdb;

	// No types (all content), unless only menus are chosen (an empty list, see apply_specs()).
	if ( ! isset( $args['eizport_types'] ) || ! is_array( $args['eizport_types'] ) || ( ! $args['eizport_types'] && ! \Eizport\Menus\post_ids( $args ) ) ) {
		return '';
	}

	$parts = array();
	$also  = array();
	// Unless the export says, as Settings → Eizport → "Only attached media" (off) says.
	$media_used = array_key_exists( 'eizport_media_used', $args ) ? ! empty( $args['eizport_media_used'] ) : ! \Eizport\Settings\get()['attached_only'];
	foreach ( $args['eizport_types'] as $type => $spec ) {
		$condition = type_condition( (string) $type, (array) $spec );
		$parts[]   = $condition;
		// The items' children of other types (a product's variations) come along, selected or filtered.
		$ids      = isset( $spec['ids'] ) ? $spec['ids'] : filtered_ids( (string) $type, (array) ( $spec['filters'] ?? array() ) );
		$children = children_of( array_map( 'intval', (array) $ids ), (string) $type );
		$also     = array_merge( $also, $children );
		if ( $media_used ) {
			$also = array_merge( $also, media_used( array_merge( (array) $ids, $children ) ) );
		}
	}
	// The media the look chosen uses (logo, header and background images, widgets' media…).
	if ( $media_used ) {
		$also = array_merge( $also, \Eizport\Appearance\media_ids( \Eizport\Appearance\chosen( $args ) ) );
	}
	// The templates and template parts chosen (and, with media, the images their blocks use).
	$also = array_merge( $also, \Eizport\Appearance\post_ids( \Eizport\Appearance\chosen( $args ), $media_used ) );
	// The menus chosen: classic menus' items and block navigations.
	$also = array_merge( $also, \Eizport\Menus\post_ids( $args ) );
	if ( $also ) {
		$parts[] = "{$wpdb->posts}.ID IN (" . implode( ',', array_unique( $also ) ) . ')';
	}
	if ( ! $parts ) {
		return '';
	}

	return "{$wpdb->posts}.post_status != 'auto-draft' AND (" . implode( ' OR ', $parts ) . ')';
}

/**
 * Replaces the WHERE of export_wp()'s post query by where().
 *
 * @param array $args Export args.
 */
function limit_query( $args ) {
	$where = where( $args );
	if ( '' === $where ) {
		return;
	}

	$filter = function ( $query ) use ( &$filter, $where ) {
		global $wpdb;

		// "SELECT ID FROM {$wpdb->posts} $join WHERE $where" in export_wp(); there's no join without core's category filter.
		if ( ! preg_match( '/^SELECT ID FROM ' . preg_quote( $wpdb->posts, '/' ) . '\s+WHERE /', $query ) ) {
			return $query;
		}
		remove_filter( 'query', $filter );

		return "SELECT ID FROM {$wpdb->posts} WHERE " . $where;
	};

	add_filter( 'query', $filter );
}

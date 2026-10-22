<?php
/**
 * Tests for the abilities (Abilities API).
 *
 * Exports read the site and write only files (copies, removed after each
 * test); the cleanup event is intercepted so nothing is written to the
 * database. Imports are only checked up to their input validation here.
 *
 * @package Eizport
 */

namespace Eizport\Tests;

use Eizport\Copies;
use Eizport\Files;

/**
 * Abilities tests.
 */
class AbilitiesTest extends Test_Case {

	/**
	 * Logs in an administrator.
	 */
	protected function set_up() {
		parent::set_up();
		// Copies go to a temporary uploads folder, not the site's.
		$this->sandbox_uploads();
		// No cron event in the database.
		add_filter( 'pre_schedule_event', '__return_true' );
		// The plugin's default settings, whatever the site has.
		$this->stored_settings( \Eizport\Settings\defaults() );
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
			)
		);
		wp_set_current_user( $admins[0]->ID );
	}

	/**
	 * Removes the copies made and logs out.
	 */
	protected function tear_down() {
		foreach ( Copies\copies() as $copy ) {
			if ( $copy['time'] >= $this->started ) {
				Copies\delete_copies( array( $copy['name'] ) );
			}
		}
		remove_filter( 'pre_schedule_event', '__return_true' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * When the test started, to tell its copies apart.
	 *
	 * @var int
	 */
	private $started = 0;

	/**
	 * Runs an ability as a client would (schema, permission, then callback).
	 *
	 * @param string     $name  Ability name.
	 * @param array|null $input Input.
	 * @return mixed
	 */
	private function run_ability( $name, $input = null ) {
		$this->started = $this->started ? $this->started : time() - 1;

		return wp_get_ability( $name )->execute( $input );
	}

	/**
	 * The variable product and its variations, or skips.
	 *
	 * @return array{product: int, variations: int[]}
	 */
	private function variable_product() {
		$variations = post_type_exists( 'product_variation' ) ? get_posts(
			array(
				'post_type'   => 'product_variation',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		) : array();
		if ( ! $variations ) {
			$this->markTestSkipped( 'A WooCommerce variable product is needed.' );
		}

		return array(
			'product'    => (int) wp_get_post_parent_id( $variations[0] ),
			'variations' => array_map( 'intval', $variations ),
		);
	}

	public function test_registered_with_their_annotations() {
		$abilities = wp_get_abilities( array( 'category' => 'eizport' ) );
		$this->assertSame( array( 'eizport/get-export-options', 'eizport/get-items', 'eizport/get-suggestions', 'eizport/get-reviews', 'eizport/get-options', 'eizport/export', 'eizport/get-copies', 'eizport/delete-copies', 'eizport/get-settings', 'eizport/get-export-defaults', 'eizport/save-export-defaults', 'eizport/clear-export-defaults', 'eizport/update-settings', 'eizport/import' ), array_keys( $abilities ) );

		$annotations = function ( $name ) use ( $abilities ) {
			return $abilities[ $name ]->get_meta()['annotations'];
		};
		$this->assertTrue( $annotations( 'eizport/get-export-options' )['readonly'] );
		$this->assertTrue( $annotations( 'eizport/get-items' )['readonly'] );
		$this->assertTrue( $annotations( 'eizport/get-copies' )['readonly'] );
		$this->assertTrue( $annotations( 'eizport/get-suggestions' )['readonly'] );
		$this->assertTrue( $annotations( 'eizport/get-reviews' )['readonly'] );
		$this->assertTrue( $annotations( 'eizport/delete-copies' )['destructive'] );
		$this->assertTrue( $annotations( 'eizport/delete-copies' )['idempotent'] );
		$this->assertFalse( $annotations( 'eizport/export' )['readonly'] );
		$this->assertTrue( $abilities['eizport/export']->get_meta()['show_in_rest'] );
	}

	public function test_export_defaults_are_sanitized_and_scoped() {
		$page  = (int) get_posts(
			array(
				'post_type'   => 'page',
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		)[0];
		$clean = \Eizport\Defaults\sanitize(
			array(
				'types'    => array(
					'page' => array( 'ids' => array( $page, 999999 ) ),
					'nope' => array( 'ids' => array( 1 ) ),
				),
				'settings' => array(
					'format'        => 'zip',
					'filename'      => 'site.wxrz',
					'option_names'  => array( 'blogname', 'siteurl' ),
					'option_groups' => array( 'basics', 'unknown' ),
					'plugins'       => array( 'not/installed.php' ),
				),
			)
		);
		$this->assertSame( array( 'page' => array( 'ids' => array( $page ) ) ), $clean['types'], 'Unknown types and items are dropped.' );
		$filtered = \Eizport\Defaults\sanitize( array( 'types' => array( 'page' => array( 'filters' => array( 'status' => 'publish' ) ) ) ) );
		$this->assertEquals( (object) array( 'status' => 'publish' ), $filtered['types']['page']['filters'], 'Only the filters set, as the export takes them.' );
		$this->assertSame( 'wxr', $clean['settings']['format'] );
		$this->assertSame( 'site', $clean['settings']['filename'] );
		$this->assertSame( array( 'blogname' ), $clean['settings']['option_names'], 'Options never exported are dropped.' );
		$this->assertSame( array( 'basics' ), $clean['settings']['option_groups'] );
		$this->assertSame( array(), $clean['settings']['plugins'] );
		$this->assertTrue( $clean['settings']['media'] );
		$this->assertSame( array(), $clean['settings']['include_suggested'], 'Suggestions wait for their row.' );
		$rows = \Eizport\Defaults\sanitize(
			array(
				'settings' => array(
					'include_suggested' => array( 'page', '__reviews', 'nope' ),
					'review_ids'        => array( '3', 'x', 3 ),
				),
			)
		)['settings'];
		$this->assertSame( array( 'page', '__reviews' ), $rows['include_suggested'] );
		$this->assertSame( array( 3 ), $rows['review_ids'] );

		// Saved per user by default; the site's need manage_options. Nothing reaches the database.
		$written = array();
		$keep    = function ( $check, $object_id, $key, $value ) use ( &$written ) {
			// A user option: the key carries the site's prefix.
			if ( $GLOBALS['wpdb']->get_blog_prefix() . \Eizport\Defaults\KEY === $key ) {
				$written['user'] = $value;
				return true;
			}
			return $check;
		};
		add_filter( 'update_user_metadata', $keep, 10, 4 );
		$saved = $this->run_ability( 'eizport/save-export-defaults', array( 'settings' => array( 'filename' => 'mine' ) ) );
		remove_filter( 'update_user_metadata', $keep, 10 );
		$this->assertSame( 'mine', $saved['settings']['filename'] );
		$this->assertSame( 'mine', $written['user']['settings']['filename'] );

		$this->stored_settings( array( 'defaults_scope' => 'site' ) );
		$this->assertTrue( \Eizport\Defaults\site_wide() );
		$editor = new \WP_User( 0 );
		$editor->add_cap( 'export' );
		wp_set_current_user( $editor->ID );
		$this->assertFalse( \Eizport\Defaults\can_save(), 'The site\'s defaults need manage_options.' );
	}

	/**
	 * Makes the plugin's settings read as given.
	 *
	 * @param array $value Settings.
	 */
	private function stored_settings( $value ) {
		add_filter(
			'pre_option_' . \Eizport\Settings\OPTION,
			function () use ( $value ) {
				return $value;
			}
		);
	}

	public function test_permissions() {
		wp_set_current_user( 0 );
		$this->assertInstanceOf( \WP_Error::class, $this->run_ability( 'eizport/get-copies' ) );
		$this->assertInstanceOf( \WP_Error::class, $this->run_ability( 'eizport/import', array( 'copy' => 'x' ) ) );
	}

	public function test_items_are_paged_searched_and_filtered() {
		$pages = $this->run_ability(
			'eizport/get-items',
			array(
				'type'     => 'page',
				'per_page' => 1,
				'orderby'  => 'title',
				'order'    => 'asc',
			)
		);
		$this->assertCount( 1, $pages['items'] );
		$this->assertGreaterThanOrEqual( 1, $pages['total'] );
		$this->assertSame( $pages['total'], $pages['total_pages'] );
		$this->assertSame( 'page', get_post_type( $pages['items'][0]['id'] ) );

		$first  = $pages['items'][0];
		$search = $this->run_ability(
			'eizport/get-items',
			array(
				'type'   => 'page',
				'search' => $first['title'],
			)
		);
		$this->assertContains( $first['id'], wp_list_pluck( $search['items'], 'id' ) );

		$chosen = $this->run_ability(
			'eizport/get-items',
			array(
				'type'    => 'page',
				'include' => array( $first['id'] ),
			)
		);
		$this->assertSame( array( $first['id'] ), wp_list_pluck( $chosen['items'], 'id' ), 'Only the items chosen.' );
		$this->assertSame(
			0,
			$this->run_ability(
				'eizport/get-items',
				array(
					'type'    => 'page',
					'include' => array(),
				)
			)['total'],
			'None chosen, none listed.'
		);

		$none = $this->run_ability(
			'eizport/get-items',
			array(
				'type'    => 'page',
				'filters' => array( 'status' => 'no-such-status' ),
			)
		);
		$this->assertSame( $pages['total'], $none['total'], 'An unknown status is no filter, as in eizport/export.' );

		$this->assertSame( 'ability_invalid_input', $this->run_ability( 'eizport/get-items', array( 'type' => 'nope' ) )->get_error_code() );
		$this->assertSame( 'ability_invalid_input', $this->run_ability( 'eizport/get-items', array() )->get_error_code() );
		$this->assertSame( 'eizport_missing_type', \Eizport\Abilities\get_items( array() )->get_error_code() );
	}

	public function test_items_mark_the_homepage() {
		$front = (int) get_posts(
			array(
				'post_type'   => 'page',
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		)[0];
		$pin   = function ( $value, $option ) use ( $front ) {
			return 'show_on_front' === $option ? 'page' : $front;
		};
		add_filter( 'pre_option_show_on_front', $pin, 10, 2 );
		add_filter( 'pre_option_page_on_front', $pin, 10, 2 );

		$items = $this->run_ability(
			'eizport/get-items',
			array(
				'type'     => 'page',
				'per_page' => 100,
			)
		)['items'];
		remove_filter( 'pre_option_show_on_front', $pin, 10 );
		remove_filter( 'pre_option_page_on_front', $pin, 10 );

		$marked = wp_list_pluck( wp_list_filter( $items, array( 'homepage' => true ) ), 'id' );
		$this->assertSame( array( $front ), array_values( $marked ) );
	}

	public function test_options_one_by_one_without_denied_ones() {
		$page  = $this->run_ability(
			'eizport/get-options',
			array(
				'search'   => 'blog',
				'per_page' => 100,
			)
		);
		$names = wp_list_pluck( $page['items'], 'name' );
		$this->assertContains( 'blogname', $names );
		$blogname = array_values( wp_list_filter( $page['items'], array( 'name' => 'blogname' ) ) )[0];
		$this->assertSame( \Eizport\Options\value_summary( get_option( 'blogname' ) ), $blogname['value'] );
		$this->assertSame( '{"a":[1,2]}', \Eizport\Options\value_summary( array( 'a' => array( 1, 2 ) ) ) );
		$this->assertSame( 200, mb_strlen( \Eizport\Options\value_summary( str_repeat( 'x', 300 ) ) ) );
		$this->assertSame( count( $names ), $page['total'] );

		$all   = $this->run_ability( 'eizport/get-options', array( 'per_page' => 100 ) );
		$every = array();
		for ( $i = 1; $i <= $all['total_pages']; $i++ ) {
			$every = array_merge(
				$every,
				wp_list_pluck(
					$this->run_ability(
						'eizport/get-options',
						array(
							'per_page' => 100,
							'page'     => $i,
						)
					)['items'],
					'name'
				)
			);
		}
		$this->assertNotContains( 'siteurl', $every );
		$this->assertNotContains( 'admin_email', $every );
		$this->assertEmpty( preg_grep( '/^_(site_)?transient_/', $every ) );
		$this->assertSame( $every, \Eizport\Options\all_names(), 'options.all exports what is listed.' );

		$chosen = $this->run_ability( 'eizport/get-options', array( 'names' => array( 'blogname', 'siteurl', 'blogdescription' ) ) );
		$this->assertSame( array( 'blogdescription', 'blogname' ), wp_list_pluck( $chosen['items'], 'name' ), 'Only the options chosen, never the denied ones.' );

		$basics = wp_list_pluck( $this->run_ability( 'eizport/get-options', array( 'group' => 'basics' ) )['items'], 'name' );
		$this->assertContains( 'blogname', $basics, 'Basics has the site title.' );
		$this->assertContains( 'permalink_structure', $basics );
		$this->assertNotContains( 'comments_per_page', $basics, 'Discussion is not a basic.' );
		$this->assertSame( 'ability_invalid_input', $this->run_ability( 'eizport/get-options', array( 'group' => 'nope' ) )->get_error_code() );

		$front = wp_list_filter( $this->run_ability( 'eizport/get-options', array( 'search' => 'page_on_front' ) )['items'], array( 'name' => 'page_on_front' ) );
		$this->assertTrue( array_values( $front )[0]['post_id'], 'Its post ID is mapped on import.' );
	}

	public function test_settings_abilities() {
		$settings = $this->run_ability( 'eizport/get-settings' );
		$this->assertArrayHasKey( 'copy_lifetime', $settings );
		$this->assertContains( 0, wp_list_pluck( $settings['lifetime_choices'], 'value' ), 'Until deleted is a choice.' );

		$this->assertSame( 'ability_invalid_input', $this->run_ability( 'eizport/update-settings', array( 'copy_lifetime' => 5 ) )->get_error_code() );
		$this->assertSame( 'ability_invalid_input', $this->run_ability( 'eizport/update-settings', array( 'colour' => 'red' ) )->get_error_code() );
		$this->assertSame( 'ability_invalid_input', $this->run_ability( 'eizport/update-settings', array( 'defaults_scope' => 'everyone' ) )->get_error_code() );
		$this->assertSame( 'ability_invalid_input', $this->run_ability( 'eizport/update-settings', array( 'linked_options' => 'sometimes' ) )->get_error_code() );
		$this->assertSame( 'ability_invalid_input', $this->run_ability( 'eizport/update-settings', array( 'experimental_ui' => true ) )->get_error_code() );
		$this->assertContains( 'site', wp_list_pluck( $settings['scope_choices'], 'value' ) );
		$this->assertContains( 'export', wp_list_pluck( $settings['linked_choices'], 'value' ) );

		wp_set_current_user( 0 );
		$this->assertInstanceOf( \WP_Error::class, $this->run_ability( 'eizport/get-settings' ) );
	}

	public function test_export_options_list_the_types() {
		$options = $this->run_ability( 'eizport/get-export-options' );
		$types   = wp_list_pluck( $options['types'], 'type' );

		$this->assertContains( 'page', $types );
		$this->assertContains( 'attachment', $types );
		$this->assertContains( 'general', wp_list_pluck( $options['option_groups'], 'group' ) );
		$this->assertContains( get_stylesheet(), wp_list_pluck( $options['themes'], 'stylesheet' ) );
		if ( post_type_exists( 'product' ) ) {
			$this->assertContains( 'product', $types );
		}

		$this->assertIsInt( $options['review_count'] );
		if ( ! post_type_exists( 'product' ) ) {
			$this->assertSame( 0, $options['review_count'], 'No reviews row without WooCommerce.' );
		}
	}

	public function test_invalid_input() {
		$codes = function ( $input ) {
			$result = $this->run_ability( 'eizport/export', $input );
			return is_wp_error( $result ) ? $result->get_error_code() : 'no error';
		};

		$this->assertSame( 'eizport_invalid_type', $codes( array( 'types' => array( 'nope' => array() ) ) ) );
		$this->assertSame( 'eizport_invalid_options', $codes( array( 'options' => array( 'names' => array( 'siteurl' ) ) ) ) );
		$this->assertSame( 'eizport_invalid_blueprint', $codes( array( 'blueprint' => array( 'plugins' => array( 'nope/nope.php' ) ) ) ) );
		$this->assertSame( 'ability_invalid_input', $codes( array( 'colour' => 'red' ) ) );
		$this->assertSame( 'ability_invalid_input', $codes( array( 'types' => array( 'page' => array( 'filters' => array( 'start_date' => '2026-1' ) ) ) ) ) );

		$import = $this->run_ability( 'eizport/import', array( 'copy' => '../wp-config.php' ) );
		$this->assertSame( 'eizport_invalid_copy', $import->get_error_code() );
		$this->assertSame( 'eizport_missing_copy', $this->run_ability( 'eizport/import', array() )->get_error_code() );
		$this->assertSame( 'eizport_missing_names', $this->run_ability( 'eizport/delete-copies', array() )->get_error_code() );
	}

	public function test_export_writes_a_bundle_copy() {
		$product = $this->variable_product();
		$page    = (int) get_posts(
			array(
				'post_type'   => 'page',
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		)[0];
		$copy    = $this->run_ability(
			'eizport/export',
			array(
				'types'   => array(
					'page'    => array( 'ids' => array( $page ) ),
					'product' => array( 'filters' => array( 'status' => 'publish' ) ),
				),
				'options' => array( 'groups' => array( 'general' ) ),
			)
		);

		$this->assertIsArray( $copy, is_wp_error( $copy ) ? $copy->get_error_message() : '' );
		$this->assertSame( 'wxrz', $copy['format'] );
		$this->assertSame( '', $copy['playground_url'] );
		$this->assertStringContainsString( 'eizport_playground=' . $copy['name'], $copy['download_url'] );

		$tar = $this->temp_file();
		$this->assertTrue( \Eizport\Wxrz\decompress( Copies\path( $copy['name'] ), $tar ) );
		$manifest = \Eizport\Bundle\read_manifest( $tar );
		$this->assertSame( 'options.json', $manifest['options'] );

		$xml = '';
		\Eizport\Tar\each_file(
			$tar,
			function ( $name, $size, $handle ) use ( $manifest, &$xml ) {
				if ( $name === $manifest['wxr'][0] ) {
					$xml = (string) fread( $handle, $size ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				}
			}
		);
		$this->assertStringContainsString( '<wp:post_id>' . $page . '</wp:post_id>', $xml );
		$this->assertStringContainsString( '<wp:post_id>' . $product['product'] . '</wp:post_id>', $xml );
		foreach ( $product['variations'] as $variation ) {
			$this->assertStringContainsString( '<wp:post_id>' . $variation . '</wp:post_id>', $xml );
		}

		$again = $this->run_ability( 'eizport/export', array() );
		$this->assertSame( 'eizport_export_unavailable', $again->get_error_code(), 'export_wp() runs once per request.' );

		$this->assertContains( $copy['name'], wp_list_pluck( $this->run_ability( 'eizport/get-copies' ), 'name' ) );
		$this->assertSame( array( 'deleted' => 1 ), $this->run_ability( 'eizport/delete-copies', array( 'names' => array( $copy['name'] ) ) ) );
		$this->assertFileDoesNotExist( Files\tmp_path() . '/' . $copy['name'] );
	}

	/**
	 * A page as the static homepage, without touching the database.
	 *
	 * @return int The page's ID.
	 */
	private function page_as_homepage() {
		$page = (int) get_posts(
			array(
				'post_type'   => 'page',
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		)[0];
		add_filter(
			'pre_option_show_on_front',
			function () {
				return 'page';
			}
		);
		add_filter(
			'pre_option_page_on_front',
			function () use ( $page ) {
				return $page;
			}
		);

		return $page;
	}

	public function test_media_used_in_content() {
		$content = '<!-- wp:image {"id":12,"sizeSlug":"large"} --><figure><img class="wp-image-12" /></figure><!-- /wp:image -->'
			. '<!-- wp:gallery {"ids":[21, 22]} /--><!-- wp:media-text {"mediaId":31} /-->[gallery ids="41,42"]<img class="wp-image-51" />';
		$ids     = \Eizport\Selection\ids_in_content( $content, '' );
		sort( $ids );
		$this->assertSame( array( 12, 21, 22, 31, 41, 42, 51 ), $ids );
		$this->assertSame( array(), \Eizport\Selection\ids_in_content( 'No media.', '' ) );
	}

	public function test_linked_options_and_the_woocommerce_compat() {
		$linked = \Eizport\Options\linked_options();
		$this->assertSame( array( 'show_on_front' ), $linked['page_on_front']['with'] );
		// The WooCommerce compatibility, whether WooCommerce is active or not (it isn't in CI).
		$compat = '\\Eizport\\Compat\\WooCommerce\\';
		$this->assertArrayHasKey( 'woocommerce_cart_page_id', call_user_func( $compat . 'linked_options', array() ) );
		$this->assertArrayHasKey( 'woocommerce', call_user_func( $compat . 'option_groups', array() ) );
		$this->assertContains( 'woocommerce_db_version', call_user_func( $compat . 'denied_options', array() ) );
		$this->assertSame( class_exists( 'WooCommerce' ), isset( $linked['woocommerce_cart_page_id'] ), 'Registered only with WooCommerce.' );

		$add = function ( $linked_list ) {
			$linked_list['my_landing_page'] = array( 'label' => 'Landing page' );
			return $linked_list;
		};
		add_filter( 'eizport_linked_options', $add );
		$this->assertContains( 'my_landing_page', \Eizport\Options\post_id_options() );
		remove_filter( 'eizport_linked_options', $add );

		$deny = function ( $names ) {
			return array_merge( $names, array( 'my_api_key' ) );
		};
		add_filter( 'eizport_denied_options', $deny );
		$this->assertTrue( \Eizport\Options\is_denied( 'my_api_key' ) );
		remove_filter( 'eizport_denied_options', $deny );

		$page  = $this->page_as_homepage();
		$names = \Eizport\Options\linked_names( array( $page ) );
		$this->assertContains( 'page_on_front', $names );
		$this->assertContains( 'show_on_front', $names, 'It comes with page_on_front.' );
		$items = $this->run_ability(
			'eizport/get-items',
			array(
				'type'     => 'page',
				'per_page' => 100,
			)
		)['items'];
		$item  = array_values( wp_list_filter( $items, array( 'id' => $page ) ) )[0];
		$this->assertContains( 'Homepage', wp_list_pluck( $item['roles'], 'label' ) );
	}

	/**
	 * Runs alone: export_wp() runs once per process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_export_brings_linked_options() {
		$page = $this->page_as_homepage();
		$copy = $this->run_ability(
			'eizport/export',
			array(
				'types'   => array( 'page' => array( 'ids' => array( $page ) ) ),
				'options' => array( 'linked' => true ),
				'media'   => false,
			)
		);
		$this->assertIsArray( $copy, is_wp_error( $copy ) ? $copy->get_error_message() : '' );

		$tar = $this->temp_file();
		$this->assertTrue( \Eizport\Wxrz\decompress( Copies\path( $copy['name'] ), $tar ) );
		$json = '';
		\Eizport\Tar\each_file(
			$tar,
			function ( $name, $size, $handle ) use ( &$json ) {
				if ( 'options.json' === $name ) {
					$json = (string) fread( $handle, $size ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				}
			}
		);
		$data = json_decode( $json, true );
		$this->assertSame( $page, (int) $data['options']['page_on_front'] );
		$this->assertSame( 'page', $data['options']['show_on_front'] );
		$this->assertContains( 'page_on_front', $data['post_ids'] );
	}

	/**
	 * Runs alone: export_wp() runs once per process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_export_writes_a_zip_bundle_that_imports_back() {
		$page = (int) get_posts(
			array(
				'post_type'   => 'page',
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		)[0];
		$copy = $this->run_ability(
			'eizport/export',
			array(
				'types'   => array( 'page' => array( 'ids' => array( $page ) ) ),
				'options' => array( 'groups' => array( 'general' ) ),
				'zip'     => true,
			)
		);

		$this->assertIsArray( $copy, is_wp_error( $copy ) ? $copy->get_error_message() : '' );
		$this->assertSame( 'zip', $copy['format'] );
		$this->assertStringEndsWith( '.zip', $copy['name'] );
		$this->assertSame( '', $copy['playground_url'], 'Not a Playground .zip.' );
		$path = Copies\path( $copy['name'] );
		$this->assertTrue( \Eizport\Bundle\is_zip_bundle( $path ) );

		$wxr = $this->temp_file();
		$dir = \Eizport\Import\prepare_wxr( $path, $wxr );
		$this->assertIsString( $dir, is_wp_error( $dir ) ? $dir->get_error_message() : '' );
		$this->assertNotSame( '', $dir, 'Unpacked as a bundle.' );
		$this->assertStringContainsString( '<wp:post_id>' . $page . '</wp:post_id>', (string) file_get_contents( $wxr ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertArrayHasKey( 'blogname', \Eizport\Bundle\read_options( $dir )['options'] );
		\Eizport\Bundle\delete_folder( $dir );
	}

	/**
	 * A product with an approved review, if the site has one.
	 *
	 * @return array{0: int, 1: int}|null Product and review IDs.
	 */
	private function reviewed_product() {
		if ( ! post_type_exists( 'product' ) ) {
			return null;
		}
		$review = get_comments(
			array(
				'post_type' => 'product',
				'type'      => 'review',
				'status'    => 'approve',
				'number'    => 1,
			)
		);

		return $review ? array( (int) $review[0]->comment_post_ID, (int) $review[0]->comment_ID ) : null;
	}

	/**
	 * Runs alone: export_wp() runs once per process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_export_leaves_out_reviews_not_chosen() {
		$reviewed = $this->reviewed_product();
		if ( ! $reviewed ) {
			$this->markTestSkipped( 'No WooCommerce product review here.' );
		}
		$copy = $this->run_ability(
			'eizport/export',
			array(
				'types'   => array( 'product' => array( 'ids' => array( $reviewed[0] ) ) ),
				'bundle'  => false,
				'reviews' => array( 'ids' => array() ),
			)
		);
		$this->assertIsArray( $copy, is_wp_error( $copy ) ? $copy->get_error_message() : '' );
		$xml = (string) file_get_contents( Copies\path( $copy['name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( '<wp:post_id>' . $reviewed[0] . '</wp:post_id>', $xml );
		$this->assertStringNotContainsString( '<wp:comment_id>' . $reviewed[1] . '</wp:comment_id>', $xml, 'Its review stays out.' );
	}

	/**
	 * Runs alone: export_wp() runs once per process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_export_takes_reviews_chosen() {
		$reviewed = $this->reviewed_product();
		if ( ! $reviewed ) {
			$this->markTestSkipped( 'No WooCommerce product review here.' );
		}
		$copy = $this->run_ability(
			'eizport/export',
			array(
				'types'   => array( 'product' => array( 'ids' => array( $reviewed[0] ) ) ),
				'bundle'  => false,
				'reviews' => array( 'ids' => array( $reviewed[1] ) ),
			)
		);
		$xml  = (string) file_get_contents( Copies\path( $copy['name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( '<wp:comment_id>' . $reviewed[1] . '</wp:comment_id>', $xml );
	}

	/**
	 * Runs alone: export_wp() runs once per process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_export_without_bundle_writes_a_plain_wxr() {
		$copy = $this->run_ability(
			'eizport/export',
			array(
				'bundle'   => false,
				'filename' => 'plain.xml',
			)
		);

		$this->assertIsArray( $copy, is_wp_error( $copy ) ? $copy->get_error_message() : '' );
		$this->assertSame( 'wxr', $copy['format'] );
		$this->assertMatchesRegularExpression( '/^plain-[A-Za-z0-9]{16}\.xml$/', $copy['name'] );
		$xml = (string) file_get_contents( Copies\path( $copy['name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( '<rss', $xml );
		$this->assertStringContainsString( 'xmlns:wp=', $xml );
		$this->assertSame( 'xml', Copies\extension_of( $copy['name'] ) );
		$this->assertFalse( Copies\is_playground( $copy['name'] ) );
	}

	/**
	 * Runs alone: export_wp() runs once per process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_export_with_blueprint_writes_a_playground_zip() {
		// WordPress's update check, faked (no request): the theme isn't from the directory, so it goes in the .zip.
		$not_from_directory = function () {
			return (object) array(
				'checked'   => array( get_stylesheet() => '1' ),
				'response'  => array(),
				'no_update' => array(),
			);
		};
		add_filter( 'pre_site_transient_update_themes', $not_from_directory );
		add_filter( 'pre_site_transient_update_plugins', $not_from_directory );
		$copy = $this->run_ability(
			'eizport/export',
			array(
				'types'     => array( 'page' => array( 'ids' => array( 999999 ) ) ),
				'blueprint' => array( 'themes' => array( get_stylesheet() ) ),
				'filename'  => 'eizport-ability-test',
			)
		);

		$this->assertIsArray( $copy, is_wp_error( $copy ) ? $copy->get_error_message() : '' );
		$this->assertSame( 'playground', $copy['format'] );
		$this->assertStringStartsWith( 'eizport-ability-test-', $copy['name'] );
		$this->assertStringStartsWith( 'https://playground.wordpress.net/?blueprint-url=', $copy['playground_url'] );
		$this->assertStringContainsString( '--blueprint=./' . $copy['name'], $copy['command'] );
		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new \ZipArchive();
			$this->assertTrue( $zip->open( Copies\path( $copy['name'] ) ) );
			$this->assertNotFalse( $zip->getFromName( 'blueprint.json' ) );
			$this->assertNotFalse( $zip->getFromName( 'themes/' . get_stylesheet() . '.zip' ) );
			$zip->close();
		}
	}

	public function test_suggestions() {
		$page = $this->page_as_homepage();
		$all  = (int) get_posts(
			array(
				'post_type'   => 'post',
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		)[0];
		// A block in the page's content shows the post (as WooCommerce's blocks show products).
		$shows = function ( $suggested, $post ) use ( $page, $all ) {
			return $page === $post->ID ? array_merge( $suggested, array( $all ) ) : $suggested;
		};
		add_filter( 'eizport_suggested_items', $shows, 10, 2 );
		$out = $this->run_ability( 'eizport/get-suggestions', array( 'types' => array( 'page' => array( 'ids' => array( $page ) ) ) ) );
		remove_filter( 'eizport_suggested_items', $shows, 10 );

		$this->assertSame( array( $all ), $out['items']->post ?? $out['items']['post'] );
		$this->assertContains( 'page_on_front', $out['options'] );
		$this->assertContains( 'show_on_front', $out['options'] );
		$this->assertSame( array(), $out['reviews'] );

		$this->assertSame( array(), (array) $this->run_ability( 'eizport/get-suggestions', array() )['items'], 'Nothing chosen, nothing suggested.' );

		// "Linked settings" off: no settings suggested.
		add_filter(
			'pre_option_' . \Eizport\Settings\OPTION,
			function () {
				return array( 'linked_options' => 'off' );
			}
		);
		$this->assertSame( array(), \Eizport\Suggestions\suggest( array( 'page' => array( 'ids' => array( $page ) ) ) )['options'] );
		remove_all_filters( 'pre_option_' . \Eizport\Settings\OPTION );
	}

	public function test_products_in_content_and_reviews() {
		$compat  = '\\Eizport\\Compat\\WooCommerce\\';
		$content = '<!-- wp:woocommerce/handpicked-products {"products":[%1$d]} /--> [products ids="%1$d"] {"productId":%1$d} {"woocommerceHandPickedProducts":["%1$d"]}';
		$this->assertSame( array(), call_user_func( $compat . 'products_in_content', sprintf( $content, 999999 ) ), 'Only products.' );

		// Reviews: export_wp()'s comments query keeps only the ones chosen.
		global $wpdb;
		$filter = call_user_func( $compat . 'review_filter', array( 5, 6 ) );
		$query  = "SELECT * FROM {$wpdb->comments} WHERE comment_post_ID = 12 AND comment_approved <> 'spam'";
		$this->assertSame( $query . " AND ( comment_type <> 'review' OR comment_ID IN ( 0,5,6 ) )", $filter( $query ) );
		$this->assertSame( 'SELECT 1', $filter( 'SELECT 1' ), 'Other queries stay.' );

		$product = post_type_exists( 'product' ) ? get_posts(
			array(
				'post_type'   => 'product',
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		) : array();
		if ( ! $product ) {
			$this->markTestIncomplete( 'No WooCommerce product here: the rest needs one.' );
		}
		$this->assertSame( array( (int) $product[0] ), call_user_func( $compat . 'products_in_content', sprintf( $content, $product[0] ) ) );
	}
}

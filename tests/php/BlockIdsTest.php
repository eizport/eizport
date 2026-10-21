<?php
/**
 * Post IDs inside block content, mapped to the imported items when an
 * import ends.
 *
 * Posts are made inside a transaction rolled back after each test.
 *
 * @package Eizport
 */

namespace Eizport\Tests;

/**
 * Block IDs tests.
 */
class BlockIdsTest extends Test_Case {

	/**
	 * Opens the transaction.
	 */
	protected function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the test's transaction.
		require_once dirname( __DIR__, 2 ) . '/includes/apply-options.php';
	}

	/**
	 * Rolls it back.
	 */
	protected function tear_down() {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the test's transaction.
		wp_cache_flush();
		unset( $GLOBALS['wp_import'] );
		parent::tear_down();
	}

	public function test_media_blocks_and_their_markup() {
		$map = array(
			12 => 112,
			13 => 113,
			14 => 114,
		);
		$in  = '<!-- wp:image {"id":12,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="a.jpg" alt="" class="wp-image-12"/></figure><!-- /wp:image -->'
			. '<!-- wp:gallery {"ids":[12,13],"linkTo":"none"} --><figure class="wp-block-gallery"><img src="b.jpg" data-id="13" class="wp-image-13"/></figure><!-- /wp:gallery -->'
			. '<!-- wp:cover {"id":14,"dimRatio":50} --><div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-14" src="c.jpg"/></div><!-- /wp:cover -->'
			. '<!-- wp:media-text {"mediaId":13,"mediaType":"image"} --><div class="wp-block-media-text"></div><!-- /wp:media-text -->'
			. '[gallery ids="12,13"]';
		$out = eizport_replace_block_ids( $in, $map );

		$this->assertStringContainsString( '{"id":112,"sizeSlug":"large"}', $out );
		$this->assertStringContainsString( 'class="wp-image-112"', $out );
		$this->assertStringContainsString( '{"ids":[112,113],"linkTo":"none"}', $out );
		$this->assertStringContainsString( 'data-id="113" class="wp-image-113"', $out );
		$this->assertStringContainsString( '{"id":114,"dimRatio":50}', $out );
		$this->assertStringContainsString( 'wp-block-cover__image-background wp-image-114', $out );
		$this->assertStringContainsString( '{"mediaId":113,', $out );
		$this->assertStringContainsString( '[gallery ids="112,113"]', $out );
	}

	public function test_ids_swap_in_one_pass_and_others_stay() {
		$out = eizport_replace_block_ids(
			'<!-- wp:image {"id":5} --><img class="wp-image-5"/><!-- /wp:image --><!-- wp:image {"id":6} --><img class="wp-image-6"/><!-- /wp:image --><!-- wp:image {"id":7} /-->',
			array(
				5 => 6,
				6 => 5,
			)
		);

		$this->assertSame( '<!-- wp:image {"id":6} --><img class="wp-image-6"/><!-- /wp:image --><!-- wp:image {"id":5} --><img class="wp-image-5"/><!-- /wp:image --><!-- wp:image {"id":7} /-->', $out );
	}

	public function test_navigation_links_to_posts_not_to_terms_nor_other_blocks() {
		$map = array( 9 => 90 );
		$out = eizport_replace_block_ids(
			'<!-- wp:navigation-link {"label":"About","type":"page","id":9,"kind":"post-type"} /-->'
			. '<!-- wp:navigation-link {"label":"News","type":"category","id":9,"kind":"taxonomy"} /-->'
			. '<!-- wp:paragraph {"id":9} --><p data-id="9">9</p><!-- /wp:paragraph -->'
			. '<!-- wp:my-plugin/card {"id":9} /-->',
			$map
		);

		$this->assertStringContainsString( '"type":"page","id":90,"kind":"post-type"', $out );
		$this->assertStringContainsString( '"type":"category","id":9,"kind":"taxonomy"', $out, 'A term ID.' );
		$this->assertStringContainsString( '<!-- wp:paragraph {"id":9} --><p data-id="9">', $out, 'Not a block of the list, not an image.' );
		$this->assertStringContainsString( '<!-- wp:my-plugin/card {"id":9} /-->', $out );

		add_filter(
			'eizport_block_id_attributes',
			function ( $blocks ) {
				$blocks['my-plugin/card'] = array( 'id' );
				return $blocks;
			}
		);
		$this->assertStringContainsString( '<!-- wp:my-plugin/card {"id":90} /-->', eizport_replace_block_ids( '<!-- wp:my-plugin/card {"id":9} /-->', $map ) );
		remove_all_filters( 'eizport_block_id_attributes' );
	}

	public function test_the_imported_items_are_mapped_when_the_import_ends() {
		$image                = (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Imported image',
				'post_status'    => 'inherit',
			),
			'2026/10/imported.png'
		);
		$page                 = (int) wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Imported page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:image {"id":999001} --><figure class="wp-block-image"><img src="x.png" class="wp-image-999001"/></figure><!-- /wp:image -->',
			)
		);
		$GLOBALS['wp_import'] = (object) array( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- the importer's, unset in tear_down().
			'processed_posts' => array(
				999001 => $image,
				999002 => $page,
			),
		);

		$this->assertSame( 1, eizport_remap_block_ids() );
		$this->assertStringContainsString( '{"id":' . $image . '}', get_post( $page )->post_content );
		$this->assertStringContainsString( 'wp-image-' . $image . '"', get_post( $page )->post_content );
		$this->assertCount( 0, wp_get_post_revisions( $page ), 'No revision.' );
	}

	public function test_block_widgets_are_mapped_with_the_options() {
		$apply = eizport_options_to_apply(
			array( 'options' => array( 'widget_block' => array( 2 => array( 'content' => '<!-- wp:image {"id":21} --><img class="wp-image-21"/><!-- /wp:image -->' ) ) ) ),
			array( 21 => 121 ),
			array()
		);

		$this->assertSame( '<!-- wp:image {"id":121} --><img class="wp-image-121"/><!-- /wp:image -->', $apply['widget_block'][2]['content'] );
	}
}

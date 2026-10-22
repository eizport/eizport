<?php
/**
 * Tests for the templates and template parts rows (block themes).
 *
 * @package Eizport
 */

namespace Eizport\Tests;

use Eizport\Appearance;
use Eizport\Import;

/**
 * Templates tests.
 */
class TemplatesTest extends Test_Case {

	/**
	 * The theme active before the test.
	 *
	 * @var string
	 */
	private $theme = '';

	/**
	 * A block theme active: the rows list its templates.
	 */
	protected function set_up() {
		parent::set_up();
		if ( ! wp_get_theme( 'twentytwentyfive' )->exists() ) {
			$this->markTestSkipped( 'Twenty Twenty-Five is not installed.' );
		}
		$this->theme = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		$this->delete_theme_posts();
	}

	/**
	 * Deletes the templates and parts saved on the test site (a test run in
	 * its own process commits its posts).
	 */
	private function delete_theme_posts() {
		foreach ( get_posts(
			array(
				'post_type'   => array( 'wp_template', 'wp_template_part', 'wp_navigation' ),
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		) as $id ) {
			wp_delete_post( $id, true );
		}
	}

	/**
	 * Restores the theme and the hooks an import leaves.
	 */
	protected function tear_down() {
		$this->delete_theme_posts();
		switch_theme( $this->theme );
		wp_set_current_user( 0 );
		$GLOBALS['eizport_templates_pending'] = array();
		parent::tear_down();
	}

	/**
	 * A template or part saved on the site, for a theme.
	 *
	 * @param string $type    wp_template or wp_template_part.
	 * @param string $slug    Slug.
	 * @param string $content Block markup.
	 * @param string $theme   Theme (default: the active one).
	 * @return int
	 */
	private function theme_post( $type, $slug, $content, $theme = '' ) {
		$id = (int) wp_insert_post(
			array(
				'post_type'    => $type,
				'post_name'    => $slug,
				'post_title'   => ucfirst( $slug ),
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
		wp_set_object_terms( $id, '' !== $theme ? $theme : get_stylesheet(), 'wp_theme' );
		if ( 'wp_template_part' === $type ) {
			wp_set_object_terms( $id, 'header', 'wp_template_part_area' );
		}

		return $id;
	}

	/**
	 * An item as the importer reads it from a WXR.
	 *
	 * @param string $type    Post type.
	 * @param string $slug    Slug.
	 * @param string $content Content.
	 * @param string $theme   Theme.
	 * @return array
	 */
	private function item( $type, $slug, $content, $theme ) {
		return array(
			'post_id'      => 900001,
			'post_type'    => $type,
			'post_name'    => $slug,
			'post_title'   => ucfirst( $slug ),
			'post_content' => $content,
			'post_excerpt' => '',
			'post_date'    => '2020-01-01 00:00:00',
			'status'       => 'publish',
			'terms'        => array(
				array(
					'domain' => 'wp_theme',
					'slug'   => $theme,
					'name'   => $theme,
				),
			),
		);
	}

	public function test_the_rows_list_the_active_theme_s_edited_templates() {
		$header = $this->theme_post( 'wp_template_part', 'header', '<!-- wp:site-title /-->' );
		$this->theme_post( 'wp_template', 'page', '<!-- wp:post-content /-->' );
		$this->theme_post( 'wp_template', 'single', '<!-- wp:post-content /-->', 'another-theme' );

		$this->assertSame( array( 'header' ), wp_list_pluck( Appearance\items( 'template_parts' ), 'key' ) );
		$this->assertSame( array( 'page' ), wp_list_pluck( Appearance\items( 'templates' ), 'key' ), 'Not another theme\'s, not the theme\'s files.' );
		$item = Appearance\items( 'template_parts' )[0];
		$this->assertSame( 'Header', $item['label'] );
		$this->assertStringContainsString( 'header', $item['details'] );
		$this->assertSame( 'code', $item['preview']['type'] );
		$this->assertSame( '<!-- wp:site-title /-->', $item['preview']['value'] );
		$this->assertSame( 1, Appearance\available()['template_parts'] );

		switch_theme( 'twentytwentyfive' === $this->theme ? 'default' : $this->theme );
		if ( ! wp_is_block_theme() ) {
			$this->assertSame( array(), Appearance\items( 'template_parts' ), 'A classic theme has none.' );
		}
		switch_theme( 'twentytwentyfive' );
		$this->assertContains( $header, Appearance\post_ids( array( 'template_parts' => 'all' ) ) );
	}

	public function test_the_posts_chosen_and_their_images_go_in_the_wxr() {
		$image  = (int) wp_insert_attachment(
			array(
				'post_title'     => 'Eizport header image',
				'post_mime_type' => 'image/png',
				'post_status'    => 'inherit',
			)
		);
		$header = $this->theme_post( 'wp_template_part', 'header', '<!-- wp:image {"id":' . $image . '} --><figure class="wp-block-image"><img class="wp-image-' . $image . '"/></figure><!-- /wp:image -->' );
		$footer = $this->theme_post( 'wp_template_part', 'footer', '<!-- wp:site-title /-->' );

		$ids = Appearance\post_ids( array( 'template_parts' => array( 'header' ) ) );
		$this->assertContains( $header, $ids );
		$this->assertContains( $image, $ids, 'The image its block uses.' );
		$this->assertNotContains( $footer, $ids );
		$this->assertSame( array( $header ), Appearance\post_ids( array( 'template_parts' => array( 'header' ) ), false ), 'Without media.' );
		$this->assertSame( array(), Appearance\post_ids( array( 'templates' => 'all' ) ) );
		$this->assertArrayNotHasKey( 'template_parts', Appearance\data( array( 'template_parts' => 'all' ) ), 'Not in the options file: in the WXR.' );
		wp_delete_attachment( $image, true );
	}

	public function test_an_imported_template_updates_the_site_s_one_of_its_theme() {
		$stylesheet = get_stylesheet();
		$header     = $this->theme_post( 'wp_template_part', 'header', '<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->' );
		$other      = $this->theme_post( 'wp_template_part', 'header', '<!-- wp:paragraph --><p>Other theme</p><!-- /wp:paragraph -->', 'another-theme' );

		$this->assertSame( $header, eizport_templates_existing( 0, $this->item( 'wp_template_part', 'header', '<!-- wp:paragraph --><p>New</p><!-- /wp:paragraph -->', $stylesheet ) ) );
		$this->assertSame( 0, eizport_templates_existing( $other, $this->item( 'wp_template_part', 'footer', '', $stylesheet ) ), 'Nothing of its slug and theme: inserted, whatever the title and date found.' );
		$this->assertSame( 7, eizport_templates_existing( 7, $this->item( 'page', 'header', '', $stylesheet ) ), 'Other types: as they were.' );

		$this->assertSame( 1, eizport_templates_import_end() );
		$this->assertStringContainsString( '<p>New</p>', get_post( $header )->post_content );
		$this->assertStringContainsString( '<p>Other theme</p>', get_post( $other )->post_content, 'Another theme\'s part stays.' );
		$this->assertSame( 0, eizport_templates_import_end(), 'Once.' );
	}

	public function test_update_existing_leaves_templates_to_their_theme() {
		$other = $this->theme_post( 'wp_template_part', 'header', '<!-- wp:paragraph --><p>Other theme</p><!-- /wp:paragraph -->', 'another-theme' );
		$undo  = Import\update_existing( true );
		$item  = $this->item( 'wp_template_part', 'header', '<p>New</p>', get_stylesheet() );
		$found = apply_filters( 'wp_import_existing_post', 0, $item ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the WordPress Importer's.
		$undo();

		$this->assertSame( 0, $found, 'The slug alone would have found another theme\'s part.' );
		$this->assertStringContainsString( '<p>Other theme</p>', get_post( $other )->post_content );
		$this->assertSame( '', Import\update_existing_label( array( 'wp_template', 'wp_template_part' ) ) );
	}

	/**
	 * Runs alone: export_wp() runs once per process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_export_and_import_bring_a_header_back_with_its_navigation() {
		$admin = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
			)
		);
		wp_set_current_user( $admin[0]->ID );
		$nav    = (int) wp_insert_post(
			array(
				'post_type'    => 'wp_navigation',
				'post_title'   => 'Eizport header navigation',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:navigation-link {"label":"Docs","url":"https://example.org/docs"} /-->',
			)
		);
		$header = $this->theme_post( 'wp_template_part', 'header', '<!-- wp:navigation {"ref":' . $nav . '} /-->' );
		$page   = $this->theme_post( 'wp_template', 'page', '<!-- wp:template-part {"slug":"header","theme":"' . get_stylesheet() . '"} /-->' );
		$copy   = wp_get_ability( 'eizport/export' )->execute(
			array(
				'types'      => array(
					'page' => array(
						'ids' => array(
							(int) wp_insert_post(
								array(
									'post_type'   => 'page',
									'post_title'  => 'Eizport templates page',
									'post_status' => 'publish',
								)
							),
						),
					),
				),
				'menus'      => array( 'navigation' => array( $nav ) ),
				'appearance' => array(
					'templates'      => array( 'page' ),
					'template_parts' => array( 'header' ),
				),
				'bundle'     => false,
			)
		);
		$this->assertIsArray( $copy, is_wp_error( $copy ) ? $copy->get_error_message() : '' );
		$wxr = (string) file_get_contents( \Eizport\Copies\path( $copy['name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( '<wp:post_type><![CDATA[wp_template_part]]></wp:post_type>', $wxr );
		$this->assertStringContainsString( '<wp:post_type><![CDATA[wp_template]]></wp:post_type>', $wxr );
		$this->assertStringContainsString( 'domain="wp_theme"', $wxr );
		$this->assertStringContainsString( 'domain="wp_template_part_area"', $wxr );

		// The other site: no such navigation, its own header and no page template.
		wp_delete_post( $nav, true );
		wp_delete_post( $page, true );
		wp_update_post(
			array(
				'ID'           => $header,
				'post_content' => '<!-- wp:paragraph --><p>Its own header</p><!-- /wp:paragraph -->',
				'post_date'    => '2019-05-05 05:05:05',
			)
		);

		ob_start();
		$result = Import\import_file( \Eizport\Copies\path( $copy['name'] ) );
		ob_end_clean();
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$navs = get_posts(
			array(
				'post_type'   => 'wp_navigation',
				'title'       => 'Eizport header navigation',
				'post_status' => 'publish',
				'fields'      => 'ids',
			)
		);
		$this->assertCount( 1, $navs );
		$this->assertSame( '<!-- wp:navigation {"ref":' . $navs[0] . '} /-->', get_post( $header )->post_content, 'The site\'s header, updated, pointing to the imported navigation.' );
		$parts = get_posts(
			array(
				'post_type'   => 'wp_template_part',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);
		$this->assertSame( array( $header ), $parts, 'No header-2.' );
		$template = get_block_template( get_stylesheet() . '//page' );
		$this->assertNotNull( $template );
		$this->assertSame( 'custom', $template->source, 'The page template is back, for this theme.' );
		$this->assertStringContainsString( '"slug":"header"', $template->content );
		foreach ( get_posts(
			array(
				'post_type'   => 'page',
				'title'       => 'Eizport templates page',
				'post_status' => 'any',
				'fields'      => 'ids',
			)
		) as $id ) {
			wp_delete_post( $id, true );
		}
	}
}

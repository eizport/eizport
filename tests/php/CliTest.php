<?php
/**
 * The WP-CLI commands' input.
 *
 * @package Eizport
 */

namespace Eizport\Tests;

use Eizport\Cli;

require_once dirname( __DIR__, 2 ) . '/includes/cli.php';

/**
 * CLI tests.
 */
class CliTest extends Test_Case {

	public function test_export_flags_become_the_ability_input() {
		$input = Cli\export_input(
			array(
				'types'           => 'page:43+44,product',
				'options'         => 'basics,page_on_front',
				'media'           => false,
				'as-plugin'       => true,
				'update-existing' => true,
				'filename'        => 'delivery',
			)
		);

		$this->assertSame( array( 'ids' => array( 43, 44 ) ), $input['types']['page'] );
		$this->assertSame( array(), $input['types']['product'] );
		$this->assertSame( array( 'basics' ), $input['options']['groups'] );
		$this->assertSame( array( 'page_on_front' ), $input['options']['names'] );
		$this->assertFalse( $input['media'] );
		$this->assertTrue( $input['as_plugin'] );
		$this->assertTrue( $input['update_existing'] );
		$this->assertSame( 'delivery', $input['filename'] );
		$this->assertArrayNotHasKey( 'zip', $input );
	}

	public function test_no_flags_export_everything_with_media() {
		$this->assertSame( array( 'media' => true ), Cli\export_input( array() ) );

		// A Playground .zip: themes, plugins, where it opens and its language.
		$this->assertSame(
			array(
				'themes'       => array( 'twentytwentyfive' ),
				'plugins'      => array( 'akismet/akismet.php', 'hello.php' ),
				'landing_page' => array( 'page' => 43 ),
				'language'     => 'pt_BR',
			),
			Cli\export_input(
				array(
					'playground' => true,
					'themes'     => 'twentytwentyfive',
					'plugins'    => 'akismet/akismet.php, hello.php',
					'landing'    => 'page:43',
					'language'   => 'pt_BR',
				)
			)['blueprint']
		);
		$this->assertSame(
			'/shop/',
			Cli\export_input(
				array(
					'playground' => true,
					'landing'    => '/shop/',
				)
			)['blueprint']['landing_page']
		);
		$this->assertArrayNotHasKey( 'blueprint', Cli\export_input( array( 'landing' => 'admin' ) ), 'Only with --playground.' );
	}
}

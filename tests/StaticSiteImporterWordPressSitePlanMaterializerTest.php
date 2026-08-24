<?php
/**
 * Runs the plan materializer's full smoke fixture under WP Codebox PHPUnit.
 *
 * @package StaticSiteImporter
 */

class StaticSiteImporterWordPressSitePlanMaterializerTest extends WP_UnitTestCase {

	/**
	 * The smoke owns the fixture and assertions; this runtime wrapper supplies
	 * the Codebox Core path while keeping the stateful stubs isolated.
	 */
	public function test_runtime_smoke_admits_strict_editability_reports(): void {
		$process = proc_open(
			array( PHP_BINARY, dirname( __DIR__ ) . '/tests/smoke-wordpress-site-plan-materializer.php' ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			null,
			array_merge( $_ENV, array( 'STATIC_SITE_IMPORTER_WP_ROOT' => ABSPATH ) )
		);
		$this->assertIsResource( $process );
		if ( ! is_resource( $process ) ) {
			return;
		}

		$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$this->assertSame( 0, proc_close( $process ), $output );
		$this->assertStringContainsString( 'WordPress site plan materializer smoke passed.', $output );
	}
}

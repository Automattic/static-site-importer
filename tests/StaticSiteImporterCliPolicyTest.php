<?php
/**
 * CLI compile policy tests.
 *
 * @package StaticSiteImporter
 */

require_once dirname( __DIR__ ) . '/includes/cli.php';

/** Memory sizing is independent of the machine running the test. */
class StaticSiteImporterCliPolicyTest extends PHPUnit\Framework\TestCase {

	/** Cover byte budgets, unlimited PHP memory, and both clamp boundaries. */
	public function test_compile_worker_count(): void {
		$mb = 1024 * 1024;
		// The readers supply the tighter available value; -1 uses a 768 MiB budget.
		$cases = array(
			'cgroup-limited'  => array( 1748 * $mb, 1024 * $mb, 364 * $mb, 1 ),
			'meminfo-only'    => array( 2800 * $mb, 1024 * $mb, 364 * $mb, 2 ),
			'unreadable'      => array( null, 1024 * $mb, 364 * $mb, 4 ),
			'memory_limit=-1' => array( 2800 * $mb, 768 * $mb, 364 * $mb, 3 ),
			'large-host'      => array( 32000 * $mb, 1024 * $mb, 364 * $mb, 4 ),
			'exhausted'       => array( 0, 1024 * $mb, 364 * $mb, 1 ),
			'exact-boundary'  => array( 2412 * $mb, 1024 * $mb, 364 * $mb, 2 ),
		);
		foreach ( $cases as $name => [ $available, $budget, $reserve, $expected ] ) {
			$this->assertSame( $expected, static_site_importer_cli_compile_worker_count( $available, $budget, $reserve ), $name );
		}
	}
}

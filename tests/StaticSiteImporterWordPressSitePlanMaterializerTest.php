<?php
/**
 * Verifies strict plan admission under WP Codebox PHPUnit.
 *
 * @package StaticSiteImporter
 */

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

class StaticSiteImporterWordPressSitePlanMaterializerTest extends WP_UnitTestCase {

	/**
	 * Current producer reports are admitted only when bound to the canonical plan.
	 */
	public function test_runtime_smoke_admits_strict_editability_reports(): void {
		$result = ( new ArtifactCompiler() )->compile(
			array(
				'entrypoint' => 'index.html',
				'files'      => array(
					'index.html' => '<main><h1>Codebox admission</h1></main>',
				),
			)
		)->toArray();
		$plan   = $result['source_reports']['wordpress_site_plan'];

		$unbound_plan = $plan;
		unset( $unbound_plan['plan_identity'], $unbound_plan['quality']['editability_report'], $unbound_plan['quality']['editability_report_plan_hash'], $unbound_plan['quality']['editability_report_required'] );
		$bound_hash                                      = WordPressSitePlan::planIdentity( $unbound_plan )['hash'];
		$plan['quality']['editability_report_required']  = true;
		$plan['quality']['editability_report']           = $result['source_reports']['editability_report'];
		$plan['quality']['editability_report_plan_hash'] = $bound_hash;
		$plan['plan_identity']                           = WordPressSitePlan::planIdentity( $plan );

		$admit_report = new ReflectionMethod( Static_Site_Importer_WordPress_Site_Plan_Materializer::class, 'editability_report_admission' );
		$admission    = $admit_report->invoke( null, $plan );
		$this->assertSame( 'passed', $admission['status'] ?? '' );
		$this->assertSame( 'blocks-engine/php-transformer/editability-report/v2', $admission['report_schema'] ?? '' );
		$this->assertSame( $bound_hash, $admission['plan_hash'] ?? '' );

		$mismatched_plan                                            = $plan;
		$mismatched_plan['quality']['editability_report_plan_hash'] = str_repeat( '0', 64 );
		$mismatched_plan['plan_identity']                           = WordPressSitePlan::planIdentity( $mismatched_plan );
		$mismatched = $admit_report->invoke( null, $mismatched_plan );
		$this->assertSame( 'rejected', $mismatched['status'] ?? '' );
		$this->assertSame( 'editability_report_plan_hash_mismatch', $mismatched['diagnostic']['reason_code'] ?? '' );
	}
}

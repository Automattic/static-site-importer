<?php
/**
 * Verifies plan admission under WP Codebox PHPUnit.
 *
 * @package StaticSiteImporter
 */

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

class StaticSiteImporterWordPressSitePlanMaterializerTest extends WP_UnitTestCase {

	/**
	 * The Blocks Engine editability decision is honoured once, not re-gated.
	 */
	public function test_runtime_smoke_honours_producer_editability_decision(): void {
		$result = ( new ArtifactCompiler() )->compile(
			array(
				'entrypoint' => 'index.html',
				'files'      => array(
					'index.html' => '<main><h1>Codebox admission</h1></main>',
				),
			)
		)->toArray();
		$plan   = $result['source_reports']['wordpress_site_plan'];

		$passed = Static_Site_Importer_Site_Plan_Preparation::editability_decision( $plan );
		$this->assertSame( 'passed', $passed['status'] ?? '' );
		$this->assertSame( 'blocks-engine/php-transformer/editability-policy/v1', $passed['policy_schema'] ?? '' );

		$failed_plan = $plan;
		$failed_plan['quality']['editability_policy']['status']   = 'failed';
		$failed_plan['quality']['editability_policy']['failures'] = array(
			array(
				'metric'      => 'wrapper_to_content_ratio',
				'actual'      => 5,
				'maximum'     => 4,
				'source_path' => 'index.html',
				'message'     => 'wrapper_to_content_ratio is 5; meaningful editability allows at most 4.',
			),
		);
		$failed = Static_Site_Importer_Site_Plan_Preparation::editability_decision( $failed_plan );
		$this->assertSame( 'failed', $failed['status'] ?? '' );
		$this->assertSame( 'editability_policy_failed', $failed['diagnostic']['reason_code'] ?? '' );
		$this->assertSame( 'index.html', $failed['diagnostic']['source_path'] ?? '' );
	}
}

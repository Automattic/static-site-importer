<?php
/**
 * Add site-sized informational compiler evidence without bypassing import execution.
 *
 * @package StaticSiteImporter
 */

add_filter(
	'static_site_importer_direct_artifact_compiler',
	static function ( $compiler ) {
		return new class( $compiler ) {
			public function __construct( private object $compiler ) {}
			public function __call( string $method, array $arguments ) {
				$result = $this->compiler->$method( ...$arguments );
				if ( 'compose' !== $method ) {
					return $result;
				}
				$fields = get_object_vars( $result );
				for ( $i = 0; $i < 500; ++$i ) {
					$fields['sourceReports']['wordpress_site_plan']['reporting']['diagnostic_codes'][] = 'receipt_fixture_' . $i;
					$fields['sourceReports']['wordpress_site_plan']['diagnostics'][]                   = array(
						'code'        => 'receipt_fixture_' . $i,
						'severity'    => 'info',
						'message'     => 'Receipt fixture informational diagnostic ' . $i,
						'source_path' => 'website/index.html',
					);
				}
				$plan                  = &$fields['sourceReports']['wordpress_site_plan'];
				$plan['plan_identity'] = Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan::planIdentity( $plan );
				return new Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult( ...$fields );
			}
		};
	}
);

<?php
/** Regression for #2619: compile the reduced artifact and reuse the moved site's seed. */
define( 'SSI_PLAN_HARNESS_ONLY', true );
require __DIR__ . '/smoke-wordpress-site-plan-materializer.php';

function __( string $text ): string { return $text; }
function get_comment( int $id ): mixed { return null; }
class WP_Privacy_Policy_Content {
	public static function get_default_content(): string {
		return '<!-- wp:paragraph --><p>Our website address is: ' . get_option( 'home' ) . '.</p><!-- /wp:paragraph -->';
	}
}

$artifact = array(
	'entrypoint' => 'index.html',
	'files' => array(
		'index.html' => '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Home</title></head><body><main><h1>Home</h1><p>Hello.</p><p><a href="/privacy-policy/">Privacy</a></p></main></body></html>',
		'privacy-policy/index.html' => '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Privacy Policy</title></head><body><main><h1>Privacy Policy</h1><p>Policy text.</p></main></body></html>',
	),
);
$plan = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( $artifact )->toArray()['source_reports']['wordpress_site_plan'];
foreach ( array( 'core/group', 'core/heading', 'core/paragraph', 'core/html', 'core/navigation', 'core/navigation-link', 'core/template-part', 'core/post-content' ) as $block_name ) {
	WP_Block_Type_Registry::get_instance()->register( $block_name );
}
$GLOBALS['ssi_plan_options']['home'] = 'https://installed.test/subdir';
$seed = array(
	'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'privacy-policy',
	'post_title' => 'Privacy Policy', 'post_content' => WP_Privacy_Policy_Content::get_default_content(),
	'guid' => 'https://installed.test/subdir/?page_id=3',
);
$GLOBALS['ssi_plan_options']['home'] = 'https://moved.test';
$GLOBALS['ssi_plan_options']['siteurl'] = 'https://moved.test';
foreach ( array( false, true ) as $edited ) {
	$GLOBALS['ssi_plan_posts'] = array( 3 => $seed );
	$GLOBALS['ssi_plan_meta'] = array( 3 => array( '_wp_page_template' => 'default' ) );
	$GLOBALS['ssi_plan_options']['fresh_site'] = '1';
	$GLOBALS['ssi_plan_options']['wp_page_for_privacy_policy'] = 3;
	if ( $edited ) {
		$GLOBALS['ssi_plan_posts'][3]['post_content'] .= ' My own policy.';
	}
	$receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'privacy-seed-regression', 'overwrite' => false ) );
	if ( $edited ) {
		$error = $receipt['errors'][0] ?? array();
		$assert( 'post_conflict' === ( $error['code'] ?? '' ), 'Edited policy must conflict' );
		foreach ( array( '/privacy-policy', 'post 3', 'type page', 'status draft', 'content_mismatch' ) as $detail ) {
			$assert( str_contains( $error['message'], $detail ), 'Conflict message missing ' . $detail );
		}
		foreach ( array( 'Privacy Policy', 'Our website address is:', 'My own policy.', 'https://installed.test' ) as $private_text ) {
			$assert( ! str_contains( $error['message'], $private_text ), 'Conflict message must not expose title or content' );
		}
		$diagnostics = array_values( array_filter( $receipt['diagnostics'], static fn ( array $diagnostic ): bool => isset( $diagnostic['declined_adoption_reason'] ) ) );
		$assert( 3 === ( $diagnostics[0]['post_id'] ?? null ) && 'content_mismatch' === ( $diagnostics[0]['declined_adoption_reason'] ?? null ), 'Conflict must carry structured occupant diagnostics' );
		$assert( $GLOBALS['ssi_plan_posts'][3]['post_content'] === $seed['post_content'] . ' My own policy.', 'Rejected policy must remain unchanged' );
	} else {
		$assert( 'completed' === $receipt['status'], 'Moved untouched seed must import: ' . json_encode( $receipt['errors'] ) );
		$assert( 3 === ( $receipt['completed']['pages']['privacy-policy/index.html'] ?? null ), 'Policy must reuse post 3' );
		$home_id = $receipt['completed']['pages']['index.html'] ?? 0;
		$home_markup = stripslashes( $GLOBALS['ssi_plan_posts'][ $home_id ]['post_content'] ?? '' );
		$assert( str_contains( $home_markup, 'Hello.' ) && str_contains( $home_markup, '/privacy-policy' ), 'Compiled home blocks must retain the policy link' );
		$markup = stripslashes( $GLOBALS['ssi_plan_posts'][3]['post_content'] );
		$assert( str_contains( $markup, '<!-- wp:heading' ) && str_contains( $markup, 'Privacy Policy' ) && str_contains( $markup, 'Policy text.' ), 'Compiled policy blocks must replace the seed' );
	}
	$assert( 'https://moved.test' === get_option( 'home' ), 'Install-home filter must not leak' );
}
// A URL move must not weaken any of the other seed eligibility checks.
foreach ( array(
	array( 'post_status', 'publish', 'metadata_mismatch' ),
	array( 'post_title', 'Custom policy', 'metadata_mismatch' ),
	array( 'post_name', 'custom-policy', 'metadata_mismatch' ),
	array( 'post_type', 'post', 'not_core_seed' ),
	array( 'guid', 'https://installed.test/subdir/?page_id=4', 'not_core_seed' ),
) as list( $field, $value, $reason ) ) {
	$GLOBALS['ssi_plan_posts'] = array( 3 => array_replace( $seed, array( $field => $value ) ) );
	$discovery = Static_Site_Importer_Default_Content::discover();
	$assert( ! in_array( 3, array_column( $discovery['posts'], 'id' ), true ), 'Changed ' . $field . ' must prevent discovery' );
	$assert( $reason === Static_Site_Importer_Default_Content::declined_adoption_reason( $discovery, get_post( 3 ) ), 'Changed ' . $field . ' must retain its reason code' );
	$assert( 'https://moved.test' === get_option( 'home' ), 'Declined discovery must not change home' );
}
foreach ( array( 'option', 'template', 'provenance', 'fresh_site', 'fingerprint' ) as $changed ) {
	$GLOBALS['ssi_plan_posts'] = array( 3 => $seed );
	$GLOBALS['ssi_plan_meta'] = array( 3 => array( '_wp_page_template' => 'default' ) );
	$GLOBALS['ssi_plan_options']['fresh_site'] = '1';
	$GLOBALS['ssi_plan_options']['wp_page_for_privacy_policy'] = 3;
	if ( 'option' === $changed ) {
		$GLOBALS['ssi_plan_options']['wp_page_for_privacy_policy'] = 4;
	} elseif ( 'template' === $changed ) {
		$GLOBALS['ssi_plan_meta'][3]['_wp_page_template'] = 'custom.php';
	} elseif ( 'provenance' === $changed ) {
		$GLOBALS['ssi_plan_meta'][3]['_static_site_importer_provenance'] = 'previous-run';
	} elseif ( 'fresh_site' === $changed ) {
		$GLOBALS['ssi_plan_options']['fresh_site'] = '0';
	}
	$discovery = Static_Site_Importer_Default_Content::discover();
	if ( 'fingerprint' === $changed ) {
		$assert( in_array( 3, array_column( $discovery['posts'], 'id' ), true ), 'Untouched seed must be discovered before mutation' );
		$GLOBALS['ssi_plan_posts'][3]['post_content'] .= ' Later edit.';
	} else {
		$assert( ! in_array( 3, array_column( $discovery['posts'], 'id' ), true ), 'Changed ' . $changed . ' must prevent discovery' );
	}
	$assert( ! Static_Site_Importer_Default_Content::is_untouched_seed( $discovery, get_post( 3 ) ), 'Changed ' . $changed . ' must prevent adoption' );
	$assert( 'https://moved.test' === get_option( 'home' ), 'Eligibility checks must not change home' );
}
echo "Privacy seed URL-change regression passed (reuse and edited-policy conflict).\n";

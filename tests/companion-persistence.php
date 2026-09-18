<?php
/**
 * Real WordPress multi-request companion refresh regression.
 *
 * In a disposable site with SSI active, run with WP-CLI --user=admin:
 * SSI_COMPANION_PERSISTENCE_TEST=1 wp eval-file tests/companion-persistence.php setup
 * SSI_COMPANION_PERSISTENCE_TEST=1 wp eval-file tests/companion-persistence.php refresh
 * SSI_COMPANION_PERSISTENCE_TEST=1 wp eval-file tests/companion-persistence.php reopen
 *
 * @package StaticSiteImporter
 */

if ( '1' !== getenv( 'SSI_COMPANION_PERSISTENCE_TEST' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Run explicitly in a disposable WordPress site with SSI_COMPANION_PERSISTENCE_TEST=1.' );
}
$phase     = $args[0] ?? '';
$assert    = static function ( bool $value, string $message ): void {
	if ( ! $value ) {
		WP_CLI::error( $message );
	}
	WP_CLI::log( 'PASS: ' . $message );
};
$payload   = array(
	'schema'    => Static_Site_Importer_Companion_Plugin::PAYLOAD_SCHEMA,
	'site_slug' => 'saved-usage-proof',
	'blocks'    => array_map( static fn ( $name ) => array(
		'name'       => $name,
		'block_json' => array(
			'name'       => 'ssi-proof/' . $name,
			'title'      => $name,
			'category'   => 'text',
			'attributes' => array(
				'content' => array(
					'type'    => 'string',
					'default' => '',
				),
			),
		),
		'render'     => '<p>Template default</p>',
		'assets'     => array( 'view.js' => 'console.debug("original");' ),
	), array( 'used', 'unused' ) ),
);
$state_key = 'ssi_companion_persistence_test';
$state     = get_option( $state_key );
$markup    = '<!-- wp:ssi-proof/used {"content":"<p>Owner edit survives</p>"} /-->';
if ( 'setup' === $phase ) {
	$assert( false === $state, 'fresh disposable fixture' );
	$result = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $payload );
	$assert( 'installed_activated' === $result['status'], 'companion installed through real WordPress activation' );
	$page_id = wp_insert_post( array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Saved owner edit',
		'post_content' => $markup,
	), true );
	$assert( ! is_wp_error( $page_id ), 'owner-edited block persisted as a WordPress page' );
	// A text mention and a similarly named block are not instances of /unused.
	wp_insert_post( array(
		'post_type'    => 'page',
		'post_status'  => 'draft',
		'post_title'   => 'False positive',
		'post_content' => '<p>wp:ssi-proof/unused</p><!-- wp:ssi-proof/unused-other /-->',
	) );
	update_option( $state_key, array(
		'post_id' => $page_id,
		'payload' => $payload,
	) );
	$assert( str_contains( do_blocks( get_post( $page_id )->post_content ), 'Owner edit survives' ), 'actual generated renderer displays the saved edit' );
} elseif ( 'refresh' === $phase ) {
	$assert( is_array( $state ), 'fixture read in a fresh WordPress process' );
	$before      = get_post( $state['post_id'] )->post_content;
	$metadata    = WP_PLUGIN_DIR . '/ssi-saved-usage-proof/blocks/used/block.json';
	$before_hash = hash_file( 'sha256', $metadata );
	$breaking    = $payload;
	$breaking['blocks'][0]['block_json']['attributes']['content'] = array(
		'type'    => 'number',
		'default' => 0,
	);
	// Core itself demonstrates the data-loss mechanism, independently of SSI.
	$control       = new WP_Block_Type( 'ssi-proof/control', array( 'attributes' => $breaking['blocks'][0]['block_json']['attributes'] ) );
	$control_attrs = $control->prepare_attributes_for_render( array( 'content' => '<p>Owner edit survives</p>' ) );
	$assert( 0 === $control_attrs['content'], 'Core discards the saved string under the incompatible numeric schema' );
	$result = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $breaking, null, true );
	$assert( 'failed' === $result['status'] && 'static_site_importer_companion_saved_contract_changed' === $result['error']['code'], 'used incompatible schema rejected before overwrite' );
	$assert( hash_file( 'sha256', $metadata ) === $before_hash && get_post( $state['post_id'] )->post_content === $before, 'installed schema and saved owner content remain byte-identical' );
	// Changing an unused schema, adding an attribute, and updating implementation
	// files are legitimate refreshes, not reasons for a blanket refusal.
	$compatible = $payload;
	$compatible['blocks'][1]['block_json']['attributes']['content'] = array(
		'type'    => 'number',
		'default' => 0,
	);
	$compatible['blocks'][0]['block_json']['attributes']['caption'] = array( 'type' => 'string' );
	$compatible['blocks'][0]['assets']['view.js']                   = 'console.debug("updated implementation");';
	$result = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $compatible, static fn () => true, true );
	$assert( 'refreshed' === $result['status'], 'unused schema, additive attribute and JavaScript update accepted' );
	$assert( str_contains( file_get_contents( WP_PLUGIN_DIR . '/ssi-saved-usage-proof/blocks/used/view.js' ), 'updated implementation' ), 'legitimate implementation update reaches disk' );
	update_option( $state_key, array(
		'post_id' => $state['post_id'],
		'payload' => $compatible,
	) );
} elseif ( 'reopen' === $phase ) {
	$assert( is_array( $state ), 'saved fixture reopened after replacement in another process' );
	$saved_post = get_post( $state['post_id'] );
	$assert( $saved_post->post_content === $markup, 'original owner edit persists across requests' );
	$assert( str_contains( do_blocks( $saved_post->post_content ), 'Owner edit survives' ), 'actual refreshed renderer still displays the owner edit' );
	$registry = WP_Block_Type_Registry::get_instance();
	$assert( 'number' === $registry->get_registered( 'ssi-proof/unused' )->attributes['content']['type'], 'unused schema refresh is registered in the fresh runtime' );
	$assert( isset( $registry->get_registered( 'ssi-proof/used' )->attributes['caption'] ), 'additive schema refresh is registered in the fresh runtime' );
} else {
	WP_CLI::error( 'Expected setup, refresh or reopen.' );
}
WP_CLI::success( 'Companion persistence phase: ' . $phase );

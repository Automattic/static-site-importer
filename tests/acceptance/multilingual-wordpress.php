<?php
/** Real TranslatePress dependency preparation, fresh runtime, ownership and rollback proof. */
if ( '1' !== getenv( 'SSI_MULTILINGUAL_DISPOSABLE' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Use an explicitly disposable WP-CLI runtime.' );
}
$assert  = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Machine-readable CLI assertion, never rendered HTML.
};
$import  = static function ( array $request ): array {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$file = wp_tempnam( 'ssi-multilingual-request.json' );
	file_put_contents( $file, wp_json_encode( $request ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Operator-owned temporary CLI request fixture.
	try {
		$output = WP_CLI::runcommand(
			'static-site-importer import --request=' . escapeshellarg( $file ) . ' --user=admin --keep-source',
			array(
				'return'     => true,
				'launch'     => true,
				'exit_error' => false,
			)
		);
		foreach ( array_reverse( explode( "\n", trim( $output ) ) ) as $line ) {
			$receipt = json_decode( $line, true );
			if ( is_array( $receipt ) && 'static-site-importer/import-cli-receipt/v1' === ( $receipt['schema'] ?? '' ) ) {
				wp_cache_flush();
				return $receipt['response'];
			}
		}
		throw new RuntimeException( 'Canonical CLI returned no receipt: ' . substr( $output, 0, 2000 ) );
	} finally {
		wp_delete_file( $file ); }
};
$request = array(
	'operation' => 'apply',
	'slug'      => 'multilingual-acceptance',
	'activate'  => true,
	'overwrite' => true,
	'source'    => array(
		'type'       => 'files',
		'entrypoint' => 'website/index.html',
		'files'      => array(
			array(
				'path'    => 'website/index.html',
				'content' => '<!doctype html><html lang="en"><head><title>Hello</title></head><body><main><h1>Hello community</h1><p>Editable native multilingual site.</p></main></body></html>',
			),
		),
	),
);
$plain   = $import( $request );
$assert( true === ( $plain['success'] ?? false ), 'The ordinary monolingual import completes.' );
$assert( ! is_dir( WP_PLUGIN_DIR . '/translatepress-multilingual' ), 'An undeclared monolingual import does not install TranslatePress.' );
$request['source']['metadata']['runtime_declarations'] = array(
	array(
		'kind'        => 'entity_collection',
		'type'        => 'multilingual',
		'source_path' => 'website/index.html',
		'payload'     => array(
			'schema'   => 'generic/multilingual/v1',
			'entities' => array(
				array(
					'id'               => 'site-languages',
					'default_language' => 'en_US',
					'languages'        => array( 'en_US', 'fr_FR' ),
				),
			),
		),
	),
);
$result = $import( $request );
$assert( true === ( $result['success'] ?? false ), 'The declared import prepares TranslatePress and completes through fresh-runtime continuation: ' . wp_json_encode( $result ) );
$assert( is_plugin_active( 'translatepress-multilingual/index.php' ), 'TranslatePress is actually active.' );
$settings = get_option( 'trp_settings' );
$assert( array( 'en_US', 'fr_FR' ) === ( $settings['publish-languages'] ?? null ) && 'en_US' === ( $settings['default-language'] ?? '' ), 'Declared native language settings persist.' );
$again = $import( $request );
$assert( true === ( $again['success'] ?? false ) && get_option( 'trp_settings' ) === $settings, 'Reimport preserves exact native settings.' );
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-translatepress-materializer.php';
// Load the newly activated provider in the parent proof request through its own bootstrap.
if ( ! class_exists( 'TRP_Translate_Press' ) ) {
	require_once WP_PLUGIN_DIR . '/translatepress-multilingual/index.php'; }
// The parent started before installation; initialize the provider's own component graph.
$initialize_provider = array( 'TRP_Translate_Press', 'get_trp_instance' );
call_user_func( $initialize_provider );
$ownership = get_option( 'static_site_importer_multilingual_configuration' );
$variants  = Static_Site_Importer_TranslatePress_Materializer::materialize(
	array(
		'multilingual' => array(
			array(
				'id'               => 'site-languages',
				'default_language' => 'en_US',
				'languages'        => array( 'en_US', 'en_GB' ),
			),
		),
	),
	array( 'declaration_reconciliation_identity' => $ownership['declaration'] )
);
$assert( ! is_wp_error( $variants ) && 2 === count( array_unique( get_option( 'trp_settings' )['url-slugs'] ) ), 'Locale variants have distinct native URL slugs.' );
$variant_rollback = Static_Site_Importer_TranslatePress_Materializer::rollback( $variants );
$assert( 'rolled_back' === $variant_rollback['status'] && get_option( 'trp_settings' ) === $settings, 'Variant configuration restores exactly.' );
$changed = Static_Site_Importer_TranslatePress_Materializer::materialize(
	array(
		'multilingual' => array(
			array(
				'id'               => 'site-languages',
				'default_language' => 'en_US',
				'languages'        => array( 'en_US', 'es_ES' ),
			),
		),
	),
	array( 'declaration_reconciliation_identity' => $ownership['declaration'] )
);
$assert( ! is_wp_error( $changed ) && array( 'en_US', 'es_ES' ) === get_option( 'trp_settings' )['publish-languages'], 'Source-backed updates change importer-owned language settings: ' . ( is_wp_error( $changed ) ? $changed->get_error_message() : wp_json_encode( $changed ) ) );
$rollback = Static_Site_Importer_TranslatePress_Materializer::rollback( $changed );
$assert( 'rolled_back' === $rollback['status'] && get_option( 'trp_settings' ) === $settings, 'Rollback restores exact owned language settings.' );
$owner                     = $settings;
$owner['default-language'] = 'fr_FR';
update_option( 'trp_settings', $owner );
$conflict = Static_Site_Importer_TranslatePress_Materializer::materialize(
	array(
		'multilingual' => array(
			array(
				'id'               => 'site-languages',
				'default_language' => 'en_US',
				'languages'        => array( 'en_US', 'es_ES' ),
			),
		),
	),
	array( 'declaration_reconciliation_identity' => $ownership['declaration'] )
);
$assert( is_wp_error( $conflict ) && get_option( 'trp_settings' ) === $owner, 'Explicit owner language changes are protected.' );
update_option( 'trp_settings', $settings );
echo wp_json_encode(
	array(
		'status'              => 'passed',
		'wordpress'           => get_bloginfo( 'version' ),
		'provider_version'    => defined( 'TRP_PLUGIN_VERSION' ) ? TRP_PLUGIN_VERSION : null,
		'published_languages' => $settings['publish-languages'],
		'reimport'            => 'idempotent',
		'rollback'            => 'restored',
		'owner_conflict'      => 'preserved',
	)
) . "\n";

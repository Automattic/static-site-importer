<?php
/** Load the explicitly mounted, committed paired producer source for prototype acceptance. */
if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'Producer source overlay is acceptance-only and requires WordPress.' );
}
$ssi_external_metric_producer_source = getenv( 'SSI_BLOCKS_ENGINE_PHP_TRANSFORMER_SOURCE' );
if ( ! is_string( $ssi_external_metric_producer_source ) || '' === $ssi_external_metric_producer_source || ! is_file( $ssi_external_metric_producer_source . '/composer.json' ) ) {
	throw new RuntimeException( 'The committed producer source checkout must be explicitly mounted for prototype acceptance.' );
}
$ssi_external_metric_producer_source = realpath( $ssi_external_metric_producer_source );
$GLOBALS['ssi_external_metric_producer_source'] = $ssi_external_metric_producer_source;
spl_autoload_register(
	static function ( string $class ) use ( $ssi_external_metric_producer_source ): void {
		$prefix = 'Automattic\\BlocksEngine\\PhpTransformer\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return; }
		$file = $ssi_external_metric_producer_source . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_file( $file ) ) {
			require_once $file; }
	},
	true,
	true
);

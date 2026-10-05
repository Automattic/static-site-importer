<?php
/** Real WordPress option-storage semantics, including no-op writes. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
$GLOBALS['ssi_options'] = array( 'page_on_front' => '9', 'use_smilies' => '0' );
$GLOBALS['ssi_option_writes'] = 0;
function get_option( $name, $default = false ) { return $GLOBALS['ssi_options'][ $name ] ?? $default; }
function sanitize_option( $name, $value ) { return 'page_on_front' === $name ? (int) $value : $value; }
function update_option( $name, $value ) {
	++$GLOBALS['ssi_option_writes'];
	if ( ! empty( $GLOBALS['ssi_refuse_option_write'] ) || ( isset( $GLOBALS['ssi_options'][ $name ] ) && (string) $GLOBALS['ssi_options'][ $name ] === (string) $value ) ) {
		return false;
	}
	$GLOBALS['ssi_options'][ $name ] = (string) $value;
	return true;
}
require dirname( __DIR__ ) . '/includes/class-static-site-importer-site-plan-persistence.php';
$assert = static function ( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } };
$assert( Static_Site_Importer_Site_Plan_Persistence::write_option( 'page_on_front', 9 ), 'An already-applied front page remains applied across requests.' );
$assert( Static_Site_Importer_Site_Plan_Persistence::write_option( 'use_smilies', 0 ), 'An already-applied scalar runtime policy remains applied.' );
$assert( 0 === $GLOBALS['ssi_option_writes'], 'Idempotent verification requires no database write.' );
$assert( Static_Site_Importer_Site_Plan_Persistence::write_option( 'page_on_front', 10 ) && '10' === get_option( 'page_on_front' ), 'A changed front page is written and verified using database storage semantics.' );
$GLOBALS['ssi_refuse_option_write'] = true;
$assert( ! Static_Site_Importer_Site_Plan_Persistence::write_option( 'page_on_front', 11 ), 'A genuinely unapplied change still fails verification.' );
echo "Option idempotence smoke passed.\n";

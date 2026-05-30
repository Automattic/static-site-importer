<?php
/**
 * Smoke test: CLI handles Ability WP_Error results without fataling.
 *
 * Run from the repository root:
 * php tests/smoke-cli-ability-error.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

class WP_Error {
	private string $code;
	private string $message;

	public function __construct( string $code, string $message ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}
}

function is_wp_error( mixed $thing ): bool {
	return $thing instanceof WP_Error;
}

function wp_get_ability( string $name ): object|null {
	if ( 'static-site-importer/import-theme' !== $name ) {
		return null;
	}

	return new class() {
		public function execute( array $args ): WP_Error {
			if ( array_key_exists( 'max_fallbacks', $args ) ) {
				throw new RuntimeException( 'max_fallbacks should be omitted when --max-fallbacks is absent.' );
			}

			return new WP_Error( 'fixture_error', 'Fixture ability failure.' );
		}
	};
}

class WP_CLI {
	public static function error( string $message ): void {
		throw new RuntimeException( $message );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-cli-command.php';

$command = new Static_Site_Importer_CLI_Command();

try {
	$command->import_theme( array( '/tmp/source/index.html' ), array() );
} catch ( RuntimeException $error ) {
	if ( 'Fixture ability failure.' !== $error->getMessage() ) {
		fwrite( STDERR, 'FAIL [wrong-error-message]: ' . $error->getMessage() . "\n" );
		exit( 1 );
	}

	echo "OK: CLI ability WP_Error smoke passed (1 assertion)\n";
	exit( 0 );
}

fwrite( STDERR, "FAIL [missing-cli-error]\n" );
exit( 1 );

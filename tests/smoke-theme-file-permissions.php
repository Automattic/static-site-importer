<?php
/**
 * Published theme/plugin files take the site's normal file mode; private workspace files stay 0600.
 *
 * Run: php tests/smoke-theme-file-permissions.php
 *
 * @package StaticSiteImporter
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
		public function get_error_code(): string {
			return $this->code;
		}
	}
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
// Mirrors core: new directories inherit the parent's permission bits.
function wp_mkdir_p( $dir ) {
	if ( is_dir( $dir ) ) {
		return true;
	}
	$parent = dirname( $dir );
	if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
		return false;
	}
	$mode = fileperms( $parent ) & 0777;
	return mkdir( $dir, $mode ) && chmod( $dir, $mode );
}
function wp_json_encode( $value, int $options = 0 ) { return json_encode( $value, $options ); }
function apply_filters( string $hook, $value, ...$args ) { return $value; }

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-site-plan-persistence.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-plugin-materializer.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-artifact-run.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-journaled-report-writer.php';

$failures = 0;
$assert   = static function ( bool $ok, string $message ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
		fwrite( STDERR, 'FAIL: ' . $message . "\n" );
	}
};
$mode = static function ( string $path ): int {
	clearstatcache( true, $path );
	return fileperms( $path ) & 0777;
};

umask( 0022 );
$expected_file = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : ( 0644 & ~umask() );
$expected_dir  = defined( 'FS_CHMOD_DIR' ) ? FS_CHMOD_DIR : ( 0755 & ~umask() );
$assert( 0644 === $expected_file, 'Without FS_CHMOD_FILE the file mode follows core: 0644 & ~umask().' );
$assert( 0755 === $expected_dir, 'Without FS_CHMOD_DIR the directory mode follows core: 0755 & ~umask().' );

$root = sys_get_temp_dir() . '/ssi-theme-perms-' . bin2hex( random_bytes( 6 ) );
mkdir( $root, 0700 );
chmod( $root, 0700 );
$themes = $root . '/themes';
mkdir( $themes, 0755 );
chmod( $themes, 0755 );
$theme = $themes . '/imported-theme';

$writes = array(
	'style.css'                        => "/*\nTheme Name: Imported\n*/\n",
	'templates/index.html'             => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
	'ssi-runtime/companion/runtime.js' => 'console.log("runtime");',
	'assets/fonts/inter.woff2'         => "wOF2\0binary",
);
foreach ( $writes as $target => $bytes ) {
	$result = Static_Site_Importer_Site_Plan_Persistence::write_file(
		$theme,
		array(
			'source_path' => $target,
			'target_path' => $target,
			'payload'     => array(
				'encoding' => 'base64',
				'data'     => base64_encode( $bytes ),
			),
		)
	);
	$assert( is_array( $result ), $target . ': write_file must publish the theme file.' );
	$path = $theme . '/' . $target;
	$assert( is_file( $path ) && $bytes === file_get_contents( $path ), $target . ': published bytes must match.' );
	$actual = is_file( $path ) ? $mode( $path ) : -1;
	$assert( $expected_file === $actual, sprintf( '%s: published theme file mode must be %04o, got %04o.', $target, $expected_file, $actual ) );
	$assert( 0600 !== $actual, $target . ': published theme file must not be 0600.' );
}
foreach ( array( $theme, $theme . '/templates', $theme . '/ssi-runtime', $theme . '/ssi-runtime/companion', $theme . '/assets/fonts' ) as $dir ) {
	$actual = is_dir( $dir ) ? $mode( $dir ) : -1;
	$assert( $expected_dir === $actual, sprintf( '%s: published theme directory mode must be %04o, got %04o.', basename( $dir ), $expected_dir, $actual ) );
}
$assert( empty( glob( $theme . '/.ssi-plan-*' ) ), 'No temporary publication file may remain.' );

// A previously published 0600 file is repaired when it reconciles unchanged.
chmod( $theme . '/style.css', 0600 );
Static_Site_Importer_Site_Plan_Persistence::write_file(
	$theme,
	array(
		'source_path' => 'style.css',
		'target_path' => 'style.css',
		'payload'     => array(
			'encoding' => 'base64',
			'data'     => base64_encode( $writes['style.css'] ),
		),
	)
);
$assert( $expected_file === $mode( $theme . '/style.css' ), 'A reconciled theme file must take the site file mode.' );

// Generated companion plugin files.
$plugin_dir = $root . '/plugins/ssi-companion';
mkdir( dirname( $plugin_dir ), 0755 );
chmod( dirname( $plugin_dir ), 0755 );
$write_generated = new ReflectionMethod( Static_Site_Importer_Plugin_Materializer::class, 'write_generated_files' );
$written         = $write_generated->invoke(
	null,
	array(
		'absolute_files' => array(
			$plugin_dir . '/ssi-companion.php'          => "<?php\n",
			$plugin_dir . '/build/blocks/hero/index.js' => 'export {};',
		),
	)
);
$assert( true === $written, 'Companion plugin files must be written.' );
foreach ( array( 'ssi-companion.php', 'build/blocks/hero/index.js' ) as $relative ) {
	$path   = $plugin_dir . '/' . $relative;
	$actual = is_file( $path ) ? $mode( $path ) : -1;
	$assert( $expected_file === $actual, sprintf( '%s: companion plugin file mode must be %04o, got %04o.', $relative, $expected_file, $actual ) );
}
foreach ( array( $plugin_dir, $plugin_dir . '/build', $plugin_dir . '/build/blocks', $plugin_dir . '/build/blocks/hero' ) as $dir ) {
	$actual = is_dir( $dir ) ? $mode( $dir ) : -1;
	$assert( $expected_dir === $actual, sprintf( '%s: companion plugin directory mode must be %04o, got %04o.', basename( $dir ), $expected_dir, $actual ) );
}

// The theme manifest is a journaled projection published into the theme.
mkdir( $root . '/manifest-theme', 0755 );
chmod( $root . '/manifest-theme', 0755 );
Static_Site_Importer_Journaled_Report_Writer::write( $root . '/manifest-theme/static-site-importer-manifest.json', array( 'theme' => 'perms' ) );
$assert( $expected_file === $mode( $root . '/manifest-theme/static-site-importer-manifest.json' ), 'Theme manifest projection must take the site file mode.' );

// Private artifact-run workspace files stay private.
mkdir( $root . '/workspace', 0700 );
$workspace = new Static_Site_Importer_Artifact_Run_Workspace( $root . '/workspace', 'perms-run' );
$published = $workspace->publish_json_once( 'page-plans/home.json', array( 'page' => 'home' ) );
$assert( ! is_wp_error( $published ), 'Workspace artifact must publish.' );
$workspace_files = array_filter(
	iterator_to_array( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/workspace', FilesystemIterator::SKIP_DOTS ) ) ),
	static fn( SplFileInfo $file ): bool => $file->isFile()
);
$assert( ! empty( $workspace_files ), 'Workspace must contain a file.' );
foreach ( $workspace_files as $file ) {
	$assert( 0600 === $mode( $file->getPathname() ), $file->getFilename() . ': workspace file must stay 0600.' );
}

$cleanup = static function ( string $path ) use ( &$cleanup ): void {
	if ( is_dir( $path ) && ! is_link( $path ) ) {
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$cleanup( $path . '/' . $entry );
			}
		}
		rmdir( $path );
	} elseif ( file_exists( $path ) || is_link( $path ) ) {
		unlink( $path );
	}
};
$cleanup( $root );

if ( $failures > 0 ) {
	fwrite( STDERR, $failures . " theme file permission assertion(s) failed.\n" );
	exit( 1 );
}
echo "theme file permissions smoke passed\n";

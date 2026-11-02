<?php
/**
 * Front-page title facts and theme separator presentation.
 *
 * @package StaticSiteImporter
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
function wp_strip_all_tags( string $text ): string {
	return strip_tags( $text );
}
function sanitize_text_field( string $text ): string {
	return trim( strip_tags( $text ) );
}
function get_option( string $name, $default = false ) {
	return $GLOBALS['title_options'][ $name ] ?? $default;
}
function update_option( string $name, $value ): bool {
	$GLOBALS['title_options'][ $name ] = $value;
	return true;
}
function add_filter( string $name, callable $callback ): void {
	$GLOBALS['title_filters'][ $name ] = $callback;
}
require_once ABSPATH . 'includes/class-static-site-importer-site-identity.php';
require_once ABSPATH . 'includes/class-static-site-importer-site-language.php';
require_once ABSPATH . 'includes/class-static-site-importer-import-destination.php';
require_once ABSPATH . 'includes/class-static-site-importer-site-plan-persistence.php';
require_once ABSPATH . 'includes/class-static-site-importer-media-library-materializer.php';

$cases = array();
foreach ( array( '-', '–', '—', '|', '·', '•' ) as $separator ) {
	$cases[] = array( 'Name ' . $separator . ' Tagline', '', '', 'Tagline', $separator );
}
$cases[] = array( 'Name - Tagline', 'Explicit', 'Slogan', 'Explicit', '-' );
$cases[] = array( 'Name - Tagline', '', 'Slogan', 'Slogan', '-' );
$cases[] = array( 'Name', '', '', '', '' );
$cases[] = array( 'Other - Tagline', '', '', '', '' );
$cases[] = array( 'Name extra - Tagline', '', '', '', '' );
$cases[] = array( 'Name - ', '', '', '', '' );
$cases[] = array( 'Name - Team - Tagline', '', '', 'Tagline', '-', 'Name - Team' );
$cases[] = array( 'Name &amp; Team &#124; News &amp; tips', '', '', 'News & tips', '|', 'Name & Team' );
$failures = array();
$assert   = static function ( bool $ok, string $label ) use ( &$failures ): void {
	if ( ! $ok ) {
		$failures[] = $label;
	}
};
foreach ( $cases as $index => $case ) {
	list( $title, $explicit, $slogan, $expected, $separator ) = $case;
	$artifact = array(
		'entrypoint' => 'index.html',
		'files'      => array( array(
			'path'    => 'index.html',
			'content' => '<title>' . $title . '</title><script type="application/ld+json">' . json_encode( array( '@type' => 'WebSite', 'slogan' => $slogan ) ) . '</script>',
		) ),
	);
	$evidence = Static_Site_Importer_Site_Identity::evidence_from_website_artifact( $artifact, null, $explicit, $case[5] ?? 'Name' );
	$assert( $expected === $evidence['tagline'], "$index tagline" );
	$assert( $separator === ( $evidence['title_separator'] ?? '' ), "$index separator evidence" );
	$GLOBALS['title_options'] = array();
	$state = array(
		'args'      => array( 'activate' => true, 'site_tagline' => $explicit, 'native_site_identity_evidence' => $evidence ),
		'theme_dir' => '/theme',
		'resolved'  => array( 'writes' => array( array( 'target_path' => 'functions.php', 'payload' => array( 'encoding' => 'utf8', 'data' => "<?php\n// Plan bootstrap.\n" ) ) ) ),
	);
	Static_Site_Importer_Media_Library_Materializer::materialize_identity( $state );
	$assert( $expected === get_option( 'blogdescription', '' ), "$index native tagline" );
	$GLOBALS['title_options']['blogdescription'] = 'Owner tagline';
	Static_Site_Importer_Media_Library_Materializer::materialize_identity( $state );
	$assert( 'Owner tagline' === get_option( 'blogdescription' ), "$index owner preserved" );
	foreach ( array( false, true ) as $composed ) {
		$run = $state;
		if ( $composed ) {
			$run['composed_theme_writes']['/theme/functions.php'] = "<?php\n// Prior overlay.\n";
		}
		Static_Site_Importer_Site_Identity::compose_title_separator( $run );
		$bootstrap = $run['composed_theme_writes']['/theme/functions.php'] ?? '';
		$needed    = '' !== $separator && '–' !== $separator;
		$assert( $needed === str_contains( $bootstrap, "add_filter( 'document_title_separator'" ), "$index filter presence" );
		if ( $needed ) {
			$assert( str_contains( $bootstrap, $composed ? '// Prior overlay.' : '// Plan bootstrap.' ), "$index bootstrap preserved" );
			$assert( $composed || $bootstrap === $run['resolved']['writes'][0]['payload']['data'], "$index plan write carries the filter" );
			$before = $bootstrap;
			Static_Site_Importer_Site_Identity::compose_title_separator( $run );
			$assert( $before === $run['composed_theme_writes']['/theme/functions.php'], "$index idempotent" );
			$GLOBALS['title_filters'] = array();
			eval( substr( $bootstrap, 5 ) );
			$actual = $GLOBALS['title_filters']['document_title_separator']( '–' );
			$assert( $separator === $actual, "$index executable separator" );
		}
	}
}
if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo "Title tagline smoke passed.\n";

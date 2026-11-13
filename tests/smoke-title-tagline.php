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
function get_bloginfo( string $name, string $filter = 'raw' ): string {
	return get_option( 'name' === $name ? 'blogname' : 'blogdescription', '' );
}
function is_front_page(): bool {
	return $GLOBALS['title_front_page'] ?? false;
}
function add_filter( string $name, callable $callback ): void {
	$GLOBALS['title_filters'][ $name ] = $callback;
}
require_once ABSPATH . 'vendor/autoload.php';
require_once ABSPATH . 'includes/class-static-site-importer-site-identity.php';
require_once ABSPATH . 'includes/class-static-site-importer-site-language.php';
require_once ABSPATH . 'includes/class-static-site-importer-import-destination.php';
require_once ABSPATH . 'includes/class-static-site-importer-site-plan-persistence.php';
require_once ABSPATH . 'includes/class-static-site-importer-media-library-materializer.php';

$cases = array();
foreach ( array( '-', '–', '—', '|', '·', '•', ':' ) as $separator ) {
	$cases[] = array( 'Name ' . $separator . ' Tagline', '', '', 'Tagline', $separator );
}
$cases[] = array( 'Atelier Exemple : céramique à Lyon', '', '', 'céramique à Lyon', ':', 'Atelier Exemple' );
$cases[] = array( 'Name: subtitle', '', '', '', '' );
$cases[] = array( 'Name :subtitle', '', '', '', '' );
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
			'content' => '<title>' . $title . '</title><meta property="og:site_name" content="' . ( $case[5] ?? 'Name' ) . '"><meta name="description" content="Une longue description du site."><script type="application/ld+json">' . json_encode( array( '@type' => 'WebSite', 'slogan' => $slogan ) ) . '</script>',
		) ),
	);
	$site_title = Static_Site_Importer_Site_Identity::site_title_from_website_artifact( $artifact );
	$assert( ( $case[5] ?? 'Name' ) === $site_title, "$index site title" );
	$evidence = Static_Site_Importer_Site_Identity::evidence_from_website_artifact( $artifact, null, $explicit, $site_title );
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
		if ( $needed && str_contains( $bootstrap, "add_filter( 'document_title_separator'" ) ) {
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
// Without branding metadata, an English colon title must also remain whole.
$artifact = array( 'entrypoint' => 'index.html', 'files' => array( array( 'path' => 'index.html', 'content' => '<title>Name: subtitle</title>' ) ) );
$assert( 'Name: subtitle' === Static_Site_Importer_Site_Identity::site_title_from_website_artifact( $artifact ), 'unspaced colon majority route title' );
// A brand can move between title edges across routes and separators.
$artifact = array( 'entrypoint' => 'index.html', 'files' => array() );
foreach ( array( 'Home | Acme Electric', 'Services | Installation | Acme Electric', 'Acme Electric — About', 'Acme Electric — Contact' ) as $index => $title ) {
	$artifact['files'][] = array(
		'path'    => 0 === $index ? 'index.html' : $index . '/index.html',
		'content' => '<title>' . $title . '</title>',
	);
}
$assert( 'Acme Electric' === Static_Site_Importer_Site_Identity::site_title_from_website_artifact( $artifact ), 'mixed-edge majority route title' );
// Public evidence/composition handoff preserves a brand-last homepage title.
foreach ( array( '|', '–' ) as $separator ) {
	$artifact = array( 'entrypoint' => 'index.html', 'files' => array(
		array( 'path' => 'index.html', 'content' => '<title>Home ' . $separator . ' My Site 1</title>' ),
		array( 'path' => 'about.html', 'content' => '<title>About ' . $separator . ' My Site 1</title>' ),
	) );
	$brand = Static_Site_Importer_Site_Identity::site_title_from_website_artifact( $artifact );
	$evidence = Static_Site_Importer_Site_Identity::evidence_from_website_artifact( $artifact, null, '', $brand );
	$state = array(
		'args' => array( 'native_site_identity_evidence' => $evidence ),
		'theme_dir' => '/theme',
		'resolved' => array( 'writes' => array( array( 'target_path' => 'functions.php', 'payload' => array( 'encoding' => 'utf8', 'data' => "<?php\n" ) ) ) ),
	);
	Static_Site_Importer_Site_Identity::compose_title_separator( $state );
	$bootstrap = $state['composed_theme_writes']['/theme/functions.php'] ?? '<?php';
	$before = $bootstrap;
	Static_Site_Importer_Site_Identity::compose_title_separator( $state );
	$assert( $before === ( $state['composed_theme_writes']['/theme/functions.php'] ?? '<?php' ), 'brand-last composition is idempotent' );
	$GLOBALS['title_filters'] = array();
	eval( substr( $bootstrap, 5 ) );
	$filter = $GLOBALS['title_filters']['document_title_parts'] ?? static fn( array $parts ): array => $parts;
	$GLOBALS['title_front_page'] = true;
	$parts = $filter( array( 'title' => $brand, 'tagline' => $evidence['tagline'] ) );
	$assert( 'Home ' . $separator . ' My Site 1' === implode( ' ' . $separator . ' ', $parts ), 'brand-last source title renders through native parts' );
	$assert( array( 'tagline' => 'New tagline', 'title' => 'New brand' ) === $filter( array( 'title' => 'New brand', 'tagline' => 'New tagline' ) ), 'owner edits remain live' );
	$assert( array( 'title' => 'New brand' ) === $filter( array( 'title' => 'New brand' ) ), 'missing tagline stays absent' );
	$GLOBALS['title_front_page'] = false;
	$inner = array( 'title' => 'About', 'site' => $brand );
	$assert( $inner === $filter( $inner ), 'inner route ordering unchanged' );
}
// A site-wide shared document title must not acquire an inner page's heading.
// Exercise evidence intake and the generated theme filter, not private helpers.
foreach ( array( 'shared', 'brand-last', 'per-page', 'single', 'missing' ) as $shape ) {
	$titles = 'brand-last' === $shape ? array( 'Home | My Site', 'Home | My Site' ) : array( 'Business Casual - Start Bootstrap Theme', 'Business Casual - Start Bootstrap Theme' );
	if ( 'per-page' === $shape ) {
		$titles = array( 'Home | My Site', 'About | My Site' );
	} elseif ( 'single' === $shape ) {
		$titles = array( $titles[0] );
	} elseif ( 'missing' === $shape ) {
		$titles[] = '';
	}
	$artifact = array( 'entrypoint' => 'index.html', 'files' => array() );
	foreach ( $titles as $index => $title ) {
		$artifact['files'][] = array( 'path' => $index ? $index . '.html' : 'index.html', 'content' => '<title>' . $title . '</title>' . ( 'brand-last' === $shape ? '<meta property="og:site_name" content="My Site">' : '' ) );
	}
	// Capture bundles include untitled shared fragments alongside the routes.
	$artifact['files'][] = array( 'path' => 'parts/header.html', 'content' => '<header>Site header</header>' );
	$artifact['files'][] = array( 'path' => 'template-parts/footer.htm', 'content' => '<footer>Site footer</footer>' );
	$artifact['files'][] = array( 'path' => 'shared/nav.html', 'role' => 'template-part', 'content' => '<nav>Navigation</nav>' );
	$artifact['files'][] = array( 'path' => 'style.css', 'content' => 'body {}' );
	$brand = Static_Site_Importer_Site_Identity::site_title_from_website_artifact( $artifact );
	$evidence = Static_Site_Importer_Site_Identity::evidence_from_website_artifact( $artifact, null, '', $brand );
	$state = array(
		'args' => array( 'native_site_identity_evidence' => $evidence ),
		'theme_dir' => '/theme',
		'resolved' => array( 'writes' => array( array( 'target_path' => 'functions.php', 'payload' => array( 'encoding' => 'utf8', 'data' => "<?php\n" ) ) ) ),
	);
	Static_Site_Importer_Site_Identity::compose_title_separator( $state );
	$GLOBALS['title_filters'] = array();
	$GLOBALS['title_options'] = array( 'blogname' => $brand, 'blogdescription' => $evidence['tagline'] );
	eval( substr( $state['composed_theme_writes']['/theme/functions.php'] ?? '<?php', 5 ) );
	$filter = $GLOBALS['title_filters']['document_title_parts'] ?? static fn( array $parts ): array => $parts;
	$GLOBALS['title_front_page'] = false;
	$inner = array( 'title' => 'About Our Cafe', 'site' => $brand );
	$shared = in_array( $shape, array( 'shared', 'brand-last' ), true );
	$parts = $filter( $inner );
	$separator = $evidence['title_separator'] ?? '–';
	$assert( $shared ? $titles[0] === implode( ' ' . $separator . ' ', $parts ) : $inner === $parts, "$shape inner route title" );
	if ( $shared ) {
		$GLOBALS['title_options'] = array( 'blogname' => 'Owner brand', 'blogdescription' => 'Owner tagline' );
		$expected = 'brand-last' === $shape ? array( 'tagline' => 'Owner tagline', 'title' => 'Owner brand' ) : array( 'title' => 'Owner brand', 'tagline' => 'Owner tagline' );
		$assert( $expected === $filter( $inner ), "$shape owner edits remain live" );
		$GLOBALS['title_options']['blogdescription'] = '';
		$assert( array( 'title' => 'Owner brand' ) === $filter( $inner ), "$shape empty tagline omitted" );
	}
}
// A standalone home title must not hide the inner-route branding consensus.
$artifact = array( 'entrypoint' => 'index.html', 'files' => array(
	array( 'path' => 'index.html', 'content' => '<title>Acme Widgets Company</title>' ),
	array( 'path' => 'about.html', 'content' => '<title>About | Acme</title>' ),
	array( 'path' => 'blog.html', 'content' => '<title>Blog | Acme</title>' ),
) );
$brand = Static_Site_Importer_Site_Identity::site_title_from_website_artifact( $artifact );
$assert( 'Acme' === $brand, 'standalone home route consensus brand' );
$evidence = Static_Site_Importer_Site_Identity::evidence_from_website_artifact( $artifact, null, 'Meta description tagline', $brand );
$assert( '|' === ( $evidence['title_separator'] ?? '' ), 'route consensus separator' );
$assert( true === ( $evidence['title_brand_last'] ?? false ), 'route consensus brand last' );
$assert( 'Meta description tagline' === $evidence['tagline'], 'standalone home preserves tagline evidence' );
$state = array(
	'args' => array( 'native_site_identity_evidence' => $evidence ),
	'theme_dir' => '/theme',
	'resolved' => array( 'writes' => array( array( 'target_path' => 'functions.php', 'payload' => array( 'encoding' => 'utf8', 'data' => "<?php\n" ) ) ) ),
);
Static_Site_Importer_Site_Identity::compose_title_separator( $state );
$bootstrap = $state['composed_theme_writes']['/theme/functions.php'];
Static_Site_Importer_Site_Identity::compose_title_separator( $state );
$assert( $bootstrap === $state['composed_theme_writes']['/theme/functions.php'], 'standalone title composition idempotent' );
$GLOBALS['title_filters'] = array();
eval( substr( $bootstrap, 5 ) );
$filter = $GLOBALS['title_filters']['document_title_parts'];
$separator = $GLOBALS['title_filters']['document_title_separator']( '–' );
$GLOBALS['title_front_page'] = true;
$parts = $filter( array( 'title' => $brand, 'tagline' => $evidence['tagline'] ) );
$assert( 'Acme Widgets Company' === implode( ' ' . $separator . ' ', $parts ), 'standalone source home title renders through composed filters' );
$GLOBALS['title_front_page'] = false;
$parts = $filter( array( 'title' => 'About', 'site' => $brand ) );
$assert( 'About | Acme' === implode( ' ' . $separator . ' ', $parts ), 'inner title uses route separator without home override' );
if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo "Title tagline smoke passed.\n";

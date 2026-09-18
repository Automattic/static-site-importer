<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['static_site_importer_head_metadata_emitted'] = false;
$GLOBALS['ssi_head_meta']                              = array();
$GLOBALS['ssi_queried_id']                             = 1;
$GLOBALS['ssi_is_singular']                            = true;
$GLOBALS['ssi_is_front_page']                          = false;
$GLOBALS['ssi_has_action']                             = array();

function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value );
}
function wp_strip_all_tags( string $value ): string {
	return trim( strip_tags( $value ) );
}
function esc_attr( string $value ): string {
	return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}
function esc_url( string $value ): string {
	$value = trim( $value );
	if ( '' === $value || 1 === preg_match( '/^(?:javascript|vbscript|data):/i', $value ) ) {
		return '';
	}
	return $value;
}
function is_singular(): bool {
	return (bool) $GLOBALS['ssi_is_singular'];
}
function is_front_page(): bool {
	return (bool) $GLOBALS['ssi_is_front_page'];
}
function get_queried_object_id(): int {
	return (int) $GLOBALS['ssi_queried_id'];
}
function get_post_meta( int $id, string $key, bool $single = false ): string {
	unset( $single );
	return (string) ( $GLOBALS['ssi_head_meta'][ $id ][ $key ] ?? '' );
}
function has_action( string $hook, $callback = false ): bool {
	return ! empty( $GLOBALS['ssi_has_action'][ $hook ][ (string) $callback ] );
}
function add_action( string $hook, $callback, int $priority = 10 ): void {
	unset( $hook, $callback, $priority );
}

$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( is_readable( $autoload ) ) {
	require $autoload;
}
require dirname( __DIR__ ) . '/includes/class-static-site-importer-route-head-metadata.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$page = static function ( string $path, array $meta, bool $entrypoint = false ): array {
	$row = array(
		'source_path'       => $path,
		'document_metadata' => array( 'meta' => $meta ),
	);
	if ( $entrypoint ) {
		$row['entrypoint'] = true;
	}
	return $row;
};

$home_tags = Static_Site_Importer_Route_Head_Metadata::from_page(
	$page(
		'index.html',
		array(
			array( 'charset' => 'utf-8' ),
			array( 'name' => 'viewport', 'content' => 'width=device-width' ),
			array( 'name' => 'robots', 'content' => 'index,follow' ),
			array( 'name' => 'description', 'content' => 'Home description' ),
			array( 'property' => 'og:title', 'content' => 'Home OG' ),
			array( 'property' => 'og:description', 'content' => 'Home social' ),
			array( 'property' => 'og:image', 'content' => '/media/home.png', 'resolved_url' => 'https://example.test/wp-content/themes/site/assets/media/home.png' ),
			array( 'property' => 'og:url', 'content' => 'https://source.example/' ),
			array( 'property' => 'og:type', 'content' => 'website' ),
			array( 'property' => 'og:site_name', 'content' => 'Example' ),
			array( 'name' => 'twitter:card', 'content' => 'summary_large_image' ),
			array( 'name' => 'twitter:title', 'content' => 'Home Twitter' ),
			array( 'name' => 'twitter:image', 'content' => '/media/home.png', 'resolved_url' => 'https://example.test/wp-content/themes/site/assets/media/home.png' ),
			array( 'name' => 'apple-mobile-web-app-title', 'content' => 'Example' ),
		),
		true
	)
);
$about_tags = Static_Site_Importer_Route_Head_Metadata::from_page(
	$page(
		'about.html',
		array(
			array( 'name' => 'description', 'content' => 'About description' ),
			array( 'property' => 'og:title', 'content' => 'About OG' ),
		)
	)
);

$by_key = static function ( array $tags ): array {
	$indexed = array();
	foreach ( $tags as $tag ) {
		$indexed[ $tag['key'] ] = $tag;
	}
	return $indexed;
};
$home_by_key = $by_key( $home_tags );
$assert( 11 === count( $home_tags ), 'Descriptive head tags should be persisted and non-descriptive tags skipped.' );
$assert( 'Home description' === ( $home_by_key['description']['content'] ?? '' ) && 'name' === ( $home_by_key['description']['attr'] ?? '' ), 'Meta description should persist as a name tag.' );
$assert( 'url' === ( $home_by_key['og:image']['kind'] ?? '' ) && 'https://example.test/wp-content/themes/site/assets/media/home.png' === ( $home_by_key['og:image']['content'] ?? '' ), 'Relative og:image should resolve through the importer asset URL, not string concatenation.' );
$assert( 'About description' === $about_tags[0]['content'] && 'About OG' === $about_tags[1]['content'], 'Each page should keep its own descriptive metadata.' );

$escaped = Static_Site_Importer_Route_Head_Metadata::from_page(
	$page(
		'xss.html',
		array(
			array( 'name' => 'description', 'content' => 'Hello "><script>alert(1)</script>' ),
			array( 'property' => 'og:url', 'content' => 'javascript:alert(1)' ),
		)
	)
);
$assert( 'Hello ">alert(1)' === ( $escaped[0]['content'] ?? null ) && 1 === count( $escaped ), 'Stored text strips tags; javascript URLs are dropped.' );

$result    = Static_Site_Importer_Route_Head_Metadata::prepare_overlay(
	array( 'writes' => array() ),
	array( 'writes' => array( array( 'target_path' => 'functions.php', 'content' => "<?php\n// Existing bootstrap.\n" ) ) )
);
$bootstrap = (string) ( $result['writes'][0]['content'] ?? '' );
$assert( 'materialized' === ( $result['status'] ?? '' ), 'Route head metadata should materialize into the portable theme bootstrap.' );
$assert( str_contains( $bootstrap, '// Existing bootstrap.' ) && str_contains( $bootstrap, 'Static Site Importer authored route head metadata' ), 'The overlay should keep prior bootstrap code and add the head-metadata marker.' );
$assert( str_contains( $bootstrap, "add_action( 'wp_head'" ) && str_contains( $bootstrap, 'esc_attr' ) && str_contains( $bootstrap, 'esc_url' ), 'The portable theme bootstrap must emit escaped tags on wp_head.' );
$assert( str_contains( $bootstrap, 'WPSEO_VERSION' ) && str_contains( $bootstrap, 'RANK_MATH_VERSION' ) && str_contains( $bootstrap, 'THE_SEO_FRAMEWORK_VERSION' ) && str_contains( $bootstrap, 'AIOSEO_VERSION' ) && str_contains( $bootstrap, 'jetpack_og_tags' ), 'The overlay must suppress emission when Yoast, Rank Math, SEO Framework, AIOSEO, or Jetpack OG already owns wp_head.' );

$repeat = Static_Site_Importer_Route_Head_Metadata::prepare_overlay(
	array(),
	array( 'writes' => array( array( 'target_path' => 'functions.php', 'content' => $bootstrap ) ) )
);
$assert( 1 === substr_count( (string) ( $repeat['writes'][0]['content'] ?? '' ), 'Static Site Importer authored route head metadata' ), 'The overlay should be idempotent.' );

$GLOBALS['ssi_head_meta'][1]['_static_site_importer_provenance'] = (string) wp_json_encode(
	array(
		'schema'        => 'static-site-importer/page-provenance/v1',
		'head_metadata' => $home_tags,
	)
);
ob_start();
Static_Site_Importer_Route_Head_Metadata::print_tags();
$emitted = (string) ob_get_clean();
$assert( str_contains( $emitted, '<meta name="description" content="Home description" />' ), 'wp_head should emit the persisted description.' );
$assert( str_contains( $emitted, '<meta property="og:image" content="https://example.test/wp-content/themes/site/assets/media/home.png" />' ), 'wp_head should emit the resolved og:image URL.' );
$assert( str_contains( $emitted, '<meta name="twitter:card" content="summary_large_image" />' ), 'wp_head should emit twitter card metadata.' );

$GLOBALS['ssi_head_meta'][2]['_static_site_importer_provenance'] = (string) wp_json_encode(
	array(
		'schema'        => 'static-site-importer/page-provenance/v1',
		'head_metadata' => array(
			array(
				'attr'    => 'name',
				'key'     => 'description',
				'content' => 'Hello "><script>alert(1)</script>',
				'kind'    => 'text',
			),
		),
	)
);
$GLOBALS['ssi_queried_id']                                     = 2;
$GLOBALS['static_site_importer_head_metadata_emitted']         = false;
ob_start();
Static_Site_Importer_Route_Head_Metadata::print_tags();
$escaped_output = (string) ob_get_clean();
$assert( str_contains( $escaped_output, 'content="Hello &quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"' ) && ! str_contains( $escaped_output, '<script>' ), 'Emitter must HTML-escape attacker-influenced text through esc_attr.' );

$GLOBALS['static_site_importer_head_metadata_emitted'] = false;
if ( ! defined( 'WPSEO_VERSION' ) ) {
	define( 'WPSEO_VERSION', '26.0' );
}
ob_start();
Static_Site_Importer_Route_Head_Metadata::print_tags();
$suppressed = (string) ob_get_clean();
$assert( '' === $suppressed, 'Emission must be suppressed when another SEO producer owns wp_head.' );

$rewritten = Static_Site_Importer_Route_Head_Metadata::reword_handled_diagnostics(
	array(
		array(
			'message'     => 'Named head metadata (meta description and social property tags) is not representable in block markup; the tags were omitted from generated page content.',
			'source_path' => 'index.html',
			'severity'    => 'warning',
		),
		array(
			'message'     => 'Unrelated conversion warning.',
			'source_path' => 'index.html',
		),
	),
	array(
		'status' => 'completed',
		'plan'   => array(
			'pages' => array(
				$page(
					'index.html',
					array( array( 'name' => 'description', 'content' => 'Home description' ) ),
					true
				),
			),
		),
	)
);
$assert( 'named_head_metadata_persisted' === ( $rewritten[0]['reason_code'] ?? '' ) && 'report_only' === ( $rewritten[0]['constraints'] ?? '' ) && ! str_contains( (string) ( $rewritten[0]['message'] ?? '' ), 'not representable' ), 'Persisted head metadata should retire the block-markup diagnostic.' );
$assert( 'Unrelated conversion warning.' === ( $rewritten[1]['message'] ?? '' ), 'Unrelated diagnostics must be left unchanged.' );
$compiler = Static_Site_Importer_Route_Head_Metadata::reword_handled_diagnostics(
	array(
		array(
			'code'     => 'html_head_metadata_not_carried',
			'message'  => 'Named head metadata (meta description and social property tags) is not representable in block markup; the entries are surfaced in source_reports.head_metadata for the destination document to adopt deliberately.',
			'source'   => 'Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer',
			'severity' => 'info',
		),
	),
	array(
		'status' => 'completed',
		'plan'   => array(
			'pages' => array(
				$page(
					'about.html',
					array( array( 'name' => 'description', 'content' => 'About description' ) )
				),
			),
		),
	)
);
$assert( 'named_head_metadata_persisted' === ( $compiler[0]['code'] ?? '' ) && 'report_only' === ( $compiler[0]['constraints'] ?? '' ), 'Compiler html_head_metadata_not_carried rows should be retired once tags are persisted.' );

$assert( class_exists( '\Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\AssetReferenceCanonicalizer' ), 'Relative og:image resolution requires AssetReferenceCanonicalizer.' );
$resolved_plan = array(
	'reference_tokens' => array(
		array(
			'source_path' => 'website/external/9e25/icon.png',
			'token'       => 'hero',
			'target_path' => 'assets/external/9e25/icon.png',
		),
	),
	'resolution'       => array( 'theme_uri' => 'https://example.test/wp-content/themes/site' ),
	'pages'            => array( $page( 'website/index.html', array(), true ) ),
);
$imported_url = 'https://example.test/wp-content/themes/site/assets/external/9e25/icon.png';
$relative_tags = Static_Site_Importer_Route_Head_Metadata::from_page(
	$page(
		'website/index.html',
		array(
			array( 'property' => 'og:image', 'content' => '/external/9e25/icon.png/v1/fill/w_1200,h_630/icon.png' ),
			array( 'name' => 'twitter:image', 'content' => '/external/9e25/icon.png' ),
		),
		true
	),
	$resolved_plan
);
$relative_by_key = $by_key( $relative_tags );
$assert( $imported_url === ( $relative_by_key['og:image']['content'] ?? '' ), 'Site-relative og:image must resolve through AssetReferenceCanonicalizer to the imported theme URL.' );
$assert( $imported_url === ( $relative_by_key['twitter:image']['content'] ?? '' ), 'Site-relative twitter:image must resolve through the same materialized asset.' );

$rewritten_tags = Static_Site_Importer_Route_Head_Metadata::from_page(
	$page(
		'website/index.html',
		array( array( 'property' => 'og:image', 'content' => 'external/9e25/icon.png' ) ),
		true
	),
	$resolved_plan
);
$assert( $imported_url === ( $rewritten_tags[0]['content'] ?? '' ), 'Collector-rewritten artifact-relative og:image must resolve to the imported theme URL.' );

$dropped = Static_Site_Importer_Route_Head_Metadata::from_page(
	$page(
		'website/index.html',
		array(
			array( 'name' => 'description', 'content' => 'Kept' ),
			array( 'property' => 'og:image', 'content' => '/missing/social.png' ),
		),
		true
	),
	$resolved_plan
);
$assert( 1 === count( $dropped ) && 'description' === ( $dropped[0]['key'] ?? '' ), 'Relative og:image that cannot resolve to a materialized asset is dropped rather than emitted.' );

$link_bootstrap = "<?php\n/* Static Site Importer portable internal links. */\n";
$composed       = Static_Site_Importer_Route_Head_Metadata::prepare_overlay(
	array( 'writes' => array() ),
	array( 'writes' => array( array( 'target_path' => 'functions.php', 'content' => $link_bootstrap ) ) )
);
$composed_php = (string) ( $composed['writes'][0]['content'] ?? '' );
$assert( str_contains( $composed_php, 'Static Site Importer portable internal links' ) && str_contains( $composed_php, 'Static Site Importer authored route head metadata' ), 'Head-metadata overlay must keep the internal-link bootstrap it chains from.' );

echo "route head metadata smoke passed\n";

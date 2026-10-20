<?php
/**
 * Synced pattern persistence: one wp_block per declared pattern, integer refs,
 * runtime bindings applied once inside the pattern, and stable reimport IDs.
 *
 * Run: php tests/smoke-synced-pattern-materializer.php
 *
 * @package StaticSiteImporter
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO, Generic.Files.OneObjectStructurePerFile, Squiz.Commenting, Generic.Commenting.DocComment, WordPress.Files.FileName -- Standalone smoke stubs WordPress.

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$GLOBALS['ssi_pattern_posts'] = array();
$GLOBALS['ssi_pattern_meta']  = array();

class WP_Error {
	public function __construct( private string $code, private string $message = '', private mixed $data = null ) {}
	public function get_error_code(): string {
		return $this->code; }
	public function get_error_message(): string {
		return $this->message; }
	public function get_error_data(): mixed {
		return $this->data; }
}
class WP_Post {
	public int $ID;
	public string $post_type;
	public string $post_content;
	public function __construct( int $pattern_id ) {
		$this->ID           = $pattern_id;
		$this->post_type    = (string) ( $GLOBALS['ssi_pattern_posts'][ $pattern_id ]['post_type'] ?? '' );
		$this->post_content = (string) ( $GLOBALS['ssi_pattern_posts'][ $pattern_id ]['post_content'] ?? '' );
	}
}
function is_wp_error( $value ): bool {
	return $value instanceof WP_Error; }
function post_type_exists( string $type ): bool {
	return 'wp_block' === $type; }
function esc_html( string $value ): string {
	return $value; }
function wp_slash( string $value ): string {
	return $value; }
function wp_json_encode( $value ) {
	return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Stub.
}
function wp_insert_post( array $block_post, bool $wp_error = false ) {
	unset( $wp_error );
	$pattern_id                                  = ! empty( $block_post['ID'] ) ? (int) $block_post['ID'] : 100 + count( $GLOBALS['ssi_pattern_posts'] );
	$GLOBALS['ssi_pattern_posts'][ $pattern_id ] = $block_post;
	return $pattern_id;
}
function get_post_field( string $field, int $pattern_id ): string {
	return (string) ( $GLOBALS['ssi_pattern_posts'][ $pattern_id ][ $field ] ?? '' ); }
function get_post_meta( int $pattern_id, string $key, bool $single = true ): string {
	unset( $single );
	return (string) ( $GLOBALS['ssi_pattern_meta'][ $pattern_id ][ $key ] ?? '' ); }
function update_post_meta( int $pattern_id, string $key, string $value ): void {
	$GLOBALS['ssi_pattern_meta'][ $pattern_id ][ $key ] = $value; }
function delete_post_meta( int $pattern_id, string $key ): void {
	unset( $GLOBALS['ssi_pattern_meta'][ $pattern_id ][ $key ] ); }
function metadata_exists( string $type, int $pattern_id, string $key ): bool {
	return 'post' === $type && array_key_exists( $key, $GLOBALS['ssi_pattern_meta'][ $pattern_id ] ?? array() ); }

class Static_Site_Importer_Site_Plan_Persistence {
	public static function journal_post( array &$state, array $block_post ): void {
		$pattern_id                                = (int) $block_post['planned_existing_id'];
		$state['rollback']['posts'][ $pattern_id ] = array(
			'existing' => true,
			'post'     => $GLOBALS['ssi_pattern_posts'][ $pattern_id ],
		);
	}
	public static function reconciled_post( string $identity, string|array $post_types = 'any' ) {
		foreach ( $GLOBALS['ssi_pattern_meta'] as $pattern_id => $meta ) {
			if ( ( $meta[ Static_Site_Importer_Synced_Pattern_Materializer::META_KEY ] ?? null ) === $identity && in_array( $GLOBALS['ssi_pattern_posts'][ $pattern_id ]['post_type'], (array) $post_types, true ) ) {
				return new WP_Post( $pattern_id );
			}
		}
		return null;
	}
	public static function write_post_meta( int $pattern_id, string $key, string $value ): bool {
		update_post_meta( $pattern_id, $key, $value );
		return get_post_meta( $pattern_id, $key, true ) === $value;
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-site-plan-preparation.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-synced-pattern-materializer.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI smoke output.
		exit( 1 );
	}
};

$identity    = hash( 'sha256', 'synced-pattern:contact' );
$token       = 'pattern-' . substr( $identity, 0, 16 );
$source_path = 'wordpress-site-plan/synced-patterns/' . $token;
$fallback    = '<!-- wp:html --><form class="contact"><input name="email" type="email"><button type="submit">Send</button></form><!-- /wp:html -->';
$native      = '<!-- wp:jetpack/contact-form {"to":"owner@example.com"} --><div class="wp-block-jetpack-contact-form"><!-- wp:jetpack/field-email {"label":"Email"} /--><!-- wp:jetpack/button {"element":"button","text":"Send"} /--></div><!-- /wp:jetpack/contact-form -->';
$pattern     = '<!-- wp:group {"className":"contact-card"} --><div class="wp-block-group contact-card"><!-- wp:heading --><h2 class="wp-block-heading">Contact us</h2><!-- /wp:heading -->' . $fallback . '</div><!-- /wp:group -->';
$reference   = '<!-- wp:block {"ref":"{{wordpress-site-plan:pattern:' . $token . '}}"} /-->';
$panel       = static fn( string $label ): string => '<!-- wp:tab-panel {"label":"' . $label . '"} --><div class="wp-block-tab-panel">' . $reference . '</div><!-- /wp:tab-panel -->';
$tabs_markup = '<!-- wp:tabs --><div class="wp-block-tabs">' . $panel( 'One' ) . $panel( 'Two' ) . $panel( 'Three' ) . '</div><!-- /wp:tabs -->';

$resolved = array(
	'reference_semantics' => array( 'synced_patterns' => 'explicit_refs/v1' ),
	'synced_patterns'     => array(
		array(
			'identity'               => $identity,
			'title'                  => 'Contact us',
			'canonical_block_markup' => $pattern,
			'resolved_block_markup'  => $pattern,
			'source_path'            => $source_path,
			'provenance'             => array(
				'occurrences' => array(
					array(
						'source_path' => 'index.html',
						'panel_count' => 3,
					),
				),
			),
		),
	),
	'pages'               => array(
		array(
			'source_path'            => 'index.html',
			'canonical_block_markup' => $tabs_markup,
			'resolved_block_markup'  => $tabs_markup,
		),
	),
	'template_parts'      => array(),
	'templates'           => array(),
	'writes'              => array(),
);

// The provider form binding addresses the pattern document, not the page.
$binding  = array(
	'schema'                   => 'static-site-importer/runtime-entity-binding/v1',
	'source_path'              => $source_path,
	'search_block_markup'      => $fallback,
	'replacement_block_markup' => $native,
	'occurrence'               => 1,
	'role'                     => 'form',
	'reconciliation_identity'  => hash( 'sha256', 'binding:contact-form' ),
	'provider'                 => 'jetpack',
);
$reports  = array();
$messages = array();
$assert( isset( Static_Site_Importer_Site_Plan_Preparation::runtime_binding_documents( $resolved )[ $source_path ] ), 'The synced pattern is a runtime binding document.' );
Static_Site_Importer_Site_Plan_Preparation::apply_runtime_entity_bindings( $resolved, array( $binding ), $reports, $messages );
$bound = (string) $resolved['synced_patterns'][0]['materialized_block_markup'];
$assert( 1 === substr_count( $bound, $native ) && ! str_contains( $bound, $fallback ), 'The form binding is applied once inside the pattern.' );
$assert( ! isset( $resolved['pages'][0]['materialized_block_markup'] ), 'The page holding the panels is untouched by the pattern binding.' );
$assert( null === Static_Site_Importer_Synced_Pattern_Materializer::preflight( $resolved ), 'A valid declaration with explicit refs is admitted.' );

foreach ( array( 'undeclared', 'unreferenced', 'semantics', 'non_block' ) as $case ) {
	$invalid = $resolved;
	if ( 'undeclared' === $case ) {
		$invalid['synced_patterns'] = array();
	} elseif ( 'unreferenced' === $case ) {
		$invalid['pages'][0]['resolved_block_markup'] = '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->';
	} elseif ( 'semantics' === $case ) {
		unset( $invalid['reference_semantics'] );
	} else {
		$invalid['pages'][0]['resolved_block_markup'] .= '<!-- wp:group {"ref":"{{wordpress-site-plan:pattern:' . $token . '}}"} /-->';
	}
	$assert( Static_Site_Importer_Synced_Pattern_Materializer::preflight( $invalid ) instanceof WP_Error, 'Invalid ' . $case . ' contract is rejected before writes.' );
}
$assert( array() === $GLOBALS['ssi_pattern_posts'], 'Preflight never writes.' );

$state    = array(
	'resolved'      => $resolved,
	'ordered_pages' => $resolved['pages'],
	'applied'       => array(
		'posts'                => array(),
		'runtime_declarations' => array( 'entity_bindings' => $reports ),
	),
	'rollback'      => array( 'posts' => array() ),
);
$prepared = $state;
$result   = Static_Site_Importer_Synced_Pattern_Materializer::materialize( $state );
$assert( ! is_wp_error( $result ), 'First import succeeds.' );
$blocks = array_filter( $GLOBALS['ssi_pattern_posts'], static fn( array $block_post ): bool => 'wp_block' === $block_post['post_type'] );
$assert( 1 === count( $blocks ), 'Exactly one wp_block post is created.' );
$pattern_id = (int) array_key_first( $blocks );
$block_post = $blocks[ $pattern_id ];
$assert( 'publish' === $block_post['post_status'] && 'Contact us' === $block_post['post_title'] && ! metadata_exists( 'post', $pattern_id, 'wp_pattern_sync_status' ), 'The pattern is a published, synced wp_block titled from the entity.' );
$assert( 1 === substr_count( $block_post['post_content'], $native ), 'The persisted pattern carries one native form.' );
foreach ( array( $state['resolved']['pages'][0]['resolved_block_markup'], $state['ordered_pages'][0]['resolved_block_markup'] ) as $markup ) {
	preg_match_all( '/<!-- wp:block \{"ref":(\d+)\} \/-->/', $markup, $refs );
	$assert( array( $pattern_id, $pattern_id, $pattern_id ) === array_map( 'intval', $refs[1] ) && ! str_contains( $markup, '{{wordpress-site-plan:pattern:' ), 'All three panel refs equal the wp_block ID.' );
}
$report = $state['applied']['runtime_declarations']['entity_bindings'][ $binding['reconciliation_identity'] ];
$assert( 'completed' === $report['status'] && $pattern_id === $report['post_id'], 'The pattern binding completes against the persisted pattern.' );
$assert( 'created' === $state['applied']['synced_patterns'][0]['status'] && $pattern_id === $state['applied']['synced_patterns'][0]['id'] && $token === $state['applied']['synced_patterns'][0]['token'], 'The materialized pattern is recorded for the import report.' );
$assert( false === $state['rollback']['posts'][ $pattern_id ]['existing'], 'A created pattern is journaled for rollback.' );

$state  = $prepared;
$result = Static_Site_Importer_Synced_Pattern_Materializer::materialize( $state );
$assert( ! is_wp_error( $result ) && 1 === count( $GLOBALS['ssi_pattern_posts'] ) && $pattern_id === $state['applied']['synced_patterns'][0]['id'] && 'updated' === $state['applied']['synced_patterns'][0]['status'], 'Reimport reconciles the same wp_block ID.' );
$assert( true === $state['rollback']['posts'][ $pattern_id ]['existing'], 'A reconciled pattern is journaled as existing.' );
$assert( str_contains( $state['resolved']['pages'][0]['resolved_block_markup'], '{"ref":' . $pattern_id . '}' ), 'Reimport refs keep the ID.' );

$GLOBALS['ssi_pattern_posts'][ $pattern_id ]['post_content'] = '<!-- wp:paragraph --><p>Owner edit</p><!-- /wp:paragraph -->';
$state  = $prepared;
$result = Static_Site_Importer_Synced_Pattern_Materializer::materialize( $state );
$assert( ! is_wp_error( $result ) && 'preserved_owner_edit' === $state['applied']['synced_patterns'][0]['status'] && str_contains( $GLOBALS['ssi_pattern_posts'][ $pattern_id ]['post_content'], 'Owner edit' ), 'An owner-edited pattern is not overwritten.' );
$assert( str_contains( $state['resolved']['pages'][0]['resolved_block_markup'], '{"ref":' . $pattern_id . '}' ), 'Refs still resolve to the owner-edited pattern.' );

echo "smoke-synced-pattern-materializer: ok\n";

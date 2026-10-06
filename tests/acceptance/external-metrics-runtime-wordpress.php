<?php
/** Prove public fetch, native binding render and no post rewrite. */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SSI_EXTERNAL_METRICS_DISPOSABLE' ) ) {
	throw new RuntimeException( 'External metric verification must run in its disposable WordPress site.' ); }
$assert         = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	} };
$metric_post_id = (int) get_option( 'ssi_external_metric_acceptance_post_id', 0 );
$facts          = get_option( 'ssi_external_metric_acceptance_facts', array() );
$assert( $metric_post_id > 0 && is_array( $facts ) && 5 === count( $facts ), 'A completed five-fact imported page is persisted.' );
$post_content = (string) get_post_field( 'post_content', $metric_post_id );
$before_hash  = hash( 'sha256', $post_content );
$rendered     = do_blocks( $post_content );
$assert( is_string( $rendered ) && '' !== $rendered, 'WordPress renders persisted native text blocks.' );
foreach ( $facts as $fact ) {
	$assert( ! str_contains( $rendered, (string) $fact['fallback']['text'] ), 'Fresh WordPress.org value replaces fallback for ' . $fact['id'] ); }
$receipts = get_option( 'static_site_importer_external_metric_receipts', array() );
foreach ( $facts as $fact ) {
	$row = $receipts[ $fact['id'] ] ?? array();
	$assert( 'fresh' === ( $row['status'] ?? '' ) && is_int( $row['fetched_at'] ?? null ) && ! empty( $row['value'] ), 'Timestamped fresh provider receipt exists for ' . $fact['id'] ); }
$plugin_file  = (string) get_option( 'static_site_importer_active_companion_plugin', '' );
$runtime_file = WP_PLUGIN_DIR . '/' . dirname( $plugin_file ) . '/includes/external-metric-runtime.php';
$source       = is_readable( $runtime_file ) ? (string) file_get_contents( $runtime_file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads generated local companion runtime source for the acceptance assertion.
preg_match( '/final class ([A-Za-z_][A-Za-z0-9_]*)/', $source, $class_match );
$runtime_class = $class_match[1] ?? '';
$assert( '' !== $runtime_class && class_exists( $runtime_class ), 'Generated companion owns its independent provider runtime.' );
$assert( hash( 'sha256', (string) get_post_field( 'post_content', $metric_post_id ) ) === $before_hash, 'Fetch and cache expiry refresh leave saved post content unchanged.' );

// A separate editor page proves that refresh returns a new value for the
// existing native binding controls, and that detach freezes that exact value.
$metric_map         = array_column( $facts, null, 'id' );
$make_editor_metric = static function ( array $template, string $id, string $slug, string $fallback, string $role ): array {
	$fact                      = $template;
	$fact['id']                = $id;
	$fact['provider']['slugs'] = array( $slug );
	$fact['fallback']['text']  = $fallback;
	$fact['fallback']['hash']  = hash( 'sha256', $fallback );
	$block                     = 'heading' === $role ? 'core/heading' : 'core/paragraph';
	$tag                       = 'heading' === $role ? 'h2' : 'p';
	$fact['bindings'][0]       = array(
		'schema'              => 'generic/block-binding/v1',
		'role'                => $role,
		'source_path'         => 'editor-acceptance.html',
		'search_block_markup' => '<!-- wp:' . ( 'heading' === $role ? 'heading' : 'paragraph' ) . ' --><' . $tag . '>' . esc_html( $fallback ) . '</' . $tag . '><!-- /wp:' . ( 'heading' === $role ? 'heading' : 'paragraph' ) . ' -->',
		'occurrence'          => 1,
		'leaf'                => array(
			'block'     => $block,
			'attribute' => 'content',
		),
	);
	return $fact;
};
$editor_facts       = array(
	$make_editor_metric( $metric_map['active-installs'], 'editor-detach-metric', 'ssi-editor-controlled', '111+', 'paragraph' ),
	$make_editor_metric( $metric_map['active-installs'], 'editor-sibling-metric', 'ssi-editor-sibling', '444+', 'paragraph' ),
	$make_editor_metric( $metric_map['project-version'], 'literal-fallback-paragraph', 'ssi-literal-paragraph', '<em>pending</em> "quoted" &amp; &#38;', 'paragraph' ),
	$make_editor_metric( $metric_map['project-version'], 'literal-fallback-heading', 'ssi-literal-heading', '<em>pending</em> "quoted" &amp; &#38;', 'heading' ),
);
$companion_path     = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );
$config_path        = $companion_path . '/companion.json';
$config             = json_decode( (string) file_get_contents( $config_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the generated disposable companion config for editor setup.
$assert( is_array( $config ) && is_array( $config['external_metrics'] ?? null ), 'Generated companion config is available for controlled editor acceptance.' );
$config['external_metrics'] = array_merge( $config['external_metrics'], $editor_facts );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes only the generated disposable companion fixture.
file_put_contents( $config_path, wp_json_encode( $config ) );
update_option( 'ssi_external_metric_editor_test_facts', $editor_facts, false );

$editor_block   = static function ( string $name, string $metric_id, string $tag, string $text, array $metadata = array() ): string {
	$metadata['name'] = $metadata['name'] ?? $name;
	$attributes       = array(
		'metadata' => array_merge(
			$metadata,
			array(
				'bindings' => array(
					'content' => array(
						'source' => 'ssi/external-metric',
						'args'   => array( 'metric_id' => $metric_id ),
					),
				),
			)
		),
	);
	$block_name       = 'h2' === $tag ? 'heading' : 'paragraph';
	if ( 'heading' === $block_name ) {
		$attributes['level'] = 2;
	}
	return '<!-- wp:' . $block_name . ' ' . wp_json_encode( $attributes ) . ' --><' . $tag . '>' . esc_html( $text ) . '</' . $tag . '><!-- /wp:' . $block_name . ' -->';
};
$editor_content = implode(
	"\n\n",
	array(
		$editor_block(
			'editor-detach-target',
			'editor-detach-metric',
			'p',
			'111+',
			array(
				'name'   => 'Preserve target metadata',
				'custom' => 'preserve-me',
			)
		),
		$editor_block( 'editor-sibling-binding', 'editor-sibling-metric', 'p', '444+', array( 'name' => 'Preserve sibling binding' ) ),
		$editor_block( 'literal-fallback-paragraph', 'literal-fallback-paragraph', 'p', $editor_facts[2]['fallback']['text'] ),
		$editor_block( 'literal-fallback-heading', 'literal-fallback-heading', 'h2', $editor_facts[3]['fallback']['text'] ),
	)
);
$admin_user     = get_user_by( 'login', 'admin' );
$editor_post_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'External Metric Editor Acceptance',
		'post_content' => $editor_content,
		'post_author'  => $admin_user instanceof WP_User ? (int) $admin_user->ID : 1,
	),
	true
);
$assert( ! is_wp_error( $editor_post_id ) && (int) $editor_post_id > 0, 'Controlled native binding editor page is persisted.' );

$mu_plugin_path = WPMU_PLUGIN_DIR . '/ssi-external-metric-editor-http-fixture.php';
wp_mkdir_p( WPMU_PLUGIN_DIR );
$mu_plugin_source = <<<'PHP'
<?php
/** Controlled external metric responses for disposable editor acceptance only. */
add_filter(
	'pre_http_request',
	static function ( $preempt, array $args, string $url ) {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$slug = (string) ( $query['slug'] ?? '' );
		if ( in_array( $slug, array( 'ssi-literal-paragraph', 'ssi-literal-heading' ), true ) ) {
			return array(
				'headers'  => array(),
				'body'     => '{}',
				'response' => array( 'code' => 503, 'message' => 'Injected unavailable source.' ),
				'cookies'  => array(),
			);
		}
		$values = array(
			'ssi-editor-controlled' => 222,
			'ssi-editor-sibling'    => 444,
		);
		if ( ! isset( $values[ $slug ] ) ) {
			return $preempt;
		}
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'active_installs' => $values[ $slug ], 'slug' => $slug ) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
		);
	},
	10,
	3
);
PHP;
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Installs an HTTP fixture only in the disposable site's must-use plugin directory.
file_put_contents( $mu_plugin_path, $mu_plugin_source );
echo wp_json_encode(
	array(
		'status'         => 'verified',
		'core'           => get_bloginfo( 'version' ),
		'post_id'        => $metric_post_id,
		'editor_post_id' => (int) $editor_post_id,
		'companion'      => $plugin_file,
		'content_sha256' => $before_hash,
		'receipts'       => $receipts,
	)
) . "\n";

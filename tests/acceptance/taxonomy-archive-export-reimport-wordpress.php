<?php
/** Import a complete served-theme export into a second clean WordPress install. */
if ( ! defined( 'SSI_TAXONOMY_DISPOSABLE_TEST' ) || true !== SSI_TAXONOMY_DISPOSABLE_TEST ) {
	throw new RuntimeException( 'Run taxonomy export reimport acceptance only in a declared disposable runtime.' );
}
wp_set_current_user( 1 );
$active_plugins = get_option( 'active_plugins', array() );
$ssi_basename   = 'static-site-importer/static-site-importer.php';
if ( is_array( $active_plugins ) && in_array( $ssi_basename, $active_plugins, true ) && function_exists( 'deactivate_plugins' ) ) {
	deactivate_plugins( $ssi_basename, true );
}
$bundle = json_decode( (string) file_get_contents( '/wordpress/wp-content/uploads/ssi-taxonomy-evidence/exported-website-artifact.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads complete host-mounted exported artifact in the second disposable site.
if ( ! is_array( $bundle ) || 'blocks-engine/php-transformer/site-artifact/v1' !== ( $bundle['schema'] ?? null ) || 'website' !== ( $bundle['artifact_type'] ?? null ) ) {
	throw new RuntimeException( 'The complete first-site export artifact could not be read from the evidence mount.' );
}
$autoload             = require_once '/wordpress/wp-content/plugins/static-site-importer/vendor/autoload.php';
$release_package_mode = defined( 'SSI_TAXONOMY_RELEASE_PACKAGE' ) && true === SSI_TAXONOMY_RELEASE_PACKAGE;
if ( ! $release_package_mode ) {
	$engine = '/wordpress/wp-content/plugins/blocks-engine-candidate';
	$autoload->setPsr4( 'Automattic\\BlocksEngine\\PhpTransformer\\', $engine . '/src/', true );
	if ( ! function_exists( 'blocks_engine_php_transformer_convert_format' ) ) {
		require_once $engine . '/php-transformer.php';
	}
}
require_once '/wordpress/wp-content/plugins/static-site-importer/static-site-importer.php';
require_once '/wordpress/wp-content/plugins/static-site-importer/includes/class-static-site-importer-compilation-preparation.php';
require_once '/wordpress/wp-content/plugins/static-site-importer/includes/class-static-site-importer-wordpress-site-plan-materializer.php';
if ( $release_package_mode ) {
	require_once '/wordpress/wp-content/plugins/static-site-importer/tests/acceptance/taxonomy-release-package-proof.php';
	$release_package_proof = ssi_taxonomy_release_package_proof();
	echo 'SSI-TAXONOMY-RELEASE-PACKAGE:' . wp_json_encode( $release_package_proof ) . "\n";
}
$assert                = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Disposable runtime CLI acceptance evidence.
	}
};
$acceptance_issues     = array();
$exported_paths        = array_fill_keys( array_map( static fn( array $file ): string => (string) ( $file['path'] ?? '' ), $bundle['files'] ?? array() ), true );
$resolved_archive_refs = 0;
$broken_archive_refs   = array();
foreach ( $bundle['files'] ?? array() as $export_file ) {
	if ( 'taxonomy-archive' !== ( $export_file['role'] ?? '' ) || ! is_string( $export_file['content'] ?? null ) ) {
		continue;
	}
	$document = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	try {
		if ( $document->loadHTML( (string) $export_file['content'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING ) ) {
			foreach (
				array(
					'a'      => 'href',
					'link'   => 'href',
					'img'    => 'src',
					'script' => 'src',
				) as $element_tag => $attribute
			) {
				foreach ( $document->getElementsByTagName( $element_tag ) as $element ) {
					$href = trim( (string) $element->getAttribute( $attribute ) );
					if ( '' === $href || str_starts_with( $href, '#' ) || preg_match( '~^(?:[a-z][a-z0-9+.-]*:|//)~i', $href ) ) {
						continue;
					}
					$resolved_path = (string) ( wp_parse_url( $href, PHP_URL_PATH ) ?? '' );
					$parts         = str_starts_with( $resolved_path, '/' ) ? array( 'website' ) : explode( '/', dirname( (string) $export_file['path'] ) );
					foreach ( explode( '/', ltrim( $resolved_path, '/' ) ) as $part ) {
						if ( '' === $part || '.' === $part ) {
							continue;
						}
						if ( '..' === $part ) {
							if ( count( $parts ) > 1 ) {
								array_pop( $parts );
							}
							continue;
						}
						$parts[] = $part;
					}
					$target = implode( '/', $parts );
					if ( ! preg_match( '/\.[a-z0-9]{1,8}$/i', $target ) ) {
						$target = rtrim( $target, '/' ) . '/index.html';
					}
					++$resolved_archive_refs;
					if ( ! isset( $exported_paths[ $target ] ) ) {
						$broken_archive_refs[] = array(
							'archive' => $export_file['path'],
							'href'    => $href,
							'target'  => $target,
						);
					}
				}
			}
		}
	} finally {
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
	}
}
$assert( $resolved_archive_refs > 0 && array() === $broken_archive_refs, 'The complete exported taxonomy archive navigation/assets do not resolve within the served artifact: ' . wp_json_encode( array_slice( $broken_archive_refs, 0, 20 ) ) );
$served_files = array();
foreach ( $bundle['files'] ?? array() as $export_file ) {
	$artifact_path = (string) ( $export_file['path'] ?? '' );
	if ( ! str_starts_with( $artifact_path, 'website/' ) ) {
		continue;
	}
	$relative_path = substr( $artifact_path, strlen( 'website/' ) );
	if ( '' === $relative_path || str_contains( $relative_path, '..' ) || str_starts_with( $relative_path, '/' ) ) {
		continue;
	}
	$destination = ABSPATH . $relative_path;
	wp_mkdir_p( dirname( $destination ) );
	$content = (string) ( $export_file['content'] ?? '' );
	if ( 'base64' === ( $export_file['encoding'] ?? 'utf8' ) ) {
		$decoded = base64_decode( $content, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes binary files from the declared site artifact transport format.
		$content = false === $decoded ? '' : $decoded;
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes the generated complete static artifact only inside this disposable second-site document root for HTTP proof.
	if ( false === file_put_contents( $destination, $content ) ) {
		throw new RuntimeException( 'Could not stage exported file ' . $artifact_path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion output is evidence, not rendered HTML.
	}
	$served_files[] = $artifact_path;
}
$assert( in_array( 'website/writing/category/personal/index.html', $served_files, true ) && in_array( 'website/writing/category/personal/page/2/index.html', $served_files, true ), 'The exported base and page-2 archive documents must be staged for HTTP serving.' );
$compiled = Static_Site_Importer_Compilation_Preparation::compile_website_artifact(
	$bundle,
	array(
		'slug'     => 'taxonomy-export-reimport',
		'activate' => true,
	)
);
$assert( ! is_wp_error( $compiled ), 'Second-site compiler rejected the complete served export: ' . ( is_wp_error( $compiled ) ? $compiled->get_error_message() : '' ) );
$plan = $compiled['plan'];
$assert( 0 === ( $plan['quality']['metrics']['fallback_count'] ?? -1 ), 'Second-site export reimport has nonzero fallback blocks.' );
$entities              = array_values(
	array_filter(
		$plan['taxonomy_entities'] ?? array(),
		static fn( array $entity ): bool => 'personal' === ( $entity['slug'] ?? '' ) && 'category' === ( $entity['taxonomy'] ?? '' )
	)
);
$plan_taxonomy_summary = array(
	'entity_count' => count( $entities ),
	'entities'     => $plan['taxonomy_entities'] ?? array(),
);
$assert(
	1 === count( $entities ) && count( $entities[0]['membership_source_paths'] ?? array() ) >= 2,
	'Second-site producer plan failed to recover a reciprocal Personal collection: ' . wp_json_encode( $plan_taxonomy_summary )
);
$expected_members = 12;
$proven_members   = count( $entities[0]['membership_source_paths'] ?? array() );
if ( $expected_members !== $proven_members ) {
	$acceptance_issues[] = array(
		'case'                 => 'export-reimport-memberships',
		'expected'             => $expected_members,
		'producer_proven'      => $proven_members,
		'missing_source_paths' => array_values(
			array_diff(
				array( 'website/story-2/index.html', 'website/story-3/index.html' ),
				$entities[0]['membership_source_paths']
			)
		),
	);
}
$receipt          = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, $compiled['args'] );
$import_completed = 'completed' === ( $receipt['status'] ?? '' );
if ( ! $import_completed ) {
	$acceptance_issues[]     = array(
		'case'              => 'second-site-native-import',
		'status'            => $receipt['status'] ?? null,
		'errors'            => $receipt['errors'] ?? array(),
		'taxonomy_entities' => array_map(
			static fn( array $entity ): array => array(
				'slug'                    => $entity['slug'] ?? '',
				'membership_count'        => count( $entity['membership_source_paths'] ?? array() ),
				'membership_source_paths' => $entity['membership_source_paths'] ?? array(),
			),
			$plan['taxonomy_entities'] ?? array()
		),
	);
	$ssi_active_after_import = in_array( $ssi_basename, (array) get_option( 'active_plugins', array() ), true );
	echo wp_json_encode(
		array(
			'schema'                  => 'ssi-taxonomy/second-site-export-reimport/v1',
			'import_completed'        => false,
			'release_package'         => $release_package_proof ?? null,
			'ssi_active_after_import' => $ssi_active_after_import,
			'served_files'            => count( $served_files ),
			'archive_refs_resolved'   => $resolved_archive_refs,
			'broken_archive_refs'     => $broken_archive_refs,
			'acceptance_issues'       => $acceptance_issues,
		)
	) . "\n";
	echo "Exported archive files staged and link-resolved; producer pagination reimport contract remains incomplete.\n";
	return;
}
$personal_term = get_term_by( 'slug', 'personal', 'category' );
$assert( $personal_term instanceof WP_Term, 'Second-site native Personal term did not persist.' );
$member_ids = array_map(
	'intval',
	wp_list_pluck(
		get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'category_name'  => 'personal',
			)
		),
		'ID'
	)
);
if ( count( $member_ids ) !== $expected_members ) {
	$acceptance_issues[] = array(
		'case'     => 'second-site-native-memberships',
		'expected' => $expected_members,
		'actual'   => count( $member_ids ),
	);
}
$archive_template = get_block_template( get_stylesheet() . '//category-personal' );
$assert(
	$archive_template instanceof WP_Block_Template && str_contains( $archive_template->content, 'query-pagination' ) && str_contains( $archive_template->content, 'inherit' ),
	'Second-site inherited contextual category template or pagination did not survive reimport.'
);
$ids_by_source = $receipt['completed']['pages'] ?? array();
$native_routes = array();
foreach ( $entities[0]['membership_source_paths'] as $source_path ) {
	$reimported_id = (int) ( $ids_by_source[ $source_path ] ?? 0 );
	if ( $reimported_id > 0 && 'post' === get_post_type( $reimported_id ) ) {
		$native_routes[] = (string) get_permalink( $reimported_id );
	}
}
$assert( count( $native_routes ) === $proven_members, 'Every producer-proven reimport member must resolve to a persisted native post route.' );
$ssi_active_after_import = in_array( $ssi_basename, (array) get_option( 'active_plugins', array() ), true );
$assert( ! $ssi_active_after_import, 'SSI must be absent during second-site archive HTTP route proof.' );
$result = array(
	'schema'                  => 'ssi-taxonomy/second-site-export-reimport/v1',
	'wordpress'               => get_bloginfo( 'version' ),
	'release_package'         => $release_package_proof ?? null,
	'import_completed'        => $import_completed,
	'plan_fallback_count'     => $plan['quality']['metrics']['fallback_count'] ?? null,
	'archive_route'           => (string) get_term_link( $personal_term ),
	'membership_count'        => count( $member_ids ),
	'proven_members'          => count( $native_routes ),
	'expected_members'        => $expected_members,
	'archive_refs_resolved'   => $resolved_archive_refs,
	'broken_archive_refs'     => $broken_archive_refs,
	'acceptance'              => array() === $acceptance_issues,
	'acceptance_issues'       => $acceptance_issues,
	'pagination_template'     => str_contains( $archive_template->content, 'query-pagination' ),
	'editor_marker'           => str_contains( $archive_template->content, 'SSI Gutenberg category template reload persisted' ),
	'ssi_active_after_import' => $ssi_active_after_import,
	'native_post_routes'      => $native_routes,
);
echo wp_json_encode( $result ) . "\n";
echo ( array() === $acceptance_issues ? 'Taxonomy full export and second-site native reimport acceptance passed.' : 'Taxonomy full export and second-site import completed with acceptance gaps: ' . wp_json_encode( $acceptance_issues ) ) . "\n";

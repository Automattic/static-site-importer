<?php
/**
 * Real TEC acceptance inside an explicitly disposable WordPress runtime.
 *
 * @package StaticSiteImporter
 */

if ( '1' !== getenv( 'SSI_TEC_DISPOSABLE' ) || ! function_exists( 'tribe_events' ) ) {
	throw new RuntimeException( 'An explicitly disposable WordPress site with real TEC is required.' );
}

function ssi_tec_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI-only machine-readable assertion evidence, never a rendered page.
	}
}

function ssi_tec_import( array $input ): array {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$request = wp_tempnam( 'ssi-tec-request.json' );
	file_put_contents( $request, wp_json_encode( $input ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes the operator-owned temporary CLI request fixture.
	try {
		$output = WP_CLI::runcommand(
			'static-site-importer import --request=' . escapeshellarg( $request ) . ' --user=admin --keep-source',
			array(
				'return'     => true,
				'launch'     => true,
				'exit_error' => false,
			)
		);
		foreach ( array_reverse( explode( "\n", trim( $output ) ) ) as $line ) {
			$receipt = json_decode( $line, true );
			if ( is_array( $receipt ) && 'static-site-importer/import-cli-receipt/v1' === ( $receipt['schema'] ?? '' ) ) {
				wp_cache_flush();
				// The parent verifier must observe settings written by fresh CLI children.
				tribe_set_var( Tribe__Settings_Manager::OPTION_CACHE_VAR_NAME, get_option( Tribe__Main::OPTIONNAME, array() ) );
				return $receipt['response'];
			}
		}
		throw new RuntimeException( 'The canonical CLI returned no terminal receipt: ' . substr( $output, 0, 2000 ) );
	} finally {
		wp_delete_file( $request );
	}
}

/** Exercise either explicit producer declarations or ordinary source JSON-LD. */
function ssi_tec_source_events( array $input, array $rows ): array {
	if ( '1' !== getenv( 'SSI_TEC_AUTOMATIC_EVENTS' ) ) {
		$input['source']['metadata'] = array(
			'runtime_declarations' => array(
				array(
					'kind'        => 'entity_collection',
					'type'        => 'events',
					'source_path' => $rows[0]['source_path'],
					'payload'     => array(
						'schema'   => 'generic/events/v1',
						'entities' => $rows,
					),
				),
			),
		);
		return $input;
	}
	foreach ( $rows as $row ) {
		$event = array(
			'@context'  => 'https://schema.org',
			'@type'     => 'Event',
			'name'      => $row['name'],
			'startDate' => $row['start_date'],
			'endDate'   => $row['end_date'],
		);
		if ( isset( $row['image'] ) ) {
			$event['image'] = $row['image'];
		}
		if ( isset( $row['venue'] ) ) {
			$event['location'] = $row['venue'] + array( '@type' => 'Place' );
		}
		foreach ( $input['source']['files'] as &$file ) {
			if ( $file['path'] === $row['source_path'] ) {
				$file['content'] = str_replace( '</head>', '<script type="application/ld+json">' . wp_json_encode( $event ) . '</script></head>', $file['content'] );
			}
		}
		unset( $file );
	}
	return $input;
}

$png       = 'iVBORw0KGgoAAAANSUhEUgAAAIAAAABgCAYAAADVenpJAAAA3UlEQVR4Ae3BAQGAMADDsH5qkHiJuAIhbXKe+35Ea0RtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRG1EbURtRO0HIu0DJ4v/T0EAAAAASUVORK5CYII=';
$rows      = array(
	array(
		'id'                   => 'future',
		'whole_page_candidate' => 'blocks-engine/whole-page-candidate/v1',
		'source_path'          => 'website/notes/future.html',
		'source_route'         => '/notes/future',
		'name'                 => 'Future gathering',
		'start_date'           => '2027-04-03T18:00:00-04:00',
		'end_date'             => '2027-04-03T20:00:00-04:00',
		'venue'                => array(
			'name'    => 'Community Hall',
			'address' => array(
				'streetAddress'   => '1 Main St',
				'addressLocality' => 'Austin',
			),
		),
		'image'                => '/media/future.png',
	),
	array(
		'id'                   => 'past',
		'whole_page_candidate' => 'blocks-engine/whole-page-candidate/v1',
		'source_path'          => 'website/notes/past.html',
		'source_route'         => '/notes/past',
		'name'                 => 'Past gathering',
		'start_date'           => '2020-01-03T18:00:00Z',
		'end_date'             => '2020-01-03T20:00:00Z',
	),
);
$files     = array(
	array(
		'path'    => 'website/index.html',
		'content' => '<!doctype html><html><head><title>Community</title></head><body><main><h1>Community calendar</h1><a href="/notes/future">Future gathering</a><a href="/notes/past">Past gathering</a></main></body></html>',
	),
	array(
		'path'    => 'website/notes/future.html',
		'content' => '<!doctype html><html><head><title>Future gathering</title></head><body><main><h1>Future gathering</h1><p>Full source copy with an apostrophe: everyone\'s welcome.</p><figure><img src="/media/future.png" alt="Future gathering" width="128" height="96"></figure></main></body></html>',
	),
	array(
		'path'    => 'website/notes/past.html',
		'content' => '<!doctype html><html><head><title>Past gathering</title></head><body><main><h1>Past gathering</h1><p>Full source copy for the past gathering.</p></main></body></html>',
	),
	array(
		'path'           => 'website/media/future.png',
		'content_base64' => $png,
	),
);
$input     = array(
	'operation' => 'apply',
	'source'    => array(
		'type'       => 'files',
		'entrypoint' => 'website/index.html',
		'files'      => $files,
	),
	'slug'      => 'tec-acceptance',
	'activate'  => true,
	'overwrite' => true,
);
$unrelated = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_title'   => 'Owner content',
		'post_name'    => 'owner-content',
		'post_content' => 'Preserve this unrelated page.',
		'post_status'  => 'publish',
	)
);
$baseline  = ssi_tec_import( $input );
ssi_tec_assert( ! empty( $baseline['success'] ), 'The ordinary source pages import before provider adoption: ' . wp_json_encode( $baseline['error'] ?? array() ) );

$base_input = $input;
$input      = ssi_tec_source_events( $base_input, $rows );
$first      = ssi_tec_import( $input );
ssi_tec_assert( ! empty( $first['success'] ), 'Real TEC import completes: ' . wp_json_encode( $first['error'] ?? array() ) );
$editor_options = get_option( 'tribe_events_calendar_options', array() );
ssi_tec_assert( true === ( $editor_options['toggle_blocks_editor'] ?? false ), 'A fresh native provider initializes the Gutenberg editor through its own settings API.' );
$event_query = array(
	'post_type'                    => 'tribe_events',
	'post_status'                  => 'publish',
	'numberposts'                  => -1,
	'tribe_suppress_query_filters' => true,
);
$events      = get_posts( $event_query );
if ( 2 !== count( $events ) ) {
	$receipt_path = $first['result']['response_artifacts']['artifacts']['materialization_receipt']['path'] ?? '';
	$receipt      = '' !== $receipt_path ? json_decode( (string) file_get_contents( $receipt_path ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the exact disposable runtime receipt for diagnostics.
	echo wp_json_encode(
		array(
			'events'           => array_map(
				static fn( $post ) => array(
					'id'     => $post->ID,
					'title'  => $post->post_title,
					'status' => $post->post_status,
				),
				get_posts( $event_query )
			),
			'provider_reports' => $receipt['completed']['runtime_declarations']['entities'] ?? array(),
		)
	) . PHP_EOL;
}
ssi_tec_assert( 2 === count( $events ), 'Exactly two published native events exist; observed ' . count( $events ) );
$event_ids = array();
foreach ( $events as $event ) {
	$event_ids[ $event->post_title ] = (int) $event->ID;
	ssi_tec_assert( str_contains( $event->post_content, 'Full source copy' ), 'Native events preserve compiled source content, not only a JSON-LD summary.' );
}
$future = $event_ids['Future gathering'];
$past   = $event_ids['Past gathering'];
ssi_tec_assert( '2027-04-03 22:00:00' === get_post_meta( $future, '_EventStartDateUTC', true ), 'The source offset preserves the future event UTC instant.' );
ssi_tec_assert( '2020-01-03 18:00:00' === get_post_meta( $past, '_EventStartDateUTC', true ), 'The past event UTC instant is retained.' );
ssi_tec_assert( (int) get_post_meta( $future, '_EventVenueID', true ) > 0, 'The native event has a native venue.' );
$attachment = (int) get_post_thumbnail_id( $future );
ssi_tec_assert( $attachment > 0 && hash_file( 'sha256', get_attached_file( $attachment ) ) === hash( 'sha256', base64_decode( $png, true ) ), 'The native featured image retains exact source bytes.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the transparent PNG fixture for a byte-level proof.
foreach ( array(
	'notes/future' => $future,
	'notes/past'   => $past,
) as $route => $route_post_id ) {
	$target = Static_Site_Importer_Source_Route_Redirect::target_url( '/' . $route );
	ssi_tec_assert( get_permalink( $route_post_id ) === $target, 'The original source route reaches its native event.' );
}
$published_pages = get_posts(
	array(
		'post_type'   => 'page',
		'post_status' => 'publish',
		'numberposts' => -1,
	)
);
foreach ( $published_pages as $published_page ) {
	ssi_tec_assert( ! in_array( $published_page->post_title, array( 'Future gathering', 'Past gathering' ), true ), 'Source event pages are not duplicate published content.' );
}
$repeat = ssi_tec_import( $input );
ssi_tec_assert( ! empty( $repeat['success'] ), 'Reimport completes: ' . wp_json_encode( $repeat['error'] ?? array() ) );
ssi_tec_assert( 2 === count( get_posts( $event_query ) ), 'Reimport creates no duplicate native events, including past events.' );
ssi_tec_assert( (int) get_post_thumbnail_id( $future ) === $attachment, 'Reimport reuses the exact native image.' );
ssi_tec_assert( 'Preserve this unrelated page.' === get_post_field( 'post_content', $unrelated ), 'Unrelated owner content is preserved.' );
$before                        = array(
	'content'   => get_post_field( 'post_content', $future ),
	'title'     => get_post_field( 'post_title', $future ),
	'start'     => get_post_meta( $future, '_EventStartDateUTC', true ),
	'venue'     => (int) get_post_meta( $future, '_EventVenueID', true ),
	'thumbnail' => (int) get_post_thumbnail_id( $future ),
);
$changed_rows                  = $rows;
$changed_rows[0]['name']       = 'Must roll back';
$changed_rows[0]['start_date'] = '2027-04-03T19:00:00-04:00';
$changed                       = ssi_tec_source_events( $base_input, $changed_rows );
$failure_args                  = array(
	'slug'                           => 'tec-acceptance',
	'activate'                       => true,
	'overwrite'                      => true,
	'seed_entities'                  => true,
	'materialize_dependencies'       => true,
	'inject_materialization_failure' => 'after_activation',
);
$runtime                       = static_site_importer_source_runtime( $changed['source'] );
$planning                      = Static_Site_Importer_Canonical_Import_Service::plan_artifact( $runtime['artifact'], $failure_args, 'files', array() );
ssi_tec_assert( ! empty( $planning['success'] ), 'The real compiler produces a canonical rollback candidate.' );
$prepared = Static_Site_Importer_WordPress_Site_Plan_Materializer::prepare_for_materialization( $planning['plan'], $failure_args );
ssi_tec_assert( 'prepared' === $prepared['status'], 'The canonical rollback candidate passes destination preparation.' );
$lifecycle = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle( $planning['plan'], $failure_args );
$failure   = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize_prepared_lifecycle( $prepared, $lifecycle, null, array(), array( 'strategy' => 'block' ) );
ssi_tec_assert( is_wp_error( $failure ), 'A late injected failure rejects the migration through the trusted materialization seam.' );
ssi_tec_assert( get_post_field( 'post_content', $future ) === $before['content'] && get_post_field( 'post_title', $future ) === $before['title'] && get_post_meta( $future, '_EventStartDateUTC', true ) === $before['start'] && (int) get_post_thumbnail_id( $future ) === $before['thumbnail'], 'Provider compensation restores content, dates, title and image after a late failure.' );
ssi_tec_assert( get_permalink( $future ) === Static_Site_Importer_Source_Route_Redirect::target_url( '/notes/future' ), 'Rollback restores source route ownership.' );
$notes          = get_page_by_path( 'notes' );
$retired_source = get_page_by_path( 'notes/future' );
if ( $retired_source && 'draft' === $retired_source->post_status ) {
	wp_update_post(
		array(
			'ID'        => $retired_source->ID,
			'post_name' => 'retired-future-source',
		)
	);
}
$collision = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_title'   => 'Owner route',
		'post_name'    => 'future',
		'post_parent'  => $notes->ID,
		'post_content' => 'Keep this owner route.',
		'post_status'  => 'publish',
	)
);
ssi_tec_assert( 'notes/future' === get_page_uri( $collision ), 'The collision fixture actually occupies the original source URL.' );
$conflicted = ssi_tec_import( $input );
ssi_tec_assert( empty( $conflicted['success'] ), 'A newly occupied source route rejects provider ownership.' );
ssi_tec_assert( 'Keep this owner route.' === get_post_field( 'post_content', $collision ) && 'publish' === get_post_status( $collision ), 'An unrelated route is never overwritten or demoted.' );
wp_delete_post( $collision, true );
wp_cache_flush();
global $wpdb;
$event_rows  = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tec_events", ARRAY_A );
$occurrences = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tec_occurrences", ARRAY_A );
ssi_tec_assert( count( $event_rows ) >= 2 && count( $occurrences ) >= 2, 'Real TEC event and occurrence tables are populated.' );
echo wp_json_encode(
	array(
		'status'              => 'passed',
		'wordpress'           => get_bloginfo( 'version' ),
		'event_ids'           => $event_ids,
		'attachment_id'       => $attachment,
		'event_rows'          => $event_rows,
		'occurrences'         => $occurrences,
		'reimport'            => 'idempotent',
		'rollback'            => 'restored',
		'conflict_protection' => 'owner_preserved',
		'routes'              => array( '/notes/future', '/notes/past' ),
	)
) . PHP_EOL;

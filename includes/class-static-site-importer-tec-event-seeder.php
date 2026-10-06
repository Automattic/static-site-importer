<?php
/** The Events Calendar provider for producer-declared generic/events/v1 facts. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Static_Site_Importer_TEC_Event_Seeder {
	private const SOURCE_META = '_static_site_importer_event_source';
	private const VENUE_META  = '_static_site_importer_event_venue_source';

	public static function adapter(): array {
		return array(
			'id'                   => 'tec_event',
			'entity_type'          => 'event',
			'entity_collection'    => 'events',
			'capability'           => 'events',
			'provider'             => 'the-events-calendar',
			'validator'            => array( self::class, 'validate' ),
			'materializer'         => array( self::class, 'seed' ),
			'rollback_callback'    => array( self::class, 'rollback' ),
			'rollback_contract_id' => 'static-site-importer/tec-event-rollback/v1',
			'report_callback'      => array( self::class, 'report' ),
			'dependencies'         => array(
				array(
					'type'                  => 'wp_org_plugin',
					'slug'                  => 'the-events-calendar',
					'plugin_file'           => 'the-events-calendar/the-events-calendar.php',
					'availability_callback' => array( self::class, 'available' ),
					'missing_apis'          => array( 'tribe_events', 'tribe_events_post_type' ),
				),
			),
		);
	}

	public static function available(): bool {
		return function_exists( 'tribe_events' ) && function_exists( 'tribe_venues' ) && function_exists( 'post_type_exists' ) && post_type_exists( 'tribe_events' ) && post_type_exists( 'tribe_venue' );
	}

	/** Validate only the bounded producer schema, not inferred HTML or event-like text. */
	public static function validate( mixed $manifest ): array {
		$accepted = array();
		$errors   = array();
		$seen     = array();
		$routes   = array();
		$rows     = $manifest['events'] ?? null;
		if ( ! is_array( $rows ) || ! array_is_list( $rows ) ) {
			return array(
				'events' => array(),
				'errors' => array(
					array(
						'path'    => '$.events',
						'message' => 'events must be a list.',
					),
				),
			);
		}
		foreach ( $rows as $index => $row ) {
			$path = '$.events[' . $index . ']';
			if ( ! is_array( $row ) || array_is_list( $row ) ) {
				$errors[] = array(
					'path'    => $path,
					'message' => 'Event must be an object.',
				);
				continue;
			}
			$valid = true;
			foreach ( array(
				'source_path'  => 2048,
				'source_route' => 2048,
				'name'         => 512,
				'start_date'   => 40,
				'end_date'     => 40,
			) as $field => $max ) {
				if ( ! is_string( $row[ $field ] ?? null ) || '' === trim( $row[ $field ] ) || strlen( $row[ $field ] ) > $max ) {
					$errors[] = array(
						'path'    => $path . '.' . $field,
						'message' => $field . ' must be a bounded non-empty string.',
					);
					$valid    = false;
				}
			}
			if ( ! $valid ) {
				continue;
			}
			$source = $row['source_path'];
			if ( isset( $seen[ $source ] ) ) {
				$errors[] = array(
					'path'    => $path . '.source_path',
					'message' => 'Source identity must be unique.',
				);
				continue;
			}
			$seen[ $source ] = true;
			if ( isset( $routes[ $row['source_route'] ] ) ) {
				$errors[] = array(
					'path'    => $path . '.source_route',
					'message' => 'Source route must be unique.',
				);
				continue;
			}
			$routes[ $row['source_route'] ] = true;
			if ( str_contains( $source, '..' ) || ! preg_match( '~^[a-zA-Z0-9_./-]+$~', $source ) || str_starts_with( $source, '/' ) || ! preg_match( '~^[a-zA-Z0-9_./-]+$~', $row['source_route'] ) || str_contains( $row['source_route'], '..' ) ) {
				$errors[] = array(
					'path'    => $path . '.source_path',
					'message' => 'Source path and route must be relative safe paths.',
				);
				continue;
			}
			$dates = array();
			foreach ( array( 'start_date', 'end_date' ) as $field ) {
				$value = $row[ $field ];
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})$/', $value ) ) {
					$errors[] = array(
						'path'    => $path . '.' . $field,
						'message' => 'Date requires an explicit UTC offset.',
					);
					continue;
				}
				try {
					$date = new DateTimeImmutable( $value );
					if ( $date->format( 'Y-m-d\TH:i' ) !== substr( $value, 0, 16 ) ) {
						throw new Exception( 'Invalid date.' );
					}
					$dates[ $field ] = $date;
				} catch ( Exception $error ) {
					$errors[] = array(
						'path'    => $path . '.' . $field,
						'message' => 'Invalid date.',
					);
				}
			}
			if ( 2 !== count( $dates ) || $dates['end_date'] < $dates['start_date'] ) {
				$errors[] = array(
					'path'    => $path . '.end_date',
					'message' => 'End must follow start.',
				);
				continue;
			}
			if ( isset( $row['description'] ) && ( ! is_string( $row['description'] ) || strlen( $row['description'] ) > 8192 ) ) {
				$errors[] = array(
					'path'    => $path . '.description',
					'message' => 'Invalid description.',
				);
				continue;
			}
			if ( isset( $row['venue'] ) && ( ! is_array( $row['venue'] ) || ! is_string( $row['venue']['name'] ?? null ) || '' === trim( $row['venue']['name'] ) || strlen( $row['venue']['name'] ) > 512 ) ) {
				$errors[] = array(
					'path'    => $path . '.venue',
					'message' => 'Invalid venue.',
				);
				continue;
			}
			if ( isset( $row['venue']['address'] ) ) {
				$address = $row['venue']['address'];
				if ( ! is_string( $address ) && ! is_array( $address ) ) {
					$errors[] = array(
						'path'    => $path . '.venue.address',
						'message' => 'Invalid address.',
					);
					continue;
				}
				foreach ( is_array( $address ) ? $address : array( $address ) as $part ) {
					if ( ! is_string( $part ) || strlen( $part ) > 1024 ) {
						$errors[] = array(
							'path'    => $path . '.venue.address',
							'message' => 'Invalid address part.',
						);
						continue 2;
					}
				}
			}
			if ( isset( $row['image'] ) && ( ! is_string( $row['image'] ) || strlen( $row['image'] ) > 2048 || ! ( ( filter_var( $row['image'], FILTER_VALIDATE_URL ) && preg_match( '~^https?://~i', $row['image'] ) ) || ( preg_match( '~^/media/[a-zA-Z0-9_./-]+$~', $row['image'] ) && ! str_contains( $row['image'], '..' ) ) ) ) ) {
				$errors[] = array(
					'path'    => $path . '.image',
					'message' => 'Invalid image URL.',
				);
				continue;
			}
			$accepted[] = $row;
		}
		return array(
			'events' => $accepted,
			'errors' => $errors,
		);
	}

	public static function report(): array {
		return array(
			'status'             => 'skipped',
			'provider'           => 'the-events-calendar',
			'provider_available' => self::available(),
			'route_status'       => 'requires_verified_whole_page_handoff',
			'counts'             => array(
				'created' => 0,
				'updated' => 0,
				'skipped' => 0,
				'error'   => 0,
			),
			'events'             => array(),
		);
	}

	/** Use TEC's repository rather than inserting tribe_events posts without occurrences. */
	public static function seed( array $manifest, array $args = array() ): array {
		$report = self::report();
		$rows   = $manifest['events'] ?? array();
		if ( ! $report['provider_available'] ) {
			$report['status']          = 'failed';
			$report['reason']          = 'tec_unavailable';
			$report['counts']['error'] = count( $rows );
			return $report;
		}
		$report['status'] = 'completed';
		foreach ( $rows as $row ) {
			$source      = $row['source_path'];
			$declaration = (string) ( $args['declaration_reconciliation_identity'] ?? '' );
			$entity_id   = (string) ( $row['id'] ?? '' );
			$document    = $args['whole_page_documents'][ $declaration ][ $entity_id ] ?? null;
			if ( ! is_array( $document ) || ! is_string( $document['post_content'] ?? null ) || $source !== $document['source_path'] || $row['source_route'] !== $document['source_route'] ) {
				$report['events'][] = array(
					'source_path' => $source,
					'status'      => 'skipped',
					'reason'      => 'whole_page_source_document_unavailable',
				);
				++$report['counts']['skipped'];
				continue;
			}
			$image = isset( $row['image'] ) ? ( $args['resolved_entity_images'][ $row['image'] ] ?? null ) : null;
			if ( isset( $row['image'] ) && ! is_array( $image ) ) {
				$report['events'][] = array(
					'source_path' => $source,
					'status'      => 'skipped',
					'reason'      => 'source_image_unresolved',
				);
				++$report['counts']['skipped'];
				continue;
			}
			$source_identity = hash( 'sha256', (string) ( $args['slug'] ?? '' ) . "\n" . $source );
			$found           = get_posts(
				array(
					'post_type'                    => 'tribe_events',
					'tribe_suppress_query_filters' => true,
					'post_status'                  => array( 'publish', 'draft', 'private' ),
					'meta_key'                     => self::SOURCE_META,
					'meta_value'                   => $source_identity,
					'fields'                       => 'ids',
					'posts_per_page'               => 2,
				)
			);
			if ( count( $found ) > 1 ) {
				$report['status'] = 'failed';
				$report['reason'] = 'ambiguous_source_identity';
				++$report['counts']['error'];
				break;
			}
			$id    = empty( $found ) ? 0 : (int) $found[0];
			$start = new DateTimeImmutable( $row['start_date'] );
			$end   = new DateTimeImmutable( $row['end_date'] );
			// TEC's repository accepts title/content/status aliases. UTC is a
			// supported timezone even when the producer only supplies an offset.
			$utc    = new DateTimeZone( 'UTC' );
			$fields = array(
				'title'      => $row['name'],
				'content'    => $document['post_content'],
				'status'     => 'publish',
				'start_date' => $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				'end_date'   => $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				'timezone'   => 'UTC',
			);
			$status = $id ? 'updated' : 'created';
			try {
				if ( ! empty( $row['venue'] ) ) {
					$venue_id = self::venue( array_merge( $row, array( 'source_identity' => $source_identity ) ), $report );
					if ( $venue_id <= 0 ) {
						throw new RuntimeException( 'TEC venue creation failed.' );
					}
					$fields['venue'] = $venue_id;
				}
				if ( $id ) {
					$previous                  = get_post( $id );
					$report['rollback'][ $id ] = array(
						'title'      => $previous->post_title,
						'content'    => $previous->post_content,
						'status'     => $previous->post_status,
						'start_date' => get_post_meta( $id, '_EventStartDate', true ),
						'end_date'   => get_post_meta( $id, '_EventEndDate', true ),
						'timezone'   => get_post_meta( $id, '_EventTimezone', true ),
						'venue'      => (int) get_post_meta( $id, '_EventVenueID', true ),
						'thumbnail'  => (int) get_post_thumbnail_id( $id ),
					);
					$report['events'][]        = array(
						'id'          => $id,
						'source_path' => $source,
						'status'      => 'updated',
					);
					$repository                = self::repository( 'tribe_events' )->where( 'ID', $id );
					foreach ( $fields as $key => $value ) {
						$repository->set( $key, $value );
					}
					$saved = $repository->save();
					$ok    = ! empty( $saved[ $id ] ) && true === $saved[ $id ];
				} else {
					$created = self::repository( 'tribe_events' )->set_args( $fields )->create();
					$id      = $created instanceof WP_Post ? (int) $created->ID : 0;
					$ok      = $id > 0 && update_post_meta( $id, self::SOURCE_META, $source_identity );
				}
				if ( $ok ) {
					// WordPress owns the saved block bytes; its insertion API requires slashing.
					$saved_content = wp_update_post(
						array(
							'ID'           => $id,
							'post_content' => wp_slash( $document['post_content'] ),
						),
						true
					);
					$ok            = ! is_wp_error( $saved_content ) && get_post_field( 'post_content', $id ) === $document['post_content'];
				}
			} catch ( Throwable $error ) {
				$ok              = false;
				$report['error'] = substr( $error->getMessage(), 0, 500 );
			}
			if ( ! $ok ) {
				$report['status'] = 'failed';
				$report['reason'] = 'tec_orm_write_failed';
				++$report['counts']['error'];
				if ( $id && 'created' === $status ) {
					$report['events'][] = array(
						'id'          => $id,
						'source_path' => $source,
						'status'      => 'created',
					);
				}
				break;
			}
			$result_row                     = array(
				'id'           => $id,
				'source_path'  => $source,
				'status'       => $status,
				'permalink'    => get_permalink( $id ),
				'image_status' => isset( $row['image'] ) ? 'canonical_media_pending' : 'not_declared',
			);
			$claim                          = array_intersect_key( $document, array_flip( array( 'source_path', 'source_route', 'page_reconciliation_identity', 'declaration_reconciliation_identity', 'entity_id' ) ) );
			$report['whole_page_results'][] = $claim + array(
				'schema'                     => Static_Site_Importer_Whole_Page_Handoff::SCHEMA,
				'status'                     => 'committed',
				'destination_post_id'        => $id,
				'post_type'                  => 'tribe_events',
				'featured_image_target_path' => is_array( $image ) ? $image['target_path'] : '',
			);
			if ( 'updated' === $status ) {
				$report['events'][ array_key_last( $report['events'] ) ] = $result_row;
			} else {
				$report['events'][] = $result_row;
			}
			++$report['counts'][ $status ];
		}
		$options = get_option( 'tribe_events_calendar_options', array() );
		$configure_editor = array( 'Tribe__Settings_Manager', 'set_option' );
		if ( 'completed' === $report['status'] && ( $report['counts']['created'] + $report['counts']['updated'] ) > 0 && is_array( $options ) && ! array_key_exists( 'toggle_blocks_editor', $options ) && is_callable( $configure_editor ) ) {
			// Configure after ORM date-range updates, which also write provider options.
			call_user_func( $configure_editor, 'toggle_blocks_editor', true );
			$report['editor_configuration'] = 'initialized_block_editor';
		}
		if ( 'failed' === $report['status'] ) {
			$report['compensation'] = self::rollback( $report );
		}
		return $report;
	}

	/** One source-owned venue per event, never a title-based claim on another owner's venue. */
	private static function venue( array $row, array &$report ): int {
		$found = get_posts(
			array(
				'post_type'      => 'tribe_venue',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'meta_key'       => self::VENUE_META,
				'meta_value'     => $row['source_identity'],
				'fields'         => 'ids',
				'posts_per_page' => 2,
			)
		);
		if ( count( $found ) > 1 ) {
			return 0;
		}
		$id      = empty( $found ) ? 0 : (int) $found[0];
		$venue   = $row['venue'];
		$address = $venue['address'] ?? array();
		$fields  = array(
			'title'  => $venue['name'],
			'status' => 'publish',
		);
		if ( is_string( $address ) ) {
			$fields['address'] = $address;
		}
		if ( is_array( $address ) ) {
			foreach ( array(
				'streetAddress'   => 'address',
				'addressLocality' => 'city',
				'addressRegion'   => 'state',
				'postalCode'      => 'zip',
				'addressCountry'  => 'country',
			) as $source => $target ) {
				if ( isset( $address[ $source ] ) ) {
					$fields[ $target ] = $address[ $source ];
				}
			}
		}
		if ( $id ) {
			$previous                        = get_post( $id );
			$report['venue_rollback'][ $id ] = array(
				'title'  => $previous->post_title,
				'status' => $previous->post_status,
			);
			foreach ( array(
				'address' => '_VenueAddress',
				'city'    => '_VenueCity',
				'state'   => '_VenueState',
				'zip'     => '_VenueZip',
				'country' => '_VenueCountry',
			) as $field => $meta ) {
				$report['venue_rollback'][ $id ][ $field ] = get_post_meta( $id, $meta, true );
			}
			$repo = self::repository( 'tribe_venues' )->where( 'ID', $id );
			foreach ( $fields as $field => $value ) {
				$repo->set( $field, $value );
			}
			$saved = $repo->save();
			if ( true !== ( $saved[ $id ] ?? null ) ) {
				return 0;
			}
		} else {
			$post = self::repository( 'tribe_venues' )->set_args( $fields )->create();
			$id   = $post instanceof WP_Post ? (int) $post->ID : 0;
			if ( $id ) {
				$report['created_venues'][] = $id;
				if ( ! update_post_meta( $id, self::VENUE_META, $row['source_identity'] ) ) {
					return 0;
				}
			}
		}
		return $id;
	}

	public static function rollback( array $report ): array {
		$failures = array();
		foreach ( array_reverse( $report['events'] ?? array() ) as $event ) {
			$id = (int) ( $event['id'] ?? 0 );
			if ( ! $id ) {
				continue;
			}
			if ( 'created' === $event['status'] ) {
				if ( ! wp_delete_post( $id, true ) ) {
					$failures[] = $id;
				}
			} elseif ( isset( $report['rollback'][ $id ] ) ) {
				$before     = $report['rollback'][ $id ];
				$repository = self::repository( 'tribe_events' )->where( 'ID', $id );
				foreach ( array(
					'title'      => 'title',
					'content'    => 'content',
					'status'     => 'status',
					'start_date' => 'start_date',
					'end_date'   => 'end_date',
					'timezone'   => 'timezone',
					'venue'      => 'venue',
				) as $field => $key ) {
					$repository->set( $field, $before[ $key ] );
				}
				$saved            = $repository->save();
				$restored_content = wp_update_post(
					array(
						'ID'           => $id,
						'post_content' => wp_slash( (string) $before['content'] ),
					),
					true
				);
				if ( ! empty( $before['thumbnail'] ) ) {
					set_post_thumbnail( $id, (int) $before['thumbnail'] );
				} else {
					delete_post_thumbnail( $id );
				}
				if ( true !== ( $saved[ $id ] ?? null ) || is_wp_error( $restored_content ) || get_post_field( 'post_content', $id ) !== $before['content'] ) {
					$failures[] = $id;
				}
			}
		}
		foreach ( $report['venue_rollback'] ?? array() as $id => $before ) {
			$repo = self::repository( 'tribe_venues' )->where( 'ID', (int) $id );
			foreach ( $before as $field => $value ) {
				$repo->set( $field, $value );
			}
			$saved = $repo->save();
			if ( true !== ( $saved[ $id ] ?? null ) ) {
				$failures[] = (int) $id;
			}
		}
		foreach ( $report['created_venues'] ?? array() as $id ) {
			if ( ! wp_delete_post( $id, true ) ) {
				$failures[] = $id;
			}
		}
		return array(
			'status'     => $failures ? 'partial' : 'rolled_back',
			'failed_ids' => $failures,
		);
	}

	/** Resolve an optional provider factory only while its plugin is active. */
	private static function repository( string $factory ) {
		if ( ! is_callable( $factory ) ) {
			throw new RuntimeException( 'TEC repository is unavailable.' );
		}
		return $factory();
	}
}

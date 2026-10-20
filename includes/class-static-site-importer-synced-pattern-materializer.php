<?php
/**
 * Persist producer-declared synced patterns and resolve their exact references.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Materializes the site plan's synced_patterns group as synced wp_block posts. */
final class Static_Site_Importer_Synced_Pattern_Materializer {
	public const TOKEN_PREFIX    = '{{wordpress-site-plan:pattern:';
	public const META_KEY        = '_static_site_importer_reconciliation_identity';
	public const PROVENANCE_META = '_static_site_importer_provenance';
	public const SOURCE_PREFIX   = 'wordpress-site-plan/synced-patterns/';
	private const REFERENCE      = '/(<!--\s+wp:block\s+\{[^}]*?)"ref"\s*:\s*"\{\{wordpress-site-plan:pattern:(pattern-[a-f0-9]{16})}}"/';

	/**
	 * Admit all synced pattern declarations and references before destination writes.
	 *
	 * @param array<string,mixed> $plan Resolved plan.
	 */
	public static function preflight( array $plan ): ?WP_Error {
		$entities = array();
		foreach ( is_array( $plan['synced_patterns'] ?? null ) ? $plan['synced_patterns'] : array() as $pattern ) {
			$identity = is_array( $pattern ) ? ( $pattern['identity'] ?? null ) : null;
			$token    = is_string( $identity ) ? 'pattern-' . substr( $identity, 0, 16 ) : '';
			if ( ! is_string( $identity ) || ! preg_match( '/^[a-f0-9]{64}$/', $identity ) || isset( $entities[ $token ] )
				|| ( $pattern['source_path'] ?? null ) !== self::SOURCE_PREFIX . $token
				|| '' === trim( self::content( $pattern ) ) || str_contains( self::content( $pattern ), self::TOKEN_PREFIX ) ) {
				return new WP_Error( 'synced_pattern_declaration_invalid', 'Synced pattern declarations must have unique valid identities, source paths and content.' );
			}
			$entities[ $token ] = 0;
			$existing           = Static_Site_Importer_Site_Plan_Persistence::reconciled_post( $identity, 'wp_block' );
			if ( $existing && 'wp_block' !== $existing->post_type ) {
				return new WP_Error( 'synced_pattern_identity_conflict' );
			}
		}
		$placeholders = array_fill_keys( array_keys( $entities ), 1 );
		$markups      = array();
		foreach ( $plan['writes'] ?? array() as $write ) {
			if ( 'utf8' === ( $write['payload']['encoding'] ?? null ) ) {
				$markups[] = array( (string) $write['payload']['data'], false );
			}
		}
		foreach ( array( 'pages', 'template_parts', 'templates' ) as $group ) {
			foreach ( $plan[ $group ] ?? array() as $document ) {
				$markups[] = array( (string) ( $document['materialized_block_markup'] ?? $document['resolved_block_markup'] ?? $document['canonical_block_markup'] ?? '' ), true );
			}
		}
		foreach ( $markups as list( $markup, $counted ) ) {
			preg_match_all( self::REFERENCE, $markup, $matches );
			foreach ( $matches[2] as $token ) {
				if ( ! array_key_exists( $token, $entities ) ) {
					return new WP_Error( 'synced_pattern_reference_undeclared' );
				}
				$entities[ $token ] += $counted ? 1 : 0;
			}
			if ( str_contains( self::rewrite_references( $markup, $placeholders ), self::TOKEN_PREFIX ) ) {
				return new WP_Error( 'synced_pattern_reference_invalid' );
			}
		}
		if ( array() !== $entities ) {
			if ( 'explicit_refs/v1' !== ( $plan['reference_semantics']['synced_patterns'] ?? null ) || in_array( 0, $entities, true ) ) {
				return new WP_Error( 'synced_pattern_explicit_references_required' );
			}
			if ( ! post_type_exists( 'wp_block' ) ) {
				return new WP_Error( 'synced_pattern_post_type_unavailable' );
			}
		}
		return null;
	}

	/**
	 * Persist each declared pattern and replace its token refs with the post ID.
	 *
	 * @param array $state Materialization state.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function materialize( array &$state ) {
		if ( 'classic' === ( $state['args']['theme_materialization'] ?? null ) ) {
			return $state;
		}
		$error = self::preflight( $state['resolved'] );
		if ( $error ) {
			return $error;
		}
		$refs = array();
		foreach ( $state['resolved']['synced_patterns'] ?? array() as $pattern ) {
			$result = self::upsert( $pattern, $state );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$token          = 'pattern-' . substr( $pattern['identity'], 0, 16 );
			$refs[ $token ] = $result['id'];

			$state['applied']['synced_patterns'][] = $result + array(
				'token'       => $token,
				'identity'    => $pattern['identity'],
				'title'       => self::title( $pattern ),
				'source_path' => $pattern['source_path'],
			);
		}
		foreach ( $state['resolved']['writes'] ?? array() as $index => $write ) {
			if ( 'utf8' !== ( $write['payload']['encoding'] ?? null ) ) {
				continue;
			}
			$markup = self::rewrite_references( $write['payload']['data'], $refs );
			$state['resolved']['writes'][ $index ]['payload']['data'] = $markup;
			$state['resolved']['writes'][ $index ]['payload_hash']    = hash( 'sha256', $markup );
		}
		foreach ( array( 'pages', 'template_parts', 'templates' ) as $group ) {
			foreach ( $state['resolved'][ $group ] ?? array() as $index => $document ) {
				foreach ( array( 'resolved_block_markup', 'materialized_block_markup' ) as $field ) {
					if ( is_string( $document[ $field ] ?? null ) ) {
						$state['resolved'][ $group ][ $index ][ $field ] = self::rewrite_references( $document[ $field ], $refs );
					}
				}
			}
		}
		foreach ( $state['ordered_pages'] ?? array() as $index => $page ) {
			foreach ( array( 'resolved_block_markup', 'materialized_block_markup' ) as $field ) {
				if ( is_string( $page[ $field ] ?? null ) ) {
					$state['ordered_pages'][ $index ][ $field ] = self::rewrite_references( $page[ $field ], $refs );
				}
			}
		}
		return $state;
	}

	/**
	 * Resolve declared core/block references to integer IDs; ownership belongs to the producer.
	 *
	 * @param string            $content Block markup.
	 * @param array<string,int> $refs    Post IDs keyed by token.
	 */
	public static function rewrite_references( string $content, array $refs ): string {
		return (string) preg_replace_callback(
			self::REFERENCE,
			static fn( array $reference ): string => isset( $refs[ $reference[2] ] ) ? $reference[1] . '"ref":' . (int) $refs[ $reference[2] ] : $reference[0],
			$content
		);
	}

	/**
	 * Bound and runtime-processed pattern markup, as pages persist theirs.
	 *
	 * @param array<string,mixed> $pattern Pattern entity.
	 */
	private static function content( array $pattern ): string {
		return (string) ( $pattern['materialized_block_markup'] ?? $pattern['resolved_block_markup'] ?? $pattern['canonical_block_markup'] ?? '' );
	}

	/**
	 * Entity title, with the producer's documented default.
	 *
	 * @param array<string,mixed> $pattern Pattern entity.
	 */
	private static function title( array $pattern ): string {
		$title = trim( (string) ( $pattern['title'] ?? '' ) );
		return '' !== $title ? $title : 'Shared content';
	}

	/**
	 * Create or reconcile one wp_block by identity.
	 *
	 * @param array<string,mixed> $pattern Pattern entity.
	 * @param array               $state   Materialization state.
	 * @return array{id:int,status:string,content_hash:string}|WP_Error
	 */
	private static function upsert( array $pattern, array &$state ) {
		$identity = $pattern['identity'];
		$existing = Static_Site_Importer_Site_Plan_Persistence::reconciled_post( $identity, 'wp_block' );
		if ( $existing && 'wp_block' !== $existing->post_type ) {
			return new WP_Error( 'synced_pattern_identity_conflict' );
		}
		if ( $existing instanceof WP_Post ) {
			// An owner edit since the last import wins, as for owned pages.
			$provenance = json_decode( (string) get_post_meta( (int) $existing->ID, self::PROVENANCE_META, true ), true );
			$recorded   = is_array( $provenance ) ? (string) ( $provenance['content_hash'] ?? '' ) : '';
			$current    = hash( 'sha256', (string) $existing->post_content );
			if ( '' !== $recorded && ! hash_equals( $recorded, $current ) ) {
				self::complete_bindings( $state, $pattern['source_path'], (int) $existing->ID, (string) $existing->post_content );
				return array(
					'id'           => (int) $existing->ID,
					'status'       => 'preserved_owner_edit',
					'content_hash' => $current,
				);
			}
		}
		$postarr = array(
			'post_title'   => self::title( $pattern ),
			'post_status'  => 'publish',
			'post_type'    => 'wp_block',
			'post_content' => wp_slash( self::content( $pattern ) ),
		);
		if ( $existing instanceof WP_Post ) {
			$postarr['ID'] = (int) $existing->ID;
			Static_Site_Importer_Site_Plan_Persistence::journal_post(
				$state,
				array(
					'planned_existing_id' => $existing->ID,
					'source_path'         => $pattern['source_path'],
				)
			);
		}
		$id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$id = (int) $id;
		if ( ! isset( $postarr['ID'] ) ) {
			$state['rollback']['posts'][ $id ] = array( 'existing' => false );
		}
		$state['applied']['posts'][] = array(
			'id'                      => $id,
			'source_path'             => $pattern['source_path'],
			'reconciliation_identity' => $identity,
			'post_type'               => 'wp_block',
		);
		// Absent wp_pattern_sync_status meta is what makes a wp_block synced.
		delete_post_meta( $id, 'wp_pattern_sync_status' );
		$persisted  = (string) get_post_field( 'post_content', $id );
		$provenance = array(
			'schema'       => 'static-site-importer/synced-pattern-provenance/v1',
			'identity'     => $identity,
			'source_path'  => $pattern['source_path'],
			'content_hash' => hash( 'sha256', $persisted ),
		);
		if ( ! Static_Site_Importer_Site_Plan_Persistence::write_post_meta( $id, self::META_KEY, $identity )
			|| ! Static_Site_Importer_Site_Plan_Persistence::write_post_meta( $id, self::PROVENANCE_META, (string) wp_json_encode( $provenance ) ) ) {
			return new WP_Error( 'synced_pattern_metadata_write_failed' );
		}
		self::complete_bindings( $state, $pattern['source_path'], $id, $persisted );
		return array(
			'id'           => $id,
			'status'       => isset( $postarr['ID'] ) ? 'updated' : 'created',
			'content_hash' => $provenance['content_hash'],
		);
	}

	/**
	 * A pattern binding completes when the persisted pattern carries its provider fragment.
	 *
	 * @param array  $state       Materialization state.
	 * @param string $source_path Pattern source path.
	 * @param int    $id          Pattern post ID.
	 * @param string $content     Persisted pattern content.
	 */
	private static function complete_bindings( array &$state, string $source_path, int $id, string $content ): void {
		foreach ( $state['applied']['runtime_declarations']['entity_bindings'] ?? array() as $key => $binding ) {
			if ( ( $binding['source_path'] ?? '' ) !== $source_path ) {
				continue;
			}
			$fragment = (string) ( $binding['replacement_block_markup'] ?? '' );
			if ( '' === $fragment || ! str_contains( $content, $fragment ) ) {
				$state['applied']['runtime_declarations']['entity_bindings'][ $key ]['status'] = 'unresolved';
				continue;
			}
			$state['applied']['runtime_declarations']['entity_bindings'][ $key ] = array_merge(
				$binding,
				array(
					'status'                    => 'completed',
					'post_id'                   => $id,
					'persisted_fragment_hash'   => hash( 'sha256', $fragment ),
					'materialized_content_hash' => hash( 'sha256', $content ),
				)
			);
		}
	}
}

<?php
/**
 * Owner-manageable source routes through Redirection's native REST contract.
 *
 * @package StaticSiteImporter
 */

defined( 'ABSPATH' ) || exit;

final class Static_Site_Importer_Redirection_Materializer {
	public const OWNERSHIP_OPTION = 'static_site_importer_redirect_ownership';
	private const MAX_RULES       = 500;

	public static function adapter(): array {
		return array(
			'id'                    => 'redirection_routes',
			'provider'              => 'redirection',
			'capability'            => 'redirects',
			'entity_type'           => 'redirect',
			'entity_collection'     => 'redirects',
			'materialization_stage' => 'after_pages',
			'intent_callback'       => array( self::class, 'has_intent' ),
			'export_callback'       => array( self::class, 'export' ),
			'validator'             => array( self::class, 'validate' ),
			'materializer'          => array( self::class, 'materialize' ),
			'rollback_callback'     => array( self::class, 'rollback' ),
			'rollback_contract_id'  => 'static-site-importer/redirection-rollback/v1',
			'dependencies'          => array(
				array(
					'type'                  => 'wp_org_plugin',
					'slug'                  => 'redirection',
					'plugin_file'           => 'redirection/redirection.php',
					'availability_callback' => array( self::class, 'available' ),
					'preparation_callback'  => array( self::class, 'prepare' ),
					'missing_apis'          => array( 'redirection-native-database' ),
				),
			),
		);
	}

	public static function has_intent( array $plan, array $args ): bool {
		if ( ! empty( $args['source_route_aliases'] ) ) {
			return true; }
		foreach ( $plan['pages'] ?? array() as $page ) {
			if ( ! empty( $page['whole_page_candidates'] ) ) {
				return true; }
		}
		$owned = function_exists( 'get_option' ) ? get_option( self::OWNERSHIP_OPTION, array() ) : array();
		return is_array( $owned ) && isset( $owned[ (string) ( $args['slug'] ?? '' ) ] );
	}

	private static function invoke( object|string $receiver, string $method, mixed ...$args ): mixed {
		$callback = array( $receiver, $method );
		if ( ! is_callable( $callback ) ) {
			throw new RuntimeException( 'Redirection does not expose the required native API.' );
		}
		return call_user_func( $callback, ...$args );
	}

	public static function available(): bool {
		$class = 'Redirection\\Database\\Status';
		if ( ! class_exists( $class ) ) {
			return false; }
		$status = new $class();
		$state  = self::invoke( $status, 'get_json' );
		return is_array( $state ) && 'ok' === ( $state['status'] ?? '' ) && ! self::invoke( $status, 'needs_installing' ) && ! self::invoke( $status, 'needs_updating' ) && ! self::invoke( $status, 'is_error' );
	}

	/** Advance the provider's own bounded install stages rather than writing its tables. */
	public static function prepare() {
		$class = 'Redirection\\Database\\Status';
		if ( ! class_exists( $class ) ) {
			return new WP_Error( 'static_site_importer_redirection_unavailable', 'Redirection has not loaded its native database API.' );
		}
		for ( $step = 0; $step < 32; ++$step ) {
			if ( self::available() ) {
				return true; }
			$status = new $class();
			if ( ! self::invoke( $status, 'needs_installing' ) && ! self::invoke( $status, 'needs_updating' ) && ! self::invoke( $status, 'is_error' ) ) {
				$finish = self::api( 'POST', 'plugin/finish' );
				return is_wp_error( $finish ) ? $finish : self::available();
			}
			$result = self::api( 'POST', 'plugin/data' );
			if ( is_wp_error( $result ) ) {
				return $result; }
			$status = new $class();
			if ( self::invoke( $status, 'is_error' ) ) {
				return new WP_Error( 'static_site_importer_redirection_setup_failed', 'Redirection reported a native database preparation failure.' );
			}
			if ( ! self::invoke( $status, 'needs_installing' ) && ! self::invoke( $status, 'needs_updating' ) ) {
				$finish = self::api( 'POST', 'plugin/finish' );
				return is_wp_error( $finish ) ? $finish : self::available();
			}
		}
		return new WP_Error( 'static_site_importer_redirection_setup_incomplete', 'Redirection did not complete its bounded native installation.' );
	}

	/** Grant Redirection's own access check to the identity-less operator process. */
	public static function operator_access(): string {
		return 'exist';
	}

	public static function api( string $method, string $path, array $params = array() ) {
		$request = new WP_REST_Request( $method, '/redirection/v1/' . $path );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params ); } else {
			$request->set_body_params( $params ); }
			// WP-CLI imports are operator-authorized but may have no current user. Satisfy
			// Redirection's documented capability filter only for this in-process call;
			// web requests keep their authenticated user's provider capabilities.
			$operator = Static_Site_Importer_Current_Site_Capabilities::is_operator_process();
			if ( $operator ) {
				add_filter( 'redirection_capability_check', array( self::class, 'operator_access' ), PHP_INT_MAX, 0 );
			}
			try {
				$response = rest_do_request( $request );
			} finally {
				if ( $operator ) {
					remove_filter( 'redirection_capability_check', array( self::class, 'operator_access' ), PHP_INT_MAX );
				}
			}
			if ( $response->is_error() ) {
				return $response->as_error(); }
			$data = $response->get_data();
			return is_array( $data ) ? $data : new WP_Error( 'static_site_importer_redirection_response_invalid', 'Redirection returned no native API result.' );
	}

	public static function validate( mixed $manifest ): array {
		$rows = is_array( $manifest ) ? ( $manifest['redirects'] ?? null ) : null;
		return array(
			'redirects' => array(),
			'errors'    => is_array( $rows ) && array() === $rows ? array() : array(
				array(
					'path'    => '$.redirects',
					'message' => 'Redirect destinations must bind to the committed canonical page receipt.',
				),
			),
		);
	}

	/** Bind typed source paths to the actual destination, never to guessed slugs. */
	public static function routes( array $receipt, array $args ) {
		$pages   = $receipt['plan']['pages'] ?? array();
		$ids     = $receipt['completed']['pages'] ?? array();
		$aliases = Static_Site_Importer_Redirects_Manifest::aliases_for_source_paths( $args['source_route_aliases'] ?? array(), array_column( $pages, 'source_path' ) );
		$alias_paths = array();
		foreach ( $aliases as $source => $paths ) {
			foreach ( $paths as $path ) {
				$alias_paths[ '/' . ltrim( $path, '/' ) ] = $source;
			}
		}
		$routes  = array();
		foreach ( $pages as $page ) {
			$source = (string) ( $page['source_path'] ?? '' );
			$id     = (int) ( $ids[ $source ] ?? 0 );
			if ( $id <= 0 ) {
				continue; }
			if ( ! empty( $page['synthetic'] ) && 'draft' === ( $page['materialized_post_status'] ?? '' ) && isset( $page['alias_target_source_path'] ) && $page['alias_target_source_path'] === ( $alias_paths[ $page['route']['path'] ?? '' ] ?? null ) && 'draft' === get_post_status( $id ) ) {
				// This native row supplies descendant ancestry; its exact public URL
				// is explicitly owned by the alias bound to a different page receipt.
				continue;
			}
			$target = get_permalink( $id );
			if ( ! is_string( $target ) || '' === $target ) {
				return new WP_Error( 'static_site_importer_redirect_destination_missing', 'A committed source document has no native destination URL.' );
			}
			$target_path = wp_parse_url( $target, PHP_URL_PATH );
			$sources     = array_merge( array( $page['route']['path'] ?? '' ), $aliases[ $source ] ?? array() );
			foreach ( $sources as $from ) {
				$from = '/' . ltrim( (string) $from, '/' );
				if ( '/' === $from || trim( $from, '/' ) === trim( (string) $target_path, '/' ) ) {
					continue; }
				if ( isset( $routes[ $from ] ) && $routes[ $from ] !== $target ) {
					return new WP_Error( 'static_site_importer_redirect_ambiguous', 'More than one destination claims a source route.' );
				}
				$occupied = self::published_route_owner( $from );
				if ( $occupied && 'publish' === $occupied->post_status && (int) $occupied->ID !== $id ) {
					return new WP_Error( 'static_site_importer_redirect_route_occupied', 'Unrelated published content owns a requested source route.' );
				}
				$routes[ $from ] = $target;
			}
		}
		if ( count( $routes ) > self::MAX_RULES ) {
			return new WP_Error( 'static_site_importer_redirect_bound_exceeded', 'The source redirect inventory exceeds its supported bound.' );
		}
		foreach ( $routes as $from => $target ) {
			$path = wp_parse_url( $target, PHP_URL_PATH );
			if ( isset( $routes[ $path ] ) ) {
				return new WP_Error( 'static_site_importer_redirect_chain_unproven', 'A destination is itself a source redirect; native final destinations are required.' );
			}
		}
		ksort( $routes, SORT_STRING );
		return $routes;
	}

	/** Draft hierarchy rows must never hide a genuine published route owner. */
	public static function published_route_owner( string $path ) {
		$parts = array_map( 'sanitize_title_for_query', explode( '/', rawurldecode( trim( $path, '/' ) ) ) );
		$path  = implode( '/', $parts );
		$posts = get_posts( array(
			'name'        => end( $parts ),
			'post_type'   => array( 'page', 'post' ),
			'post_status' => 'publish',
			'numberposts' => -1,
		) );
		foreach ( $posts as $post ) {
			if ( trim( (string) get_page_uri( $post ), '/' ) === $path ) {
				return $post;
			}
		}
		return null;
	}

	private static function find( array $filter ) {
		$result = self::api(
			'GET',
			'redirect',
			array(
				'filterBy' => $filter,
				'per_page' => 200,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result; }
		if ( (int) ( $result['total'] ?? 0 ) > 200 ) {
			return new WP_Error( 'static_site_importer_redirect_conflicts_unbounded', 'The native rule collision inventory exceeds its bounded verification window.' );
		}
		return $result['items'] ?? array();
	}

	private static function config( array $item ): array {
		return array_intersect_key( $item, array_flip( array( 'id', 'url', 'regex', 'match_type', 'action_type', 'action_code', 'action_data', 'match_data', 'group_id', 'enabled', 'title' ) ) );
	}

	/** The provider's own matcher covers regex, case and slash semantics without visits. */
	private static function matches( array $item, string $path ): bool {
		if ( empty( $item['enabled'] ) ) {
			return false; }
		$url_class   = 'Redirection\\Url\\Url';
		$flags_class = 'Redirection\\Url\\SourceFlags';
		if ( ! class_exists( $url_class ) || ! class_exists( $flags_class ) ) {
			throw new RuntimeException( 'Redirection did not expose its native source matcher.' );
		}
		$flags               = is_array( $item['match_data']['source'] ?? null ) ? $item['match_data']['source'] : array();
		$flags['flag_regex'] = ! empty( $item['regex'] );
		return (bool) self::invoke( new $url_class( (string) $item['url'] ), 'is_match', $path, new $flags_class( $flags ) );
	}

	private static function inventory() {
		$items = array();
		for ( $page = 0; $page < 10; ++$page ) {
			$result = self::api(
				'GET',
				'redirect',
				array(
					'per_page' => 200,
					'page'     => $page,
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result; }
			$total = (int) ( $result['total'] ?? 0 );
			if ( $total > 2000 ) {
				return new WP_Error( 'static_site_importer_redirect_conflicts_unbounded', 'The native rule inventory exceeds its bounded collision verification window.' );
			}
			$items = array_merge( $items, $result['items'] ?? array() );
			if ( count( $items ) >= $total ) {
				return $items; }
		}
		return new WP_Error( 'static_site_importer_redirect_inventory_incomplete', 'The provider did not return its complete bounded rule inventory.' );
	}

	public static function materialize( array $manifest, array $args ) {
		if ( ! self::available() ) {
			return new WP_Error( 'static_site_importer_redirection_unavailable', 'The native redirect provider is unavailable.' );
		}
		$receipt = $args['materialized_receipt'] ?? array();
		$routes  = self::routes( $receipt, $args );
		if ( is_wp_error( $routes ) ) {
			return $routes; }
		$scope = (string) ( $receipt['theme']['slug'] ?? $args['slug'] ?? '' );
		if ( '' === $scope ) {
			return new WP_Error( 'static_site_importer_redirect_scope_missing', 'Native rules require a stable import scope.' ); }
		$ownership_existed = null !== get_option( self::OWNERSHIP_OPTION, null );
		$before            = get_option( self::OWNERSHIP_OPTION, array() );
		$before            = is_array( $before ) ? $before : array();
		$owned             = $before[ $scope ] ?? array();
		$journal           = array(
			'scope'             => $scope,
			'ownership_existed' => $ownership_existed,
			'ownership_before'  => $before,
			'rules'             => array(),
			'created_group'     => 0,
		);
		$report            = array(
			'status'    => 'completed',
			'provider'  => 'redirection',
			'counts'    => array(
				'mapped'  => 0,
				'created' => 0,
				'updated' => 0,
			),
			'mutations' => array(),
			'rollback'  => $journal,
		);
		if ( array() === $routes && empty( $owned ) ) {
			return $report;
		}
		$next = array(
			'group_id' => (int) ( $owned['group_id'] ?? 0 ),
			'rules'    => array(),
		);
		try {
			// Inspect every occupied native source before writing any rule.
			$inventory = self::inventory();
			if ( is_wp_error( $inventory ) ) {
				return $inventory; }
			$existing = array();
			foreach ( $routes as $from => $target ) {
				foreach ( $inventory as $item ) {
					if ( ( $item['url'] ?? '' ) !== $from && ! self::matches( $item, $from ) ) {
						continue; }
					if ( isset( $existing[ $from ] ) || (int) ( $item['id'] ?? 0 ) !== (int) ( $owned['rules'][ $from ]['id'] ?? 0 ) || self::config( $item ) !== ( $owned['rules'][ $from ]['config'] ?? null ) ) {
						return new WP_Error( 'static_site_importer_redirect_owner_conflict', 'An owner-created or edited native rule occupies a source route.' );
					}
					$existing[ $from ] = $item;
				}
				if ( isset( $owned['rules'][ $from ] ) && ! isset( $existing[ $from ] ) ) {
					return new WP_Error( 'static_site_importer_redirect_owner_conflict', 'A previously owned rule was removed or moved by its owner.' );
				}
			}
			if ( array() !== $routes && 0 === $next['group_id'] ) {
				$name   = 'Imported source routes: ' . $scope;
				$groups = self::api(
					'GET',
					'group',
					array(
						'filterBy' => array( 'name' => $name ),
						'per_page' => 200,
					)
				);
				if ( is_wp_error( $groups ) ) {
					return $groups; }
				foreach ( $groups['items'] ?? array() as $group_item ) {
					if ( ( $group_item['name'] ?? '' ) === $name ) {
						return new WP_Error( 'static_site_importer_redirect_owner_conflict', 'An unrelated native group uses the proposed import scope.' );
					}
				}
				$group = self::api(
					'POST',
					'group',
					array(
						'name'     => $name,
						'moduleId' => 1,
						'filterBy' => array( 'name' => $name ),
						'per_page' => 200,
					)
				);
				if ( is_wp_error( $group ) ) {
					return $group; }
				foreach ( $group['items'] ?? array() as $item ) {
					if ( ( $item['name'] ?? '' ) === $name ) {
						$next['group_id'] = (int) $item['id'];
						break; }
				}
				if ( $next['group_id'] <= 0 ) {
					throw new RuntimeException( 'The native provider did not return its created group.' ); }
				$report['rollback']['created_group'] = $next['group_id'];
				$report['mutations'][]               = array(
					'status'   => 'created',
					'group_id' => $next['group_id'],
				);
			}
			foreach ( $routes as $from => $target ) {
				$params = array(
					'url'         => $from,
					'group_id'    => $next['group_id'],
					'match_type'  => 'url',
					'action_type' => 'url',
					'action_code' => 301,
					'action_data' => array( 'url' => $target ),
					'match_data'  => array(
						'source' => array(
							'flag_regex'    => false,
							'flag_case'     => false,
							'flag_trailing' => true,
							'flag_query'    => 'pass',
						),
					),
					'title'       => 'Imported source route ' . substr( hash( 'sha256', $scope . "\n" . $from ), 0, 20 ),
					'enabled'     => true,
					'filterBy'    => array( 'url' => $from ),
					'per_page'    => 200,
				);
				$old    = $existing[ $from ] ?? null;
				$result = self::api( 'POST', null === $old ? 'redirect' : 'redirect/' . $old['id'], $params );
				if ( is_wp_error( $result ) ) {
					throw new RuntimeException( $result->get_error_message() ); }
				// Journal the native write response before any follow-up request can fail.
				$written_items = null === $old ? ( $result['items'] ?? array() ) : ( isset( $result['item'] ) ? array( $result['item'] ) : array() );
				$saved         = array_values( array_filter( $written_items, static fn( array $item ): bool => ( $item['url'] ?? '' ) === $from && ( $item['title'] ?? '' ) === $params['title'] ) );
				if ( 1 !== count( $saved ) ) {
					throw new RuntimeException( 'The native write response did not identify its committed rule.' );
				}
				$item          = $saved[0];
				$journal_index = null;
				if ( null === $old || self::config( $old ) !== self::config( $item ) || empty( $old['enabled'] ) ) {
					$status = null === $old ? 'created' : 'updated';
					++$report['counts'][ $status ];
					$journal_index                 = count( $report['rollback']['rules'] );
					$report['rollback']['rules'][] = array(
						'id'     => (int) $item['id'],
						'before' => $old,
						'after'  => self::config( $item ),
					);
					$report['mutations'][]         = array(
						'status' => $status,
						'id'     => (int) $item['id'],
					);
				}
				if ( null !== $old && empty( $old['enabled'] ) ) {
					$enabled = self::api( 'POST', 'bulk/redirect/enable', array( 'items' => array( $old['id'] ) ) );
					if ( is_wp_error( $enabled ) ) {
						throw new RuntimeException( $enabled->get_error_message() ); }
					$report['rollback']['rules'][ $journal_index ]['after']['enabled'] = true;
				}
				$items = self::find( array( 'url' => $from ) );
				if ( is_wp_error( $items ) ) {
					throw new RuntimeException( $items->get_error_message() ); }
				$saved = array_values( array_filter( $items, static fn( array $item ): bool => ( $item['url'] ?? '' ) === $from && ( $item['title'] ?? '' ) === $params['title'] ) );
				if ( 1 !== count( $saved ) ) {
					throw new RuntimeException( 'The committed native rule is missing or ambiguous.' ); }
				$item = $saved[0];
				if ( null !== $journal_index ) {
					$report['rollback']['rules'][ $journal_index ]['after'] = self::config( $item );
				}
				if ( ( $item['action_data']['url'] ?? null ) !== $target || 301 !== (int) ( $item['action_code'] ?? 0 ) || ! empty( $item['regex'] ) || empty( $item['enabled'] ) ) {
					throw new RuntimeException( 'The native rule did not commit its required exact source and destination behavior.' );
				}
				$next['rules'][ $from ] = array(
					'id'     => (int) $item['id'],
					'config' => self::config( $item ),
				);
				if ( null !== $old && self::config( $old ) === self::config( $item ) ) {
					++$report['counts']['mapped'];
					continue; }
			}
			foreach ( $owned['rules'] ?? array() as $from => $stale ) {
				if ( isset( $routes[ $from ] ) ) {
					continue; }
				$items = self::find( array( 'id' => (string) $stale['id'] ) );
				if ( is_wp_error( $items ) || 1 !== count( $items ) || self::config( $items[0] ) !== $stale['config'] ) {
					throw new RuntimeException( 'An owner changed a stale rule; it cannot be removed by reimport.' );
				}
				$old = $items[0];
				if ( empty( $old['enabled'] ) ) {
					$next['rules'][ $from ] = $stale;
					continue;
				}
				// Retire the owned rule without destroying its native ID or admin history.
				$result = self::api( 'POST', 'bulk/redirect/disable', array( 'items' => array( $stale['id'] ) ) );
				if ( is_wp_error( $result ) ) {
					throw new RuntimeException( $result->get_error_message() ); }
				$retired_config                = self::config( $old );
				$retired_config['enabled']     = false;
				$journal_index                 = count( $report['rollback']['rules'] );
				$report['rollback']['rules'][] = array(
					'id'     => (int) $stale['id'],
					'before' => $old,
					'after'  => $retired_config,
				);
				$report['mutations'][]         = array(
					'status' => 'updated',
					'id'     => (int) $stale['id'],
				);
				$retired                       = self::find( array( 'id' => (string) $stale['id'] ) );
				if ( is_wp_error( $retired ) || 1 !== count( $retired ) || ! empty( $retired[0]['enabled'] ) ) {
					throw new RuntimeException( 'The native provider did not prove rule retirement.' );
				}
				$next['rules'][ $from ]                                 = array(
					'id'     => (int) $stale['id'],
					'config' => self::config( $retired[0] ),
				);
				$report['rollback']['rules'][ $journal_index ]['after'] = self::config( $retired[0] );
			}
			$after                                 = $before;
			$after[ $scope ]                       = $next;
			$report['rollback']['ownership_after'] = $after;
			update_option( self::OWNERSHIP_OPTION, $after );
			if ( get_option( self::OWNERSHIP_OPTION, array() ) !== $after ) {
				throw new RuntimeException( 'Native rule ownership could not be committed.' ); }
			return $report;
		} catch ( Throwable $error ) {
			$report['status']           = 'failed';
			$report['counts']['failed'] = 1;
			$report['code']             = 'static_site_importer_redirect_materialization_failed';
			$report['error']            = $error->getMessage();
			return $report;
		}
	}

	/** Native rule journals are replayed only against the exact unchanged committed state. */
	public static function rollback( array $report ): array {
		$journal = $report['rollback'] ?? array();
		$current = get_option( self::OWNERSHIP_OPTION, array() );
		if ( ( $journal['ownership_before'] ?? array() ) !== $current && ( $journal['ownership_after'] ?? null ) !== $current ) {
			return array(
				'status' => 'failed',
				'reason' => 'native_redirect_ownership_changed',
			);
		}
		foreach ( array_reverse( $journal['rules'] ?? array() ) as $row ) {
			$items = self::find( array( 'id' => (string) $row['id'] ) );
			if ( is_wp_error( $items ) || 1 !== count( $items ) || self::config( $items[0] ) !== $row['after'] ) {
				return array(
					'status' => 'failed',
					'reason' => 'native_redirect_changed_after_materialization',
				);
			}
			$result = null === $row['before'] ? self::api( 'POST', 'bulk/redirect/delete', array( 'items' => array( $row['id'] ) ) ) : self::api( 'POST', 'redirect/' . $row['id'], $row['before'] );
			if ( is_wp_error( $result ) ) {
				return array(
					'status' => 'failed',
					'reason' => 'native_redirect_restore_failed',
				); }
			if ( null !== $row['before'] ) {
				$restore_state = self::api( 'POST', 'bulk/redirect/' . ( ! empty( $row['before']['enabled'] ) ? 'enable' : 'disable' ), array( 'items' => array( $row['id'] ) ) );
				if ( is_wp_error( $restore_state ) ) {
					return array(
						'status' => 'failed',
						'reason' => 'native_redirect_status_restore_failed',
					); }
			}
			$restored = self::find( array( 'id' => (string) $row['id'] ) );
			if ( is_wp_error( $restored ) || ( null === $row['before'] ? array() !== $restored : 1 !== count( $restored ) || self::config( $restored[0] ) !== self::config( $row['before'] ) ) ) {
				return array(
					'status' => 'failed',
					'reason' => 'native_redirect_restore_unproven',
				);
			}
		}
		if ( ! empty( $journal['created_group'] ) ) {
			$remaining = self::find( array( 'group' => (string) $journal['created_group'] ) );
			if ( is_wp_error( $remaining ) || array() !== $remaining ) {
				return array(
					'status' => 'failed',
					'reason' => 'native_redirect_group_contains_unowned_rules',
				);
			}
			$result = self::api( 'POST', 'bulk/group/delete', array( 'items' => array( $journal['created_group'] ) ) );
			if ( is_wp_error( $result ) ) {
				return array(
					'status' => 'failed',
					'reason' => 'native_redirect_group_restore_failed',
				); }
			$groups = self::api( 'GET', 'group', array(
				'filterBy' => array( 'name' => 'Imported source routes: ' . $journal['scope'] ),
				'per_page' => 200,
			) );
			if ( is_wp_error( $groups ) || (int) ( $groups['total'] ?? 0 ) > 200 || in_array( (int) $journal['created_group'], array_map( 'intval', array_column( $groups['items'] ?? array(), 'id' ) ), true ) ) {
				return array(
					'status' => 'failed',
					'reason' => 'native_redirect_group_restore_unproven',
				);
			}
		}
		$current = get_option( self::OWNERSHIP_OPTION, array() );
		if ( ( $journal['ownership_before'] ?? array() ) !== $current && ( $journal['ownership_after'] ?? null ) !== $current ) {
			return array(
				'status' => 'failed',
				'reason' => 'native_redirect_ownership_changed',
			);
		}
		if ( ! empty( $journal['ownership_existed'] ) ) {
			update_option( self::OWNERSHIP_OPTION, $journal['ownership_before'] ?? array() );
		} else {
			delete_option( self::OWNERSHIP_OPTION );
		}
		if ( ! empty( $journal['ownership_existed'] ) ? get_option( self::OWNERSHIP_OPTION, null ) !== $journal['ownership_before'] : null !== get_option( self::OWNERSHIP_OPTION, null ) ) {
			return array(
				'status' => 'failed',
				'reason' => 'native_redirect_ownership_restore_failed',
			);
		}
		return array( 'status' => 'rolled_back' );
	}

	public static function owns_route( string $route ): bool {
		$owned = function_exists( 'get_option' ) ? get_option( self::OWNERSHIP_OPTION, array() ) : array();
		if ( ! is_array( $owned ) ) {
			return false; }
		foreach ( $owned as $scope ) {
			if ( isset( $scope['rules'][ '/' . ltrim( $route, '/' ) ] ) ) {
				return true; }
		}
		return false;
	}

	/** Export active native rules bound to actual portable destination documents. */
	public static function export( array $artifact, array $args ) {
		$ownership = get_option( self::OWNERSHIP_OPTION, array() );
		$scope     = (string) ( $args['theme_slug'] ?? '' );
		$owned     = is_array( $ownership ) ? ( $ownership[ $scope ] ?? array() ) : array();
		if ( empty( $owned['rules'] ) ) {
			return $artifact; }
		if ( ! self::available() ) {
			return new WP_Error( 'static_site_importer_redirect_export_unavailable', 'Native redirect state cannot be exported without its owning provider.' );
		}
		$inventory = self::inventory();
		if ( is_wp_error( $inventory ) ) {
			return $inventory; }
		$by_id     = array_column( $inventory, null, 'id' );
		$root      = trim( (string) $artifact['root'], '/' );
		$documents = array();
		foreach ( $artifact['files'] as $file ) {
			if ( ! empty( $file['post_id'] ) ) {
				$documents[ (int) $file['post_id'] ] = '/' . substr( (string) $file['path'], strlen( $root ) + 1 );
			}
		}
		$lines = array();
		foreach ( $owned['rules'] as $row ) {
			$item = $by_id[ $row['id'] ] ?? null;
			if ( ! is_array( $item ) || empty( $item['enabled'] ) ) {
				continue; }
			if ( ! empty( $item['regex'] ) || 'url' !== ( $item['match_type'] ?? '' ) || 'url' !== ( $item['action_type'] ?? '' ) || 301 !== (int) ( $item['action_code'] ?? 0 ) || ! is_string( $item['action_data']['url'] ?? null ) || ( $item['match_data'] ?? null ) !== ( $row['config']['match_data'] ?? null ) ) {
				return new WP_Error( 'static_site_importer_redirect_export_unproven', 'An owner-edited native rule no longer fits the portable exact-path contract.' );
			}
			$target_id = url_to_postid( $item['action_data']['url'] );
			if ( $target_id <= 0 || get_permalink( $target_id ) !== $item['action_data']['url'] ) {
				return new WP_Error( 'static_site_importer_redirect_export_unproven', 'A native target does not exactly match its portable document permalink.' );
			}
			if ( ! isset( $documents[ $target_id ] ) ) {
				return new WP_Error( 'static_site_importer_redirect_export_target_missing', 'A native redirect target was not included in the exported document inventory.' );
			}
			$from = (string) $item['url'];
			if ( ! str_starts_with( $from, '/' ) || str_starts_with( $from, '//' ) || preg_match( '/[\s?#]/', $from ) ) {
				return new WP_Error( 'static_site_importer_redirect_export_unproven', 'A native source URL is not a portable exact-path alias.' );
			}
			$lines[] = $from . ' ' . $documents[ $target_id ] . ' 301';
		}
		if ( array() === $lines ) {
			return $artifact; }
		sort( $lines, SORT_STRING );
		$content                                        = implode( "\n", $lines ) . "\n";
		$artifact['files'][]                            = array(
			'path'      => $root . '/_redirects',
			'content'   => $content,
			'kind'      => 'metadata',
			'role'      => 'redirects',
			'encoding'  => 'utf8',
			'bytes'     => strlen( $content ),
			'sha256'    => hash( 'sha256', $content ),
			'mime_type' => 'text/plain',
		);
		$artifact['metadata']['runtime_declarations'][] = array(
			'kind'        => 'dependency',
			'capability'  => 'redirects',
			'source_path' => $artifact['entrypoint'],
		);
		$artifact['report']['redirect_count']           = count( $lines );
		$artifact['report']['file_count']               = count( $artifact['files'] );
		return $artifact;
	}
}

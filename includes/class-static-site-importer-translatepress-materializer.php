<?php
/**
 * Native multilingual configuration through TranslatePress's persistent settings.
 *
 * @package StaticSiteImporter
 */

defined( 'ABSPATH' ) || exit;

final class Static_Site_Importer_TranslatePress_Materializer {

	private const OPTION           = 'trp_settings';
	private const OWNERSHIP_OPTION = 'static_site_importer_multilingual_configuration';

	public static function adapter(): array {
		return array(
			'id'                   => 'translatepress_multilingual',
			'entity_type'          => 'multilingual',
			'entity_collection'    => 'multilingual',
			'capability'           => 'multilingual',
			'provider'             => 'translatepress-multilingual',
			'validator'            => array( self::class, 'validate' ),
			'materializer'         => array( self::class, 'materialize' ),
			'rollback_callback'    => array( self::class, 'rollback' ),
			'rollback_contract_id' => 'static-site-importer/translatepress-settings-rollback/v1',
			'dependencies'         => array(
				array(
					'type'                  => 'wp_org_plugin',
					'slug'                  => 'translatepress-multilingual',
					'plugin_file'           => 'translatepress-multilingual/index.php',
					'availability_callback' => array( self::class, 'available' ),
					'missing_apis'          => array( 'trp_is_valid_language_code' ),
				),
			),
		);
	}

	public static function available(): bool {
		return class_exists( 'TRP_Translate_Press' ) && function_exists( 'trp_is_valid_language_code' );
	}

	/** Admit one explicit site configuration; the free provider supports two languages. */
	public static function validate( mixed $manifest ): array {
		$rows   = is_array( $manifest ) ? ( $manifest['multilingual'] ?? null ) : null;
		$errors = array();
		if ( ! is_array( $rows ) || ! array_is_list( $rows ) || 1 !== count( $rows ) ) {
			$errors[] = array(
				'path'    => '$.multilingual',
				'message' => 'Declare exactly one multilingual site configuration.',
			);
		} else {
			$row       = $rows[0];
			$languages = is_array( $row ) ? ( $row['languages'] ?? null ) : null;
			if ( ! is_array( $row ) || ! is_string( $row['id'] ?? null ) || '' === $row['id'] || ! is_string( $row['default_language'] ?? null ) || ! is_array( $languages ) || ! array_is_list( $languages ) || 2 !== count( $languages ) || ! in_array( $row['default_language'], $languages, true ) || count( array_unique( $languages, SORT_REGULAR ) ) !== count( $languages ) ) {
				$errors[] = array(
					'path'    => '$.multilingual[0]',
					'message' => 'Declare an ID, default_language and two unique language codes including the default.',
				);
			} else {
				foreach ( $languages as $language ) {
					if ( ! is_string( $language ) || ! preg_match( '/^[a-z]{2,3}(?:_[A-Z]{2}|_[A-Za-z]{4}(?:_[A-Z]{2})?)?$/', $language ) ) {
						$errors[] = array(
							'path'    => '$.multilingual[0].languages',
							'message' => 'Use explicit WordPress locale codes.',
						);
					}
				}
			}
		}
		return array(
			'multilingual' => empty( $errors ) ? $rows : array(),
			'errors'       => $errors,
		);
	}

	/** Configure only the declared languages, leaving switcher styling and owner options intact. */
	public static function materialize( array $manifest, array $args = array() ) {
		$validation = self::validate( $manifest );
		if ( ! empty( $validation['errors'] ) || ! self::available() ) {
			return new WP_Error( 'static_site_importer_multilingual_unavailable', 'TranslatePress or its declared multilingual configuration is unavailable.' );
		}
		$row           = $validation['multilingual'][0];
		$before        = get_option( self::OPTION, null );
		$ownership     = get_option( self::OWNERSHIP_OPTION, null );
		$current       = is_array( $before ) ? $before : array();
		$languages     = array_values( array_unique( array_merge( array( $row['default_language'] ), $row['languages'] ) ) );
		$slug_callback = array( 'TRP_Translate_Press', 'get_trp_instance' );
		if ( ! is_callable( $slug_callback ) ) {
			return new WP_Error( 'static_site_importer_multilingual_unavailable', 'TranslatePress could not expose its native language service.' );
		}
		$provider           = call_user_func( $slug_callback );
		$component_callback = array( $provider, 'get_component' );
		if ( ! is_callable( $component_callback ) ) {
			return new WP_Error( 'static_site_importer_multilingual_unavailable', 'TranslatePress could not expose its native language service.' );
		}
		$language_service  = call_user_func( $component_callback, 'languages' );
		$language_callback = array( $language_service, 'get_languages' );
		if ( ! is_callable( $language_callback ) ) {
			return new WP_Error( 'static_site_importer_multilingual_unavailable', 'TranslatePress could not expose its supported languages.' );
		}
		$supported_languages = call_user_func( $language_callback );
		foreach ( $row['languages'] as $language ) {
			if ( ! is_array( $supported_languages ) || ! array_key_exists( $language, $supported_languages ) ) {
				return new WP_Error( 'static_site_importer_multilingual_language_unsupported', 'TranslatePress does not support a declared language code.' );
			}
		}
		$iso_callback = array( $language_service, 'get_iso_codes' );
		if ( ! is_callable( $iso_callback ) ) {
			return new WP_Error( 'static_site_importer_multilingual_unavailable', 'TranslatePress could not resolve native language URL slugs.' );
		}
		$url_slugs = call_user_func( $iso_callback, $languages );
		if ( ! is_array( $url_slugs ) ) {
			return new WP_Error( 'static_site_importer_multilingual_unavailable', 'TranslatePress returned no native language URL slugs.' );
		}
		$url_slugs = array_intersect_key( $url_slugs, array_flip( $languages ) );
		if ( count( array_unique( $url_slugs ) ) !== count( $languages ) ) {
			// Match the provider's own duplicate-slug fallback without ambiguous routes.
			$url_slugs = array_combine( $languages, array_map( 'sanitize_title', $languages ) );
		}
		$desired = array(
			'default-language'      => $row['default_language'],
			'translation-languages' => $languages,
			'publish-languages'     => $languages,
			'url-slugs'             => $url_slugs,
		);
		$fields  = array_intersect_key( $current, $desired );
		$owned   = is_array( $ownership ) && ( $ownership['declaration'] ?? '' ) === ( $args['declaration_reconciliation_identity'] ?? '' ) && ( $ownership['fields'] ?? null ) === $fields;
		if ( ! $owned && $fields === $desired ) {
			return array(
				'status'    => 'completed',
				'provider'  => 'translatepress-multilingual',
				'counts'    => array( 'mapped' => 1 ),
				'mutations' => array(),
			);
		}
		if ( ! $owned && $fields !== $desired && ( count( $current['translation-languages'] ?? array() ) > 1 || ( isset( $current['default-language'] ) && $current['default-language'] !== $row['default_language'] ) ) ) {
			return new WP_Error( 'static_site_importer_multilingual_owner_conflict', 'Existing owner language settings differ from the declared configuration.' );
		}
		$after         = array_replace( $current, $desired );
		$new_ownership = array(
			'declaration' => (string) ( $args['declaration_reconciliation_identity'] ?? '' ),
			'fields'      => $desired,
		);
		$report        = array(
			'status'    => 'completed',
			'provider'  => 'translatepress-multilingual',
			'counts'    => array( 'updated' => 1 ),
			'mutations' => array(
				array(
					'status' => 'updated',
					'id'     => $row['id'],
				),
			),
			'rollback'  => array(
				'settings_before'  => $before,
				'settings_after'   => $after,
				'ownership_before' => $ownership,
				'ownership_after'  => $new_ownership,
			),
		);
		update_option( self::OPTION, $after );
		update_option( self::OWNERSHIP_OPTION, $new_ownership );
		if ( get_option( self::OPTION, null ) !== $after || get_option( self::OWNERSHIP_OPTION, null ) !== $new_ownership ) {
			$report['rollback']['settings_after']  = get_option( self::OPTION, null );
			$report['rollback']['ownership_after'] = get_option( self::OWNERSHIP_OPTION, null );
			$restored                              = self::rollback( $report );
			return new WP_Error( 'static_site_importer_multilingual_settings_write_failed', 'TranslatePress language settings could not be persisted.', array( 'rollback' => $restored ) );
		}
		if ( $before === $after && $ownership === $new_ownership ) {
			$report['counts']    = array( 'mapped' => 1 );
			$report['mutations'] = array();
		}
		return $report;
	}

	/** Restore only the exact importer-owned option writes, preserving subsequent owner edits. */
	public static function rollback( array $report ): array {
		$journal = $report['rollback'] ?? array();
		foreach ( array(
			self::OPTION           => 'settings',
			self::OWNERSHIP_OPTION => 'ownership',
		) as $option => $key ) {
			$current = get_option( $option, null );
			if ( ! array_key_exists( $key . '_before', $journal ) || ( ( $journal[ $key . '_after' ] ?? null ) !== $current && $journal[ $key . '_before' ] !== $current ) ) {
				return array(
					'status' => 'failed',
					'reason' => 'multilingual_option_changed_after_materialization',
				);
			}
		}
		foreach ( array(
			self::OPTION           => 'settings',
			self::OWNERSHIP_OPTION => 'ownership',
		) as $option => $key ) {
			$before = $journal[ $key . '_before' ];
			if ( null === $before ) {
				delete_option( $option );
			} else {
				update_option( $option, $before );
			}
			if ( get_option( $option, null ) !== $before ) {
				return array(
					'status' => 'failed',
					'reason' => 'multilingual_option_restore_failed',
				);
			}
		}
		return array( 'status' => 'rolled_back' );
	}
}

<?php
/**
 * Website artifact import input normalization.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the canonical importer argument contract shared by every artifact intake.
 */
class Static_Site_Importer_Website_Artifact_Import_Input {

	/** @var array<string,array<string,mixed>> */
	public const SCHEMA_PROPERTIES = array(
		'slug'                                 => array( 'type' => 'string' ),
		'name'                                 => array( 'type' => 'string' ),
		'site_title'                           => array( 'type' => 'string' ),
		'stale_page_action'                    => array(
			'type' => 'string',
			'enum' => array( 'report_only', 'draft' ),
		),
		'activate'                             => array( 'type' => 'boolean' ),
		'overwrite'                            => array( 'type' => 'boolean' ),
		'disable_smilies'                      => array( 'type' => 'boolean' ),
		'fail_on_quality'                      => array( 'type' => 'boolean' ),
		'allow_missing_woocommerce'            => array( 'type' => 'boolean' ),
		'allow_missing_jetpack'                => array( 'type' => 'boolean' ),
		'materialize_dependencies'             => array( 'type' => 'boolean' ),
		'runtime_lifecycle_phase'               => array(
			'type' => 'string',
			'enum' => array( 'prepare', 'resume' ),
		),
		'runtime_lifecycle_request_id'          => array( 'type' => 'string' ),
		'require_proven_dynamic_client_assets' => array( 'type' => 'boolean' ),
		'seed_entities'                        => array( 'type' => 'boolean' ),
		'products_manifest'                    => array( 'type' => 'object' ),
		'commerce_context'                     => array( 'type' => 'object' ),
		'write_theme_report_artifacts'         => array( 'type' => 'boolean' ),
		'asset_materialization_policy'         => array(
			'type' => 'string',
			'enum' => array( 'copy_to_theme', 'use_map' ),
		),
		'asset_map'                            => array( 'type' => 'object' ),
		'compiler_options'                     => array( 'type' => 'object' ),
		'source_metadata'                      => array( 'type' => 'object' ),
		'validation_artifacts'                 => array( 'type' => 'object' ),
		'client_script_policy'                 => array(
			'type' => 'string',
			'enum' => array( 'inert', 'isolated_preview' ),
		),
		'client_script_provenance'             => array( 'type' => 'object' ),
		'client_script_isolated'               => array( 'type' => 'boolean' ),
		'theme_materialization'                => array(
			'type' => 'string',
			'enum' => array( 'block', 'classic' ),
		),
	);

	/**
	 * Normalize importer arguments with entrypoint-owned defaults.
	 *
	 * @param array<string,mixed> $input    Raw entrypoint input.
	 * @param array<string,mixed> $defaults Explicit defaults for this entrypoint.
	 * @return array<string,mixed>
	 */
	public static function normalize( array $input, array $defaults = array() ): array {
		$values = array_merge(
			array(
				'slug'                                 => '',
				'name'                                 => '',
				'site_title'                           => '',
				'stale_page_action'                    => '',
				'activate'                             => false,
				'overwrite'                            => false,
				'disable_smilies'                      => true,
				'fail_on_quality'                      => false,
				'allow_missing_woocommerce'            => false,
				'allow_missing_jetpack'                => false,
				'materialize_dependencies'             => true,
				'runtime_lifecycle_phase'               => '',
				'runtime_lifecycle_request_id'          => '',
				'require_proven_dynamic_client_assets' => true,
				'seed_entities'                        => false,
				'products_manifest'                    => array(),
				'commerce_context'                     => array(),
				'write_theme_report_artifacts'         => false,
				'asset_materialization_policy'         => '',
				'asset_map'                            => array(),
				'compiler_options'                     => array(),
				'source_metadata'                      => array(),
				'validation_artifacts'                 => array(),
				'client_script_policy'                 => 'inert',
				'client_script_provenance'             => array(),
				'client_script_isolated'               => false,
				'theme_materialization'                => 'block',
			),
			$defaults
		);

		foreach ( self::SCHEMA_PROPERTIES as $field => $schema ) {
			if ( array_key_exists( $field, $input ) ) {
				$values[ $field ] = $input[ $field ];
			}
		}

		foreach ( array( 'slug', 'name', 'site_title', 'stale_page_action', 'runtime_lifecycle_phase', 'runtime_lifecycle_request_id', 'asset_materialization_policy', 'client_script_policy', 'theme_materialization' ) as $field ) {
			$values[ $field ] = is_scalar( $values[ $field ] ) ? (string) $values[ $field ] : '';
		}
		foreach ( array( 'activate', 'overwrite', 'disable_smilies', 'fail_on_quality', 'allow_missing_woocommerce', 'allow_missing_jetpack', 'materialize_dependencies', 'require_proven_dynamic_client_assets', 'seed_entities', 'write_theme_report_artifacts', 'client_script_isolated' ) as $field ) {
			$values[ $field ] = (bool) $values[ $field ];
		}
		foreach ( array( 'products_manifest', 'commerce_context', 'asset_map', 'compiler_options', 'source_metadata', 'validation_artifacts', 'client_script_provenance' ) as $field ) {
			$values[ $field ] = is_array( $values[ $field ] ) ? $values[ $field ] : array();
		}

		return $values;
	}
}

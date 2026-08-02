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
		'slug'                         => array( 'type' => 'string' ),
		'name'                         => array( 'type' => 'string' ),
		'site_title'                   => array( 'type' => 'string' ),
		'stale_page_action'            => array( 'type' => 'string', 'enum' => array( 'report_only', 'draft' ) ),
		'activate'                     => array( 'type' => 'boolean' ),
		'overwrite'                    => array( 'type' => 'boolean' ),
		'fail_on_quality'              => array( 'type' => 'boolean' ),
		'allow_missing_woocommerce'    => array( 'type' => 'boolean' ),
		'allow_missing_jetpack'        => array( 'type' => 'boolean' ),
		'materialize_dependencies'     => array( 'type' => 'boolean' ),
		'require_proven_dynamic_client_assets' => array( 'type' => 'boolean' ),
		'seed_entities'                => array( 'type' => 'boolean' ),
		'products_manifest'            => array( 'type' => 'object' ),
		'commerce_context'             => array( 'type' => 'object' ),
		'report'                       => array( 'type' => 'string' ),
		'write_theme_report_artifacts' => array( 'type' => 'boolean' ),
		'asset_materialization_policy' => array( 'type' => 'string', 'enum' => array( 'copy_to_theme', 'use_map' ) ),
		'asset_map'                    => array( 'type' => 'object' ),
		'compiler_options'             => array( 'type' => 'object' ),
		'source_metadata'              => array( 'type' => 'object' ),
		'validation_artifacts'         => array( 'type' => 'object' ),
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
				'slug'                         => '',
				'name'                         => '',
				'site_title'                   => '',
				'stale_page_action'            => '',
				'activate'                     => false,
				'overwrite'                    => false,
				'fail_on_quality'              => false,
				'allow_missing_woocommerce'    => false,
				'allow_missing_jetpack'        => false,
				'materialize_dependencies'     => true,
				'require_proven_dynamic_client_assets' => true,
				'seed_entities'                => false,
				'products_manifest'            => array(),
				'commerce_context'             => array(),
				'report'                       => '',
				'write_theme_report_artifacts' => false,
				'asset_materialization_policy' => '',
				'asset_map'                    => array(),
				'compiler_options'             => array(),
				'source_metadata'              => array(),
				'validation_artifacts'         => array(),
			),
			$defaults
		);

		foreach ( self::SCHEMA_PROPERTIES as $field => $schema ) {
			if ( array_key_exists( $field, $input ) ) {
				$values[ $field ] = $input[ $field ];
			}
		}

		foreach ( array( 'slug', 'name', 'site_title', 'stale_page_action', 'report', 'asset_materialization_policy' ) as $field ) {
			$values[ $field ] = is_scalar( $values[ $field ] ) ? (string) $values[ $field ] : '';
		}
		foreach ( array( 'activate', 'overwrite', 'fail_on_quality', 'allow_missing_woocommerce', 'allow_missing_jetpack', 'materialize_dependencies', 'require_proven_dynamic_client_assets', 'seed_entities', 'write_theme_report_artifacts' ) as $field ) {
			$values[ $field ] = (bool) $values[ $field ];
		}
		foreach ( array( 'products_manifest', 'commerce_context', 'asset_map', 'compiler_options', 'source_metadata', 'validation_artifacts' ) as $field ) {
			$values[ $field ] = is_array( $values[ $field ] ) ? $values[ $field ] : array();
		}

		return $values;
	}
}

<?php
/**
 * Studio Native artifact envelope contract.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes the Studio Native <-> SSI artifact handoff.
 */
class Static_Site_Importer_Artifact_Envelope {
	public const WEBSITE_ARTIFACT_BUNDLE_SCHEMA = 'studio-native/website-artifact-bundle/v1';
	public const EXPORT_RESULT_SCHEMA           = 'static-site-importer/export-theme-result/v1';

	/**
	 * Build the canonical export result envelope consumed by Studio Native.
	 *
	 * @param array<string,mixed> $website_artifact Blocks Engine website artifact.
	 * @return array<string,mixed>
	 */
	public static function export_result_from_website_artifact( array $website_artifact ): array {
		return array(
			'schema'          => self::EXPORT_RESULT_SCHEMA,
			'artifact_bundle' => self::bundle_from_website_artifact( $website_artifact ),
		);
	}

	/**
	 * Convert a Blocks Engine website artifact into the Studio Native bundle shape.
	 *
	 * @param array<string,mixed> $website_artifact Blocks Engine website artifact.
	 * @return array<string,mixed>
	 */
	public static function bundle_from_website_artifact( array $website_artifact ): array {
		$files = isset( $website_artifact['files'] ) && is_array( $website_artifact['files'] ) ? $website_artifact['files'] : array();

		return array_filter(
			array(
				'schema'     => self::WEBSITE_ARTIFACT_BUNDLE_SCHEMA,
				'root'       => isset( $website_artifact['root'] ) ? (string) $website_artifact['root'] : 'website',
				'entrypoint' => isset( $website_artifact['entrypoint'] ) ? (string) $website_artifact['entrypoint'] : 'website/index.html',
				'files'      => array_values( array_map( array( self::class, 'bundle_file_from_website_artifact_file' ), $files ) ),
				'provenance' => isset( $website_artifact['provenance'] ) && is_array( $website_artifact['provenance'] ) ? $website_artifact['provenance'] : array(),
				'report'     => isset( $website_artifact['report'] ) && is_array( $website_artifact['report'] ) ? $website_artifact['report'] : array(),
				'validation' => isset( $website_artifact['validation'] ) && is_array( $website_artifact['validation'] ) ? $website_artifact['validation'] : array(),
				'import'     => isset( $website_artifact['import'] ) && is_array( $website_artifact['import'] ) ? $website_artifact['import'] : array(),
				'reports'    => isset( $website_artifact['reports'] ) && is_array( $website_artifact['reports'] ) ? $website_artifact['reports'] : array(),
			),
			static fn ( $value ): bool => array() !== $value && '' !== $value
		);
	}

	/**
	 * Convert the canonical Studio Native bundle into a Blocks Engine website artifact.
	 *
	 * @param array<string,mixed> $bundle Studio Native artifact bundle.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function website_artifact_from_bundle( array $bundle ) {
		if ( ( $bundle['schema'] ?? '' ) !== self::WEBSITE_ARTIFACT_BUNDLE_SCHEMA ) {
			return new WP_Error( 'static_site_importer_artifact_bundle_schema_invalid', 'The artifact_bundle input must use schema studio-native/website-artifact-bundle/v1.', array( 'status' => 400 ) );
		}

		$files = isset( $bundle['files'] ) && is_array( $bundle['files'] ) ? $bundle['files'] : array();
		if ( empty( $files ) ) {
			return new WP_Error( 'static_site_importer_artifact_bundle_empty', 'The artifact_bundle input must contain files.', array( 'status' => 400 ) );
		}

		return array(
			'schema'        => Static_Site_Importer_Transformer_Adapter::WEBSITE_ARTIFACT_SCHEMA,
			'artifact_type' => 'website',
			'root'          => isset( $bundle['root'] ) ? (string) $bundle['root'] : 'website',
			'entrypoint'    => isset( $bundle['entrypoint'] ) ? (string) $bundle['entrypoint'] : 'website/index.html',
			'files'         => array_values( array_map( array( self::class, 'website_artifact_file_from_bundle_file' ), $files ) ),
			'provenance'    => isset( $bundle['provenance'] ) && is_array( $bundle['provenance'] ) ? $bundle['provenance'] : array( 'producer' => 'studio-native' ),
		);
	}

	/**
	 * Convert one website artifact file into a bundle file.
	 *
	 * @param mixed $file File entry.
	 * @return array<string,mixed>
	 */
	private static function bundle_file_from_website_artifact_file( $file ): array {
		$file = is_array( $file ) ? $file : array();

		return array_filter(
			array(
				'path'           => isset( $file['path'] ) ? (string) $file['path'] : '',
				'role'           => isset( $file['role'] ) ? (string) $file['role'] : '',
				'kind'           => isset( $file['kind'] ) ? (string) $file['kind'] : '',
				'mime_type'      => isset( $file['mime_type'] ) ? (string) $file['mime_type'] : '',
				'encoding'       => isset( $file['encoding'] ) ? (string) $file['encoding'] : 'utf-8',
				'content'        => isset( $file['content'] ) ? (string) $file['content'] : null,
				'content_base64' => isset( $file['content_base64'] ) ? (string) $file['content_base64'] : null,
				'sha256'         => isset( $file['sha256'] ) ? (string) $file['sha256'] : '',
				'bytes'          => isset( $file['bytes'] ) ? (int) $file['bytes'] : null,
				'size'           => isset( $file['size'] ) ? (int) $file['size'] : null,
				'entrypoint'     => ! empty( $file['entrypoint'] ),
				'provenance'     => isset( $file['provenance'] ) && is_array( $file['provenance'] ) ? $file['provenance'] : array(),
			),
			static fn ( $value ): bool => null !== $value && array() !== $value && '' !== $value
		);
	}

	/**
	 * Convert one bundle file into a website artifact file.
	 *
	 * @param mixed $file File entry.
	 * @return array<string,mixed>
	 */
	private static function website_artifact_file_from_bundle_file( $file ): array {
		$file       = is_array( $file ) ? $file : array();
		$entrypoint = ! empty( $file['entrypoint'] ) || 'entrypoint' === ( $file['role'] ?? '' );

		return array_filter(
			array(
				'path'           => isset( $file['path'] ) ? (string) $file['path'] : '',
				'kind'           => isset( $file['kind'] ) ? (string) $file['kind'] : '',
				'role'           => isset( $file['role'] ) ? (string) $file['role'] : '',
				'mime_type'      => isset( $file['mime_type'] ) ? (string) $file['mime_type'] : '',
				'encoding'       => isset( $file['encoding'] ) ? (string) $file['encoding'] : 'utf-8',
				'entrypoint'     => $entrypoint,
				'content'        => isset( $file['content'] ) ? (string) $file['content'] : null,
				'content_base64' => isset( $file['content_base64'] ) ? (string) $file['content_base64'] : null,
				'provenance'     => isset( $file['provenance'] ) && is_array( $file['provenance'] ) ? $file['provenance'] : array(),
			),
			static fn ( $value ): bool => null !== $value && array() !== $value && '' !== $value
		);
	}
}

<?php
/**
 * Document metadata reporting helpers.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records compiler-routed full-document metadata into the import report.
 */
class Static_Site_Importer_Document_Metadata_Reporter {
	/**
	 * Record compiler-routed full-document metadata and asset references.
	 *
	 * @param array<string,mixed> $report    Conversion report envelope, passed by reference.
	 * @param array<string,mixed> $artifacts WordPress artifacts from Blocks Engine.
	 * @return void
	 */
	public static function record( array &$report, array $artifacts ): void {
		$metadata = isset( $artifacts['document_metadata'] ) && is_array( $artifacts['document_metadata'] ) ? $artifacts['document_metadata'] : array();
		if ( 'blocks-engine/php-transformer/document-metadata/v1' !== (string) ( $metadata['schema'] ?? '' ) ) {
			return;
		}

		$normalized = array(
			'schema'      => 'static-site-importer/document-metadata/v1',
			'source'      => 'blocks-engine/document_metadata',
			'source_path' => isset( $metadata['source_path'] ) && is_scalar( $metadata['source_path'] ) ? (string) $metadata['source_path'] : '',
			'title'       => isset( $metadata['title'] ) && is_scalar( $metadata['title'] ) ? sanitize_text_field( (string) $metadata['title'] ) : '',
			'meta'        => self::normalize_document_metadata_rows( $metadata['meta'] ?? array(), array( 'charset', 'name', 'property', 'http_equiv', 'content' ) ),
			'links'       => self::normalize_document_metadata_rows( $metadata['links'] ?? array(), array( 'rel', 'href', 'as', 'type', 'media', 'crossorigin', 'integrity' ) ),
			'styles'      => self::normalize_hashed_head_assets( $metadata['styles'] ?? array() ),
			'scripts'     => self::normalize_document_scripts( $metadata['scripts'] ?? array() ),
		);

		$report['generated_theme']['document_metadata'] = $normalized;
		$report['diagnostics'][]                        = array(
			'type'         => 'document_metadata_routed',
			'source'       => $normalized['source_path'],
			'severity'     => 'info',
			'stage'        => 'website_artifact_materialization',
			'constraints'  => 'report_only',
			'message'      => 'Full-document metadata/assets were routed through the generated_theme.document_metadata contract instead of generated page block content.',
			'meta_count'   => count( $normalized['meta'] ),
			'link_count'   => count( $normalized['links'] ),
			'style_count'  => count( $normalized['styles'] ),
			'script_count' => count( $normalized['scripts'] ),
		);
	}

	/**
	 * Normalize scalar rows from the document metadata contract.
	 *
	 * @param mixed             $rows    Raw rows.
	 * @param array<int,string> $allowed Allowed keys.
	 * @return array<int,array<string,string>>
	 */
	private static function normalize_document_metadata_rows( mixed $rows, array $allowed ): array {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$item = array();
			foreach ( $allowed as $key ) {
				if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
					$item[ $key ] = sanitize_text_field( (string) $row[ $key ] );
				}
			}

			if ( ! empty( $item ) ) {
				$normalized[] = $item;
			}
		}

		return $normalized;
	}

	/**
	 * Normalize hashed inline head asset summaries.
	 *
	 * @param mixed $rows Raw rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_hashed_head_assets( mixed $rows ): array {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$hash  = isset( $row['hash'] ) && is_scalar( $row['hash'] ) ? preg_replace( '/[^a-f0-9]/i', '', (string) $row['hash'] ) : '';
			$bytes = isset( $row['bytes'] ) ? max( 0, (int) $row['bytes'] ) : 0;
			if ( '' === $hash && 0 === $bytes ) {
				continue;
			}

			$normalized[] = array(
				'bytes' => $bytes,
				'hash'  => $hash,
			);
		}

		return $normalized;
	}

	/**
	 * Normalize script references from the document metadata contract.
	 *
	 * @param mixed $rows Raw rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_document_scripts( mixed $rows ): array {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$item = array();
			foreach ( array( 'src', 'type', 'crossorigin', 'integrity' ) as $key ) {
				if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
					$item[ $key ] = sanitize_text_field( (string) $row[ $key ] );
				}
			}

			if ( isset( $row['placement'] ) && in_array( $row['placement'], array( 'head', 'body' ), true ) ) {
				$item['placement'] = (string) $row['placement'];
			}

			foreach ( array( 'defer', 'async' ) as $key ) {
				if ( isset( $row[ $key ] ) ) {
					$item[ $key ] = (bool) $row[ $key ];
				}
			}

			if ( isset( $row['inline'] ) ) {
				$inline = self::normalize_hashed_head_assets( array( $row['inline'] ) );
				if ( ! empty( $inline ) ) {
					$item['inline'] = $inline[0];
				}
			}

			if ( ! empty( $item ) ) {
				$normalized[] = $item;
			}
		}

		return $normalized;
	}
}

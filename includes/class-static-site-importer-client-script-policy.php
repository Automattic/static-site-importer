<?php
/**
 * Client script trust policy for imported website artifacts.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Client_Script_Policy_Report' ) ) {
	require_once __DIR__ . '/class-static-site-importer-client-script-policy-report.php';
}

/** Applies an explicit, provenance-bound client-script policy before compilation. */
class Static_Site_Importer_Client_Script_Policy {
	/**
	 * Make executable client code inert unless an isolated preview explicitly opts in.
	 *
	 * @return array{artifact:array<string,mixed>,report:array<string,mixed>}
	 */
	public static function apply( array $artifact, array $args ): array {
		$policy     = self::policy_name( $args );
		$provenance = self::provenance( $args );
		$preserve   = 'isolated_preview' === $policy && ! empty( $args['client_script_isolated'] ) && '' !== $provenance;
		$report     = new Static_Site_Importer_Client_Script_Policy_Report(
			$preserve ? 'isolated_preview' : 'inert',
			'untrusted_imported_code',
			$preserve ? $provenance : ''
		);
		$files      = isset( $artifact['files'] ) && is_array( $artifact['files'] ) ? $artifact['files'] : array();
		$filtered   = array();

		foreach ( $files as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}
			$path = isset( $file['path'] ) && is_scalar( $file['path'] ) ? (string) $file['path'] : '';
			if ( self::is_script_file( $file ) ) {
				self::record( $report, $preserve ? 'preserved' : 'dropped', self::file_row( $path, $file ) );
				if ( ! $preserve ) {
					continue;
				}
			}
			if ( self::is_html_file( $file ) ) {
				$content = self::filter_html( self::file_content( $file ), $path, $preserve, $report );
				if ( ! $preserve ) {
					$file = self::with_file_content( $file, $content );
				}
			}
			$filtered[] = $file;
		}

		$artifact['files'] = $filtered;
		return array(
			'artifact' => $artifact,
			'report'   => $report->to_array(),
		);
	}

	private static function policy_name( array $args ): string {
		return 'isolated_preview' === (string) ( $args['client_script_policy'] ?? '' ) ? 'isolated_preview' : 'inert';
	}

	private static function provenance( array $args ): string {
		$provenance = $args['client_script_provenance'] ?? null;
		if ( is_scalar( $provenance ) ) {
			return trim( (string) $provenance );
		}
		if ( is_array( $provenance ) && isset( $provenance['ref'] ) && is_scalar( $provenance['ref'] ) ) {
			return trim( (string) $provenance['ref'] );
		}
		return '';
	}

	private static function is_html_file( array $file ): bool {
		$path = strtolower( (string) ( $file['path'] ?? '' ) );
		$mime = strtolower( (string) ( $file['mime_type'] ?? '' ) );
		return str_ends_with( $path, '.html' ) || str_ends_with( $path, '.htm' ) || str_contains( $mime, 'html' );
	}

	private static function is_script_file( array $file ): bool {
		$path = strtolower( (string) ( $file['path'] ?? '' ) );
		$mime = strtolower( (string) ( $file['mime_type'] ?? '' ) );
		return (bool) preg_match( '/\.(?:js|mjs|cjs)$/', $path ) || str_contains( $mime, 'javascript' ) || str_contains( $mime, 'ecmascript' );
	}

	private static function filter_html( string $html, string $path, bool $preserve, Static_Site_Importer_Client_Script_Policy_Report $report ): string {
		$html = (string) preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script\s*>#is',
			static function ( array $matches ) use ( $path, $preserve, $report ): string {
				$attributes = $matches[1];
				$source     = self::attribute( $attributes, 'src' );
				$type       = strtolower( trim( (string) self::attribute( $attributes, 'type' ) ) );
				$row        = array(
					'path'   => $path,
					'class'  => self::script_class( $source, $type, $matches[2] ),
					'type'   => '' !== $type ? $type : 'classic',
					'sha256' => hash( 'sha256', $matches[0] ),
				);
				if ( null !== $source ) {
					$row['src'] = $source;
				}
				if ( $preserve ) {
					self::record( $report, 'preserved', $row );
					return $matches[0];
				}
				self::record( $report, 'data' === $row['class'] ? 'quarantined' : 'dropped', $row );
				return '';
			},
			$html
		);
		return (string) preg_replace_callback(
			'#<link\b([^>]*)/?>#is',
			static function ( array $matches ) use ( $path, $preserve, $report ): string {
				$attributes = $matches[1];
				$relation   = strtolower( trim( (string) self::attribute( $attributes, 'rel' ) ) );
				$as         = strtolower( trim( (string) self::attribute( $attributes, 'as' ) ) );
				$relations  = preg_split( '/\s+/', $relation );
				$relations  = false === $relations ? array() : $relations;
				$script     = in_array( 'modulepreload', $relations, true ) || ( in_array( 'preload', $relations, true ) && 'script' === $as );
				if ( ! $script ) {
					return $matches[0];
				}
				$row = array(
					'path'   => $path,
					'class'  => 'preload',
					'type'   => 'modulepreload' === $relation ? 'modulepreload' : 'preload',
					'href'   => (string) self::attribute( $attributes, 'href' ),
					'sha256' => hash( 'sha256', $matches[0] ),
				);
				self::record( $report, $preserve ? 'preserved' : 'dropped', $row );
				return $preserve ? $matches[0] : '';
			},
			$html
		);
	}

	private static function attribute( string $attributes, string $name ): ?string {
		if ( ! preg_match( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attributes, $matches ) ) {
			return null;
		}
		if ( '' !== $matches[1] ) {
			return $matches[1];
		}
		if ( isset( $matches[2] ) && '' !== $matches[2] ) {
			return $matches[2];
		}
		return isset( $matches[3] ) ? $matches[3] : '';
	}

	private static function script_class( ?string $source, string $type, string $content ): string {
		if ( in_array( $type, array( 'application/json', 'application/ld+json', 'application/manifest+json' ), true ) || ( null !== $source && str_starts_with( strtolower( $source ), 'data:' ) ) ) {
			return 'data';
		}
		if ( 'module' === $type ) {
			return 'module';
		}
		if ( preg_match( '/(?:google-analytics|googletagmanager|gtag\s*\(|segment\.|mixpanel|hotjar|clarity|sentry|telemetry|analytics)/i', (string) $source . "\n" . $content ) ) {
			return 'telemetry';
		}
		if ( null === $source ) {
			return 'inline';
		}
		return preg_match( '#^(?:https?:)?//#i', $source ) ? 'remote' : 'local';
	}

	private static function file_row( string $path, array $file ): array {
		return array(
			'path'   => $path,
			'class'  => 'local',
			'type'   => 'asset',
			'sha256' => hash( 'sha256', self::file_content( $file ) ),
		);
	}

	private static function file_content( array $file ): string {
		if ( isset( $file['content'] ) && is_scalar( $file['content'] ) ) {
			return (string) $file['content'];
		}
		if ( isset( $file['content_base64'] ) && is_scalar( $file['content_base64'] ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes declared artifact content before applying the script policy.
			$decoded = base64_decode( (string) $file['content_base64'], true );
			return false === $decoded ? '' : $decoded;
		}
		return '';
	}

	private static function with_file_content( array $file, string $content ): array {
		if ( array_key_exists( 'content_base64', $file ) && ! array_key_exists( 'content', $file ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Restores filtered declared artifact content to its original representation.
			$file['content_base64'] = base64_encode( $content );
			return $file;
		}
		$file['content'] = $content;
		unset( $file['content_base64'] );
		return $file;
	}

	private static function record( Static_Site_Importer_Client_Script_Policy_Report $report, string $disposition, array $row ): void {
		$report->record( $disposition, $row );
	}
}

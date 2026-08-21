<?php
/** Immutable shared-resource plan storage for resumable artifact runs. @package StaticSiteImporter */
if ( ! class_exists( 'Static_Site_Importer_Content_Policy' ) ) {
	require_once __DIR__ . '/class-static-site-importer-content-policy.php';
}
if ( ! class_exists( 'Static_Site_Importer_URL_Fetcher' ) ) {
	require_once __DIR__ . '/class-static-site-importer-url-fetcher.php';
}
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retains source-agnostic, non-page artifact resources shared by a site run.
 * Prepared batches can only be reused when they name this verified digest.
 */
final class Static_Site_Importer_Shared_Resource_Plan {
	private const SCHEMA = 'static-site-importer/shared-resource-plan/v1';
	private Static_Site_Importer_Artifact_Run_Workspace $workspace;

	public function __construct( Static_Site_Importer_Artifact_Run_Workspace $workspace ) {
		$this->workspace = $workspace;
	}

	/** @return array<string,mixed>|null */
	public function load(): ?array {
		$raw  = $this->workspace->read_raw( 'shared-resource-plan.json' );
		$plan = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $plan ) || self::SCHEMA !== ( $plan['schema'] ?? null ) || ! is_array( $plan['resources'] ?? null ) ) {
			return null;
		}
		return hash_equals( (string) ( $plan['digest'] ?? '' ), self::digest( $plan['resources'] ) ) ? $plan : null;
	}

	/** @return array<string,string> Source URL to retained artifact path. */
	public function source_paths(): array {
		$paths = array();
		foreach ( $this->retained_resources() as $resource ) {
			$paths[ $resource['source_url'] ] = $resource['path'];
		}
		ksort( $paths, SORT_STRING );
		return $paths;
	}

	/**
	 * Return only retained resources that can be materialized from this workspace.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function retained_resources(): array {
		$resources = array();
		foreach ( $this->load()['resources'] ?? array() as $resource ) {
			if ( ! is_array( $resource ) || ! $this->materializable_resource( $resource ) ) {
				continue;
			}
			$resource['source_url']               = self::canonical_url( $resource['source_url'] );
			$resources[ $resource['source_url'] ] = $resource;
		}
		ksort( $resources, SORT_STRING );
		return array_values( $resources );
	}

	/** @return array<string,mixed>|WP_Error */
	public function establish( array $artifact, ?array $paths = null ): array|WP_Error {
		$resources = self::resources( $artifact, $paths );
		return $this->store( $resources );
	}

	/** @return array<string,mixed>|WP_Error */
	private function store( array $resources ): array|WP_Error {
		$plan   = array(
			'schema'     => self::SCHEMA,
			'digest'     => self::digest( $resources ),
			'resources'  => $resources,
			'verified'   => true,
			'created_at' => gmdate( 'c' ),
		);
		$stored = $this->workspace->publish_json( 'shared-resource-plan.json', $plan );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		$verified = $this->load();
		return is_array( $verified ) && $verified['digest'] === $plan['digest'] ? $verified : new WP_Error( 'static_site_importer_shared_plan_verification_failed', 'The retained shared resource plan could not be verified.' );
	}

	/** @return array{digest:string,changed:bool,plan:array<string,mixed>|WP_Error} */
	public function reconcile( array $artifact ): array {
		$existing = $this->load();
		if ( ! is_array( $existing ) ) {
			$plan = $this->establish( $artifact );
			return array(
				'digest'  => is_array( $plan ) ? $plan['digest'] : '',
				'changed' => false,
				'plan'    => $plan,
			);
		}
		$current = self::merge_resources( $existing['resources'], self::resources( $artifact ) );
		$digest  = self::digest( $current );
		if ( hash_equals( $existing['digest'], $digest ) ) {
			return array(
				'digest'  => $digest,
				'changed' => false,
				'plan'    => $existing,
			);
		}
		$plan = $this->store( $current );
		return array(
			'digest'  => is_array( $plan ) ? $plan['digest'] : '',
			'changed' => true,
			'plan'    => $plan,
		);
	}

	/** @return array<int,array<string,mixed>> */
	private static function resources( array $artifact, ?array $paths = null ): array {
		$resources = array();
		foreach ( $artifact['files'] ?? array() as $file ) {
			if ( ! is_array( $file ) || 'text/html' === strtolower( (string) ( $file['mime_type'] ?? '' ) ) ) {
				continue;
			}
			$path = (string) ( $file['path'] ?? '' );
			if ( '' === $path || ( null !== $paths && ! in_array( $path, $paths, true ) ) ) {
				continue;
			}
			$resource = array_filter(
				array(
					'path'              => $path,
					'source_url'        => self::canonical_url( (string) ( $file['source_url'] ?? '' ) ),
					'mime_type'         => (string) ( $file['mime_type'] ?? '' ),
					'content'           => $file['content'] ?? null,
					'content_base64'    => $file['content_base64'] ?? null,
					'payload_reference' => $file['payload_reference'] ?? null,
				),
				static fn( $value ): bool => null !== $value
			);
			if ( self::structurally_materializable( $resource ) ) {
				$resources[] = $resource;
			}
		}
		usort( $resources, static fn( array $left, array $right ): int => strcmp( $left['path'], $right['path'] ) );
		return $resources;
	}

	/** @return array<int,array<string,mixed>> */
	private static function merge_resources( array $existing, array $current ): array {
		$resources = array();
		foreach ( array_merge( $existing, $current ) as $resource ) {
			if ( is_array( $resource ) && '' !== (string) ( $resource['path'] ?? '' ) ) {
				$resources[ $resource['path'] ] = $resource;
			}
		}
		ksort( $resources, SORT_STRING );
		return array_values( $resources );
	}

	private static function digest( array $resources ): string {
		$json = wp_json_encode( $resources, JSON_UNESCAPED_SLASHES );
		return hash( 'sha256', false === $json ? '' : $json );
	}

	/** @param array<string,mixed> $resource */
	private function materializable_resource( array $resource ): bool {
		if ( ! self::structurally_materializable( $resource ) ) {
			return false;
		}
		$reference = $resource['payload_reference'] ?? null;
		if ( ! is_array( $reference ) ) {
			return true;
		}
		$bytes = $this->workspace->read_raw( $reference['id'] );
		return is_string( $bytes )
			&& ( ! isset( $reference['bytes'] ) || strlen( $bytes ) === $reference['bytes'] )
			&& hash_equals( $reference['sha256'], hash( 'sha256', $bytes ) );
	}

	/** @param array<string,mixed> $resource */
	private static function structurally_materializable( array $resource ): bool {
		if ( '' === self::canonical_url( (string) ( $resource['source_url'] ?? '' ) ) || ! Static_Site_Importer_Content_Policy::is_static_path( (string) ( $resource['path'] ?? '' ) ) ) {
			return false;
		}
		$has_content = isset( $resource['content'] ) && is_string( $resource['content'] );
		$has_base64  = isset( $resource['content_base64'] ) && is_string( $resource['content_base64'] ) && false !== base64_decode( $resource['content_base64'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Validates retained binary payload bytes.
		$reference   = $resource['payload_reference'] ?? null;
		$has_ref     = is_array( $reference )
			&& 'blocks-engine/payload-reference/v1' === ( $reference['schema'] ?? null )
			&& is_string( $reference['id'] ?? null ) && '' !== $reference['id']
			&& is_string( $reference['sha256'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $reference['sha256'] )
			&& ( ! isset( $reference['bytes'] ) || ( is_int( $reference['bytes'] ) && $reference['bytes'] >= 0 ) );
		return 1 === count( array_filter( array( $has_content, $has_base64, $has_ref ) ) );
	}

	private static function canonical_url( string $url ): string {
		$url    = Static_Site_Importer_URL_Fetcher::normalize_url( trim( $url ) );
		$parts  = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standalone plan smoke tests run without WordPress URL helpers.
		$scheme = is_array( $parts ) ? strtolower( (string) ( $parts['scheme'] ?? '' ) ) : '';
		if ( ! is_array( $parts ) || ! in_array( $scheme, array( 'http', 'https' ), true ) || empty( $parts['host'] ) ) {
			return '';
		}
		$segments = array();
		$path     = (string) ( $parts['path'] ?? '/' );
		foreach ( explode( '/', '/' . ltrim( $path, '/' ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}
			$segments[] = $segment;
		}
		$path  = '/' . implode( '/', $segments );
		$path  = str_ends_with( (string) ( $parts['path'] ?? '/' ), '/' ) && '/' !== $path ? $path . '/' : $path;
		$port  = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$query = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';
		return $scheme . '://' . strtolower( (string) $parts['host'] ) . $port . $path . $query;
	}
}

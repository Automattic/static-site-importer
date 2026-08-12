<?php
/** Immutable shared-resource plan storage for resumable artifact runs. @package StaticSiteImporter */
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

	/** @return array<string,mixed>|WP_Error */
	public function establish( array $artifact, ?array $paths = null ): array|WP_Error {
		$resources = self::resources( $artifact, $paths );
		return $this->store( $resources );
	}

	/** @return array<string,mixed>|WP_Error */
	private function store( array $resources ): array|WP_Error {
		$plan      = array(
			'schema'     => self::SCHEMA,
			'digest'     => self::digest( $resources ),
			'resources'  => $resources,
			'verified'   => true,
			'created_at' => gmdate( 'c' ),
		);
		$stored    = $this->workspace->publish_json( 'shared-resource-plan.json', $plan );
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
			$resources[] = array_filter(
				array(
					'path'           => $path,
					'mime_type'      => (string) ( $file['mime_type'] ?? '' ),
					'content'        => $file['content'] ?? null,
					'content_base64' => $file['content_base64'] ?? null,
				),
				static fn( $value ): bool => null !== $value
			);
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
}

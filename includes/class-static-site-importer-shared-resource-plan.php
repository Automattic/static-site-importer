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
	private const SCHEMA = 'static-site-importer/shared-resource-plan/v2';
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
		$resources = $this->externalize( self::resources( $artifact, $paths ) );
		if ( is_wp_error( $resources ) ) {
			return $resources;
		}
		return $this->store( $resources );
	}

	/** @param array<int,array<string,mixed>> $resources @return array<string,mixed>|WP_Error */
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
		$resources = array_column( $existing['resources'], null, 'path' );
		$incoming = $this->externalize( self::resources( $artifact ) );
		if ( is_wp_error( $incoming ) ) {
			return array( 'digest' => '', 'changed' => false, 'plan' => $incoming );
		}
		foreach ( $incoming as $resource ) {
			$resources[ $resource['path'] ] = $resource;
		}
		$current = array_values( $resources );
		usort( $current, static fn( array $left, array $right ): int => strcmp( $left['path'], $right['path'] ) );
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

	/** @return array<int,array<string,mixed>>|WP_Error */
	public function hydrate( array $plan ): array|WP_Error {
		$resources = array();
		foreach ( $plan['resources'] ?? array() as $resource ) {
			$payload = $this->workspace->read_raw( (string) ( $resource['payload_ref'] ?? '' ) );
			if ( ! is_string( $payload ) || ! hash_equals( (string) ( $resource['payload_sha256'] ?? '' ), hash( 'sha256', $payload ) ) ) {
				return new WP_Error( 'static_site_importer_shared_payload_verification_failed', 'A retained shared resource payload could not be verified.' );
			}
			$encoding = (string) ( $resource['payload_encoding'] ?? '' );
			if ( ! in_array( $encoding, array( 'content', 'content_base64' ), true ) ) {
				return new WP_Error( 'static_site_importer_shared_payload_encoding_invalid', 'A retained shared resource payload has an invalid encoding.' );
			}
			$resources[] = array(
				'path'      => (string) $resource['path'],
				'mime_type' => (string) $resource['mime_type'],
				$encoding   => $payload,
			);
		}
		return $resources;
	}

	/** @param array<int,array<string,mixed>> $resources @return array<int,array<string,mixed>>|WP_Error */
	private function externalize( array $resources ): array|WP_Error {
		$descriptors = array();
		foreach ( $resources as $resource ) {
			$encoding = array_key_exists( 'content_base64', $resource ) ? 'content_base64' : 'content';
			$payload  = (string) ( $resource[ $encoding ] ?? '' );
			$hash     = hash( 'sha256', $payload );
			$ref      = 'shared-resources/' . $hash . '.payload';
			if ( $this->workspace->read_raw( $ref ) !== $payload ) {
				$stored = $this->workspace->publish_raw( $ref, $payload );
				if ( is_wp_error( $stored ) ) {
					return $stored;
				}
			}
			$descriptors[] = array(
				'path'             => (string) $resource['path'],
				'mime_type'        => (string) $resource['mime_type'],
				'payload_encoding' => $encoding,
				'payload_bytes'    => strlen( $payload ),
				'payload_sha256'   => $hash,
				'payload_ref'      => $ref,
			);
		}
		return $descriptors;
	}

	/** @return array<int,array<string,mixed>> */
	private static function resources( array $artifact, ?array $paths = null ): array {
		$resources = array();
		foreach ( $artifact['files'] ?? array() as $file ) {
			if ( ! is_array( $file ) || 'text/html' === strtolower( (string) ( $file['mime_type'] ?? '' ) ) ) {
				continue;
			}
			if ( 'page' === ( $file['metadata']['compilation']['scope'] ?? null ) ) {
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

	private static function digest( array $resources ): string {
		$json = wp_json_encode( $resources, JSON_UNESCAPED_SLASHES );
		return hash( 'sha256', false === $json ? '' : $json );
	}
}

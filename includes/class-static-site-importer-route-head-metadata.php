<?php
/**
 * Materializes route descriptions into native owners; projects native head data.
 *
 * @package StaticSiteImporter
 */

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\AssetReferenceCanonicalizer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( WordPressSitePlan::class ) && is_readable( dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-php-transformer/src/WordPressSitePlan/WordPressSitePlan.php' ) ) {
	require_once dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-php-transformer/src/WordPressSitePlan/WordPressSitePlan.php';
}
if ( ! class_exists( AssetReferenceCanonicalizer::class ) && is_readable( dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-php-transformer/src/WordPressSitePlan/AssetReferenceCanonicalizer.php' ) ) {
	require_once dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-php-transformer/src/WordPressSitePlan/AssetReferenceCanonicalizer.php';
}
if ( ! class_exists( WordPressSitePlanResolver::class ) && is_readable( dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-php-transformer/src/WordPressSitePlan/WordPressSitePlanResolver.php' ) ) {
	require_once dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-php-transformer/src/WordPressSitePlan/WordPressSitePlanResolver.php';
}

final class Static_Site_Importer_Route_Head_Metadata {
	private const TEXT_LIMIT = 1000;
	private const URL_LIMIT  = 2048;

	/** @var array<string,true> */
	private const URL_KEYS = array(
		'og:image'            => true,
		'og:image:secure_url' => true,
		'og:image:url'        => true,
		'twitter:image'       => true,
		'twitter:image:src'   => true,
	);

	/** @param array<string,mixed> $page */
	public static function description_from_page( array $page ): string {
		$tags = self::from_page( $page );
		foreach ( array( 'description', 'og:description', 'twitter:description' ) as $key ) {
			foreach ( $tags as $tag ) {
				if ( $key === $tag['key'] ) {
					return $tag['content'];
				}
			}
		}
		return '';
	}

	/**
	 * Write registered platform SEO owners, alongside the native post fields.
	 * Jetpack consumes the featured image itself; never copy a source image URL
	 * into an SEO override. Unknown plugin storage is not guessed. A route
	 * description with no registered SEO owner stays in post_excerpt, which
	 * core does not render in the head, so the receipt names it as a loss.
	 *
	 * @param array<string,mixed> $state
	 * @return true|WP_Error
	 */
	public static function materialize_platform_fields( array &$state ) {
		if ( ! function_exists( 'get_registered_meta_keys' ) ) {
			return true;
		}
		$unrendered = array();
		foreach ( $state['ordered_pages'] ?? array() as $page ) {
			if ( ! empty( $page['skip_materialization'] ) ) {
				continue;
			}
			$id = (int) ( $state['source_ids'][ $page['source_path'] ] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			$registered  = array_merge( get_registered_meta_keys( 'post' ), get_registered_meta_keys( 'post', (string) get_post_type( $id ) ) );
			$description = self::description_from_page( $page );
			if ( '' !== $description && ! isset( $registered['advanced_seo_description'] ) ) {
				$unrendered[] = (string) $page['source_path'];
			}
			$values = array(
				'jetpack_seo_html_title'   => Static_Site_Importer_Route_Document_Metadata::title_from_page( $page ),
				'advanced_seo_description' => '' !== $description ? $description : (string) ( $page['metadata']['excerpt'] ?? '' ),
			);
			foreach ( $values as $key => $value ) {
				if ( ! isset( $registered[ $key ] ) || '' === $value ) {
					continue;
				}
				$state['rollback']['route_seo'][ $id ][ $key ] = get_post_meta( $id, $key, false );
				if ( ! Static_Site_Importer_Site_Plan_Persistence::write_post_meta( $id, $key, $value ) ) {
					return new WP_Error( 'route_seo_write_failed', 'The registered SEO owner rejected authored metadata.' );
				}
			}
		}
		if ( array() !== $unrendered ) {
			$state['diagnostics'][] = array(
				'code'         => 'static_site_importer_route_description_unrendered',
				'type'         => 'static-site-importer',
				'severity'     => 'warning',
				'reason_code'  => 'route_description_unrendered',
				'constraints'  => 'owner_handoff',
				'message'      => 'Route descriptions are kept as post excerpts, but no registered SEO owner renders them as a meta description.',
				'source_paths' => $unrendered,
			);
		}
		return true;
	}

	/**
	 * Extract descriptive head tags from a resolved plan page.
	 *
	 * @param array<string,mixed> $page
	 * @param array<string,mixed> $resolved_plan
	 * @return array<int,array{attr:string,key:string,content:string,kind:string}>
	 */
	public static function from_page( array $page, array $resolved_plan = array() ): array {
		$metadata = is_array( $page['document_metadata'] ?? null ) ? $page['document_metadata'] : array();
		$rows     = is_array( $metadata['meta'] ?? null ) ? $metadata['meta'] : array();
		$tags     = array();
		$seen     = array();
		foreach ( $rows as $row ) {
			$tag = self::normalize_tag( is_array( $row ) ? $row : array(), $page, $resolved_plan );
			if ( null === $tag ) {
				continue;
			}
			$identity = $tag['attr'] . "\n" . $tag['key'];
			if ( isset( $seen[ $identity ] ) ) {
				continue;
			}
			$seen[ $identity ] = true;
			$tags[]            = $tag;
		}

		return $tags;
	}

	/**
	 * Reword compiler "not representable in block markup" noise once the tags are persisted.
	 *
	 * @param array<int,mixed>    $diagnostics
	 * @param array<string,mixed> $receipt
	 * @return array<int,mixed>
	 */
	public static function reword_handled_diagnostics( array $diagnostics, array $receipt ): array {
		if ( 'completed' !== ( $receipt['status'] ?? '' ) ) {
			return $diagnostics;
		}

		$handled = array();
		foreach ( $receipt['plan']['pages'] ?? array() as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			if ( array() !== self::from_page( $page, is_array( $receipt['plan'] ?? null ) ? $receipt['plan'] : array() ) ) {
				$handled[ (string) ( $page['source_path'] ?? '' ) ] = true;
			}
		}
		if ( array() === $handled ) {
			return $diagnostics;
		}

		foreach ( $diagnostics as &$diagnostic ) {
			if ( ! is_array( $diagnostic ) ) {
				continue;
			}
			if ( self::is_named_head_metadata_loss( $diagnostic ) ) {
				$diagnostic = self::persisted_diagnostic( $diagnostic );
				continue;
			}
			if ( 'compiler_diagnostics_aggregated' !== ( $diagnostic['code'] ?? '' ) || ! is_array( $diagnostic['context']['samples'] ?? null ) ) {
				continue;
			}
			foreach ( $diagnostic['context']['samples'] as &$sample ) {
				if ( is_array( $sample ) && self::is_named_head_metadata_loss( $sample ) ) {
					$sample = self::persisted_diagnostic( $sample );
				}
			}
			unset( $sample );
		}
		unset( $diagnostic );

		return $diagnostics;
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $page
	 * @param array<string,mixed> $resolved_plan
	 * @return array{attr:string,key:string,content:string,kind:string}|null
	 */
	private static function normalize_tag( array $row, array $page, array $resolved_plan ): ?array {
		$attr = isset( $row['property'] ) && is_scalar( $row['property'] ) && '' !== trim( (string) $row['property'] )
			? 'property'
			: ( isset( $row['name'] ) && is_scalar( $row['name'] ) && '' !== trim( (string) $row['name'] ) ? 'name' : '' );
		$key  = 'property' === $attr
			? strtolower( trim( (string) $row['property'] ) )
			: ( 'name' === $attr ? strtolower( trim( (string) $row['name'] ) ) : '' );
		$kind = self::classify( $attr, $key );
		if ( null === $kind ) {
			return null;
		}

		$raw = isset( $row['content'] ) && is_scalar( $row['content'] ) ? (string) $row['content'] : '';
		if ( 'url' === $kind ) {
			$content = self::resolve_url_content( $raw, $row, $page, $resolved_plan );
		} else {
			$content = self::normalize_text( $raw );
		}
		if ( '' === $content ) {
			return null;
		}

		return array(
			'attr'    => $attr,
			'key'     => $key,
			'content' => $content,
			'kind'    => $kind,
		);
	}

	private static function classify( string $attr, string $key ): ?string {
		if ( '' === $attr || '' === $key ) {
			return null;
		}
		if ( isset( self::URL_KEYS[ $key ] ) ) {
			return 'url';
		}
		if ( in_array( $key, array( 'description', 'og:description', 'twitter:description' ), true ) ) {
			return 'text';
		}

		return null;
	}

	private static function normalize_text( mixed $content ): string {
		if ( ! is_scalar( $content ) ) {
			return '';
		}
		$decoded = html_entity_decode( (string) $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Standalone materializer smoke tests run without WordPress tag helpers.
		$text = trim( function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $decoded ) : strip_tags( $decoded ) );

		return self::TEXT_LIMIT >= strlen( $text ) ? $text : '';
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $page
	 * @param array<string,mixed> $resolved_plan
	 */
	private static function resolve_url_content( string $content, array $row, array $page, array $resolved_plan ): string {
		foreach ( array( $row['resolved_url'] ?? null, $row['asset_reference'] ?? null, $content ) as $candidate ) {
			if ( ! is_string( $candidate ) || '' === trim( $candidate ) ) {
				continue;
			}
			$resolved = self::absolute_http_url( self::resolve_reference( trim( $candidate ), $page, $resolved_plan ) );
			if ( '' !== $resolved ) {
				return $resolved;
			}
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $page
	 * @param array<string,mixed> $resolved_plan
	 */
	private static function resolve_reference( string $content, array $page, array $resolved_plan ): string {
		$tokens    = is_array( $resolved_plan['reference_tokens'] ?? null ) ? $resolved_plan['reference_tokens'] : array();
		$theme_uri = is_string( $resolved_plan['resolution']['theme_uri'] ?? null ) ? $resolved_plan['resolution']['theme_uri'] : '';
		if ( array() === $tokens || '' === $theme_uri || ! class_exists( AssetReferenceCanonicalizer::class ) || ! class_exists( WordPressSitePlanResolver::class ) ) {
			return self::absolute_http_url( $content );
		}

		$prefix = class_exists( WordPressSitePlan::class ) ? WordPressSitePlan::TOKEN_PREFIX : '{{wordpress-site-plan:asset:';
		try {
			$references    = WordPressSitePlanResolver::references( $tokens, $theme_uri );
			$origin        = is_string( $page['source_path'] ?? null ) ? $page['source_path'] : '';
			$canonicalizer = new AssetReferenceCanonicalizer( $tokens, self::site_root( $resolved_plan ) );
			foreach ( self::reference_candidates( $content ) as $candidate ) {
				if ( str_contains( $candidate, $prefix ) ) {
					$resolved = WordPressSitePlanResolver::resolvePayload( $candidate, $references );
					if ( self::is_absolute_http_url( $resolved ) ) {
						return $resolved;
					}
					continue;
				}
				foreach ( array( $origin, '' ) as $from ) {
					$token = $canonicalizer->reference( $candidate, $from );
					if ( ! is_string( $token ) ) {
						continue;
					}
					$resolved = WordPressSitePlanResolver::resolvePayload( $token, $references );
					if ( self::is_absolute_http_url( $resolved ) ) {
						return $resolved;
					}
				}
			}
		} catch ( InvalidArgumentException $error ) {
			unset( $error );
		}

		return self::absolute_http_url( $content );
	}

	/** @return array<int,string> */
	private static function reference_candidates( string $content ): array {
		$candidates = array( $content );
		if ( preg_match( '~^([^?#]+?\.(?:avif|gif|jpe?g|png|svg|webp))(/[^?#]*)([?#].*)?$~i', $content, $parts ) ) {
			$truncated = $parts[1] . ( $parts[3] ?? '' );
			if ( $truncated !== $content ) {
				$candidates[] = $truncated;
			}
		}

		return $candidates;
	}

	/** @param array<string,mixed> $plan */
	private static function site_root( array $plan ): string {
		foreach ( $plan['pages'] ?? array() as $page ) {
			if ( is_array( $page ) && ! empty( $page['entrypoint'] ) && is_string( $page['source_path'] ?? null ) ) {
				$origin = $page['source_path'];
				return '' === $origin || '.' === dirname( $origin ) ? '' : trim( dirname( $origin ), '/' );
			}
		}

		return '';
	}

	private static function absolute_http_url( string $content ): string {
		$content = trim( $content );
		if ( '' === $content || self::URL_LIMIT < strlen( $content ) || ! self::is_absolute_http_url( $content ) ) {
			return '';
		}

		return $content;
	}

	private static function is_absolute_http_url( string $content ): bool {
		return 1 === preg_match( '#^https?://#i', $content );
	}

	/** @param array<string,mixed> $diagnostic */
	private static function persisted_diagnostic( array $diagnostic ): array {
		$diagnostic['message']       = 'Route title, description and social image are materialized into native WordPress fields; other source social tags are not replayed.';
		$diagnostic['constraints']   = 'report_only';
		$diagnostic['severity']      = 'info';
		$diagnostic['code']          = 'named_head_metadata_persisted';
		$diagnostic['reason_code']   = 'named_head_metadata_persisted';
		$diagnostic['repair_bucket'] = 'no_repair_needed';

		return $diagnostic;
	}

	/** @param array<string,mixed> $diagnostic */
	private static function is_named_head_metadata_loss( array $diagnostic ): bool {
		$code    = strtolower( (string) ( $diagnostic['code'] ?? $diagnostic['reason_code'] ?? $diagnostic['type'] ?? '' ) );
		$message = strtolower( (string) ( $diagnostic['message'] ?? $diagnostic['reason'] ?? '' ) );
		if ( str_contains( $code, 'named_head_metadata' ) || 'html_head_metadata_not_carried' === $code ) {
			return true;
		}

		return str_contains( $message, 'not representable in block markup' )
			&& ( str_contains( $message, 'named head metadata' ) || str_contains( $message, 'meta description' ) || str_contains( $message, 'social property' ) );
	}
}

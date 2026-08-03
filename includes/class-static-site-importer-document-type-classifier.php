<?php
/**
 * Post-vs-page classification for imported site plan documents.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classifies plan page rows as WordPress posts or pages.
 *
 * A document is a post when it carries an article/temporal signal that
 * survives the Blocks Engine plan projection: a dated head meta tag or a
 * hierarchical date URL (YYYY/MM). The site entrypoint is always a page;
 * everything else without date evidence stays a page to preserve existing
 * import behavior.
 */
final class Static_Site_Importer_Document_Type_Classifier {

	/** Head meta keys that carry a publish date. */
	private const PUBLISH_DATE_META_KEYS = array(
		'article:published_time',
		'article:published',
		'pubdate',
		'publishdate',
		'date',
		'dc.date.issued',
		'dc.date',
		'parsely-pub-date',
		'releasedate',
	);

	/**
	 * Classify one plan page row.
	 *
	 * @param array<string,mixed> $page One row from the resolved plan pages.
	 * @return array{post_type:string,date:?string,signal:string}
	 */
	public static function classify( array $page ): array {
		if ( ! empty( $page['entrypoint'] ) ) {
			return self::result( 'page', null, 'page_default' );
		}

		// The Blocks Engine producer declares post_type on the plan page row.
		// The compiler defaults it to 'page' for every HTML document, so only
		// a non-page value counts as a deliberate producer decision and wins
		// over this consumer-side detection. `metadata.post_type` mirrors the
		// row field; read both so hand-built plans stay supported.
		$declared = sanitize_key( (string) ( $page['post_type'] ?? $page['metadata']['post_type'] ?? '' ) );
		if ( '' !== $declared && 'page' !== $declared && self::post_type_registered( $declared ) ) {
			return self::result( $declared, self::publish_date( $page ), 'producer_declared' );
		}

		$date  = self::publish_date( $page );
		$route = (string) ( $page['route']['path'] ?? '' );
		if ( null !== $date ) {
			return self::result( 'post', $date, 'dated_meta' );
		}
		if ( self::route_is_dated_hierarchy( $route ) ) {
			return self::result( 'post', null, 'dated_route' );
		}

		// No date evidence and no /YYYY/MM/ URL: stay a page. This preserves
		// about / contact / nav-linked sources untouched.
		return self::result( 'page', null, 'page_default' );
	}

	/**
	 * Build the classification result.
	 *
	 * @param string      $post_type Classified post type.
	 * @param string|null $date      Detected publish date, if any.
	 * @param string      $signal    Signal that drove the classification.
	 * @return array{post_type:string,date:?string,signal:string}
	 */
	private static function result( string $post_type, ?string $date, string $signal ): array {
		return array(
			'post_type' => $post_type,
			'date'      => $date,
			'signal'    => $signal,
		);
	}

	/**
	 * Detect a parseable publish date from the page head meta.
	 *
	 * @param array<string,mixed> $page Plan page row.
	 * @return string|null MySQL UTC datetime, or null when no dated meta parses.
	 */
	private static function publish_date( array $page ): ?string {
		$metadata_date = isset( $page['metadata']['date'] ) ? self::normalize_date( (string) $page['metadata']['date'] ) : null;
		if ( null !== $metadata_date ) {
			return $metadata_date;
		}
		$meta = isset( $page['document_metadata']['meta'] ) && is_array( $page['document_metadata']['meta'] ) ? $page['document_metadata']['meta'] : array();
		foreach ( $meta as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key = (string) ( $row['name'] ?? $row['property'] ?? $row['http_equiv'] ?? '' );
			if ( ! in_array( strtolower( $key ), self::PUBLISH_DATE_META_KEYS, true ) ) {
				continue;
			}
			$timestamp = strtotime( (string) ( $row['content'] ?? '' ) );
			if ( false === $timestamp ) {
				continue;
			}
			return gmdate( 'Y-m-d H:i:s', $timestamp );
		}
		return null;
	}

	/**
	 * Normalize a date string into MySQL UTC datetime or null.
	 *
	 * @param string $value Raw date string.
	 * @return string|null
	 */
	private static function normalize_date( string $value ): ?string {
		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Whether the route path follows a hierarchical date URL (YYYY/MM).
	 *
	 * @param string $route Route path.
	 * @return bool
	 */
	private static function route_is_dated_hierarchy( string $route ): bool {
		return 1 === preg_match( '#(?:^|/)\d{4}/(?:0?[1-9]|1[0-2])(?:/|$)#', $route );
	}

	/**
	 * Whether a post type is registered on the runtime.
	 *
	 * Falls back to the built-in types in standalone tests where WP is not
	 * fully bootstrapped.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	private static function post_type_registered( string $post_type ): bool {
		if ( function_exists( 'get_post_type_object' ) ) {
			return null !== get_post_type_object( $post_type );
		}
		return in_array( $post_type, array( 'page', 'post' ), true );
	}
}

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
 * Producer `content_decision` and a non-page declared `post_type` win.
 * Consumer dated-meta / dated-route inference runs only when the producer
 * defaulted the row to page (or omitted a decision). The site entrypoint
 * is always a page.
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

		$decision = self::content_decision( $page );
		if ( is_array( $decision ) && in_array( $decision['state'], array( 'declared', 'inferred' ), true ) ) {
			$post_type = sanitize_key( (string) ( $decision['post_type'] ?? '' ) );
			if ( '' !== $post_type && self::post_type_registered( $post_type ) ) {
				$signal = 'declared' === $decision['state'] ? 'producer_declared' : 'producer_inferred';
				return self::result( $post_type, self::producer_date( $page ), $signal );
			}
		}

		// Hand-built plans and older rows may omit content_decision. A non-page
		// post_type on the row or metadata is still a deliberate producer fact.
		$declared = sanitize_key( (string) ( $page['post_type'] ?? $page['metadata']['post_type'] ?? '' ) );
		if ( '' !== $declared && 'page' !== $declared && self::post_type_registered( $declared ) ) {
			return self::result( $declared, self::producer_or_consumer_date( $page ), 'producer_declared' );
		}

		$date  = self::publish_date( $page );
		$route = (string) ( $page['route']['path'] ?? '' );
		if ( null !== $date ) {
			return self::result( 'post', $date, 'dated_meta' );
		}
		if ( self::route_is_dated_hierarchy( $route ) ) {
			return self::result( 'post', null, 'dated_route' );
		}

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
	 * Read a producer content_decision when it is structurally present.
	 *
	 * @param array<string,mixed> $page Plan page row.
	 * @return array<string,mixed>|null
	 */
	private static function content_decision( array $page ): ?array {
		$decision = $page['content_decision'] ?? null;
		if ( ! is_array( $decision ) || 'blocks-engine/content-decision/v1' !== ( $decision['schema'] ?? null ) ) {
			return null;
		}
		$state = (string) ( $decision['state'] ?? '' );
		if ( ! in_array( $state, array( 'declared', 'inferred', 'defaulted' ), true ) ) {
			return null;
		}

		return $decision;
	}

	/**
	 * Prefer the producer publication timestamp, then consumer dated-meta parse.
	 *
	 * @param array<string,mixed> $page Plan page row.
	 * @return string|null MySQL UTC datetime.
	 */
	private static function producer_or_consumer_date( array $page ): ?string {
		$producer = self::producer_date( $page );

		return null !== $producer ? $producer : self::publish_date( $page );
	}

	/**
	 * Convert a producer ISO-8601 UTC timestamp to MySQL UTC datetime.
	 *
	 * @param array<string,mixed> $page Plan page row.
	 * @return string|null
	 */
	private static function producer_date( array $page ): ?string {
		$candidates = array( (string) ( $page['publication_timestamp'] ?? '' ) );
		$decision   = is_array( $page['content_decision'] ?? null ) ? $page['content_decision'] : array();
		foreach ( $decision['evidence'] ?? array() as $row ) {
			if ( is_array( $row ) ) {
				$candidates[] = (string) ( $row['publication_timestamp'] ?? '' );
			}
		}
		foreach ( $candidates as $iso ) {
			if ( 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})Z$/', $iso, $matches ) ) {
				return $matches[1] . ' ' . $matches[2];
			}
		}
		return null;
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

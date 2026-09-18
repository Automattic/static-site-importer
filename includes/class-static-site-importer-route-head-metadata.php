<?php
/**
 * Persists per-page descriptive head metadata and emits it on wp_head.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Static_Site_Importer_Route_Head_Metadata {
	private const PROVENANCE_META_KEY = '_static_site_importer_provenance';
	private const PROVENANCE_SCHEMA   = 'static-site-importer/page-provenance/v1';
	private const TEXT_LIMIT          = 1000;
	private const URL_LIMIT           = 2048;

	/** @var array<string,true> */
	private const TEXT_NAMES = array(
		'description'                => true,
		'apple-mobile-web-app-title' => true,
	);

	/** @var array<string,true> */
	private const URL_KEYS = array(
		'og:audio'            => true,
		'og:image'            => true,
		'og:image:secure_url' => true,
		'og:image:url'        => true,
		'og:url'              => true,
		'og:video'            => true,
		'twitter:image'       => true,
		'twitter:image:src'   => true,
		'twitter:url'         => true,
	);

	public static function register(): void {
		add_action( 'wp_head', array( self::class, 'print_tags' ), 1 );
	}

	public static function print_tags(): void {
		if ( ! empty( $GLOBALS['static_site_importer_head_metadata_emitted'] ) || self::another_producer_owns_head() ) {
			return;
		}
		if ( ! is_singular() && ! is_front_page() ) {
			return;
		}

		$provenance = json_decode( (string) get_post_meta( get_queried_object_id(), self::PROVENANCE_META_KEY, true ), true );
		if ( ! is_array( $provenance ) || self::PROVENANCE_SCHEMA !== ( $provenance['schema'] ?? null ) || ! is_array( $provenance['head_metadata'] ?? null ) ) {
			return;
		}

		$GLOBALS['static_site_importer_head_metadata_emitted'] = true;
		self::emit_tags( $provenance['head_metadata'] );
	}

	/**
	 * Materialize a portable wp_head emitter into the generated theme.
	 *
	 * @param array<string,mixed> $resolved_plan
	 * @param array<string,mixed> $bootstrap_overlay
	 * @return array<string,mixed>
	 */
	public static function prepare_overlay( array $resolved_plan, array $bootstrap_overlay = array() ): array {
		$bootstrap = self::bootstrap_content( $resolved_plan, $bootstrap_overlay );
		$marker    = '/* Static Site Importer authored route head metadata. */';
		if ( ! str_contains( $bootstrap, $marker ) ) {
			$bootstrap .= "\n{$marker}\n" . <<<'PHP'
add_action( 'wp_head', static function (): void {
	if ( ! empty( $GLOBALS['static_site_importer_head_metadata_emitted'] ) ) {
		return;
	}
	if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'THE_SEO_FRAMEWORK_VERSION' ) || defined( 'AIOSEO_VERSION' ) || ( function_exists( 'has_action' ) && has_action( 'wp_head', 'jetpack_og_tags' ) ) ) {
		return;
	}
	if ( ! is_singular() && ! is_front_page() ) {
		return;
	}
	$provenance = json_decode( (string) get_post_meta( get_queried_object_id(), '_static_site_importer_provenance', true ), true );
	if ( ! is_array( $provenance ) || 'static-site-importer/page-provenance/v1' !== ( $provenance['schema'] ?? null ) || ! is_array( $provenance['head_metadata'] ?? null ) ) {
		return;
	}
	$GLOBALS['static_site_importer_head_metadata_emitted'] = true;
	foreach ( $provenance['head_metadata'] as $tag ) {
		if ( ! is_array( $tag ) || ! is_string( $tag['attr'] ?? null ) || ! is_string( $tag['key'] ?? null ) || ! is_string( $tag['content'] ?? null ) ) {
			continue;
		}
		$attr    = $tag['attr'];
		$key     = $tag['key'];
		$content = 'url' === ( $tag['kind'] ?? 'text' ) ? esc_url( $tag['content'] ) : $tag['content'];
		if ( '' === $content || ! in_array( $attr, array( 'name', 'property' ), true ) ) {
			continue;
		}
		echo '<meta ' . esc_attr( $attr ) . '="' . esc_attr( $key ) . '" content="' . esc_attr( $content ) . '" />' . "\n";
	}
}, 1 );
PHP;
		}

		return array(
			'status' => 'materialized',
			'writes' => array(
				array(
					'target_path' => 'functions.php',
					'content'     => $bootstrap,
					'encoding'    => 'utf8',
					'source_path' => 'static-site-importer/route-head-metadata',
				),
			),
		);
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

	public static function another_producer_owns_head(): bool {
		return defined( 'WPSEO_VERSION' )
			|| defined( 'RANK_MATH_VERSION' )
			|| defined( 'THE_SEO_FRAMEWORK_VERSION' )
			|| defined( 'AIOSEO_VERSION' )
			|| ( function_exists( 'has_action' ) && (bool) has_action( 'wp_head', 'jetpack_og_tags' ) );
	}

	/** @param array<string,mixed> $resolved_plan @param array<string,mixed> $overlay */
	private static function bootstrap_content( array $resolved_plan, array $overlay ): string {
		foreach ( array_reverse( $overlay['writes'] ?? array() ) as $write ) {
			if ( is_array( $write ) && 'functions.php' === ( $write['target_path'] ?? null ) && is_string( $write['content'] ?? null ) ) {
				return $write['content'];
			}
		}
		foreach ( $resolved_plan['writes'] ?? array() as $write ) {
			if ( ! is_array( $write ) || 'functions.php' !== ( $write['target_path'] ?? null ) || ! is_array( $write['payload'] ?? null ) ) {
				continue;
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes a declared plan payload encoding.
			$content = 'base64' === ( $write['payload']['encoding'] ?? 'utf8' ) ? base64_decode( (string) ( $write['payload']['data'] ?? '' ), true ) : $write['payload']['data'] ?? null;
			if ( is_string( $content ) && str_starts_with( ltrim( $content ), '<?php' ) ) {
				return $content;
			}
		}

		return "<?php\n";
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
		if ( 'name' === $attr ) {
			if ( isset( self::TEXT_NAMES[ $key ] ) ) {
				return 'text';
			}
			if ( str_starts_with( $key, 'twitter:' ) ) {
				return isset( self::URL_KEYS[ $key ] ) ? 'url' : 'text';
			}
			return null;
		}
		if ( 'property' === $attr && ( str_starts_with( $key, 'og:' ) || str_starts_with( $key, 'twitter:' ) ) ) {
			return isset( self::URL_KEYS[ $key ] ) ? 'url' : 'text';
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
		if ( self::is_absolute_http_url( $content ) ) {
			return $content;
		}

		$tokens    = is_array( $resolved_plan['reference_tokens'] ?? null ) ? $resolved_plan['reference_tokens'] : array();
		$theme_uri = is_string( $resolved_plan['resolution']['theme_uri'] ?? null ) ? $resolved_plan['resolution']['theme_uri'] : '';
		if ( array() === $tokens || '' === $theme_uri || ! class_exists( '\Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\AssetReferenceCanonicalizer' ) || ! class_exists( '\Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver' ) ) {
			return $content;
		}

		$prefix = class_exists( '\Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan' )
			? \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan::TOKEN_PREFIX
			: '{{wordpress-site-plan:asset:';
		try {
			$references = \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver::references( $tokens, $theme_uri );
			if ( str_contains( $content, $prefix ) ) {
				return \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver::resolvePayload( $content, $references );
			}
			$origin        = is_string( $page['source_path'] ?? null ) ? $page['source_path'] : '';
			$canonicalizer = new \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\AssetReferenceCanonicalizer( $tokens, self::site_root( $resolved_plan ) );
			$token         = $canonicalizer->reference( $content, $origin );
			if ( is_string( $token ) ) {
				return \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver::resolvePayload( $token, $references );
			}
		} catch ( InvalidArgumentException $error ) {
			unset( $error );
			return $content;
		}

		return $content;
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

	/** @param array<int,mixed> $tags */
	private static function emit_tags( array $tags ): void {
		foreach ( $tags as $tag ) {
			if ( ! is_array( $tag ) || ! is_string( $tag['attr'] ?? null ) || ! is_string( $tag['key'] ?? null ) || ! is_string( $tag['content'] ?? null ) ) {
				continue;
			}
			$attr    = $tag['attr'];
			$key     = $tag['key'];
			$content = 'url' === ( $tag['kind'] ?? 'text' ) ? esc_url( $tag['content'] ) : $tag['content'];
			if ( '' === $content || ! in_array( $attr, array( 'name', 'property' ), true ) ) {
				continue;
			}
			echo '<meta ' . esc_attr( $attr ) . '="' . esc_attr( $key ) . '" content="' . esc_attr( $content ) . '" />' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attr, key, and content are passed through esc_attr after URL sanitization.
		}
	}

	/** @param array<string,mixed> $diagnostic */
	private static function persisted_diagnostic( array $diagnostic ): array {
		$diagnostic['message']       = 'Named head metadata was persisted per page as post meta and is emitted on wp_head.';
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

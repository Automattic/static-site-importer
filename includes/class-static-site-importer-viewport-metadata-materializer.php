<?php
/**
 * Materializes one site-wide authored viewport declaration into a generated theme.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Static_Site_Importer_Viewport_Metadata_Materializer {
	/** @param array<string,mixed> $resolved_plan @param array<string,mixed> $bootstrap_overlay */
	public static function prepare_overlay( array $resolved_plan, array $bootstrap_overlay = array() ): array {
		$declarations = array();
		$missing      = array();
		$duplicates   = array();
		$invalid      = array();
		$declared     = false;

		foreach ( $resolved_plan['pages'] ?? array() as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$source_path = is_string( $page['source_path'] ?? null ) ? $page['source_path'] : '';
			$metadata    = is_array( $page['document_metadata'] ?? null ) ? $page['document_metadata'] : array();
			$rows        = array_values(
				array_filter(
					is_array( $metadata['meta'] ?? null ) ? $metadata['meta'] : array(),
					static fn( mixed $row ): bool => is_array( $row ) && 'viewport' === strtolower( trim( (string) ( $row['name'] ?? '' ) ) )
				)
			);
			if ( array() === $rows ) {
				$missing[] = $source_path;
				continue;
			}
			$declared = true;
			if ( 1 !== count( $rows ) ) {
				$duplicates[] = $source_path;
				continue;
			}
			$content = self::normalize_declaration( $rows[0]['content'] ?? null );
			if ( null === $content ) {
				$invalid[] = $source_path;
				continue;
			}
			$declarations[ $source_path ] = $content;
		}

		if ( ! $declared ) {
			return self::result( 'not_requested' );
		}
		if ( array() !== $duplicates ) {
			return self::report_only( 'viewport_metadata_duplicate', 'Authored viewport metadata remains report-only because a route declares it more than once.', $duplicates );
		}
		if ( array() !== $invalid ) {
			return self::report_only( 'viewport_metadata_invalid', 'Authored viewport metadata remains report-only because a declaration is invalid.', $invalid );
		}
		if ( array() !== $missing ) {
			return self::report_only( 'viewport_metadata_missing_route', 'Authored viewport metadata remains report-only because it is not declared by every route.', $missing );
		}
		$unique = array_values( array_unique( array_values( $declarations ) ) );
		if ( 1 !== count( $unique ) ) {
			return self::report_only( 'viewport_metadata_conflict', 'Authored viewport metadata remains report-only because routes declare conflicting values.', array_keys( $declarations ) );
		}

		$viewport  = $unique[0];
		$bootstrap = self::bootstrap_content( $resolved_plan, $bootstrap_overlay );
		$marker    = '/* Static Site Importer authored viewport metadata. */';
		if ( ! str_contains( $bootstrap, $marker ) ) {
			$literal    = (string) wp_json_encode( $viewport );
			$bootstrap .= "\n{$marker}\nadd_filter( 'template_include', static function ( \$template ) {\n\tremove_action( 'wp_head', '_block_template_viewport_meta_tag', 0 );\n\tadd_action( 'wp_head', static function (): void {\n\t\techo '<meta name=\"viewport\" content=\"' . esc_attr( {$literal} ) . '\" />' . \"\\n\";\n\t}, 0 );\n\treturn \$template;\n}, PHP_INT_MAX );\n";
		}

		return self::result(
			'materialized',
			array(
				array(
					'target_path' => 'functions.php',
					'content'     => $bootstrap,
					'encoding'    => 'utf8',
					'source_path' => 'static-site-importer/viewport-metadata',
				),
			),
			array(),
			$viewport
		);
	}

	private static function normalize_declaration( mixed $content ): ?string {
		if ( ! is_string( $content ) || '' === trim( $content ) || 512 < strlen( $content ) || preg_match( '/[\x00-\x1F\x7F]/', $content ) ) {
			return null;
		}
		$normalized = array();
		$seen       = array();
		foreach ( explode( ',', $content ) as $component ) {
			$pair = array_map( 'trim', explode( '=', $component, 2 ) );
			if ( 2 !== count( $pair ) || '' === $pair[0] || '' === $pair[1] ) {
				return null;
			}
			$key   = strtolower( $pair[0] );
			$value = strtolower( $pair[1] );
			if ( isset( $seen[ $key ] ) || ! self::valid_component( $key, $value ) ) {
				return null;
			}
			$seen[ $key ] = true;
			$normalized[] = $key . '=' . $value;
		}
		return implode( ', ', $normalized );
	}

	private static function valid_component( string $key, string $value ): bool {
		if ( in_array( $key, array( 'width', 'height' ), true ) ) {
			return in_array( $value, array( 'device-width', 'device-height' ), true ) || ( ctype_digit( $value ) && 1 <= (int) $value && 10000 >= (int) $value );
		}
		if ( in_array( $key, array( 'initial-scale', 'minimum-scale', 'maximum-scale' ), true ) ) {
			return 1 === preg_match( '/^(?:\d+(?:\.\d+)?|\.\d+)$/', $value ) && 0 < (float) $value && 10 >= (float) $value;
		}
		if ( 'user-scalable' === $key ) {
			return in_array( $value, array( 'yes', 'no', '1', '0' ), true );
		}
		if ( 'viewport-fit' === $key ) {
			return in_array( $value, array( 'auto', 'contain', 'cover' ), true );
		}
		if ( 'interactive-widget' === $key ) {
			return in_array( $value, array( 'resizes-visual', 'resizes-content', 'overlays-content' ), true );
		}
		return false;
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

	/** @param array<int,array<string,mixed>> $writes @param array<int,array<string,mixed>> $diagnostics */
	private static function result( string $status, array $writes = array(), array $diagnostics = array(), string $declaration = '' ): array {
		return array(
			'status'      => $status,
			'declaration' => $declaration,
			'writes'      => $writes,
			'diagnostics' => $diagnostics,
		);
	}

	/** @param array<int,string> $source_paths */
	private static function report_only( string $reason_code, string $message, array $source_paths ): array {
		return self::result(
			'report_only',
			array(),
			array(
				array(
					'code'         => 'static_site_importer_' . $reason_code,
					'type'         => 'static-site-importer',
					'severity'     => 'warning',
					'reason_code'  => $reason_code,
					'message'      => $message,
					'source_paths' => array_values( $source_paths ),
				),
			)
		);
	}
}

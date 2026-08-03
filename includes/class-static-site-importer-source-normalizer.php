<?php
/**
 * Auditable source normalization before artifact compilation.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Static_Site_Importer_Source_Normalizer {

	/**
	 * Remove registered source-platform chrome while retaining immutable receipts.
	 *
	 * @param string              $html       Source HTML.
	 * @param string              $source_url Source document URL or path.
	 * @param array<string,mixed> $args       Normalization options.
	 * @return array{html:string,exclusions:array<int,array<string,string>>,diagnostics:array<int,array<string,string>>}
	 */
	public static function normalize_html( string $html, string $source_url, array $args = array() ): array {
		$cloudflare_email_links = 0;
		$html                   = self::normalize_cloudflare_email_links( $html, $cloudflare_email_links );
		$diagnostics            = array();
		if ( $cloudflare_email_links > 0 ) {
			$diagnostics[] = array(
				'type'        => 'source_normalization',
				'severity'    => 'info',
				'reason_code' => 'cloudflare_email_link_decoded',
				'source_path' => $source_url,
				'count'       => (string) $cloudflare_email_links,
			);
		}

		if ( array_key_exists( 'exclude_platform_chrome', $args ) && ! $args['exclude_platform_chrome'] ) {
			return array(
				'html'        => $html,
				'exclusions'  => array(),
				'diagnostics' => $diagnostics,
			);
		}

		$rules = self::rules();
		if ( function_exists( 'apply_filters' ) ) {
			$rules = apply_filters( 'static_site_importer_source_exclusion_rules', $rules, $source_url, $args );
		}
		if ( ! is_array( $rules ) ) {
			$rules = array();
		}

		$original   = $html;
		$exclusions = array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || ! str_starts_with( (string) ( $rule['selector'] ?? '' ), '#' ) ) {
				continue;
			}
			$selector = (string) $rule['selector'];
			$removed  = self::remove_element_by_id( $html, substr( $selector, 1 ) );
			if ( null === $removed ) {
				continue;
			}
			$html          = $removed['html'];
			$receipt       = array(
				'schema'         => 'static-site-importer/source-exclusion/v1',
				'action'         => 'removed',
				'category'       => (string) ( $rule['category'] ?? 'source_chrome' ),
				'provider'       => (string) ( $rule['provider'] ?? '' ),
				'rule_id'        => (string) ( $rule['id'] ?? '' ),
				'selector'       => $selector,
				'source_path'    => $source_url,
				'reason_code'    => (string) ( $rule['reason_code'] ?? 'source_chrome_removed' ),
				'removed_sha256' => hash( 'sha256', $removed['element'] ),
			);
			$exclusions[]  = $receipt;
			$diagnostics[] = array(
				'type'        => 'source_exclusion',
				'severity'    => 'info',
				'reason_code' => $receipt['reason_code'],
				'source_path' => $source_url,
				'selector'    => $selector,
				'provider'    => $receipt['provider'],
			);
		}

		foreach ( $exclusions as &$exclusion ) {
			$exclusion['source_sha256']     = hash( 'sha256', $original );
			$exclusion['normalized_sha256'] = hash( 'sha256', $html );
		}
		unset( $exclusion );

		return array(
			'html'        => $html,
			'exclusions'  => $exclusions,
			'diagnostics' => $diagnostics,
		);
	}

	private static function normalize_cloudflare_email_links( string $html, int &$count ): string {
		return (string) preg_replace_callback(
			'~\bhref\s*=\s*(["\'])(?:https?://[^/"\']+)?/cdn-cgi/l/email-protection#([a-f0-9]+)\1~i',
			static function ( array $match ) use ( &$count ): string {
				$bytes = hex2bin( $match[2] );
				if ( false === $bytes || strlen( $bytes ) < 2 ) {
					return $match[0];
				}
				$key   = ord( $bytes[0] );
				$email = '';
				for ( $index = 1, $length = strlen( $bytes ); $index < $length; ++$index ) {
					$email .= chr( ord( $bytes[ $index ] ) ^ $key );
				}
				if ( false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
					return $match[0];
				}
				++$count;
				return 'href=' . $match[1] . 'mailto:' . htmlspecialchars( $email, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . $match[1];
			},
			$html
		);
	}

	/** @return array<int,array<string,string>> */
	private static function rules(): array {
		$path = __DIR__ . '/source-exclusion-rules.json';
		$json = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads an importer-owned static policy file.
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		return is_array( $data ) && is_array( $data['rules'] ?? null ) ? $data['rules'] : array();
	}

	/** @return null|array{html:string,element:string} */
	private static function remove_element_by_id( string $html, string $id ): ?array {
		if ( '' === $id ) {
			return null;
		}
		$quoted_id = preg_quote( $id, '#' );
		$pattern   = '#<([a-z][a-z0-9:-]*)\b[^>]*\bid\s*=\s*(?:"' . $quoted_id . '"|\'' . $quoted_id . '\'|' . $quoted_id . ')(?:\s|/?>)#is';
		if ( ! preg_match( $pattern, $html, $opening, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$tag       = strtolower( (string) $opening[1][0] );
		$start     = (int) $opening[0][1];
		$remainder = substr( $html, $start );
		if ( ! preg_match_all( '#</?' . preg_quote( $tag, '#' ) . '\b[^>]*>#is', $remainder, $tags, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$depth = 0;
		foreach ( $tags[0] as $match ) {
			$token = (string) $match[0];
			if ( str_starts_with( $token, '</' ) ) {
				--$depth;
			} elseif ( ! str_ends_with( rtrim( $token ), '/>' ) ) {
				++$depth;
			}
			if ( 0 === $depth ) {
				$length  = (int) $match[1] + strlen( $token );
				$element = substr( $remainder, 0, $length );
				return array(
					'html'    => substr_replace( $html, '', $start, $length ),
					'element' => $element,
				);
			}
		}
		return null;
	}
}

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
	 * Normalize source HTML without removing authored or platform markup.
	 *
	 * All source HTML is preserved. Platform attribution is upstream of this
	 * importer: capture adapters decide what content is in scope before an
	 * artifact ever reaches SSI.
	 *
	 * @param string              $html       Source HTML.
	 * @param string              $source_url Source document URL or path.
	 * @param array<string,mixed> $args       Normalization options.
	 * @return array{html:string,diagnostics:array<int,array<string,string>>}
	 */
	public static function normalize_html( string $html, string $source_url, array $args = array() ): array {
		unset( $args );
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

		return array(
			'html'        => $html,
			'diagnostics' => $diagnostics,
		);
	}

	private static function normalize_cloudflare_email_links( string $html, int &$count ): string {
		return (string) preg_replace_callback(
			'~\bhref\s*=\s*(["\'])(?:https?://[^/"\']+)?/cdn-cgi/l/email-protection#([a-f0-9]+)\1~i',
			static function ( array $matches ) use ( &$count ): string {
				$bytes = hex2bin( $matches[2] );
				if ( false === $bytes || strlen( $bytes ) < 2 ) {
					return $matches[0];
				}
				$key   = ord( $bytes[0] );
				$email = '';
				for ( $index = 1, $length = strlen( $bytes ); $index < $length; ++$index ) {
					$email .= chr( ord( $bytes[ $index ] ) ^ $key );
				}
				if ( false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
					return $matches[0];
				}
				++$count;
				return 'href=' . $matches[1] . 'mailto:' . htmlspecialchars( $email, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . $matches[1];
			},
			$html
		);
	}
}

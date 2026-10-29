<?php
/**
 * Language ownership: the source document language belongs to the site option.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Site_Plan_Persistence' ) ) {
	require_once __DIR__ . '/class-static-site-importer-site-plan-persistence.php';
}

/**
 * Materializes the source document language into its WordPress owners.
 *
 * One ownership decision, applied at materialization: the entry document's
 * `<html lang>` (with a `content-language` meta as fallback) becomes the site
 * language owner — `WPLANG`, installed through the core language-pack path —
 * and route-level languages that differ from the site language are recorded on
 * the route's owner (the imported post). Core emits `<html lang>` from the site
 * locale, so the generated runtime never echoes a source lang attribute; this
 * class also composes that runtime ownership into the theme bootstrap.
 *
 * Language failures are owner-handoff decisions, never import failures: when a
 * language pack cannot be installed the site keeps its default and the receipt
 * records why, and a language the site owner already set is preserved.
 */
final class Static_Site_Importer_Site_Language {
	public const ROUTE_META_KEY = '_static_site_importer_route_language';

	/** Marks the composed theme bootstrap section this class owns. */
	private const RUNTIME_MARKER = '/* Static Site Importer language ownership: core emits <html lang> from the site language option. */';

	/**
	 * Bare source-language subtags resolved to their WordPress locales. Region
	 * subtags (`pt-br`, `zh_TW`) derive their locale generically; this table
	 * covers every case the generic rule cannot: languages whose WordPress
	 * locale carries a default region (`nl` -> `nl_NL`), languages whose
	 * WordPress locale is the bare subtag (`ja`, `el`), and region variants of
	 * those (`ja-JP` -> `ja`).
	 *
	 * @var array<string,string>
	 */
	private const BARE_LOCALES = array(
		'af' => 'af',
		'ar' => 'ar',
		'ary' => 'ary',
		'az' => 'az',
		'azb' => 'azb',
		'ba' => 'ba',
		'bel' => 'bel',
		'bg' => 'bg_BG',
		'bn' => 'bn_BD',
		'bo' => 'bo',
		'bs' => 'bs_BA',
		'ca' => 'ca',
		'ceb' => 'ceb',
		'ckb' => 'ckb',
		'cs' => 'cs_CZ',
		'cy' => 'cy',
		'da' => 'da_DK',
		'de' => 'de_DE',
		'el' => 'el',
		'en' => 'en_US',
		'eo' => 'eo',
		'es' => 'es_ES',
		'et' => 'et',
		'eu' => 'eu',
		'fa' => 'fa_IR',
		'fi' => 'fi',
		'fo' => 'fo',
		'fr' => 'fr_FR',
		'fy' => 'fy',
		'ga' => 'ga',
		'gd' => 'gd',
		'gl' => 'gl_ES',
		'gu' => 'gu',
		'haz' => 'haz',
		'he' => 'he_IL',
		'hi' => 'hi_IN',
		'hr' => 'hr',
		'hu' => 'hu_HU',
		'hy' => 'hy',
		'id' => 'id_ID',
		'is' => 'is_IS',
		'it' => 'it_IT',
		'ja' => 'ja',
		'ka' => 'ka_GE',
		'kab' => 'kab',
		'kk' => 'kk',
		'km' => 'km',
		'kn' => 'kn',
		'ko' => 'ko_KR',
		'lo' => 'lo',
		'lt' => 'lt_LT',
		'lv' => 'lv',
		'mk' => 'mk_MK',
		'ml' => 'ml',
		'mn' => 'mn',
		'mr' => 'mr',
		'ms' => 'ms_MY',
		'my' => 'my_MM',
		'ne' => 'ne_NP',
		'nl' => 'nl_NL',
		'nn' => 'nn_NO',
		'no' => 'nb_NO',
		'nb' => 'nb_NO',
		'oci' => 'oci',
		'pa' => 'pa_IN',
		'pl' => 'pl_PL',
		'ps' => 'ps',
		'pt' => 'pt_PT',
		'rhg' => 'rhg',
		'ro' => 'ro_RO',
		'ru' => 'ru_RU',
		'sah' => 'sah',
		'si' => 'si_LK',
		'sk' => 'sk_SK',
		'skr' => 'skr',
		'sl' => 'sl_SI',
		'sq' => 'sq',
		'sr' => 'sr_RS',
		'sv' => 'sv_SE',
		'sw' => 'sw',
		'szl' => 'szl',
		'ta' => 'ta_IN',
		'te' => 'te',
		'th' => 'th',
		'tl' => 'tl',
		'tr' => 'tr_TR',
		'tt' => 'tt_RU',
		'tuk' => 'tuk',
		'ug' => 'ug_CN',
		'uk' => 'uk',
		'ur' => 'ur',
		'vi' => 'vi',
		'zh' => 'zh_CN',
	);

	/** Region variants whose WordPress locale stays the bare language tag. */
	private const BARE_PRIMARY_REGIONS = array(
		'el-gr' => 'el',
		'ja-jp' => 'ja',
	);

	/** Region variants that resolve to a locale their generic form would miss. */
	private const REGION_LOCALES = array(
		'zh-cn' => 'zh_CN',
		'zh-hk' => 'zh_HK',
		'zh-tw' => 'zh_TW',
	);

	/**
	 * The source document language of one plan page: `<html lang>`, with a
	 * `content-language` meta as the fallback.
	 *
	 * @param array<string,mixed> $page Resolved plan page.
	 * @return string Validated source language tag, or '' when undeclared.
	 */
	public static function language_from_page( array $page ): string {
		$metadata = is_array( $page['document_metadata'] ?? null ) ? $page['document_metadata'] : array();

		return self::valid_language( self::metadata_language( $metadata ) );
	}

	/**
	 * The composed theme bootstrap with source-lang replay removed.
	 *
	 * The Blocks Engine document-root runtime replays authored `<html>`
	 * attributes, including `lang`, through a `language_attributes` filter.
	 * `lang` has an owner — the site language option — so the composed runtime
	 * strips it from the replayed rows and core's `language_attributes()`
	 * remains the only `<html lang>` emitter. The section is idempotent so
	 * overlay chains that each carry the bootstrap stay byte-stable.
	 *
	 * @param string $bootstrap Composed theme functions.php content.
	 * @return string
	 */
	public static function with_runtime_ownership( string $bootstrap ): string {
		if ( str_contains( $bootstrap, self::RUNTIME_MARKER ) || ! str_contains( $bootstrap, 'blocks_engine_document_roots' ) ) {
			return $bootstrap;
		}
		$section = "\n" . self::RUNTIME_MARKER . "\n"
			. "if ( isset( \$blocks_engine_document_roots ) && is_array( \$blocks_engine_document_roots ) ) {\n"
			. "\tforeach ( \$blocks_engine_document_roots as \$static_site_importer_root_key => \$static_site_importer_root ) {\n"
			. "\t\tif ( is_array( \$static_site_importer_root ) && isset( \$static_site_importer_root['attributes'] ) && is_array( \$static_site_importer_root['attributes'] ) ) {\n"
			. "\t\t\tunset( \$blocks_engine_document_roots[ \$static_site_importer_root_key ]['attributes']['lang'] );\n"
			. "\t\t}\n"
			. "\t}\n"
			. "\tunset( \$static_site_importer_root, \$static_site_importer_root_key );\n"
			. "}\n";
		return $bootstrap . $section;
	}

	/**
	 * Compose runtime language ownership into the destination bootstrap.
	 *
	 * The font, viewport and internal-link overlays each rewrite
	 * `functions.php`; the composed content carries the ownership section.
	 * When no overlay composed the bootstrap, the canonical plan write gains
	 * it (payload and hash updated together, like every resolved-write overlay).
	 *
	 * @param array<string,mixed> $state Prepared materialization state.
	 */
	public static function compose_runtime_ownership( array &$state ): void {
		$path = (string) $state['theme_dir'] . '/functions.php';
		if ( isset( $state['composed_theme_writes'][ $path ] ) && is_string( $state['composed_theme_writes'][ $path ] ) ) {
			$state['composed_theme_writes'][ $path ] = self::with_runtime_ownership( (string) $state['composed_theme_writes'][ $path ] );
			$composed                                = (string) $state['composed_theme_writes'][ $path ];
			foreach ( array( 'font_overlay', 'viewport_overlay', 'internal_link_overlay' ) as $key ) {
				if ( ! is_array( $state[ $key ]['writes'] ?? null ) ) {
					continue;
				}
				foreach ( $state[ $key ]['writes'] as &$write ) {
					if ( is_array( $write ) && 'functions.php' === ( $write['target_path'] ?? '' ) && isset( $write['content'] ) && is_string( $write['content'] ) && ! str_contains( $write['content'], self::RUNTIME_MARKER ) ) {
						$write['content'] = self::with_runtime_ownership( $write['content'] );
					}
				}
				unset( $write );
			}
			return;
		}
		$resolved_writes = is_array( $state['resolved']['writes'] ?? null ) ? $state['resolved']['writes'] : array();
		foreach ( $resolved_writes as &$write ) {
			if ( ! is_array( $write ) || 'functions.php' !== ( $write['target_path'] ?? '' ) || ! is_array( $write['payload'] ?? null ) ) {
				continue;
			}
			$encoding = (string) ( $write['payload']['encoding'] ?? 'utf8' );
			$content  = 'base64' === $encoding ? base64_decode( (string) ( $write['payload']['data'] ?? '' ), true ) : (string) ( $write['payload']['data'] ?? '' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes a declared plan payload encoding.
			if ( false === $content || '' === $content ) {
				continue;
			}
			$composed = self::with_runtime_ownership( $content );
			if ( $composed === $content ) {
				continue;
			}
			$write['payload']                        = array(
				'encoding' => 'utf8',
				'data'     => $composed,
			);
			$write['payload_hash']                   = hash( 'sha256', $composed );
			$state['composed_theme_writes'][ $path ] = $composed;
		}
		unset( $write );
		$state['resolved']['writes'] = $resolved_writes;
	}

	/**
	 * Resolve one site-language ownership decision from prepared state.
	 *
	 * @param array<string,mixed> $state Prepared materialization state.
	 * @return array{source_tag:string,source_from:string,locale:string,current_locale:string,mode:string}
	 */
	public static function decision( array $state ): array {
		$explicit = isset( $state['args']['site_language'] ) && is_scalar( $state['args']['site_language'] )
			? self::valid_language( (string) $state['args']['site_language'] )
			: '';
		$source_tag = '' !== $explicit ? $explicit : '';
		$source_from = '' !== $explicit ? 'explicit' : '';
		if ( '' === $source_tag ) {
			foreach ( $state['ordered_pages'] ?? array() as $page ) {
				if ( ! is_array( $page ) || empty( $page['entrypoint'] ) || ! empty( $page['skip_materialization'] ) ) {
					continue;
				}
				$source_tag  = self::language_from_page( $page );
				$source_from = self::metadata_source( is_array( $page['document_metadata'] ?? null ) ? $page['document_metadata'] : array() );
				break;
			}
		}
		$current       = self::owner_locale();
		$locale        = self::locale_from_language( $source_tag );
		$unsupported   = '' !== $source_tag && '' === $locale;
		$owner_set     = '' !== $current;
		return array(
			'source_tag'     => $source_tag,
			'source_from'    => $source_from,
			'locale'         => $locale,
			'current_locale' => $current,
			'mode'           => '' === $source_tag ? 'absent' : ( $unsupported ? 'unsupported_language' : ( $owner_set ? 'preserved_owner_value' : 'apply' ) ),
		);
	}

	/**
	 * Record route-level languages that differ from the site language on the
	 * route's owner. Route-owner metadata, written whenever routes materialize.
	 *
	 * @param array<string,mixed> $state Prepared materialization state.
	 * @return array{status:string,routes:array<int,array<string,mixed>>}
	 */
	public static function materialize_route_languages( array &$state ): array {
		$decision = self::decision( $state );
		$site     = self::effective_locale( $decision );
		$rows     = array();
		foreach ( $state['ordered_pages'] ?? array() as $page ) {
			if ( ! is_array( $page ) || ! empty( $page['skip_materialization'] ) || ! empty( $page['synthetic'] ) ) {
				continue;
			}
			$id   = (int) ( $state['source_ids'][ (string) ( $page['source_path'] ?? '' ) ] ?? 0 );
			$lang = self::language_from_page( $page );
			if ( $id <= 0 || '' === $lang ) {
				continue;
			}
			$row = array(
				'source_path' => (string) ( $page['source_path'] ?? '' ),
				'post_id'     => $id,
				'language'    => $lang,
				'site_locale' => $site,
			);
			if ( self::locale_from_language( $lang ) === $site ) {
				$row['status'] = 'matches_site_language';
				$rows[]        = $row;
				continue;
			}
			$previous = get_post_meta( $id, self::ROUTE_META_KEY, true );
			$exists   = function_exists( 'metadata_exists' )
				? metadata_exists( 'post', $id, self::ROUTE_META_KEY )
				: ( is_string( $previous ) && '' !== $previous );
			if ( ! isset( $state['rollback']['route_language'][ $id ] ) ) {
				$state['rollback']['route_language'][ $id ] = array(
					'exists' => $exists,
					'value'  => is_string( $previous ) ? $previous : '',
				);
			}
			if ( ! Static_Site_Importer_Site_Plan_Persistence::write_post_meta( $id, self::ROUTE_META_KEY, $lang ) ) {
				$row['status'] = 'record_failed';
				$rows[]        = $row;
				continue;
			}
			$row['status'] = 'recorded';
			$rows[]        = $row;
		}
		return array(
			'status' => array_filter( $rows, static fn( array $row ): bool => 'record_failed' === $row['status'] ) ? 'partial' : 'completed',
			'site_locale' => $site,
			'routes' => $rows,
		);
	}

	/**
	 * Apply the site-language decision to its option owner. Runs inside the
	 * authorized activation transaction, like the other site-identity writes.
	 * Never fails the import: an unresolvable pack is an owner-handoff record.
	 *
	 * @param array<string,mixed> $state Prepared materialization state.
	 * @return array<string,mixed>
	 */
	public static function materialize_site_language( array &$state ): array {
		$decision = self::decision( $state );
		$report   = array(
			'source_tag'     => $decision['source_tag'],
			'source_from'    => $decision['source_from'],
			'locale'         => $decision['locale'],
			'current_locale' => $decision['current_locale'],
			'status'         => $decision['mode'],
		);
		if ( 'apply' !== $decision['mode'] ) {
			return $report;
		}
		$locale = $decision['locale'];
		$pack   = self::ensure_language_pack( $locale );
		if ( ! is_string( $pack ) ) {
			// Owner handoff: keep the site default, record why, continue.
			$report['status']       = 'owner_handoff';
			$report['reason']       = $pack->get_error_code();
			$report['kept_locale']  = self::default_locale();
			$state['diagnostics'][] = array(
				'reason_code' => 'site_language_pack_unavailable',
				'message'     => 'The source document language was kept as the site default because its language pack could not be installed.',
				'constraints' => 'owner_handoff',
				'locale'      => $locale,
				'pack_reason' => $pack->get_error_code(),
			);
			return $report;
		}
		$locale = $pack;
		Static_Site_Importer_Site_Plan_Persistence::journal_option( $state, 'WPLANG' );
		if ( ! Static_Site_Importer_Site_Plan_Persistence::write_option( 'WPLANG', $locale ) ) {
			$report['status'] = 'owner_handoff';
			$report['reason'] = 'site_language_option_write_failed';
			$report['kept_locale'] = self::default_locale();
			return $report;
		}
		$report['status'] = 'applied';
		$report['locale'] = $locale;
		return $report;
	}

	/**
	 * The effective site locale after the decision: the owner's value when one
	 * exists, otherwise the locale this import would apply.
	 *
	 * @param array<string,mixed> $decision Resolved decision.
	 * @return string
	 */
	public static function effective_locale( array $decision = array() ): string {
		$owner = self::owner_locale();
		if ( '' !== $owner ) {
			return $owner;
		}
		$locale = is_array( $decision ) ? (string) ( $decision['locale'] ?? '' ) : '';
		return '' !== $locale && 'apply' === ( $decision['mode'] ?? '' ) ? $locale : self::default_locale();
	}

	/** The locale the site owner already set, or '' when the site default runs. */
	private static function owner_locale(): string {
		if ( ! function_exists( 'get_option' ) ) {
			return '';
		}
		$owner = get_option( 'WPLANG', '' );
		return is_string( $owner ) ? trim( $owner ) : '';
	}

	/** The WordPress default locale when no owner value is set. */
	private static function default_locale(): string {
		return 'en_US';
	}

	/**
	 * Install a language pack through the core path. Returns the ready locale,
	 * or a typed reason when the pack cannot be installed.
	 *
	 * @param string $locale Target WordPress locale.
	 * @return string|WP_Error
	 */
	private static function ensure_language_pack( string $locale ) {
		if ( self::default_locale() === $locale ) {
			return $locale;
		}
		if ( ! defined( 'ABSPATH' ) || ! function_exists( 'get_available_languages' ) ) {
			return new WP_Error( 'language_pack_runtime_unavailable' );
		}
		if ( in_array( $locale, get_available_languages(), true ) ) {
			return $locale;
		}
		if ( ! function_exists( 'wp_download_language_pack' ) && is_readable( ABSPATH . 'wp-admin/includes/translation-install.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php'; // phpcs:ignore WordPress.WP.EnqueuedResourceParameters -- Core translation-install API for the core language-pack path.
		}
		if ( ! function_exists( 'wp_download_language_pack' ) || ! function_exists( 'wp_is_file_mod_allowed' ) ) {
			return new WP_Error( 'language_pack_runtime_unavailable' );
		}
		$installed = wp_download_language_pack( $locale );
		if ( is_string( $installed ) && '' !== $installed ) {
			return $installed;
		}
		return new WP_Error( 'language_pack_not_installed' );
	}

	/**
	 * WordPress-locale candidates for a source language tag, most specific
	 * first. Known mappings win; an explicit region falls back to the generic
	 * `lang_REGION` form.
	 *
	 * @param string $tag Validated source language tag.
	 * @return array<int,string>
	 */
	public static function locale_candidates( string $tag ): array {
		$tag = strtolower( trim( $tag ) );
		if ( '' === $tag ) {
			return array();
		}
		$subtags = array_values( array_filter( explode( '-', $tag ), static fn( string $subtag ): bool => '' !== $subtag ) );
		$primary = (string) $subtags[0];
		$candidates = array();
		if ( isset( self::REGION_LOCALES[ $tag ] ) ) {
			$candidates[] = self::REGION_LOCALES[ $tag ];
		} elseif ( isset( self::BARE_PRIMARY_REGIONS[ $tag ] ) ) {
			$candidates[] = self::BARE_PRIMARY_REGIONS[ $tag ];
		} elseif ( isset( $subtags[1] ) && 1 === preg_match( '/^[a-z]{2}$/', $subtags[1] ) ) {
			$candidates[] = $primary . '_' . strtoupper( $subtags[1] );
		}
		if ( isset( self::BARE_LOCALES[ $primary ] ) ) {
			$candidates[] = self::BARE_LOCALES[ $primary ];
		}
		return array_values( array_unique( $candidates ) );
	}

	/** The first WordPress-locale candidate for a source language tag. */
	public static function locale_from_language( string $tag ): string {
		foreach ( self::locale_candidates( $tag ) as $candidate ) {
			return $candidate;
		}
		return '';
	}

	/** The `<html lang>` of a page's document metadata, before validation. */
	private static function metadata_language( array $metadata ): string {
		$root = is_array( $metadata['root_attributes'] ?? null ) ? $metadata['root_attributes'] : array();
		if ( isset( $root['lang'] ) && is_scalar( $root['lang'] ) && '' !== trim( (string) $root['lang'] ) ) {
			return trim( (string) $root['lang'] );
		}
		foreach ( is_array( $metadata['meta'] ?? null ) ? $metadata['meta'] : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key = strtolower( trim( (string) ( $row['http_equiv'] ?? ( $row['name'] ?? '' ) ) ) );
			if ( 'content-language' === $key && isset( $row['content'] ) && is_scalar( $row['content'] ) && '' !== trim( (string) $row['content'] ) ) {
				return trim( (string) $row['content'] );
			}
		}
		return '';
	}

	/** Which metadata surface declared the language, for receipt evidence. */
	private static function metadata_source( array $metadata ): string {
		$root = is_array( $metadata['root_attributes'] ?? null ) ? $metadata['root_attributes'] : array();
		if ( isset( $root['lang'] ) && is_scalar( $root['lang'] ) && '' !== trim( (string) $root['lang'] ) ) {
			return 'html_lang';
		}
		return 'content_language';
	}

	/** Structural validation of a BCP 47-shaped source language tag. */
	private static function valid_language( string $tag ): string {
		$tag = trim( $tag );
		if ( '' === $tag || strlen( $tag ) > 35 || 1 !== preg_match( '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $tag ) ) {
			return '';
		}
		return $tag;
	}
}

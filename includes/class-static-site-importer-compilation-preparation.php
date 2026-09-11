<?php
/**
 * Prepares website artifacts for import materialization.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

foreach ( array(
	'Static_Site_Importer_Theme_Materialization_Strategy' => 'class-static-site-importer-theme-materialization-strategy.php',
	'Static_Site_Importer_Content_Policy'                 => 'class-static-site-importer-content-policy.php',
	'Static_Site_Importer_Client_Script_Policy'           => 'class-static-site-importer-client-script-policy.php',
	'Static_Site_Importer_Site_Identity'                  => 'class-static-site-importer-site-identity.php',
	'Static_Site_Importer_Compiler_Diagnostic_Normalizer' => 'class-static-site-importer-compiler-diagnostic-normalizer.php',
	'Static_Site_Importer_Report_Diagnostics'             => 'class-static-site-importer-report-diagnostics.php',
	'Static_Site_Importer_Companion_Plugin'               => 'class-static-site-importer-companion-plugin.php',
) as $class => $file ) {
	if ( ! class_exists( $class ) ) {
		require_once __DIR__ . '/' . $file;
	}
}

/** Coordinates SSI policy and adapter contracts around Blocks Engine compilation. */
final class Static_Site_Importer_Compilation_Preparation {

	/** Compile an artifact into its immutable canonical WordPress site plan. */
	public static function compile_website_artifact( array $artifact, array $args = array() ) {
		$precompiled = ! empty( $args['_static_site_importer_precompiled_source'] ) && is_array( $args['compiled_artifact_result'] ?? null );
		$strategy    = Static_Site_Importer_Theme_Materialization_Strategy::normalize( $args );
		if ( is_wp_error( $strategy ) ) {
			return $strategy;
		}
		$args['theme_materialization'] = $strategy['strategy'];
		$source_policy                 = $precompiled ? true : Static_Site_Importer_Content_Policy::validate_artifact( $artifact );
		if ( is_wp_error( $source_policy ) ) {
			return $source_policy;
		}
		$script_policy                       = $precompiled ? array(
			'artifact' => $artifact,
			'report'   => $args['source_metadata']['collection']['script_policy'] ?? array(),
		) : Static_Site_Importer_Client_Script_Policy::apply( $artifact, $args );
		$artifact                            = $script_policy['artifact'];
		$args['client_script_policy_report'] = $script_policy['report'];
		$compiler_class                      = 'Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler';
		if ( ! class_exists( $compiler_class ) ) {
			return new WP_Error( 'static_site_importer_missing_transformer', 'Blocks Engine php-transformer is required to import a website artifact.' );
		}
		// site_title (blogname) intentionally stays restricted to an explicit arg
		// or a real extracted document title; it never falls back to the host or
		// generic constant the way the theme name/slug do.
		if ( empty( $args['site_title'] ) ) {
			$site_title = Static_Site_Importer_Site_Identity::title_from_website_artifact( $artifact );
			if ( '' !== $site_title ) {
				$args['site_title'] = $site_title;
			}
		}
		$identity = Static_Site_Importer_Site_Identity::resolve(
			array(
				'site_title' => isset( $args['site_title'] ) ? (string) $args['site_title'] : '',
				'name'       => isset( $args['name'] ) ? (string) $args['name'] : '',
				'slug'       => isset( $args['slug'] ) ? (string) $args['slug'] : '',
				'artifact'   => $artifact,
				'url'        => isset( $args['url'] ) ? (string) $args['url'] : '',
			)
		);
		if ( empty( $args['name'] ) ) {
			$args['name'] = $identity['name'];
		}
		if ( empty( $args['slug'] ) ) {
			$args['slug'] = $identity['slug'];
		}
		if ( empty( $args['source_artifact_reference'] ) ) {
			$args['source_artifact_reference'] = self::source_artifact_reference_from_artifact( $artifact, $args );
		}

		// A URL batch run composes this canonical compiler result before the one
		// serialized WordPress mutation. Direct callers retain whole-artifact compilation.
		$supplied_compiled = isset( $args['compiled_artifact_result'] ) && is_array( $args['compiled_artifact_result'] );
		if ( $supplied_compiled ) {
			$compiled = $args['compiled_artifact_result'];
		} else {
			$compiler_result = ( new $compiler_class() )->compile( $artifact );
			$compiled        = $compiler_result->toWordPressSitePlanView();
		}
		$expected_schema = $supplied_compiled ? 'blocks-engine/wordpress-site-plan-view/v2' : 'blocks-engine/wordpress-site-plan-view/v1';
		if ( ( $compiled['schema'] ?? '' ) !== $expected_schema ) {
			return new WP_Error( 'static_site_importer_invalid_transformer_result', 'Blocks Engine php-transformer returned an invalid WordPress site plan view.' );
		}
		if ( $supplied_compiled ) {
			try {
				$compiled = \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanView::materialize( $compiled );
			} catch ( Throwable $error ) {
				return new WP_Error( 'static_site_importer_invalid_transformer_result', $error->getMessage() );
			}
		}
		$args['compiler_diagnostics'] = Static_Site_Importer_Compiler_Diagnostic_Normalizer::normalize( is_array( $compiled['diagnostics'] ?? null ) ? $compiled['diagnostics'] : array() );
		$plan                         = is_array( $compiled['wordpress_site_plan'] ?? null ) ? $compiled['wordpress_site_plan'] : array();
		if ( empty( $plan ) ) {
			$diagnostics = is_array( $compiled['diagnostics'] ?? null ) ? wp_json_encode( $compiled['diagnostics'] ) : '';
			return new WP_Error( 'static_site_importer_artifact_compile_failed', 'Website artifact compilation did not produce a WordPress site plan.' . ( false !== $diagnostics ? ' ' . $diagnostics : '' ), $compiled );
		}
		$args['missing_author_stylesheet_diagnostics'] = Static_Site_Importer_Report_Diagnostics::missing_author_stylesheet_diagnostics( $plan, $artifact );
		$companion_payload                             = null;
		$gutenberg_gaps                                = is_array( $compiled['gutenberg_gaps'] ?? null ) ? $compiled['gutenberg_gaps'] : array();
		if ( ! empty( $compiled['companion_plugin_payload'] ) ) {
			$companion_payload = $compiled['companion_plugin_payload'];
			if ( ! is_array( $companion_payload ) ) {
				return new WP_Error( 'static_site_importer_companion_plugin_payload_invalid', 'Compiled companion_plugin_payload must be an object.' );
			}
			if ( ! Static_Site_Importer_Companion_Plugin::has_materializable_content( $companion_payload ) ) {
				$companion_payload = null;
			} else {
				$companion_payload['site_slug'] = '' !== (string) ( $companion_payload['site_slug'] ?? '' ) ? (string) $companion_payload['site_slug'] : $args['slug'];
				$companion_payload['site_name'] = '' !== (string) ( $companion_payload['site_name'] ?? '' ) ? (string) $companion_payload['site_name'] : $args['name'];
				$companion_validation           = Static_Site_Importer_Companion_Plugin::validate_payload( $companion_payload );
				if ( is_wp_error( $companion_validation ) ) {
					return $companion_validation;
				}
			}
		}
		if ( isset( $args['approved_classic_plan_identity'] ) && is_array( $args['approved_classic_plan_identity'] ) && ( $plan['plan_identity'] ?? null ) !== $args['approved_classic_plan_identity'] ) {
			return new WP_Error( 'static_site_importer_approved_classic_plan_changed', 'Recompilation did not reproduce the approved canonical classic plan.' );
		}
		if ( Static_Site_Importer_Theme_Materialization_Strategy::CLASSIC === $strategy['strategy'] ) {
			if ( ! class_exists( 'Static_Site_Importer_Classic_Theme_Projection' ) ) {
				require_once __DIR__ . '/class-static-site-importer-classic-theme-projection.php';
			}
			$projection = Static_Site_Importer_Classic_Theme_Projection::build( $artifact, $plan );
			if ( is_wp_error( $projection ) ) {
				return $projection;
			}
			$args['classic_theme_projection'] = $projection;
			if ( isset( $args['approved_classic_projection_hash'] ) && is_string( $args['approved_classic_projection_hash'] ) && ! hash_equals( $args['approved_classic_projection_hash'], hash( 'sha256', (string) wp_json_encode( $projection ) ) ) ) {
				return new WP_Error( 'static_site_importer_approved_classic_projection_changed', 'Recompilation did not reproduce the approved classic projection.' );
			}
			$strategy['evidence']['status']            = 'source_artifact_projection';
			$strategy['evidence']['projection_schema'] = $projection['schema'];
		}
		return array(
			'artifact'              => $artifact,
			'args'                  => $args,
			'compiled'              => $compiled,
			'plan'                  => $plan,
			'gutenberg_gaps'        => $gutenberg_gaps,
			'companion_payload'     => $companion_payload,
			'theme_materialization' => $strategy['evidence'],
		);
	}

	/** Extract artifact identity fields supplied with a source artifact. */
	private static function source_artifact_reference_from_artifact( array $artifact, array $args = array() ): array {
		$reference = array(
			'schema'     => isset( $artifact['schema'] ) && is_scalar( $artifact['schema'] ) ? (string) $artifact['schema'] : '',
			'id'         => '',
			'hash'       => '',
			'hash_algo'  => '',
			'entrypoint' => isset( $artifact['entrypoint'] ) && is_scalar( $artifact['entrypoint'] ) ? (string) $artifact['entrypoint'] : '',
		);
		foreach ( array( 'artifact_id', 'id', 'run_id' ) as $key ) {
			if ( isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) && '' !== trim( (string) $args[ $key ] ) ) {
				$reference['id'] = (string) $args[ $key ];
				break;
			}
			if ( isset( $artifact[ $key ] ) && is_scalar( $artifact[ $key ] ) && '' !== trim( (string) $artifact[ $key ] ) ) {
				$reference['id'] = (string) $artifact[ $key ];
				break;
			}
		}
		foreach ( array( 'artifact_hash', 'hash', 'sha256' ) as $key ) {
			if ( isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) && '' !== trim( (string) $args[ $key ] ) ) {
				$reference['hash'] = (string) $args[ $key ];
				break;
			}
			if ( isset( $artifact[ $key ] ) && is_scalar( $artifact[ $key ] ) && '' !== trim( (string) $artifact[ $key ] ) ) {
				$reference['hash'] = (string) $artifact[ $key ];
				break;
			}
		}
		if ( isset( $args['artifact_hash_algo'] ) && is_scalar( $args['artifact_hash_algo'] ) ) {
			$reference['hash_algo'] = (string) $args['artifact_hash_algo'];
		} elseif ( isset( $artifact['hash_algo'] ) && is_scalar( $artifact['hash_algo'] ) ) {
			$reference['hash_algo'] = (string) $artifact['hash_algo'];
		} elseif ( isset( $artifact['sha256'] ) || isset( $args['sha256'] ) ) {
			$reference['hash_algo'] = 'sha256';
		}
		return array_filter( $reference, static fn ( $value ): bool => '' !== $value );
	}
}

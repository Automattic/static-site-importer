<?php
/**
 * Companion-plugin scaffolder.
 *
 * Generates a standalone, theme-independent WordPress plugin that houses a
 * site's metadata blocks and preserved island JS scoped to where it is used.
 *
 * Typed blocks carry their block.json metadata and are registered from their
 * directory, allowing WordPress to resolve declared editor and frontend assets.
 * The compiled artifact owns the block metadata + render + preserved-JS payload;
 * this class is the deterministic destination that turns that payload into an
 * installable plugin file set.
 *
 * The file-set builder is pure and side-effect free so it is testable without a
 * full WordPress runtime. The install/activate side effects live in
 * Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin().
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Site_Identity' ) ) {
	require_once __DIR__ . '/class-static-site-importer-site-identity.php';
}

if ( ! class_exists( 'Static_Site_Importer_Content_Policy' ) ) {
	require_once __DIR__ . '/class-static-site-importer-content-policy.php';
}
if ( ! class_exists( 'Static_Site_Importer_Generated_File' ) ) {
	require_once __DIR__ . '/class-static-site-importer-generated-file.php';
}
if ( ! class_exists( 'Static_Site_Importer_Provider_Form_Runtime_V1' ) ) {
	require_once __DIR__ . '/class-static-site-importer-provider-form-runtime.php';
}
if ( ! class_exists( 'Static_Site_Importer_Build_Provenance' ) ) {
	require_once __DIR__ . '/class-static-site-importer-build-provenance.php';
}

/**
 * Scaffolds a one-per-site companion plugin from a generated block payload.
 */
class Static_Site_Importer_Companion_Plugin {

	/** Maximum declared script files and dependency handles per block. */
	private const MAX_SCRIPT_DEPENDENCIES = 32;

	/** SSI-owned renderer available to typed responsive-media blocks. */
	private const RESPONSIVE_MEDIA_RENDERER = 'blocks-engine/responsive-media/v1';

	/** SSI-owned renderer available to typed responsive-layout blocks. */
	private const RESPONSIVE_LAYOUT_RENDERER = 'blocks-engine/responsive-layout/v1';

	/** SSI-owned renderer available to typed inline SVG artwork blocks. */
	private const SVG_ARTWORK_RENDERER = 'blocks-engine/svg-artwork/v1';

	/**
	 * Payload schema identifier consumed by the scaffolder.
	 */
	public const PAYLOAD_SCHEMA = 'blocks-engine/wordpress-companion-plugin/v1';

	/**
	 * Validate a canonical compiled companion payload before any WordPress writes.
	 *
	 * @param array<string,mixed> $payload Generated companion-plugin payload.
	 * @return true|WP_Error
	 */
	public static function validate_payload( array $payload ) {
		if ( self::PAYLOAD_SCHEMA !== ( $payload['schema'] ?? null ) ) {
			return new WP_Error( 'static_site_importer_companion_plugin_schema_invalid', 'Companion-plugin payload must use blocks-engine/wordpress-companion-plugin/v1.' );
		}
		if ( '' === self::site_slug( $payload ) ) {
			return new WP_Error( 'static_site_importer_companion_plugin_site_slug_missing', 'Companion-plugin payload must declare a non-empty site_slug.' );
		}
		if ( ! preg_match( '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', self::site_slug( $payload ) ) ) {
			return new WP_Error( 'static_site_importer_companion_plugin_site_slug_invalid', 'Companion-plugin site_slug must resolve to an ASCII slug safe for PHP identifiers and file paths.' );
		}
		if ( array_key_exists( 'provenance', $payload ) && null !== $payload['provenance'] && ! Static_Site_Importer_Build_Provenance::valid_artifact_provenance( $payload['provenance'] ) ) {
			return new WP_Error( 'static_site_importer_companion_plugin_provenance_invalid', 'Companion-plugin provenance must declare blocks-engine/generated-artifact-provenance/v1 with generator, engine_version, and artifact_hash.' );
		}
		$blocks = $payload['blocks'] ?? array();
		if ( ! is_array( $blocks ) || ! array_is_list( $blocks ) ) {
			return new WP_Error( 'static_site_importer_companion_plugin_blocks_invalid', 'Companion-plugin blocks must be an array.' );
		}
		$names       = array();
		$block_names = array();
		$namespace   = self::block_namespace( $payload );
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) || array_is_list( $block ) ) {
				return new WP_Error( 'static_site_importer_companion_plugin_block_invalid', sprintf( 'Companion-plugin block %d must be an object.', $index ) );
			}
			$name = isset( $block['name'] ) && is_string( $block['name'] ) ? $block['name'] : '';
			if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name ) || isset( $names[ $name ] ) ) {
				return new WP_Error( 'static_site_importer_companion_plugin_block_name_invalid', 'Companion-plugin block names must be unique lowercase slugs.' );
			}
			$names[ $name ] = true;
			if ( ! isset( $block['block_json'] ) || ! is_array( $block['block_json'] ) || array_is_list( $block['block_json'] ) ) {
				return new WP_Error( 'static_site_importer_companion_plugin_block_json_invalid', sprintf( 'Block %s must declare block_json as an object.', $name ) );
			}
			$declared_name = $block['block_json']['name'] ?? '';
			if ( '' !== $declared_name ) {
				if ( ! is_string( $declared_name ) || ! preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $declared_name ) ) {
					return new WP_Error( 'static_site_importer_companion_plugin_block_json_name_invalid', sprintf( 'Block %s must declare a valid WordPress block name.', $name ) );
				}
				if ( str_starts_with( $declared_name, 'core/' ) ) {
					return new WP_Error(
						'static_site_importer_companion_plugin_block_json_name_reserved',
						sprintf( 'Block %s cannot declare the reserved WordPress core block name %s.', $name, $declared_name ),
						array(
							'block'      => $name,
							'block_name' => $declared_name,
						)
					);
				}
			}
			$effective_name = '' !== $declared_name ? $declared_name : $namespace . '/' . $name;
			if ( isset( $block_names[ $effective_name ] ) ) {
				return new WP_Error( 'static_site_importer_companion_plugin_block_json_name_invalid', sprintf( 'Block %s resolves to a duplicate WordPress block name.', $name ) );
			}
			$block_names[ $effective_name ] = true;
			$declared_assets                = $block['assets'] ?? array();
			if ( ! is_array( $declared_assets ) || ( ! empty( $declared_assets ) && array_is_list( $declared_assets ) ) ) {
				return new WP_Error( 'static_site_importer_companion_plugin_assets_invalid', sprintf( 'Block %s assets must be an object.', $name ) );
			}
			$assets = self::block_assets( $block );
			if ( is_wp_error( $assets ) ) {
				return $assets;
			}
			foreach ( $assets as $path => $content ) {
				if ( self::sanitize_relative_path( $path ) !== $path || ! Static_Site_Importer_Content_Policy::is_companion_asset_path( $path ) || ! is_scalar( $content ) || Static_Site_Importer_Content_Policy::contains_server_code( (string) $content ) ) {
					return new WP_Error( 'static_site_importer_companion_plugin_asset_path_invalid', sprintf( 'Block %s has an unsafe asset path.', $name ) );
				}
			}
			$renderer = $block['renderer'] ?? null;
			if ( null !== $renderer ) {
				if ( ! is_string( $renderer ) || '' === self::typed_renderer( $renderer ) ) {
					return new WP_Error( 'static_site_importer_companion_plugin_renderer_invalid', sprintf( 'Block %s must declare a supported typed renderer.', $name ) );
				}
				if ( array_key_exists( 'render', $block ) ) {
					return new WP_Error( 'static_site_importer_companion_plugin_renderer_conflict', sprintf( 'Block %s cannot declare both render markup and a typed renderer.', $name ) );
				}
				$attribute_name = self::SVG_ARTWORK_RENDERER === $renderer ? 'svg' : 'content';
				$content_schema = $block['block_json']['attributes'][ $attribute_name ] ?? null;
				if ( ! is_array( $content_schema ) || 'string' !== ( $content_schema['type'] ?? null ) ) {
					return new WP_Error( 'static_site_importer_companion_plugin_renderer_attributes_invalid', sprintf( 'Block %s typed renderer requires a string %s attribute.', $name, $attribute_name ) );
				}
			}
			if ( isset( $block['render'] ) && is_scalar( $block['render'] ) && Static_Site_Importer_Content_Policy::contains_server_code( (string) $block['render'] ) ) {
				return new WP_Error( 'static_site_importer_companion_plugin_render_invalid', sprintf( 'Block %s render markup must be static HTML.', $name ) );
			}
			$metadata = $block['block_json'];
			if ( ( isset( $block['render'] ) && is_scalar( $block['render'] ) ) || null !== $renderer ) {
				$metadata['render'] = 'file:./render.php';
			}
			$script_dependencies = self::validate_script_dependencies( $block, $assets, $metadata );
			if ( is_wp_error( $script_dependencies ) ) {
				return $script_dependencies;
			}
			$references = self::metadata_file_references( $metadata );
			foreach ( $references as $path ) {
				if ( ! array_key_exists( $path, $assets ) && ! ( 'render.php' === $path && ( ( isset( $block['render'] ) && is_scalar( $block['render'] ) ) || null !== $renderer ) ) ) {
					return new WP_Error( 'static_site_importer_companion_plugin_metadata_asset_missing', sprintf( 'Block %s metadata references undeclared asset %s.', $name, $path ) );
				}
			}
		}
		foreach ( $payload['preserved_js'] ?? array() as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['src'] ) ) {
				continue;
			}
			$src = is_string( $entry['src'] ) ? $entry['src'] : '';
			if ( self::sanitize_relative_path( $src ) !== $src ) {
				return new WP_Error( 'static_site_importer_companion_plugin_asset_path_invalid', 'Companion-plugin preserved script has an unsafe asset path.' );
			}
		}
		if ( isset( $payload['form_visual_states'] ) && ( ! is_array( $payload['form_visual_states'] ) || ! array_is_list( $payload['form_visual_states'] ) || count( $payload['form_visual_states'] ) > 128 || array_filter( $payload['form_visual_states'], static fn( $state ): bool => ! Static_Site_Importer_Provider_Form_Runtime_V1::valid_visual_state( $state ) ) ) ) {
			return new WP_Error( 'static_site_importer_companion_plugin_form_visual_states_invalid', 'Companion form visual states must be a list.' );
		}
		$editor_scripts = self::validate_editor_scripts( $payload );
		if ( is_wp_error( $editor_scripts ) ) {
			return $editor_scripts;
		}
		$effects = self::runtime_effects( $payload );
		foreach ( $effects['retained_modules'] as $module ) {
			$unit = $effects['units'][ $module['unit_id'] ] ?? array();
			if ( 'independently_suppressible' !== ( $unit['status'] ?? '' ) || ! hash_equals( (string) ( $unit['source']['hash'] ?? '' ), hash( 'sha256', (string) $module['content'] ) ) ) {
				return new WP_Error( 'static_site_importer_runtime_effect_invalid', 'Retained runtime modules must map to hash-verified independently suppressible units.' );
			}
		}
		return true;
	}

	/**
	 * Build the standalone plugin scaffold from a generated payload.
	 *
	 * The returned descriptor carries the namespaced slug, the plugin basename
	 * used as a satisfied-dependency key, the fully-qualified block names, and
	 * the relative-path => file-content map that the install path materializes.
	 *
	 * @param array<string,mixed> $payload Generated companion-plugin payload.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function scaffold( array $payload ) {
		$validation = self::validate_payload( $payload );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		$site_slug = self::site_slug( $payload );
		if ( '' === $site_slug ) {
			return new WP_Error(
				'static_site_importer_companion_plugin_site_slug_missing',
				'Companion-plugin payload must declare a non-empty site_slug.'
			);
		}

		$blocks             = self::payload_blocks( $payload );
		$plugin_slug        = 'ssi-' . $site_slug;
		$block_namespace    = self::block_namespace( $payload );
		$preserved          = self::preserved_js( $payload, $block_namespace );
		$editor_scripts     = self::editor_scripts( $payload );
		$form_visual_states = is_array( $payload['form_visual_states'] ?? null ) ? $payload['form_visual_states'] : array();
		if ( empty( $blocks ) && empty( $preserved ) && empty( $editor_scripts ) && empty( $form_visual_states ) ) {
			return new WP_Error(
				'static_site_importer_companion_plugin_content_missing',
				'Companion-plugin payload must declare at least one block, preserved script, or editor script.'
			);
		}

		$mu_plugin = ! empty( $payload['mu_plugin'] );
		$site_name = self::site_name( $payload, $site_slug );

		$files             = array();
		$block_names       = array();
		$block_directories = array();

		foreach ( $blocks as $block ) {
			$built = self::build_block( $block, $block_namespace );
			if ( is_wp_error( $built ) ) {
				return $built;
			}

			$block_names[]       = $built['block_name'];
			$block_directories[] = $built['dir'];
			foreach ( $built['files'] as $relative => $content ) {
				$files[ $plugin_slug . '/blocks/' . $built['dir'] . '/' . $relative ] = $content;
			}
		}

		foreach ( $preserved as $island ) {
			$files[ $plugin_slug . '/' . $island['relative_src'] ] = $island['content'];
		}
		foreach ( $editor_scripts as $script ) {
			$files[ $plugin_slug . '/' . $script['src'] ] = $script['content'];
		}
		$provider_form_runtime = file_get_contents( __DIR__ . '/class-static-site-importer-provider-form-runtime.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the versioned runtime source that generated companions own independently.
		if ( ! is_string( $provider_form_runtime ) || '' === $provider_form_runtime ) {
			return new WP_Error( 'static_site_importer_companion_plugin_provider_form_runtime_missing', 'Provider form runtime projection file is unavailable.' );
		}
		$internal_link_runtime = file_get_contents( __DIR__ . '/class-static-site-importer-internal-link-runtime.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the versioned runtime source that generated companions own independently.
		if ( ! is_string( $internal_link_runtime ) || '' === $internal_link_runtime ) {
			return new WP_Error( 'static_site_importer_companion_plugin_internal_link_runtime_missing', 'Internal link runtime projection file is unavailable.' );
		}

		$inventory_source = array( $block_names, $preserved, $form_visual_states, hash( 'sha256', $provider_form_runtime ), hash( 'sha256', $internal_link_runtime ) );
		if ( ! empty( $editor_scripts ) ) {
			$inventory_source[] = $editor_scripts;
		}
		$inventory_hash        = substr( hash( 'sha256', (string) wp_json_encode( $inventory_source ) ), 0, 16 );
		$registration_callback = str_replace( '-', '_', $plugin_slug ) . '_' . $inventory_hash . '_register_blocks';
		$runtime_class         = strtoupper( str_replace( '-', '_', $plugin_slug ) ) . '_Provider_Form_Runtime_V1';
		$link_runtime_class    = strtoupper( str_replace( '-', '_', $plugin_slug ) ) . '_Internal_Link_Runtime';
		$main_file             = $plugin_slug . '/' . $plugin_slug . '.php';
		$config                = wp_json_encode(
			array(
				'site_name'          => $site_name,
				'plugin_file'        => $main_file,
				'block_directories'  => $block_directories,
				'islands'            => array_map(
					static fn ( array $island ): array => array(
						'handle'      => $island['handle'],
						'src'         => $island['relative_src'],
						'block'       => $island['block'],
						'selector'    => $island['selector'],
						'source_path' => $island['source_path'],
					),
					$preserved
				),
				'editor_scripts'     => array_map(
					static fn ( array $script ): array => array(
						'handle'       => $script['handle'],
						'src'          => $script['src'],
						'dependencies' => $script['dependencies'],
					),
					$editor_scripts
				),
				'form_visual_states' => $form_visual_states,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		if ( false === $config ) {
			return new WP_Error( 'static_site_importer_companion_plugin_config_invalid', 'Companion configuration could not be encoded as JSON.' );
		}
		$files[ $plugin_slug . '/companion.json' ]                        = $config . "\n";
		$files[ $plugin_slug . '/includes/provider-form-runtime-v1.php' ] = self::provider_form_runtime_file( $provider_form_runtime, $runtime_class );
		$files[ $plugin_slug . '/includes/internal-link-runtime.php' ]    = self::internal_link_runtime_file( $internal_link_runtime, $link_runtime_class );
		$files = array_merge(
			array(
				$main_file => self::main_plugin_file( $plugin_slug, $inventory_hash, $runtime_class, $link_runtime_class, self::artifact_provenance( $payload ) ),
			),
			$files
		);

		$descriptor = array(
			'schema'                => self::PAYLOAD_SCHEMA,
			'slug'                  => $plugin_slug,
			'namespace'             => $block_namespace,
			'site_slug'             => $site_slug,
			'plugin_file'           => $main_file,
			'registration_callback' => $registration_callback,
			'mu_plugin'             => $mu_plugin,
			'block_names'           => $block_names,
			// Handles of preserved island scripts the plugin carries + enqueues
			// scoped. Exposed so the gate/diagnostics can account for preserved
			// island JS as companion-plugin-carried (theme-independent) rather
			// than theme-coupled.
			'island_handles'        => array_map(
				static fn ( array $island ): string => (string) $island['handle'],
				$preserved
			),
			'runtime_scripts'       => array_map(
				static fn ( array $island ): array => array(
					'handle'          => (string) $island['handle'],
					'block'           => (string) $island['block'],
					'selector'        => (string) $island['selector'],
					'source_path'     => (string) $island['source_path'],
					'superseded_unit' => (string) ( $island['superseded_unit'] ?? '' ),
				),
				$preserved
			),
			'loader_file'           => '',
			'files'                 => $files,
		);

		if ( $mu_plugin ) {
			// mu-plugins only auto-load PHP files at the mu-plugins root, never
			// subdirectory files. Emit a root loader stub that requires the real
			// plugin file so the same directory layout works in both modes.
			$loader                    = $plugin_slug . '.php';
			$descriptor['loader_file'] = $loader;
			$descriptor['files']       = array_merge(
				array( $loader => self::mu_loader_file( $main_file ) ),
				$descriptor['files']
			);
		}

		return $descriptor;
	}

	/**
	 * Namespaced plugin slug, e.g. ssi-acme, for a payload.
	 *
	 * @param array<string,mixed> $payload Generated companion-plugin payload.
	 * @return string
	 */
	public static function plugin_slug( array $payload ): string {
		$site_slug = self::site_slug( $payload );
		return '' === $site_slug ? '' : 'ssi-' . $site_slug;
	}

	/**
	 * The block namespace a payload actually resolved its blocks under.
	 *
	 * The payload's blocks carry their resolved fully-qualified names in
	 * block_json.name, so the namespace the producer resolved is read from
	 * there instead of being re-derived from site_slug: a producer/consumer
	 * mismatch becomes impossible rather than merely unlikely. Blocks without
	 * declared names keep the historical `ssi-<site_slug>` fallback namespace.
	 *
	 * @param array<string,mixed> $payload Generated companion-plugin payload.
	 * @return string
	 */
	public static function block_namespace( array $payload ): string {
		$site_slug = self::site_slug( $payload );
		$fallback  = '' === $site_slug ? '' : 'ssi-' . $site_slug;
		foreach ( self::payload_blocks( $payload ) as $block ) {
			$declared_name = is_string( $block['block_json']['name'] ?? null ) ? $block['block_json']['name'] : '';
			$namespace     = str_contains( $declared_name, '/' ) ? strtok( $declared_name, '/' ) : false;
			if ( false !== $namespace && 1 === preg_match( '/^[a-z][a-z0-9-]*$/', (string) $namespace ) && 'core' !== (string) $namespace ) {
				return (string) $namespace;
			}
		}

		return $fallback;
	}

	/**
	 * Plugin basename used as the satisfied-dependency key.
	 *
	 * @param array<string,mixed> $payload Generated companion-plugin payload.
	 * @return string
	 */
	public static function plugin_file( array $payload ): string {
		$slug = self::plugin_slug( $payload );
		return '' === $slug ? '' : $slug . '/' . $slug . '.php';
	}

	/** Whether a payload requires a companion plugin. */
	public static function has_materializable_content( array $payload ): bool {
		if ( ! empty( self::payload_blocks( $payload ) ) ) {
			return true;
		}

		foreach ( is_array( $payload['preserved_js'] ?? null ) ? $payload['preserved_js'] : array() as $entry ) {
			if ( is_array( $entry ) && isset( $entry['content'] ) && is_scalar( $entry['content'] ) && '' !== (string) $entry['content'] ) {
				return true;
			}
		}
		foreach ( is_array( $payload['runtime_effects']['retained_modules'] ?? null ) ? $payload['runtime_effects']['retained_modules'] : array() as $module ) {
			if ( is_array( $module ) && isset( $module['content'] ) && is_scalar( $module['content'] ) && '' !== (string) $module['content'] ) {
				return true;
			}
		}
		foreach ( is_array( $payload['editor_scripts'] ?? null ) ? $payload['editor_scripts'] : array() as $entry ) {
			if ( is_array( $entry ) && isset( $entry['content'] ) && is_scalar( $entry['content'] ) && '' !== (string) $entry['content'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sanitized site slug from the payload.
	 *
	 * @param array<string,mixed> $payload Generated companion-plugin payload.
	 * @return string
	 */
	private static function site_slug( array $payload ): string {
		$raw = isset( $payload['site_slug'] ) && is_scalar( $payload['site_slug'] ) ? (string) $payload['site_slug'] : '';
		return self::sanitize_slug( $raw );
	}

	/**
	 * Human-readable site name for plugin headers.
	 *
	 * The display name follows the shared site-identity priority (site_name ->
	 * name -> site_title) so the companion plugin header matches the theme name
	 * derived from the same source. A payload that carries only a slug keeps the
	 * slug as its display name rather than the generic identity constant.
	 *
	 * @param array<string,mixed> $payload   Generated companion-plugin payload.
	 * @param string              $site_slug Sanitized site slug.
	 * @return string
	 */
	private static function site_name( array $payload, string $site_slug ): string {
		$identity = Static_Site_Importer_Site_Identity::resolve(
			array(
				'site_title' => isset( $payload['site_name'] ) && is_scalar( $payload['site_name'] ) ? (string) $payload['site_name'] : '',
				'name'       => isset( $payload['name'] ) && is_scalar( $payload['name'] ) ? (string) $payload['name'] : '',
				'title'      => isset( $payload['site_title'] ) && is_scalar( $payload['site_title'] ) ? (string) $payload['site_title'] : '',
			)
		);

		$name = $identity['name'];
		if ( Static_Site_Importer_Site_Identity::DEFAULT_NAME === $name || Static_Site_Importer_Site_Identity::default_name() === $name ) {
			return $site_slug;
		}

		return $name;
	}

	/**
	 * Normalize the block list from the payload.
	 *
	 * @param array<string,mixed> $payload Generated companion-plugin payload.
	 * @return array<int,array<string,mixed>>
	 */
	private static function payload_blocks( array $payload ): array {
		$blocks = isset( $payload['blocks'] ) && is_array( $payload['blocks'] ) ? $payload['blocks'] : array();
		return array_values( array_filter( $blocks, 'is_array' ) );
	}

	/**
	 * Build one metadata block directory. Blocks with a render payload also
	 * receive a normalized render.php.
	 *
	 * @param array<string,mixed> $block           Block payload entry.
	 * @param string              $block_namespace Plugin block namespace.
	 * @return array{block_name:string,dir:string,files:array<string,string>}|WP_Error
	 */
	private static function build_block( array $block, string $block_namespace ) {
		$name = isset( $block['name'] ) && is_scalar( $block['name'] ) ? self::sanitize_slug( (string) $block['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error(
				'static_site_importer_companion_plugin_block_name_missing',
				'Each companion-plugin block must declare a sanitizable name.'
			);
		}

		$declared_name = is_string( $block['block_json']['name'] ?? null ) ? $block['block_json']['name'] : '';
		$block_name    = preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $declared_name ) ? $declared_name : $block_namespace . '/' . $name;
		$renderer      = isset( $block['renderer'] ) && is_string( $block['renderer'] ) ? $block['renderer'] : '';
		$has_render    = ( isset( $block['render'] ) && is_scalar( $block['render'] ) ) || '' !== $renderer;
		$render        = isset( $block['render'] ) && is_scalar( $block['render'] ) ? (string) $block['render'] : '';
		$files         = array();
		if ( '' !== $renderer ) {
			$files['render.php'] = self::typed_renderer( $renderer );
		} elseif ( self::has_editable_content_render( $block ) ) {
			$files['render.php'] = self::editable_content_renderer();
		} elseif ( $has_render ) {
			$files['render.php'] = self::static_render_file();
			if ( '' !== trim( $render ) ) {
				$render_json = wp_json_encode( $render );
				if ( false === $render_json ) {
					return new WP_Error( 'static_site_importer_companion_plugin_render_invalid', 'Static render markup could not be encoded as JSON.' );
				}
				$files['render.json'] = $render_json . "\n";
			}
		}

		// Carried static assets (e.g. block stylesheets or a hand-written
		// Interactivity API view module) ride alongside render.php. These are
		// pass-through files, not generated JS build output.
		$assets = self::block_assets( $block );
		if ( is_wp_error( $assets ) ) {
			return $assets;
		}
		foreach ( $assets as $relative => $content ) {
			$relative = self::sanitize_relative_path( (string) $relative );
			if ( '' === $relative || ! is_scalar( $content ) ) {
				continue;
			}
			if ( isset( $files[ $relative ] ) ) {
				return new WP_Error( 'static_site_importer_companion_plugin_asset_conflict', 'Source assets cannot replace generated runtime files.' );
			}
			$files[ $relative ] = (string) $content;
		}
		foreach ( self::script_dependencies( $block ) as $relative => $dependencies ) {
			$manifest_path = self::asset_manifest_path( $relative );
			$json_path     = substr( $manifest_path, 0, -4 ) . '.json';
			if ( isset( $files[ $manifest_path ] ) || isset( $files[ $json_path ] ) ) {
				return new WP_Error( 'static_site_importer_companion_plugin_asset_conflict', 'Source assets cannot replace generated dependency manifests.' );
			}
			$files[ $json_path ]     = wp_json_encode( array(
				'dependencies' => $dependencies,
				'version'      => hash( 'sha256', (string) $assets[ $relative ] ),
			) ) . "\n";
			$files[ $manifest_path ] = "<?php\nreturn json_decode( (string) file_get_contents( substr( __FILE__, 0, -4 ) . '.json' ), true, 512, JSON_THROW_ON_ERROR );\n";
		}
		$block_json         = $block['block_json'];
		$block_json['name'] = $block_name;
		if ( $has_render ) {
			$block_json['render'] = 'file:./render.php';
		}
		$json = wp_json_encode( $block_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return new WP_Error( 'static_site_importer_companion_plugin_block_json_invalid', sprintf( 'Block %s block_json could not be encoded.', $block_name ) );
		}
		$files['block.json'] = $json . "\n";

		return array(
			'block_name' => $block_name,
			'dir'        => $name,
			'files'      => $files,
		);
	}

	/**
	 * Normalize producer-owned dedicated assets into the metadata file map.
	 *
	 * Blocks Engine carries a generated block's audited frontend script in the
	 * established `view_js` slot. WordPress block metadata addresses that payload
	 * as `file:./view.js`, so validation and scaffolding must see one canonical
	 * asset regardless of which producer representation supplied it.
	 *
	 * @return array<array-key,mixed>|WP_Error
	 */
	private static function block_assets( array $block ) {
		$assets = isset( $block['assets'] ) && is_array( $block['assets'] ) ? $block['assets'] : array();
		if ( ! array_key_exists( 'view_js', $block ) ) {
			return $assets;
		}
		if ( ! is_scalar( $block['view_js'] ) || Static_Site_Importer_Content_Policy::contains_server_code( (string) $block['view_js'] ) ) {
			return new WP_Error( 'static_site_importer_companion_plugin_view_script_invalid', 'Companion block view_js must contain safe JavaScript.' );
		}
		$view_js = (string) $block['view_js'];
		if ( isset( $assets['view.js'] ) && (string) $assets['view.js'] !== $view_js ) {
			return new WP_Error( 'static_site_importer_companion_plugin_view_script_conflict', 'Companion block view_js conflicts with its declared view.js asset.' );
		}
		$assets['view.js'] = $view_js;
		return $assets;
	}

	/**
	 * Normalize preserved island JS entries into a scoped descriptor list.
	 *
	 * @param array<string,mixed> $payload   Generated companion-plugin payload.
	 * @param string              $block_namespace Plugin block namespace.
	 * @return array<int,array<string,string>>
	 */
	private static function preserved_js( array $payload, string $block_namespace ): array {
		$entries = isset( $payload['preserved_js'] ) && is_array( $payload['preserved_js'] ) ? $payload['preserved_js'] : array();
		$effects = self::runtime_effects( $payload );
		foreach ( $effects['retained_modules'] as $module ) {
			$entries[] = array(
				'handle'          => 'runtime-unit-' . $module['unit_id'],
				'content'         => $module['content'],
				'block'           => $module['block'],
				'selector'        => $module['selector'],
				'source_path'     => $module['source_path'],
				'superseded_unit' => $module['unit_id'],
			);
		}
		$islands = array();
		$index   = 0;

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$content = isset( $entry['content'] ) && is_scalar( $entry['content'] ) ? (string) $entry['content'] : '';
			if ( '' === $content ) {
				continue;
			}

			++$index;
			$handle_raw      = isset( $entry['handle'] ) && is_scalar( $entry['handle'] ) ? self::sanitize_slug( (string) $entry['handle'] ) : '';
			$handle          = '' !== $handle_raw ? $handle_raw : $block_namespace . '-island-' . $index;
			$relative_raw    = isset( $entry['src'] ) && is_scalar( $entry['src'] ) ? self::sanitize_relative_path( (string) $entry['src'] ) : '';
			$relative        = '' !== $relative_raw ? $relative_raw : 'islands/' . $handle . '.js';
			$block           = isset( $entry['block'] ) && is_scalar( $entry['block'] ) ? (string) $entry['block'] : '';
			$selector        = isset( $entry['selector'] ) && is_scalar( $entry['selector'] ) ? (string) $entry['selector'] : '';
			$source_path     = isset( $entry['source_path'] ) && is_scalar( $entry['source_path'] ) ? (string) $entry['source_path'] : '';
			$superseded_unit = isset( $entry['superseded_unit'] ) && is_scalar( $entry['superseded_unit'] ) ? (string) $entry['superseded_unit'] : '';

			$islands[] = array(
				'handle'          => $handle,
				'relative_src'    => $relative,
				'content'         => $content,
				// Scope: enqueue only when this block renders. Empty block means
				// the island is unscoped, but slice 1 only emits scoped islands.
				'block'           => $block,
				'selector'        => $selector,
				'source_path'     => $source_path,
				'superseded_unit' => $superseded_unit,
			);
		}

		return $islands;
	}

	/**
	 * Normalize the generic Blocks Engine AST ownership contract. Malformed
	 * contracts yield no retained assets; validate_payload() rejects them.
	 *
	 * @return array{units:array<string,array<string,mixed>>,retained_modules:array<int,array<string,string>>}
	 */
	private static function runtime_effects( array $payload ): array {
		$effects = isset( $payload['runtime_effects'] ) && is_array( $payload['runtime_effects'] ) ? $payload['runtime_effects'] : array();
		$units   = array();
		foreach ( $effects['units'] ?? array() as $unit ) {
			if ( is_array( $unit ) && isset( $unit['id'] ) && is_scalar( $unit['id'] ) ) {
				$units[ (string) $unit['id'] ] = $unit;
			}
		}
		$modules = array();
		foreach ( $effects['retained_modules'] ?? array() as $module ) {
			if ( ! is_array( $module ) || ! isset( $module['unit_id'], $module['content'] ) || ! is_scalar( $module['unit_id'] ) || ! is_scalar( $module['content'] ) ) {
				continue;
			}
			$modules[] = array(
				'unit_id'     => (string) $module['unit_id'],
				'content'     => (string) $module['content'],
				'block'       => isset( $module['block'] ) && is_scalar( $module['block'] ) ? (string) $module['block'] : '',
				'selector'    => isset( $module['selector'] ) && is_scalar( $module['selector'] ) ? (string) $module['selector'] : '',
				'source_path' => isset( $module['source_path'] ) && is_scalar( $module['source_path'] ) ? (string) $module['source_path'] : '',
			);
		}
		return array(
			'units'            => $units,
			'retained_modules' => $modules,
		);
	}

	/**
	 * The validated artifact provenance record a payload carries, if any.
	 *
	 * @param array<string,mixed> $payload Generated companion-plugin payload.
	 * @return array<string,mixed> Provenance record, or array() when absent.
	 */
	private static function artifact_provenance( array $payload ): array {
		$provenance = $payload['provenance'] ?? null;

		return is_array( $provenance ) && Static_Site_Importer_Build_Provenance::valid_artifact_provenance( $provenance ) ? $provenance : array();
	}

	/**
	 * Render the main plugin PHP file.
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @param string                          $inventory_hash  Deterministic generated inventory hash.
	 * @param array<string,mixed>             $artifact_provenance Producer artifact provenance, when carried.
	 * @return string
	 */
	private static function main_plugin_file(
		string $plugin_slug,
		string $inventory_hash,
		string $runtime_class,
		string $link_runtime_class,
		array $artifact_provenance = array()
	): string {
		$fn_prefix = str_replace( '-', '_', $plugin_slug ) . '_' . $inventory_hash;

		$lines   = array();
		$lines[] = '<?php';
		$lines[] = '/**';
		$lines[] = ' * Plugin Name: SSI Companion';
		$lines[] = ' * Description: Generated companion plugin housing metadata blocks and preserved island JS.';
		// A provenance-carrying build stamps the real producing-build version
		// and an Update URI identifying this artifact, so the plugin remains
		// attributable and updatable after SSI itself is removed.
		$version_line    = ' * Version: 1.0.0';
		$update_uri_line = '';
		foreach ( Static_Site_Importer_Build_Provenance::artifact_header_lines( $artifact_provenance, $plugin_slug ) as $header_line ) {
			if ( str_starts_with( $header_line, 'Version: ' ) ) {
				$version_line = ' * ' . $header_line;
			} else {
				$update_uri_line = ' * ' . $header_line;
			}
		}
		$lines[] = $version_line;
		if ( '' !== $update_uri_line ) {
			$lines[] = $update_uri_line;
		}
		$lines[] = ' * Requires at least: 6.9';
		$lines[] = ' * Requires PHP: 8.1';
		$lines[] = ' * Text Domain: ' . $plugin_slug;
		$lines[] = ' *';
		$lines[] = ' * Blocks register from block.json directories.';
		$lines[] = ' *';
		$lines[] = ' * @package StaticSiteImporterCompanion';
		$lines[] = ' */';
		$lines[] = '';
		$lines[] = "if ( ! defined( 'ABSPATH' ) ) {";
		$lines[] = "\texit;";
		$lines[] = '}';
		$lines[] = '';
		$lines[] = sprintf( 'function %s_config() {', $fn_prefix );
		$lines[] = "\t\$config = json_decode( (string) file_get_contents( __DIR__ . '/companion.json' ), true );";
		$lines[] = "\treturn is_array( \$config ) ? \$config : array();";
		$lines[] = '}';
		$lines[] = '';
		$lines[] = "require_once __DIR__ . '/includes/provider-form-runtime-v1.php';";
		$lines[] = "require_once __DIR__ . '/includes/internal-link-runtime.php';";
		$lines[] = $runtime_class . '::configure_visual_states( ' . $fn_prefix . "_config()['form_visual_states'] ?? array() );";
		$lines[] = $runtime_class . '::register();';
		$lines[] = $link_runtime_class . '::register();';
		$lines[] = '';
		$lines[] = '/**';
		$lines[] = ' * Register generated blocks from their metadata directories.';
		$lines[] = ' */';
		$lines[] = sprintf( 'function %s_register_blocks() {', $fn_prefix );
		$lines[] = "\tif ( ! function_exists( 'register_block_type' ) ) {";
		$lines[] = "\t\treturn;";
		$lines[] = "\t}";
		$lines[] = '';
		$lines[] = sprintf( "\tforeach ( %s_config()['block_directories'] ?? array() as \$block_dir ) {", $fn_prefix );
		$lines[] = "\t\tif ( ! is_string( \$block_dir ) ) { continue; }";
		$lines[] = "\t\t\$registered = register_block_type( __DIR__ . '/blocks/' . \$block_dir );";
		$lines[] = "\t\tif ( \$registered instanceof WP_Block_Type ) {";
		$lines[] = "\t\t\tif ( ! isset( \$GLOBALS['static_site_importer_companion_block_owners'] ) || ! is_array( \$GLOBALS['static_site_importer_companion_block_owners'] ) ) {";
		$lines[] = "\t\t\t\t\$GLOBALS['static_site_importer_companion_block_owners'] = array();";
		$lines[] = "\t\t\t}";
		$lines[] = sprintf( "\t\t\t\$GLOBALS['static_site_importer_companion_block_owners'][ \$registered->name ] = array( 'plugin_file' => (string) ( %s_config()['plugin_file'] ?? '' ), 'plugin_path' => __FILE__ );", $fn_prefix );
		$lines[] = "\t\t}";
		$lines[] = "\t}";
		$lines[] = '}';
		$lines[] = sprintf( "add_action( 'init', '%s_register_blocks' );", $fn_prefix );
		$lines[] = '';
		$lines[] = '/**';
		$lines[] = ' * Enqueue preserved island JS only when its owning block renders.';
		$lines[] = ' *';
		$lines[] = ' * @param string              $content Rendered block HTML.';
		$lines[] = ' * @param array<string,mixed> $block   Parsed block.';
		$lines[] = ' * @return string';
		$lines[] = ' */';
		$lines[] = sprintf( 'function %s_enqueue_islands( $content, $block ) {', $fn_prefix );
		$lines[] = "\t\$name = is_array( \$block ) && isset( \$block['blockName'] ) ? (string) \$block['blockName'] : '';";
		$lines[] = "\tif ( '' === \$name || ! function_exists( 'wp_enqueue_script' ) ) {";
		$lines[] = "\t\treturn \$content;";
		$lines[] = "\t}";
		$lines[] = '';
		$lines[] = sprintf( "\tforeach ( %s_config()['islands'] ?? array() as \$island ) {", $fn_prefix );
		$lines[] = "\t\tif ( ( \$island['block'] ?? '' ) !== \$name || '' === ( \$island['src'] ?? '' ) ) {";
		$lines[] = "\t\t\tcontinue;";
		$lines[] = "\t\t}";
		$lines[] = "\t\twp_enqueue_script( \$island['handle'], plugin_dir_url( __FILE__ ) . \$island['src'], array(), '1.0.0', true );";
		$lines[] = "\t}";
		$lines[] = '';
		$lines[] = "\treturn \$content;";
		$lines[] = '}';
		$lines[] = sprintf( "add_filter( 'render_block', '%s_enqueue_islands', 10, 2 );", $fn_prefix );
		$lines[] = '';
		$lines[] = '/** Enqueue preserved scripts that apply to the rendered frontend document. */';
		$lines[] = sprintf( 'function %s_enqueue_global_islands() {', $fn_prefix );
		$lines[] = "\tif ( ! function_exists( 'wp_enqueue_script' ) ) {";
		$lines[] = "\t\treturn;";
		$lines[] = "\t}";
		$lines[] = sprintf( "\tif ( function_exists( 'get_option' ) && (string) ( %s_config()['plugin_file'] ?? '' ) !== (string) get_option( 'static_site_importer_active_companion_plugin', '' ) ) {", $fn_prefix );
		$lines[] = "\t\treturn;";
		$lines[] = "\t}";
		$lines[] = sprintf( "\tforeach ( %s_config()['islands'] ?? array() as \$island ) {", $fn_prefix );
		$lines[] = "\t\tif ( '' !== ( \$island['block'] ?? '' ) || '' === ( \$island['src'] ?? '' ) ) {";
		$lines[] = "\t\t\tcontinue;";
		$lines[] = "\t\t}";
		$lines[] = "\t\twp_enqueue_script( \$island['handle'], plugin_dir_url( __FILE__ ) . \$island['src'], array(), '1.0.0', true );";
		$lines[] = "\t}";
		$lines[] = '}';
		$lines[] = sprintf( "add_action( 'wp_enqueue_scripts', '%s_enqueue_global_islands' );", $fn_prefix );
		$lines[] = '';

		$lines[] = '/** Register and enqueue declared editor-only scripts. */';
		$lines[] = sprintf( 'function %s_enqueue_editor_scripts() {', $fn_prefix );
		$lines[] = "\tif ( ! function_exists( 'wp_register_script' ) || ! function_exists( 'wp_enqueue_script' ) ) {";
		$lines[] = "\t\treturn;";
		$lines[] = "\t}";
		$lines[] = sprintf( "\tif ( function_exists( 'get_option' ) && (string) ( %s_config()['plugin_file'] ?? '' ) !== (string) get_option( 'static_site_importer_active_companion_plugin', '' ) ) {", $fn_prefix );
		$lines[] = "\t\treturn;";
		$lines[] = "\t}";
		$lines[] = sprintf( "\tforeach ( %s_config()['editor_scripts'] ?? array() as \$script ) {", $fn_prefix );
		$lines[] = "\t\t\$handle = isset( \$script['handle'] ) ? (string) \$script['handle'] : '';";
		$lines[] = "\t\t\$src    = isset( \$script['src'] ) ? (string) \$script['src'] : '';";
		$lines[] = "\t\tif ( '' === \$handle || '' === \$src ) {";
		$lines[] = "\t\t\tcontinue;";
		$lines[] = "\t\t}";
		$lines[] = "\t\t\$dependencies = isset( \$script['dependencies'] ) && is_array( \$script['dependencies'] ) ? \$script['dependencies'] : array();";
		$lines[] = "\t\twp_register_script( \$handle, plugin_dir_url( __FILE__ ) . \$src, \$dependencies, '1.0.0', true );";
		$lines[] = "\t\twp_enqueue_script( \$handle );";
		$lines[] = "\t}";
		$lines[] = '}';
		$lines[] = sprintf( "add_action( 'enqueue_block_editor_assets', '%s_enqueue_editor_scripts' );", $fn_prefix );
		$lines[] = '';
		return implode( "\n", $lines );
	}

	/**
	 * Rename SSI's versioned form projection class for one generated companion.
	 *
	 * A companion remains operational after SSI is removed, while its runtime
	 * cannot collide with SSI, legacy global companions, or another companion.
	 */
	private static function provider_form_runtime_file( string $source, string $runtime_class ): string {
		return str_replace( 'Static_Site_Importer_Provider_Form_Runtime_V1', $runtime_class, $source );
	}

	private static function internal_link_runtime_file( string $source, string $runtime_class ): string {
		return str_replace( 'Static_Site_Importer_Internal_Link_Runtime', $runtime_class, $source );
	}

	/**
	 * Render the mu-plugin root loader stub.
	 *
	 * @param string $main_file   Main plugin file relative to plugins dir.
	 * @return string
	 */
	private static function mu_loader_file( string $main_file ): string {
		$lines   = array();
		$lines[] = '<?php';
		$lines[] = '/**';
		$lines[] = ' * Plugin Name: SSI Companion Loader';
		$lines[] = ' * Description: Must-use loader for a generated SSI companion plugin.';
		$lines[] = ' *';
		$lines[] = ' * @package StaticSiteImporterCompanion';
		$lines[] = ' */';
		$lines[] = '';
		$lines[] = "if ( ! defined( 'ABSPATH' ) ) {";
		$lines[] = "\texit;";
		$lines[] = '}';
		$lines[] = '';
		$lines[] = sprintf( "\$ssi_companion_main = __DIR__ . '/%s';", $main_file );
		$lines[] = 'if ( is_readable( $ssi_companion_main ) ) {';
		$lines[] = "\trequire_once \$ssi_companion_main;";
		$lines[] = '}';
		$lines[] = '';

		return implode( "\n", $lines );
	}


	/**
	 * Build the fixed render.php reader for static markup stored beside it.
	 */
	private static function static_render_file(): string {
		return "<?php\n/** Generated companion block render. */\n\n\$render = json_decode( (string) file_get_contents( __DIR__ . '/render.json' ), true );\necho is_string( \$render ) ? \$render : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static source markup was validated before compilation.\n";
	}

	/** Whether static payload markup represents an editable per-instance content attribute. */
	private static function has_editable_content_render( array $block ): bool {
		$content_schema = $block['block_json']['attributes']['content'] ?? null;
		return isset( $block['render'] ) && is_scalar( $block['render'] ) && is_array( $content_schema ) && 'string' === ( $content_schema['type'] ?? null );
	}

	/** Build the SSI-owned safe boundary for generic editable companion content. */
	private static function editable_content_renderer(): string {
		return self::safe_markup_renderer( 'editable-content' );
	}

	/**
	 * Compose a companion render template on the shared audited safe-markup
	 * boundary, so editable-content and typed layout blocks sanitize through
	 * one policy instead of duplicated divergent logic.
	 *
	 * The template reads the block's string content attribute into $content,
	 * passes it through safe_markup_boundary(), and echoes the sanitized
	 * $output.
	 *
	 * @param string $kind      Renderer label for the generated doc comment.
	 * @param string $attribute String attribute containing the bounded markup.
	 * @return string
	 */
	private static function safe_markup_renderer( string $kind, string $attribute = 'content' ): string {
		$prologue = sprintf(
			"<?php\n/** Generated %s companion block render. */\n\n\$content = is_string( \$attributes['%s'] ?? null ) ? \$attributes['%s'] : '';\n",
			$kind,
			$attribute,
			$attribute
		);
		$epilogue = 'echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- KSES-sanitized bounded markup through the shared audited safe-markup boundary.';
		return $prologue . self::safe_markup_boundary() . "\n" . $epilogue;
	}

	/**
	 * The audited safe-markup boundary shared by every content-rendering
	 * companion template.
	 *
	 * Consumes the string $content variable and produces the sanitized $output
	 * variable: executable and animation vectors (script, style, iframe,
	 * object, embed, foreignObject, animate, animateMotion, animateTransform,
	 * set) and on* / data-wp-* attributes are removed in paired, self-closing,
	 * and bare attribute forms. Custom elements become inert div wrappers so
	 * their safe selector identity and presentation survive. URL-bearing
	 * attributes are protocol-checked, inline `image-set()` notations are
	 * lowered to the `url()` fallback KSES accepts so background-only imagery
	 * is not discarded with its style attribute, and the result is KSES-filtered
	 * against an SVG-aware allowlist so inline SVG structure survives while
	 * nothing executable reaches the frontend.
	 *
	 * @return string
	 */
	private static function safe_markup_boundary(): string {
		return <<<'PHP'
$content = preg_replace( '#<\s*(?:script|style|iframe|object|embed|foreignobject|animate|animatemotion|animatetransform|set)\b[^>]*>.*?</\s*(?:script|style|iframe|object|embed|foreignobject|animate|animatemotion|animatetransform|set)\s*>#is', '', $content ) ?? '';
$content = preg_replace( '#<\s*(?:script|style|iframe|object|embed|foreignobject|animate|animatemotion|animatetransform|set)\b[^>]*/?\s*>#is', '', $content ) ?? '';
$content = preg_replace_callback(
	'#<\s*(/?)\s*([a-z][a-z0-9]*-[a-z0-9-]+)\b([^>]*)>#i',
	static function ( array $match ): string {
		if ( '/' === $match[1] ) {
			return '</div>';
		}
		$self_closing = (bool) preg_match( '#/\s*$#', $match[3] );
		$attributes   = preg_replace( '#/\s*$#', '', $match[3] ) ?? '';
		return '<div' . $attributes . '>' . ( $self_closing ? '</div>' : '' );
	},
	$content
) ?? '';
$content = preg_replace_callback(
	'#<[a-z](?:"[^"]*"|\'[^\']*\'|=>|[^>])*>#i',
	static function ( array $match ): string {
		return preg_replace(
			array(
				'/\s+(?:on[a-z0-9_-]+|data-wp-[a-z0-9_-]+)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i',
				'/\s+(?:on[a-z0-9_-]+|data-wp-[a-z0-9_-]+)\s*=\s*(?=\/?>)/i',
				'/\s+(?:on[a-z0-9_-]+|data-wp-[a-z0-9_-]+)(?=\s|\/?>)/i',
			),
			'',
			$match[0]
		) ?? '';
	},
	$content
) ?? '';

$safe_url = static function ( string $url, bool $image = false ): bool {
	$normalized = strtolower( preg_replace( '/[\x00-\x20\x7f]+/', '', html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ?? '' );
	$normalized = rawurldecode( rawurldecode( $normalized ) );
	if ( '' === $normalized || ! preg_match( '/^([a-z][a-z0-9+.-]*):/i', $normalized, $scheme ) ) {
		return '' !== $normalized;
	}
	if ( in_array( strtolower( $scheme[1] ), array( 'http', 'https' ), true ) ) {
		return true;
	}
	return $image && (bool) preg_match( '#^data:image/(?:avif|gif|jpeg|png|webp);base64,[a-z0-9+/=]+$#i', $normalized );
};
$sanitize_srcset = static function ( string $srcset ) use ( $safe_url ): string {
	$candidates = array();
	for ( $offset = 0, $length = strlen( $srcset ); $offset < $length; ) {
		while ( $offset < $length && ( ctype_space( $srcset[ $offset ] ) || ',' === $srcset[ $offset ] ) ) {
			++$offset;
		}
		$url_start = $offset;
		while ( $offset < $length && ! ctype_space( $srcset[ $offset ] ) ) {
			++$offset;
		}
		$url = substr( $srcset, $url_start, $offset - $url_start );
		while ( $offset < $length && ctype_space( $srcset[ $offset ] ) ) {
			++$offset;
		}
		$descriptor_start = $offset;
		for ( $parentheses = 0; $offset < $length; ++$offset ) {
			if ( '(' === $srcset[ $offset ] ) {
				++$parentheses;
			} elseif ( ')' === $srcset[ $offset ] && $parentheses > 0 ) {
				--$parentheses;
			} elseif ( ',' === $srcset[ $offset ] && 0 === $parentheses ) {
				break;
			}
		}
		$descriptor = trim( substr( $srcset, $descriptor_start, $offset - $descriptor_start ) );
		if ( '' !== $url && $safe_url( $url, true ) ) {
			$candidates[] = $url . ( '' === $descriptor ? '' : ' ' . $descriptor );
		}
	}
	return implode( ', ', $candidates );
};
$content = preg_replace_callback(
	'/\bsrcset\s*=\s*(?:("|\')(.*?)\1|([^\s>]+))/is',
	static function ( array $match ) use ( $sanitize_srcset ): string {
		$srcset = $sanitize_srcset( '' !== ( $match[2] ?? '' ) ? $match[2] : ( $match[3] ?? '' ) );
		return '' === $srcset ? '' : 'srcset="' . esc_attr( $srcset ) . '"';
	},
	$content
) ?? '';
$content = preg_match( '#<svg\b[^>]*>(?:(?!</svg\s*>).)*<svg\b#is', $content ) ? ( preg_replace( '#<svg\b[^>]*>.*</svg\s*>#is', '', $content ) ?? '' ) : $content;
$content = preg_replace( '#<svg\b[^>]*/\s*>#is', '', $content ) ?? '';
$content = preg_replace( '#<svg\b[^>]*>(?:(?!</svg\s*>).)*$#is', '', $content ) ?? '';
$content = preg_replace_callback(
	'#<svg\b[^>]*>.*?</svg\s*>#is',
	static function ( array $match ): string {
		$svg = $match[0];
		$ids = array();
		if ( preg_match_all( '/\bid\s*=\s*(?:"([^"\s]+)"|\'([^\'\s]+)\'|([^\s>]+))/i', $svg, $id_matches ) ) {
			foreach ( $id_matches[1] as $index => $double_quoted ) {
				$id = '' !== $double_quoted ? $double_quoted : ( '' !== $id_matches[2][ $index ] ? $id_matches[2][ $index ] : $id_matches[3][ $index ] );
				if ( preg_match( '/^[A-Za-z][A-Za-z0-9_.:-]*$/', $id ) ) {
					$ids[ $id ] = true;
				}
			}
		}
		$svg = preg_replace_callback(
			'/url\(\s*(["\']?)([^\s)"\']+)\1\s*\)/i',
			static function ( array $url_match ) use ( $ids ): string {
				$reference = $url_match[2];
				return str_starts_with( $reference, '#' ) && isset( $ids[ substr( $reference, 1 ) ] ) ? $url_match[0] : '';
			},
			$svg
		) ?? '';
		$svg = preg_replace_callback(
			'/\s+(?:href|xlink:href)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i',
			static function ( array $href_match ) use ( $ids ): string {
				$reference = '' !== ( $href_match[1] ?? '' ) ? $href_match[1] : ( '' !== ( $href_match[2] ?? '' ) ? $href_match[2] : ( $href_match[3] ?? '' ) );
				return str_starts_with( $reference, '#' ) && isset( $ids[ substr( $reference, 1 ) ] ) ? ' href="' . esc_attr( $reference ) . '"' : '';
			},
			$svg
		) ?? '';
		return preg_replace( '#<use\b(?![^>]*(?:href|xlink:href)=)[^>]*(?:/>|>.*?</use\s*>)#is', '', $svg ) ?? '';
	},
	$content
) ?? '';
// WordPress core's safecss_filter_attr() knows url() and the gradient
// functions, but has no allowance for image-set(). Its residual parenthesis
// trips the unsafe-CSS guard, so the declaration is discarded, and because
// that declaration is usually the element's only one the whole style attribute
// goes with it: an image carried solely by a background becomes an empty box.
// Lower image-set() to the plain url() fallback the sanitizer already accepts,
// preferring the 1x candidate the way a browser without image-set() support
// would, so the picture survives the boundary instead of the boundary having
// to be widened around it.
$image_set_candidate = static function ( string $candidate ): array {
	$candidate = trim( $candidate );
	if ( '' === $candidate ) {
		return array( '', '' );
	}
	if ( preg_match( '/^url\(/i', $candidate ) ) {
		$depth  = 1;
		$length = strlen( $candidate );
		for ( $offset = 4; $offset < $length && $depth > 0; ++$offset ) {
			if ( '(' === $candidate[ $offset ] ) {
				++$depth;
			} elseif ( ')' === $candidate[ $offset ] ) {
				--$depth;
			}
		}
		return 0 === $depth
			? array( substr( $candidate, 0, $offset ), trim( substr( $candidate, $offset ) ) )
			: array( '', '' );
	}
	$pieces = preg_split( '/\s+/', $candidate, 2 );
	return false === $pieces || '' === trim( (string) $pieces[0] )
		? array( '', '' )
		: array( 'url(' . trim( (string) $pieces[0] ) . ')', trim( (string) ( $pieces[1] ?? '' ) ) );
};
$image_set_fallback = static function ( string $notation ) use ( $image_set_candidate ): string {
	$candidates = array();
	$current    = '';
	$depth      = 0;
	for ( $offset = 0, $length = strlen( $notation ); $offset < $length; ++$offset ) {
		$character = $notation[ $offset ];
		if ( '(' === $character ) {
			++$depth;
		} elseif ( ')' === $character && $depth > 0 ) {
			--$depth;
		} elseif ( ',' === $character && 0 === $depth ) {
			$candidates[] = $current;
			$current      = '';
			continue;
		}
		$current .= $character;
	}
	$candidates[] = $current;
	$fallback     = '';
	foreach ( $candidates as $candidate ) {
		list( $reference, $descriptor ) = $image_set_candidate( $candidate );
		if ( '' === $reference ) {
			continue;
		}
		if ( '' === $fallback ) {
			$fallback = $reference;
		}
		if ( preg_match( '/(?:^|\s)1(?:\.0+)?x(?:\s|$)/i', $descriptor ) ) {
			return $reference;
		}
	}
	return $fallback;
};
$lower_image_sets = static function ( string $value ) use ( $image_set_fallback ): string {
	for ( $pass = 0; $pass < 32; ++$pass ) {
		if ( ! preg_match( '/(?:-webkit-)?image-set\(/i', $value, $found, PREG_OFFSET_CAPTURE ) ) {
			return $value;
		}
		$start  = (int) $found[0][1];
		$open   = $start + strlen( (string) $found[0][0] );
		$length = strlen( $value );
		$depth  = 1;
		for ( $offset = $open; $offset < $length && $depth > 0; ++$offset ) {
			if ( '(' === $value[ $offset ] ) {
				++$depth;
			} elseif ( ')' === $value[ $offset ] ) {
				--$depth;
			}
		}
		if ( 0 !== $depth ) {
			return $value;
		}
		$fallback = $image_set_fallback( substr( $value, $open, $offset - 1 - $open ) );
		if ( '' === $fallback ) {
			return $value;
		}
		$value = substr( $value, 0, $start ) . $fallback . substr( $value, $offset );
	}
	return $value;
};
$content = preg_replace_callback(
	'/\bstyle\s*=\s*(?:("|\')(.*?)\1|([^\s>]+))/is',
	static function ( array $match ) use ( $safe_url, $lower_image_sets ): string {
		$value = '' !== ( $match[2] ?? '' ) ? $match[2] : ( $match[3] ?? '' );
		$value = $lower_image_sets( $value );
		if ( preg_match_all( '/url\(\s*["\']?([^\s)"\']+)/i', $value, $urls ) ) {
			foreach ( $urls[1] as $url ) {
				if ( ! $safe_url( $url, true ) ) {
					return '';
				}
			}
		}
		return 'style="' . esc_attr( $value ) . '"';
	},
	$content
) ?? '';

// Preserve audited raster data URLs through KSES's protocol filter without
// allowing the data scheme for links or other URL-bearing attributes.
$data_images = array();
$content     = preg_replace_callback(
	'/\bsrc\s*=\s*(["\'])(data:image\/(?:avif|gif|jpeg|png|webp);base64,[a-z0-9+\/=]+)\1/i',
	static function ( array $match ) use ( &$data_images ): string {
		$placeholder                 = '/ssi-data-image-' . hash( 'sha256', $match[2] ) . '.invalid';
		$data_images[ $placeholder ] = $match[2];
		return 'src=' . $match[1] . $placeholder . $match[1];
	},
	$content
) ?? '';

$global = array(
	'aria-controls' => true, 'aria-current' => true, 'aria-describedby' => true, 'aria-details' => true,
	'aria-disabled' => true, 'aria-expanded' => true, 'aria-hidden' => true, 'aria-label' => true, 'aria-labelledby' => true,
	'aria-live' => true, 'class' => true, 'data-*' => true, 'dir' => true, 'hidden' => true, 'id' => true,
	'lang' => true, 'role' => true, 'style' => true, 'tabindex' => true, 'title' => true, 'xml:lang' => true,
);
$flow = array_merge( $global, array( 'align' => true ) );
$svg_global = array(
	'aria-hidden' => true, 'aria-label' => true, 'aria-labelledby' => true, 'class' => true, 'data-*' => true,
	'filter' => true, 'id' => true, 'role' => true, 'style' => true, 'title' => true,
);
// KSES supports data-* but not aria-* wildcards. Admit syntactically valid
// producer attributes explicitly so SVG accessibility metadata survives.
if ( preg_match_all( '/\s+(aria-[a-z][a-z0-9-]*)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', $content, $aria_names ) ) {
	foreach ( $aria_names[1] as $aria_name ) {
		$svg_global[ strtolower( $aria_name ) ] = true;
	}
}
$safe_style_css = static function ( array $properties ): array {
	return array_values( array_unique( array_merge( $properties, array( 'box-sizing', 'inset', 'overflow-x', 'overflow-y', 'transition' ) ) ) );
};
add_filter( 'safe_style_css', $safe_style_css );
$output = wp_kses(
	$content,
	array(
		'main' => $flow, 'article' => $flow, 'aside' => $flow, 'section' => $flow, 'header' => $flow,
		'footer' => $flow, 'nav' => $flow, 'div' => $flow, 'span' => $global, 'p' => $flow,
		'h1' => $flow, 'h2' => $flow, 'h3' => $flow, 'h4' => $flow, 'h5' => $flow, 'h6' => $flow,
		'ul' => $flow, 'ol' => $flow, 'li' => $flow, 'dl' => $flow, 'dt' => $flow, 'dd' => $flow,
		'strong' => $global, 'b' => $global, 'em' => $global, 'i' => $global, 'small' => $global, 'br' => $global,
		'a' => array_merge( $global, array( 'download' => true, 'href' => true, 'rel' => true, 'target' => true ) ),
		'button' => array_merge( $global, array( 'disabled' => true, 'name' => true, 'type' => true, 'value' => true ) ),
		'form' => array_merge( $flow, array( 'action' => true, 'method' => true ) ),
		'fieldset' => array_merge( $flow, array( 'disabled' => true, 'name' => true ) ), 'legend' => $global,
		'label' => array_merge( $global, array( 'for' => true ) ),
		'input' => array_merge( $global, array( 'autocomplete' => true, 'checked' => true, 'disabled' => true, 'max' => true, 'maxlength' => true, 'min' => true, 'minlength' => true, 'multiple' => true, 'name' => true, 'pattern' => true, 'placeholder' => true, 'readonly' => true, 'required' => true, 'step' => true, 'type' => true, 'value' => true ) ),
		'textarea' => array_merge( $global, array( 'cols' => true, 'disabled' => true, 'maxlength' => true, 'minlength' => true, 'name' => true, 'placeholder' => true, 'readonly' => true, 'required' => true, 'rows' => true ) ),
		'select' => array_merge( $global, array( 'disabled' => true, 'multiple' => true, 'name' => true, 'required' => true, 'size' => true ) ),
		'option' => array_merge( $global, array( 'disabled' => true, 'label' => true, 'selected' => true, 'value' => true ) ),
		'figure' => $flow, 'figcaption' => $flow, 'picture' => $flow,
		'source' => array_merge( $global, array( 'media' => true, 'sizes' => true, 'src' => true, 'srcset' => true, 'type' => true ) ),
		'img' => array_merge( $global, array( 'alt' => true, 'decoding' => true, 'fetchpriority' => true, 'height' => true, 'loading' => true, 'longdesc' => true, 'sizes' => true, 'src' => true, 'srcset' => true, 'usemap' => true, 'width' => true ) ),
		'video' => array_merge( $global, array( 'autoplay' => true, 'controls' => true, 'height' => true, 'loop' => true, 'muted' => true, 'playsinline' => true, 'poster' => true, 'preload' => true, 'src' => true, 'width' => true ) ),
		'audio' => array_merge( $global, array( 'autoplay' => true, 'controls' => true, 'loop' => true, 'muted' => true, 'preload' => true, 'src' => true ) ),
		'svg' => array_merge( $svg_global, array( 'fill' => true, 'focusable' => true, 'height' => true, 'preserveaspectratio' => true, 'stroke' => true, 'viewbox' => true, 'width' => true, 'xmlns' => true, 'xmlns:xlink' => true ) ),
		'defs' => $svg_global, 'symbol' => array_merge( $svg_global, array( 'viewbox' => true ) ), 'lineargradient' => array_merge( $svg_global, array( 'gradientunits' => true, 'x1' => true, 'x2' => true, 'y1' => true, 'y2' => true ) ), 'radialgradient' => array_merge( $svg_global, array( 'cx' => true, 'cy' => true, 'r' => true ) ), 'stop' => array_merge( $svg_global, array( 'offset' => true, 'stop-color' => true, 'stop-opacity' => true ) ), 'clippath' => $svg_global, 'mask' => $svg_global, 'use' => array_merge( $svg_global, array( 'href' => true, 'xlink:href' => true ) ),
		'filter' => array_merge( $svg_global, array( 'filterunits' => true, 'height' => true, 'primitiveunits' => true, 'width' => true, 'x' => true, 'y' => true ) ),
		'fegaussianblur' => array_merge( $svg_global, array( 'height' => true, 'in' => true, 'result' => true, 'stddeviation' => true, 'width' => true, 'x' => true, 'y' => true ) ),
		'femerge' => array_merge( $svg_global, array( 'height' => true, 'result' => true, 'width' => true, 'x' => true, 'y' => true ) ),
		'femergenode' => array_merge( $svg_global, array( 'in' => true ) ),
		'g' => array_merge( $svg_global, array( 'clip-path' => true, 'fill' => true, 'fill-opacity' => true, 'opacity' => true, 'stroke' => true, 'stroke-width' => true, 'transform' => true ) ),
		'path' => array_merge( $svg_global, array( 'd' => true, 'fill' => true, 'fill-rule' => true, 'opacity' => true, 'stroke' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'stroke-width' => true, 'transform' => true ) ),
		'circle' => array_merge( $svg_global, array( 'cx' => true, 'cy' => true, 'fill' => true, 'opacity' => true, 'r' => true, 'stroke' => true, 'stroke-width' => true ) ),
		'ellipse' => array_merge( $svg_global, array( 'cx' => true, 'cy' => true, 'fill' => true, 'opacity' => true, 'rx' => true, 'ry' => true, 'stroke' => true, 'stroke-width' => true ) ),
		'line' => array_merge( $svg_global, array( 'fill' => true, 'opacity' => true, 'stroke' => true, 'stroke-dasharray' => true, 'stroke-linecap' => true, 'stroke-width' => true, 'x1' => true, 'x2' => true, 'y1' => true, 'y2' => true ) ),
		'polygon' => array_merge( $svg_global, array( 'fill' => true, 'points' => true, 'stroke' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'stroke-width' => true ) ),
		'polyline' => array_merge( $svg_global, array( 'fill' => true, 'points' => true, 'stroke' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'stroke-width' => true ) ),
		'rect' => array_merge( $svg_global, array( 'fill' => true, 'height' => true, 'opacity' => true, 'rx' => true, 'ry' => true, 'stroke' => true, 'stroke-dasharray' => true, 'stroke-width' => true, 'width' => true, 'x' => true, 'y' => true ) ),
		'text' => array_merge( $svg_global, array( 'fill' => true, 'font-family' => true, 'font-size' => true, 'font-weight' => true, 'letter-spacing' => true, 'text-anchor' => true, 'x' => true, 'y' => true ) ),
		'tspan' => array_merge( $svg_global, array( 'dx' => true, 'dy' => true, 'fill' => true, 'x' => true, 'y' => true ) ), 'title' => $svg_global, 'desc' => $svg_global,
	)
);
remove_filter( 'safe_style_css', $safe_style_css );
foreach ( $data_images as $placeholder => $data_image ) {
	$output = str_replace( 'src="' . $placeholder . '"', 'src="' . esc_attr( $data_image ) . '"', $output );
	$output = str_replace( "src='" . $placeholder . "'", "src='" . esc_attr( $data_image ) . "'", $output );
}
$output = preg_replace_callback(
	'#<(?:svg|filter|fegaussianblur|femerge|femergenode)\b[^>]*>#i',
	static function ( array $match ): string {
		return preg_replace(
			array( '/\bviewbox\b/i', '/\bpreserveaspectratio\b/i', '/\bfilterunits\b/i', '/\bprimitiveunits\b/i', '/\bstddeviation\b/i', '/<fegaussianblur\b/i', '/<femerge(?=\s|>)/i', '/<femergenode\b/i' ),
			array( 'viewBox', 'preserveAspectRatio', 'filterUnits', 'primitiveUnits', 'stdDeviation', '<feGaussianBlur', '<feMerge', '<feMergeNode' ),
			$match[0]
		) ?? $match[0];
	},
	$output
) ?? '';
PHP;
	}

	/**
	 * Build an SSI-owned render template for an audited renderer identifier.
	 *
	 * @param string $renderer Validated renderer identifier.
	 * @return string
	 */
	private static function typed_renderer( string $renderer ): string {
		$layout = self::safe_markup_renderer( 'responsive-layout' );
		$media  = self::safe_markup_renderer( 'responsive-media' );
		$svg    = self::safe_markup_renderer( 'svg-artwork', 'svg' );

		$renderers = array(
			self::RESPONSIVE_MEDIA_RENDERER  => $media,
			self::RESPONSIVE_LAYOUT_RENDERER => $layout,
			self::SVG_ARTWORK_RENDERER       => $svg,
		);
		if ( function_exists( 'apply_filters' ) ) {
			$renderers = apply_filters( 'static_site_importer_companion_renderers', $renderers );
		}
		if ( ! is_array( $renderers ) ) {
			return '';
		}
		$source = $renderers[ $renderer ] ?? '';
		return is_string( $source ) && str_starts_with( $source, '<?php' ) ? $source : '';
	}

	/**
	 * Sanitize a slug, falling back to a portable regex when WP is unavailable.
	 *
	 * @param string $value Raw slug.
	 * @return string
	 */
	private static function sanitize_slug( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		if ( function_exists( 'sanitize_title' ) ) {
			$sanitized = sanitize_title( $value );
			if ( '' !== $sanitized ) {
				return $sanitized;
			}
		}

		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9]+/', '-', $value );
		return trim( (string) $value, '-' );
	}

	/**
	 * Sanitize a relative file path, rejecting traversal and absolute paths.
	 *
	 * @param string $value Raw relative path.
	 * @return string
	 */
	private static function sanitize_relative_path( string $value ): string {
		$value = str_replace( '\\', '/', trim( $value ) );
		if ( '' === $value || str_starts_with( $value, '/' ) || str_contains( $value, '../' ) || str_contains( $value, './' ) ) {
			return '';
		}

		$segments = array();
		foreach ( explode( '/', $value ) as $segment ) {
			$segment = preg_replace( '/[^A-Za-z0-9._-]/', '', $segment );
			if ( '' === $segment || '..' === $segment ) {
				continue;
			}
			$segments[] = $segment;
		}

		return implode( '/', $segments );
	}

	/**
	 * Validate compiler-declared WordPress script dependencies before generating
	 * trusted PHP asset manifests.
	 *
	 * @param array<string,mixed> $block    Block payload entry.
	 * @param array<string,mixed> $assets   Declared static block assets.
	 * @param array<string,mixed> $metadata Block metadata, including render normalization.
	 * @return true|WP_Error
	 */
	private static function validate_script_dependencies( array $block, array $assets, array $metadata ) {
		$dependencies = $block['script_dependencies'] ?? array();
		if ( ! is_array( $dependencies ) || ( ! empty( $dependencies ) && array_is_list( $dependencies ) ) || count( $dependencies ) > self::MAX_SCRIPT_DEPENDENCIES ) {
			return new WP_Error( 'static_site_importer_companion_plugin_script_dependencies_invalid', 'Block script_dependencies must be a bounded object map.' );
		}

		$script_references = self::metadata_script_file_references( $metadata );
		foreach ( $dependencies as $path => $handles ) {
			if ( ! is_string( $path ) || self::sanitize_relative_path( $path ) !== $path || ! preg_match( '/\.(?:js|mjs)$/', $path ) || ! array_key_exists( $path, $assets ) || ! isset( $script_references[ $path ] ) ) {
				return new WP_Error( 'static_site_importer_companion_plugin_script_dependency_path_invalid', 'Block script_dependencies must reference a declared JavaScript asset used by block metadata.' );
			}
			if ( ! is_array( $handles ) || ! array_is_list( $handles ) || count( $handles ) > self::MAX_SCRIPT_DEPENDENCIES ) {
				return new WP_Error( 'static_site_importer_companion_plugin_script_dependencies_invalid', 'Each script dependency declaration must be a bounded list.' );
			}
			$seen = array();
			foreach ( $handles as $handle ) {
				// A classic script depends on a registered handle; a script module
				// depends on an import specifier such as `@wordpress/interactivity`,
				// which block metadata resolves through the generated manifest.
				if ( ! is_string( $handle ) || ! self::is_safe_script_dependency( $handle ) || isset( $seen[ $handle ] ) ) {
					return new WP_Error( 'static_site_importer_companion_plugin_script_dependency_handle_invalid', 'Script dependency handles must be unique safe WordPress handles or module specifiers.' );
				}
				$seen[ $handle ] = true;
			}
		}

		return true;
	}

	/** @return array<string,array<int,string>> */
	private static function script_dependencies( array $block ): array {
		return isset( $block['script_dependencies'] ) && is_array( $block['script_dependencies'] ) ? $block['script_dependencies'] : array();
	}

	/**
	 * Validate producer-neutral editor-only scripts before any WordPress writes.
	 *
	 * @param array<string,mixed> $payload Generated companion-plugin payload.
	 * @return true|WP_Error
	 */
	private static function validate_editor_scripts( array $payload ) {
		$entries = $payload['editor_scripts'] ?? array();
		if ( ! is_array( $entries ) || ! array_is_list( $entries ) || count( $entries ) > self::MAX_SCRIPT_DEPENDENCIES ) {
			return new WP_Error( 'static_site_importer_companion_plugin_editor_scripts_invalid', 'Companion-plugin editor_scripts must be a bounded array.' );
		}

		$handles = array();
		$paths   = array();
		foreach ( $entries as $index => $entry ) {
			if ( ! is_array( $entry ) || array_is_list( $entry ) ) {
				return new WP_Error( 'static_site_importer_companion_plugin_editor_scripts_invalid', sprintf( 'Companion-plugin editor_scripts[%d] must be an object.', $index ) );
			}
			$handle = isset( $entry['handle'] ) && is_string( $entry['handle'] ) ? $entry['handle'] : '';
			if ( ! self::is_safe_wordpress_script_handle( $handle ) || isset( $handles[ $handle ] ) ) {
				return new WP_Error( 'static_site_importer_companion_plugin_editor_script_handle_invalid', 'Editor script handles must be unique safe WordPress handles.' );
			}
			$handles[ $handle ] = true;

			if ( ! isset( $entry['content'] ) || ! is_scalar( $entry['content'] ) || '' === (string) $entry['content'] || Static_Site_Importer_Content_Policy::contains_server_code( (string) $entry['content'] ) ) {
				return new WP_Error( 'static_site_importer_companion_plugin_editor_script_content_invalid', 'Editor scripts must declare safe JavaScript content.' );
			}

			$path = self::editor_script_path( $entry, $handle );
			if ( '' === $path || self::sanitize_relative_path( $path ) !== $path || ! preg_match( '/\.(?:js|mjs)$/', $path ) || ! Static_Site_Importer_Content_Policy::is_companion_asset_path( $path ) || isset( $paths[ $path ] ) ) {
				return new WP_Error( 'static_site_importer_companion_plugin_editor_script_path_invalid', 'Editor scripts must declare a unique safe JavaScript asset path.' );
			}
			$paths[ $path ] = true;

			$dependencies = $entry['dependencies'] ?? array();
			if ( ! is_array( $dependencies ) || ! array_is_list( $dependencies ) || count( $dependencies ) > self::MAX_SCRIPT_DEPENDENCIES ) {
				return new WP_Error( 'static_site_importer_companion_plugin_editor_script_dependencies_invalid', 'Editor script dependencies must be a bounded list.' );
			}
			$seen = array();
			foreach ( $dependencies as $dependency ) {
				if ( ! is_string( $dependency ) || ! self::is_safe_script_dependency( $dependency ) || isset( $seen[ $dependency ] ) ) {
					return new WP_Error( 'static_site_importer_companion_plugin_editor_script_dependency_handle_invalid', 'Editor script dependency handles must be unique safe WordPress handles or module specifiers.' );
				}
				$seen[ $dependency ] = true;
			}
		}

		return true;
	}

	/**
	 * Normalize declared editor-only scripts into a scaffold descriptor list.
	 *
	 * @param array<string,mixed> $payload Generated companion-plugin payload.
	 * @return array<int,array<string,mixed>>
	 */
	private static function editor_scripts( array $payload ): array {
		$entries = isset( $payload['editor_scripts'] ) && is_array( $payload['editor_scripts'] ) ? $payload['editor_scripts'] : array();
		$scripts = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$handle  = isset( $entry['handle'] ) && is_string( $entry['handle'] ) ? $entry['handle'] : '';
			$content = isset( $entry['content'] ) && is_scalar( $entry['content'] ) ? (string) $entry['content'] : '';
			if ( '' === $handle || '' === $content ) {
				continue;
			}
			$scripts[] = array(
				'handle'       => $handle,
				'src'          => self::editor_script_path( $entry, $handle ),
				'content'      => $content,
				'dependencies' => isset( $entry['dependencies'] ) && is_array( $entry['dependencies'] ) ? array_values( $entry['dependencies'] ) : array(),
			);
		}

		return $scripts;
	}

	/** @param array<string,mixed> $entry */
	private static function editor_script_path( array $entry, string $handle ): string {
		foreach ( array( 'src', 'path' ) as $field ) {
			if ( isset( $entry[ $field ] ) && is_scalar( $entry[ $field ] ) && '' !== trim( (string) $entry[ $field ] ) ) {
				return (string) $entry[ $field ];
			}
		}

		return 'editor/' . $handle . '.js';
	}

	private static function is_safe_wordpress_script_handle( string $handle ): bool {
		return 1 === preg_match( '/^[a-z0-9][a-z0-9._-]*$/', $handle );
	}

	private static function is_safe_script_dependency( string $handle ): bool {
		return 1 === preg_match( '#^(?:@[a-z0-9][a-z0-9._-]*/)?[a-z0-9][a-z0-9._-]*$#', $handle );
	}

	/** @return array<string,bool> */
	private static function metadata_script_file_references( array $block_json ): array {
		$references = array();
		foreach ( array( 'editorScript', 'script', 'viewScript', 'viewScriptModule' ) as $key ) {
			$values = isset( $block_json[ $key ] ) && is_array( $block_json[ $key ] ) ? $block_json[ $key ] : array( $block_json[ $key ] ?? null );
			foreach ( $values as $value ) {
				if ( is_string( $value ) && str_starts_with( $value, 'file:./' ) ) {
					$path = self::sanitize_relative_path( substr( $value, 7 ) );
					if ( '' !== $path ) {
						$references[ $path ] = true;
					}
				}
			}
		}

		return $references;
	}

	/** @return string */
	private static function asset_manifest_path( string $script_path ): string {
		$extension_offset = strrpos( $script_path, '.' );
		return false === $extension_offset ? $script_path . '.asset.php' : substr( $script_path, 0, $extension_offset ) . '.asset.php';
	}


	/** @return array<int,string> */
	private static function metadata_file_references( array $block_json ): array {
		$references = array();
		foreach ( array( 'editorScript', 'script', 'viewScript', 'viewScriptModule', 'style', 'editorStyle', 'viewStyle', 'render', 'variations' ) as $key ) {
			$values = 'variations' === $key
				? array( $block_json[ $key ] ?? null )
				: ( isset( $block_json[ $key ] ) && is_array( $block_json[ $key ] ) ? $block_json[ $key ] : array( $block_json[ $key ] ?? null ) );
			foreach ( $values as $value ) {
				if ( ! is_string( $value ) || ! str_starts_with( $value, 'file:./' ) ) {
					continue;
				}
				$path = self::sanitize_relative_path( substr( $value, 7 ) );
				if ( '' === $path ) {
					return array( '' );
				}
				$references[] = $path;
			}
		}
		return array_values( array_unique( $references ) );
	}
}

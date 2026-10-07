<?php
/**
 * Owner-facing inventory and README composition for generated companion plugins.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Build_Provenance' ) ) {
	require_once __DIR__ . '/class-static-site-importer-build-provenance.php';
}

/**
 * Composes the plain-language owner-facing inventory of a generated companion
 * plugin from canonical inputs the scaffolder already resolved.
 *
 * The composer never re-detects behavior and never recomposes owning-layer
 * diagnostics: owned blocks, preserved scripts, form states, and producer
 * provenance are consumed exactly as the validated companion payload carries
 * them, and unresolved rows are projected from a canonical owner-handoff
 * evidence document (static-site-importer/owner-handoff-evidence/v1) when one
 * is embedded. An absent or malformed document is reported as unknown
 * evidence — absence is never rendered as success.
 *
 * Output is deterministic: the same inputs render the same README and plugin
 * headers, so a reimport of the same payload reproduces byte-identical
 * owner-facing metadata.
 *
 * @package StaticSiteImporter
 */
final class Static_Site_Importer_Companion_Inventory {

	public const SCHEMA         = 'static-site-importer/companion-inventory/v1';
	public const HANDOFF_SCHEMA = 'static-site-importer/owner-handoff-evidence/v1';

	/** Functionality statuses used in the owner-facing README. */
	public const STATUS_REBUILT    = 'rebuilt';
	public const STATUS_PRESERVED  = 'preserved';
	public const STATUS_SNAPSHOT   = 'snapshot';
	public const STATUS_UNRESOLVED = 'unresolved';
	public const STATUS_UNKNOWN    = 'unknown';

	/** Owner-handoff finding statuses that project as unresolved behavior. */
	private const UNRESOLVED_FINDING_STATUSES = array( 'hard_failure', 'evidence_gap', 'required_owner_decision', 'acceptable_conversion' );

	/**
	 * The site-specific WordPress plugin name for a generated companion.
	 *
	 * @param string $site_name Resolved human-readable site name.
	 * @return string
	 */
	public static function plugin_name( string $site_name ): string {
		$name = self::header_value( $site_name );

		return '' === $name ? 'SSI Companion' : $name . ' Companion';
	}

	/**
	 * The site-specific name for the must-use loader of a generated companion.
	 *
	 * @param string $site_name Resolved human-readable site name.
	 * @return string
	 */
	public static function plugin_loader_name( string $site_name ): string {
		return self::plugin_name( $site_name ) . ' Loader';
	}

	/**
	 * The plain-language WordPress plugin description for a generated companion.
	 *
	 * @param array<string,mixed> $inventory Composed inventory.
	 * @return string
	 */
	public static function plugin_description( array $inventory ): string {
		$parts = array();
		if ( ! empty( $inventory['blocks'] ) ) {
			$parts[] = count( $inventory['blocks'] ) . ' imported WordPress block' . ( 1 === count( $inventory['blocks'] ) ? '' : 's' );
		}
		$scripts = array_merge( is_array( $inventory['scripts'] ?? null ) ? $inventory['scripts'] : array(), is_array( $inventory['editor_scripts'] ?? null ) ? $inventory['editor_scripts'] : array() );
		if ( ! empty( $scripts ) ) {
			$parts[] = count( $scripts ) . ' runtime script' . ( 1 === count( $scripts ) ? '' : 's' );
		}
		if ( ! empty( $inventory['forms']['carried'] ?? false ) ) {
			$parts[] = 'imported form field presentation';
		}
		$parts[] = 'source-route redirects and internal-link rewriting';
		$site    = self::header_value( (string) ( $inventory['site_name'] ?? '' ) );

		return sprintf(
			'theme' === ( $inventory['owner'] ?? '' )
				? 'Houses %1$s for the %2$s site. Switching away from or deleting the theme removes their registrations, runtime scripts and old-source redirects.'
				: 'Houses %1$s for the %2$s site. Deactivating or deleting it removes their registrations, runtime scripts and old-source redirects.',
			implode( ', ', $parts ),
			'' !== $site ? $site : 'imported'
		);
	}

	/**
	 * Compose the canonical owner-facing inventory record.
	 *
	 * @param array<string,mixed> $input Canonical inputs: site_name, plugin_slug,
	 *                                mu_plugin, blocks, block_names, islands,
	 *                                editor_scripts, form_visual_states,
	 *                                provenance, handoff.
	 * @return array<string,mixed>
	 */
	public static function compose( array $input ): array {
		$blocks = array();
		$names  = is_array( $input['block_names'] ?? null ) ? array_values( $input['block_names'] ) : array();
		$raw    = array_values( array_filter( is_array( $input['blocks'] ?? null ) ? $input['blocks'] : array(), 'is_array' ) );
		foreach ( $names as $index => $name ) {
			$source   = $raw[ $index ] ?? array();
			$title    = is_string( $source['block_json']['title'] ?? null ) ? trim( (string) $source['block_json']['title'] ) : '';
			$blocks[] = array(
				'name'     => (string) $name,
				'title'    => '' !== $title ? $title : (string) $name,
				'status'   => self::STATUS_REBUILT,
				'renderer' => is_string( $source['renderer'] ?? null ) ? (string) $source['renderer'] : '',
				'assets'   => is_array( $input['block_assets'][ (string) $name ] ?? null ) ? array_values( $input['block_assets'][ (string) $name ] ) : array( 'block.json' ),
			);
		}

		$scripts = array();
		foreach ( is_array( $input['islands'] ?? null ) ? $input['islands'] : array() as $island ) {
			if ( ! is_array( $island ) ) {
				continue;
			}
			$scripts[] = array(
				'handle'      => (string) ( $island['handle'] ?? '' ),
				'path'        => (string) ( $island['relative_src'] ?? '' ),
				'block'       => (string) ( $island['block'] ?? '' ),
				'source_path' => (string) ( $island['source_path'] ?? '' ),
				'status'      => self::STATUS_PRESERVED,
			);
		}

		$editor_scripts = array();
		foreach ( is_array( $input['editor_scripts'] ?? null ) ? $input['editor_scripts'] : array() as $script ) {
			if ( ! is_array( $script ) ) {
				continue;
			}
			$editor_scripts[] = array(
				'handle' => (string) ( $script['handle'] ?? '' ),
				'path'   => (string) ( $script['src'] ?? '' ),
				'status' => self::STATUS_PRESERVED,
			);
		}

		$form_states      = is_array( $input['form_visual_states'] ?? null ) ? $input['form_visual_states'] : array();
		$external_metrics = array();
		foreach ( is_array( $input['external_metrics'] ?? null ) ? $input['external_metrics'] : array() as $fact ) {
			if ( ! is_array( $fact ) || ! is_array( $fact['source'] ?? null ) ) {
				continue; }
			$external_metrics[] = array(
				'id'                    => (string) ( $fact['id'] ?? '' ),
				'source_id'             => (string) ( $fact['source']['id'] ?? '' ),
				'resources'             => is_array( $fact['source']['resources'] ?? null ) ? $fact['source']['resources'] : array(),
				'freshness_seconds'     => (int) ( $fact['source']['freshness']['max_age_seconds'] ?? 0 ),
				'metric'                => (string) ( $fact['metric'] ?? '' ),
				'aggregation'           => (string) ( $fact['aggregation'] ?? '' ),
				'fallback_hash'         => (string) ( $fact['fallback']['hash'] ?? '' ),
				'provenance_kind'       => (string) ( $fact['provenance']['kind'] ?? '' ),
				'provenance_repository' => (string) ( $fact['provenance']['repository'] ?? '' ),
				'provenance_revision'   => (string) ( $fact['provenance']['revision'] ?? '' ),
				'provenance_source'     => (string) ( $fact['provenance']['source_path'] ?? '' ),
				'status'                => '' === (string) ( $fact['fallback']['text'] ?? '' ) ? 'unresolved' : 'captured_fallback',
			);
		}

		return array(
			'schema'           => self::SCHEMA,
			'owner'            => 'theme' === ( $input['owner'] ?? '' ) ? 'theme' : 'plugin',
			'site_name'        => self::header_value( (string) ( $input['site_name'] ?? '' ) ),
			'plugin_slug'      => (string) ( $input['plugin_slug'] ?? '' ),
			'mu_plugin'        => ! empty( $input['mu_plugin'] ),
			'blocks'           => $blocks,
			'scripts'          => $scripts,
			'editor_scripts'   => $editor_scripts,
			'external_metrics' => $external_metrics,
			'forms'            => array(
				'carried' => array() !== $form_states,
				'count'   => count( $form_states ),
				'status'  => self::STATUS_REBUILT,
			),
			'routes'           => array(
				'redirects'      => array( 'status' => self::STATUS_REBUILT ),
				'internal_links' => array( 'status' => self::STATUS_REBUILT ),
			),
			'content'          => array(
				'status'                 => empty( $external_metrics ) ? self::STATUS_SNAPSHOT : 'partially_dynamic',
				'snapshot_status'        => self::STATUS_SNAPSHOT,
				'external_metric_status' => empty( $external_metrics ) ? 'not_present' : 'configured',
			),
			'provenance'       => self::project_provenance( is_array( $input['provenance'] ?? null ) ? $input['provenance'] : array(), (string) ( $input['plugin_slug'] ?? '' ) ),
			'handoff'          => self::project_handoff( $input['handoff'] ?? null ),
		);
	}

	/**
	 * Render the deterministic owner-facing README for a composed inventory.
	 *
	 * @param array<string,mixed> $inventory Composed inventory.
	 * @return string
	 */
	public static function render_readme( array $inventory ): string {
		$site             = '' !== (string) ( $inventory['site_name'] ?? '' ) ? (string) $inventory['site_name'] : 'the imported site';
		$blocks           = is_array( $inventory['blocks'] ?? null ) ? $inventory['blocks'] : array();
		$scripts          = is_array( $inventory['scripts'] ?? null ) ? $inventory['scripts'] : array();
		$editor           = is_array( $inventory['editor_scripts'] ?? null ) ? $inventory['editor_scripts'] : array();
		$external_metrics = is_array( $inventory['external_metrics'] ?? null ) ? $inventory['external_metrics'] : array();
		$forms            = is_array( $inventory['forms'] ?? null ) ? $inventory['forms'] : array();
		$theme_owned      = 'theme' === ( $inventory['owner'] ?? '' );
		$owner            = $theme_owned ? 'theme' : 'plugin';

		$lines   = array();
		$lines[] = '# ' . ( $theme_owned ? $site . ' theme runtime' : self::plugin_name( (string) ( $inventory['site_name'] ?? '' ) ) );
		$lines[] = '';
		$lines[] = 'This ' . ( $theme_owned ? 'theme runtime' : 'plugin' ) . ' was generated by the Static Site Importer for the ' . $site . ' WordPress site.';
		$lines[] = $theme_owned ? 'The generated theme owns these blocks and runtime through functions.php. No generated companion plugin or MU-loader is required.' : 'It is theme-independent: the imported pages below depend on this plugin, not on a specific theme.';
		$lines[] = '';
		$lines[] = '## What this ' . $owner . ' owns';
		$lines[] = '';

		$lines[] = '### Rebuilt in WordPress';
		$lines[] = '';
		foreach ( $blocks as $block ) {
			$lines[] = '- `' . (string) ( $block['name'] ?? '' ) . '` — ' . (string) ( $block['title'] ?? '' ) . ' is registered as a native WordPress block. Owned files: ' . implode( ', ', array_map( static fn ( string $path ): string => '`' . $path . '`', is_array( $block['assets'] ?? null ) ? $block['assets'] : array() ) ) . '.';
		}
		$lines[] = '- Source-route redirects: a request for an old source route (for example `/page.html`) 301-redirects to the imported page\'s WordPress permalink.';
		$lines[] = '- Internal-link rewriting: imported internal links keep resolving to the imported pages.';
		if ( ! empty( $forms['carried'] ?? false ) ) {
			$lines[] = '- Imported form field presentation: ' . (int) ( $forms['count'] ?? 0 ) . ' captured form field visual state' . ( 1 === (int) ( $forms['count'] ?? 0 ) ? '' : 's' ) . ' project onto the WordPress (Jetpack Forms) contact-form rendering this ' . $owner . ' registers.';
		}
		$lines[] = '';

		$lines[] = '### Preserved runtime assets';
		$lines[] = '';
		if ( array() === $scripts && array() === $editor ) {
			$lines[] = 'No frontend or editor runtime scripts were included in this build.';
		}
		foreach ( $scripts as $script ) {
			$owning  = '' !== (string) ( $script['block'] ?? '' ) ? 'loads when `' . (string) $script['block'] . '` renders' : 'loads on the imported frontend';
			$origin  = '' !== (string) ( $script['source_path'] ?? '' ) ? ', artifact scope `' . (string) $script['source_path'] . '`' : '';
			$lines[] = '- `' . (string) ( $script['handle'] ?? '' ) . '` (`' . (string) ( $script['path'] ?? '' ) . '`) — included frontend JavaScript' . $origin . '; ' . $owning . '.';
		}
		foreach ( $editor as $script ) {
			$lines[] = '- `' . (string) ( $script['handle'] ?? '' ) . '` (`' . (string) ( $script['path'] ?? '' ) . '`) — preserved editor-only JavaScript, enqueued in the block editor only.';
		}
		$lines[] = '';

		if ( ! empty( $external_metrics ) ) {
			$lines[] = '### Live external metrics';
			$lines[] = '';
			$lines[] = 'These native text bindings use validated, declarative HTTPS JSON source recipes. Their initial freshness receipt status is `captured_fallback` (or `unresolved` for an empty fallback); a successful frontend request changes it to `fresh`. Failed values use the last-known-good receipt as `stale`, or preserve the captured fallback. Partial aggregates are never shown as complete totals.';
			foreach ( $external_metrics as $metric ) {
				$source    = trim( (string) ( $metric['provenance_repository'] ?? '' ) . '@' . (string) ( $metric['provenance_revision'] ?? '' ) . ':' . (string) ( $metric['provenance_source'] ?? '' ), '@:' );
				$resources = wp_json_encode( $metric['resources'] ?? array(), JSON_UNESCAPED_SLASHES );
				$lines[]   = '- `' . (string) ( $metric['id'] ?? '' ) . '` — source `' . (string) ( $metric['source_id'] ?? '' ) . '` / metric `' . (string) ( $metric['metric'] ?? '' ) . '` (`' . (string) ( $metric['aggregation'] ?? '' ) . '`) with freshness `' . (int) ( $metric['freshness_seconds'] ?? 0 ) . 's`, resources `' . ( is_string( $resources ) ? $resources : '[]' ) . '`; provenance `' . $source . '`; configuration status `' . (string) ( $metric['status'] ?? 'unknown' ) . '`.';
			}
			$lines[] = '';
		}
		$lines[] = '### Captured snapshot';
		$lines[] = '';
		if ( empty( $external_metrics ) ) {
			$lines[] = 'Ordinary imported text, images and numbers begin as static values captured at import time. This includes figures that';
			$lines[] = 'originated outside the site itself (for example plugin download or install totals from an external directory):';
			$lines[] = 'their status is snapshot. Snapshot values do not update automatically; no live data source or refresh mechanism is installed';
			$lines[] = 'merely because a number appears in the content. Explicit runtime blocks and scripts listed above may implement updates;';
			$lines[] = 'their owned fields are not classified as static by this note. Other external figures remain unverified source claims.';
		} else {
			$lines[] = 'Ordinary imported text, images and values without one of the explicit bindings above remain static snapshots. Numbers are never classified as dynamic by appearance alone.';
		}
		$lines[] = '';

		$lines[]    = '### Unresolved behavior';
		$lines[]    = '';
		$handoff    = is_array( $inventory['handoff'] ?? null ) ? $inventory['handoff'] : array();
		$unresolved = is_array( $handoff['unresolved'] ?? null ) ? $handoff['unresolved'] : array();
		if ( array() === $unresolved ) {
			$lines[] = 'None recorded' . ( 'recorded' === ( $handoff['status'] ?? '' ) ? '.' : ' — see Unknown evidence below.' );
		}
		foreach ( $unresolved as $row ) {
			$scope   = trim( (string) ( $row['route'] ?? '' ) . ' ' . (string) ( $row['component'] ?? '' ) );
			$lines[] = '- `' . (string) ( $row['status'] ?? '' ) . '` ' . (string) ( $row['dimension'] ?? '' ) . ( '' !== $scope ? ' (' . $scope . ')' : '' ) . ' — ' . (string) ( $row['action'] ?? '' ) . ( '' !== (string) ( $row['owner'] ?? '' ) ? ' (owning: ' . (string) $row['owner'] . ')' : '' ) . '.';
		}
		$lines[] = '';

		$lines[] = '### Unknown evidence';
		$lines[] = '';
		if ( 'recorded' === ( $handoff['status'] ?? '' ) ) {
			$lines[] = 'An owner-handoff evidence report is embedded in this build (disposition: `' . (string) ( $handoff['disposition'] ?? 'not_proven' ) . '`), so the unresolved rows above are authoritative for this build.';
		} else {
			$lines[] = 'No owner-handoff evidence report was embedded in this build. Functionality beyond the inventories above has status: unknown. Nothing in this README asserts that unlisted behavior works.';
		}
		$lines[] = '';

		$lines[] = '## Dependencies and removal consequences';
		$lines[] = '';
		$lines[] = 'The imported blocks and scripts listed above live in this ' . $owner . '. ' . ( $theme_owned ? 'Switching away from or deleting the theme:' : 'Deactivating or deleting it:' );
		$lines[] = '';
		$lines[] = '- unregisters the imported blocks; saved static markup may remain, while dynamic rendering and editor support can be lost;';
		$lines[] = '- stops the preserved source scripts from loading, breaking the interactive behavior they provide;';
		$lines[] = '- stops source-route redirects, so old source routes begin to 404;';
		$lines[] = '- stops the imported form field presentation and its WordPress form rendering hooks.';
		if ( ! empty( $forms['carried'] ?? false ) ) {
			$lines[] = '';
			$lines[] = 'The imported form fields render through the Jetpack Forms plugin. Removing Jetpack Forms removes form submission handling; this ' . $owner . ' only projects the captured field presentation onto it.';
		}
		$lines[] = '';

		$lines[] = '## How to spot-check the import';
		$lines[] = '';
		if ( array() !== $blocks ) {
			$lines[] = '- Blocks: open an imported page in the editor and confirm each block listed above renders and stays editable after save/reload.';
		}
		if ( array() !== $scripts ) {
			$lines[] = '- Preserved scripts: view the page source on the owning route and confirm each script listed above is enqueued.';
		}
		$lines[] = '- Redirects: request an old source route such as `/page.html` and confirm a 301 to the imported permalink.';
		$lines[] = '- Snapshot content: compare a page against the source capture; imported numbers do not update.';
		if ( ! empty( $forms['carried'] ?? false ) ) {
			$lines[] = '- Forms: submit an imported form and confirm the normal WordPress form confirmation.';
		}
		$lines[] = '';

		$lines[]    = '## Build version and provenance';
		$lines[]    = '';
		$provenance = is_array( $inventory['provenance'] ?? null ) ? $inventory['provenance'] : array();
		if ( ! empty( $provenance['recorded'] ?? false ) ) {
			$lines[] = 'This build was produced by `' . (string) ( $provenance['generator'] ?? '' ) . '` using `' . (string) ( $provenance['engine_version'] ?? '' ) . '`.';
			$lines[] = 'The ' . ( $theme_owned ? 'runtime' : 'plugin' ) . ' `Version` header stamps the producing build (`' . (string) ( $provenance['version_header'] ?? '' ) . '`, from artifact hash `' . (string) ( $provenance['artifact_hash'] ?? '' ) . '`), so the artifact stays attributable after the Static Site Importer is removed; it is not a placeholder version.';
			if ( '' !== (string) ( $provenance['update_uri'] ?? '' ) ) {
				$lines[] = 'The `Update URI` header (`' . (string) $provenance['update_uri'] . '`) addresses updates for this generated artifact specifically; it points at no live update provider by default.';
			}
		} else {
			$lines[] = 'This payload carried no producer provenance, so the ' . ( $theme_owned ? 'runtime entrypoint' : 'main plugin file' ) . ' keeps the compatibility default `Version: 1.0.0` and emits no `Update URI` header. No producing build is claimed beyond this note.';
		}
		$lines[] = '';

		return implode( "\n", $lines );
	}

	/**
	 * Sanitize a single-line WordPress header value.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function header_value( string $value ): string {
		$value = str_replace( array( '*/', "\r", "\n", "\0" ), array( '* /', ' ', ' ', ' ' ), $value );

		return (string) preg_replace( '/\s+/', ' ', trim( $value ) );
	}

	/**
	 * Project the validated producer provenance into owner-facing facts.
	 *
	 * The stamped header values are read through the build-provenance contract
	 * so the README can never disagree with the main plugin file header.
	 *
	 * @param array<string,mixed> $provenance Validated artifact provenance record.
	 * @param string              $plugin_slug Generated companion slug.
	 * @return array<string,mixed>
	 */
	private static function project_provenance( array $provenance, string $plugin_slug ): array {
		if ( array() === $provenance || ! Static_Site_Importer_Build_Provenance::valid_artifact_provenance( $provenance ) ) {
			return array(
				'recorded'       => false,
				'generator'      => '',
				'engine_version' => '',
				'artifact_hash'  => '',
				'version_header' => '',
				'update_uri'     => '',
			);
		}

		$headers = Static_Site_Importer_Build_Provenance::artifact_header_lines( $provenance, $plugin_slug );
		$version = '';
		$update  = '';
		foreach ( $headers as $line ) {
			if ( str_starts_with( $line, 'Version: ' ) ) {
				$version = substr( $line, strlen( 'Version: ' ) );
			} elseif ( str_starts_with( $line, 'Update URI: ' ) ) {
				$update = substr( $line, strlen( 'Update URI: ' ) );
			}
		}

		return array(
			'recorded'       => true,
			'generator'      => (string) $provenance['generator'],
			'engine_version' => (string) $provenance['engine_version'],
			'artifact_hash'  => substr( strtolower( (string) $provenance['artifact_hash'] ), 0, 8 ),
			'version_header' => $version,
			'update_uri'     => $update,
		);
	}

	/**
	 * Project a canonical owner-handoff evidence document into unresolved rows.
	 *
	 * @param mixed $document Canonical owner-handoff evidence document.
	 * @return array<string,mixed>
	 */
	private static function project_handoff( mixed $document ): array {
		if ( ! is_array( $document ) || self::HANDOFF_SCHEMA !== ( $document['schema'] ?? '' ) ) {
			return array(
				'status'                 => self::STATUS_UNKNOWN,
				'schema'                 => '',
				'disposition'            => '',
				'accepted_built_allowed' => null,
				'unresolved'             => array(),
			);
		}

		$unresolved = array();
		foreach ( is_array( $document['findings'] ?? null ) ? $document['findings'] : array() as $finding ) {
			if ( ! is_array( $finding ) ) {
				continue;
			}
			$status = (string) ( $finding['status'] ?? '' );
			if ( '' === $status || ! in_array( $status, self::UNRESOLVED_FINDING_STATUSES, true ) ) {
				continue;
			}
			$unresolved[] = array(
				'status'    => $status,
				'dimension' => (string) ( $finding['dimension'] ?? '' ),
				'route'     => (string) ( $finding['route'] ?? '' ),
				'component' => (string) ( $finding['component'] ?? '' ),
				'action'    => (string) ( $finding['recommended_next_action'] ?? '' ),
				'owner'     => (string) ( $finding['owning_repository'] ?? '' ),
			);
		}

		return array(
			'status'                 => 'recorded',
			'schema'                 => self::HANDOFF_SCHEMA,
			'disposition'            => (string) ( $document['disposition'] ?? 'not_proven' ),
			'accepted_built_allowed' => ! empty( $document['accepted_built_allowed'] ),
			'unresolved'             => $unresolved,
		);
	}
}

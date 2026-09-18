<?php
/**
 * Compares capture section geometry against an imported WordPress render.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Diagnostic_Loss_Classes' ) ) {
	require_once __DIR__ . '/class-static-site-importer-diagnostic-loss-classes.php';
}

/**
 * Section-level visual parity oracle for import-vs-capture comparison.
 *
 * Page-level aggregates are intentionally unused: a 1px page-height delta can
 * hide a logo that rendered 20× too large. Capture `sections/*.json` is the
 * comparison grain. When either side is missing the check is skipped so an
 * environment without a browser stays on today's not-verified path.
 */
final class Static_Site_Importer_Visual_Parity_Oracle {

	public const DIAGNOSTIC_TYPE = 'visual_parity_mismatch';
	public const FAILURE_REASON  = 'visual_parity_mismatch';
	public const STAGE           = 'import_vs_capture';
	public const VERIFICATION    = 'section_geometry';

	/**
	 * Defensible defaults. Sub-pixel and 1–2px deltas are normal across
	 * rendering contexts; a section that is materially taller/shorter, a
	 * heading whose size changed, or a missing/oversized image is not.
	 *
	 * @var array<string,float|int>
	 */
	public const DEFAULT_TOLERANCES = array(
		'geometry_px'        => 2,
		'geometry_ratio'     => 0.01,
		'heading_px'         => 1,
		'heading_ratio'      => 0.05,
		'display_px'         => 2,
		'display_ratio'      => 0.05,
		'text_length_ratio'  => 0.15,
	);

	/**
	 * Evaluate runtime-provided section records, or skip when they are absent.
	 *
	 * @param array<string,mixed> $provided validation_artifacts payload.
	 * @return array<string,mixed>
	 */
	public static function evaluate( array $provided ): array {
		$input = self::input_from_validation_artifacts( $provided );
		if ( null === $input ) {
			return self::skipped_result( 'Capture or imported section records were not provided.' );
		}

		$tolerances = self::tolerances( isset( $input['tolerances'] ) && is_array( $input['tolerances'] ) ? $input['tolerances'] : array() );
		$omissions  = self::omissions( $input['omissions'] ?? array() );
		$source     = self::pages( $input['source_pages'] );
		$imported   = self::pages( $input['imported_pages'] );
		if ( array() === $source || array() === $imported ) {
			return self::skipped_result( 'Capture or imported section records were not provided.' );
		}

		$disagreements = array();
		$omitted       = array();
		$matched       = array();
		foreach ( $source as $page_id => $source_page ) {
			if ( ! isset( $imported[ $page_id ] ) ) {
				$disagreements[] = self::disagreement(
					$page_id,
					null,
					'missing_imported_page',
					'Imported render did not include this captured page.',
					array(
						'source' => $page_id,
					)
				);
				continue;
			}
			$matched[ $page_id ] = true;
			$page_result         = self::compare_page( $page_id, $source_page, $imported[ $page_id ], $omissions, $tolerances );
			$disagreements       = array_merge( $disagreements, $page_result['disagreements'] );
			$omitted             = array_merge( $omitted, $page_result['omitted'] );
		}
		foreach ( array_keys( $imported ) as $page_id ) {
			if ( ! isset( $matched[ $page_id ] ) && ! isset( $source[ $page_id ] ) ) {
				$disagreements[] = self::disagreement(
					$page_id,
					null,
					'unexpected_imported_page',
					'Imported render included a page that was not in the capture.',
					array(
						'imported' => $page_id,
					)
				);
			}
		}

		$failed = array() !== $disagreements;
		$diff   = array(
			'schema'             => 'static-site-importer/visual-parity-diff/v1',
			'status'             => $failed ? 'failed' : 'passed',
			'verification'       => self::VERIFICATION,
			'stage'              => self::STAGE,
			'tolerances'         => $tolerances,
			'page_count'         => count( $source ),
			'disagreement_count' => count( $disagreements ),
			'omitted_count'      => count( $omitted ),
			'disagreements'      => $disagreements,
			'omitted'            => $omitted,
		);

		return array(
			'status'        => $failed ? 'failed' : 'passed',
			'reason'        => $failed ? 'Imported section geometry disagrees with the capture.' : 'Imported section geometry matches the capture within tolerances.',
			'verification'  => self::VERIFICATION,
			'stage'         => self::STAGE,
			'tolerances'    => $tolerances,
			'disagreements' => $disagreements,
			'omitted'       => $omitted,
			'diagnostics'   => self::diagnostics_from_disagreements( $disagreements ),
			'artifact_refs' => array(
				'visual_diff'    => array(
					'kind'          => 'visual_diff',
					'artifact_name' => 'visual-diff.json',
				),
				'browser_render' => array(
					'kind'          => 'browser_render_evidence',
					'artifact_name' => 'imported-sections.json',
				),
			),
			'summary'       => array(
				'section_disagreement_count' => count( $disagreements ),
				'omitted_count'              => count( $omitted ),
				'compared_page_count'        => count( $source ),
			),
			'visual_diff'   => $diff,
		);
	}

	/**
	 * Read source/imported section records from the existing artifacts object.
	 *
	 * @param array<string,mixed> $provided validation_artifacts payload.
	 * @return array<string,mixed>|null
	 */
	public static function input_from_validation_artifacts( array $provided ): ?array {
		$nested = array();
		foreach ( array( 'visual_parity', 'visual_parity_oracle', 'section_parity' ) as $key ) {
			if ( isset( $provided[ $key ] ) && is_array( $provided[ $key ] ) ) {
				$nested = $provided[ $key ];
				break;
			}
		}

		$source   = $nested['source_pages'] ?? $nested['source_sections'] ?? $provided['source_pages'] ?? $provided['source_sections'] ?? null;
		$imported = $nested['imported_pages'] ?? $nested['imported_sections'] ?? $provided['imported_pages'] ?? $provided['imported_sections'] ?? null;
		if ( ! is_array( $source ) || ! is_array( $imported ) ) {
			return null;
		}

		$omissions  = $nested['omissions'] ?? $nested['cleanup'] ?? $provided['omissions'] ?? $provided['cleanup'] ?? array();
		$tolerances = $nested['tolerances'] ?? $provided['tolerances'] ?? array();

		return array(
			'source_pages'   => $source,
			'imported_pages' => $imported,
			'omissions'      => is_array( $omissions ) ? $omissions : array(),
			'tolerances'     => is_array( $tolerances ) ? $tolerances : array(),
		);
	}

	/**
	 * Merge caller overrides onto the documented defaults.
	 *
	 * @param array<string,mixed> $overrides Caller tolerances.
	 * @return array<string,float>
	 */
	public static function tolerances( array $overrides = array() ): array {
		$tolerances = array();
		foreach ( self::DEFAULT_TOLERANCES as $key => $default ) {
			$value              = isset( $overrides[ $key ] ) && is_numeric( $overrides[ $key ] ) ? 0 + $overrides[ $key ] : $default;
			$tolerances[ $key ] = max( 0, (float) $value );
		}

		return $tolerances;
	}

	/**
	 * @param string $reason Skip reason.
	 * @return array<string,mixed>
	 */
	private static function skipped_result( string $reason ): array {
		return array(
			'status'        => 'skipped',
			'reason'        => $reason,
			'verification'  => 'not_verified',
			'stage'         => self::STAGE,
			'tolerances'    => self::DEFAULT_TOLERANCES,
			'disagreements' => array(),
			'omitted'       => array(),
			'diagnostics'   => array(),
			'artifact_refs' => array(),
			'summary'       => array(),
			'visual_diff'   => array(),
		);
	}

	/**
	 * @param mixed $value Page records in list, map, or single-record form.
	 * @return array<string,array<string,mixed>>
	 */
	private static function pages( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		if ( isset( $value['sections'] ) && is_array( $value['sections'] ) ) {
			$page = self::page_record( $value, self::page_id( $value, 'page' ) );
			return array( $page['id'] => $page );
		}

		$pages = array();
		foreach ( $value as $key => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( isset( $item['pages'] ) && is_array( $item['pages'] ) ) {
				foreach ( self::pages( $item['pages'] ) as $id => $page ) {
					$pages[ $id ] = $page;
				}
				continue;
			}
			$page                 = self::page_record( $item, self::page_id( $item, is_string( $key ) ? $key : 'page' ) );
			$pages[ $page['id'] ] = $page;
		}

		return $pages;
	}

	/**
	 * @param array<string,mixed> $record Raw page record.
	 * @param string              $fallback Fallback page id.
	 */
	private static function page_id( array $record, string $fallback ): string {
		foreach ( array( 'page', 'page_id', 'id', 'slug', 'route' ) as $key ) {
			$value = self::scalar( $record, array( $key ) );
			if ( '' !== $value ) {
				return self::normalize_page_id( $value );
			}
		}
		$url = self::scalar( $record, array( 'sourceUrl', 'source_url', 'url' ) );
		if ( '' !== $url ) {
			$path = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_PATH ) : parse_url( $url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standalone smoke has no WordPress URL API.
			$path = trim( (string) $path, '/' );
			return self::normalize_page_id( '' !== $path ? $path : 'index' );
		}

		return self::normalize_page_id( $fallback );
	}

	private static function normalize_page_id( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '/\.(html?|json)$/', '', $value ) ?? $value;
		$value = str_replace( array( '\\', ' ' ), array( '/', '-' ), $value );
		$value = trim( $value, '/' );
		if ( in_array( $value, array( '', 'index', 'home', 'homepage', '/' ), true ) ) {
			return 'index';
		}

		return $value;
	}

	/**
	 * @param array<string,mixed> $record Raw page record.
	 * @param string              $id     Page id.
	 * @return array<string,mixed>
	 */
	private static function page_record( array $record, string $id ): array {
		$sections  = isset( $record['sections'] ) && is_array( $record['sections'] ) ? array_values( $record['sections'] ) : array();
		$landmarks = isset( $record['landmarks'] ) && is_array( $record['landmarks'] ) ? array_values( $record['landmarks'] ) : array();
		$viewport  = isset( $record['viewport'] ) && is_array( $record['viewport'] ) ? $record['viewport'] : array();

		return array(
			'id'        => self::normalize_page_id( $id ),
			'viewport'  => $viewport,
			'sections'  => $sections,
			'landmarks' => $landmarks,
		);
	}

	/**
	 * @param mixed $value Cleanup evidence or omission list.
	 * @return array<int,array<string,mixed>>
	 */
	private static function omissions( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$records = array();
		if ( isset( $value['pages'] ) && is_array( $value['pages'] ) ) {
			foreach ( $value['pages'] as $page ) {
				if ( ! is_array( $page ) ) {
					continue;
				}
				foreach ( $page['reports'] ?? array() as $report ) {
					if ( ! is_array( $report ) ) {
						continue;
					}
					foreach ( $report['records'] ?? array() as $record ) {
						if ( is_array( $record ) ) {
							$records[] = $record;
						}
					}
				}
			}
			return $records;
		}
		foreach ( $value as $item ) {
			if ( is_string( $item ) && '' !== trim( $item ) ) {
				$records[] = array( 'selector' => trim( $item ) );
				continue;
			}
			if ( is_array( $item ) ) {
				$records[] = $item;
			}
		}

		return $records;
	}

	/**
	 * @param string               $page_id    Page id.
	 * @param array<string,mixed>  $source     Capture page.
	 * @param array<string,mixed>  $imported   Imported page.
	 * @param array<int,array<string,mixed>> $omissions Deliberate removals.
	 * @param array<string,float>  $tolerances Comparison tolerances.
	 * @return array{disagreements:array<int,array<string,mixed>>,omitted:array<int,array<string,mixed>>}
	 */
	private static function compare_page( string $page_id, array $source, array $imported, array $omissions, array $tolerances ): array {
		$disagreements     = array();
		$omitted           = array();
		$source_sections   = array_values( $source['sections'] );
		$imported_sections = array_values( $imported['sections'] );
		$count             = max( count( $source_sections ), count( $imported_sections ) );
		$header_top        = isset( $source_sections[0] ) && is_array( $source_sections[0] ) ? self::number( $source_sections[0], array( 'top' ) ) : null;

		for ( $index = 0; $index < $count; $index++ ) {
			$source_section   = isset( $source_sections[ $index ] ) && is_array( $source_sections[ $index ] ) ? $source_sections[ $index ] : null;
			$imported_section = isset( $imported_sections[ $index ] ) && is_array( $imported_sections[ $index ] ) ? $imported_sections[ $index ] : null;
			if ( null === $source_section ) {
				$disagreements[] = self::disagreement(
					$page_id,
					$index,
					'extra_section',
					'Imported render has a section the capture does not.',
					array(
						'imported_selector' => self::scalar( $imported_section ?? array(), array( 'selector' ) ),
					)
				);
				continue;
			}
			if ( null === $imported_section ) {
				$disagreements[] = self::disagreement(
					$page_id,
					$index,
					'missing_section',
					'Imported render is missing a captured section.',
					array(
						'source_selector' => self::scalar( $source_section, array( 'selector' ) ),
					)
				);
				continue;
			}
			$section_result = self::compare_section( $page_id, $index, $source_section, $imported_section, $omissions, $tolerances );
			$disagreements  = array_merge( $disagreements, $section_result['disagreements'] );
			$omitted        = array_merge( $omitted, $section_result['omitted'] );
		}

		$landmark_result = self::compare_landmarks( $page_id, $source['landmarks'], $imported['landmarks'], $header_top, $omissions, $tolerances );
		$disagreements   = array_merge( $disagreements, $landmark_result['disagreements'] );
		$omitted         = array_merge( $omitted, $landmark_result['omitted'] );

		return array(
			'disagreements' => $disagreements,
			'omitted'       => $omitted,
		);
	}

	/**
	 * @param string              $page_id          Page id.
	 * @param int                 $index            Section index.
	 * @param array<string,mixed> $source           Capture section.
	 * @param array<string,mixed> $imported         Imported section.
	 * @param array<int,array<string,mixed>> $omissions Deliberate removals.
	 * @param array<string,float> $tolerances       Comparison tolerances.
	 * @return array{disagreements:array<int,array<string,mixed>>,omitted:array<int,array<string,mixed>>}
	 */
	private static function compare_section( string $page_id, int $index, array $source, array $imported, array $omissions, array $tolerances ): array {
		$disagreements = array();
		$omitted       = array();
		$selector      = self::scalar( $source, array( 'selector' ) );

		foreach ( array( 'top', 'height' ) as $field ) {
			$source_value   = self::number( $source, array( $field ) );
			$imported_value = self::number( $imported, array( $field ) );
			if ( null === $source_value || null === $imported_value ) {
				continue;
			}
			if ( self::materially_differs( $source_value, $imported_value, $tolerances['geometry_px'], $tolerances['geometry_ratio'] ) ) {
				$disagreements[] = self::disagreement(
					$page_id,
					$index,
					'section_' . $field,
					sprintf( 'Section %s disagrees with the capture.', $field ),
					array(
						'selector' => $selector,
						'source'   => $source_value,
						'imported' => $imported_value,
					)
				);
			}
		}

		$source_sizes   = self::number_list( $source['headingSizes'] ?? $source['heading_sizes'] ?? array() );
		$imported_sizes = self::number_list( $imported['headingSizes'] ?? $imported['heading_sizes'] ?? array() );
		$size_count     = max( count( $source_sizes ), count( $imported_sizes ) );
		for ( $size_index = 0; $size_index < $size_count; $size_index++ ) {
			if ( ! isset( $source_sizes[ $size_index ] ) || ! isset( $imported_sizes[ $size_index ] ) ) {
				$disagreements[] = self::disagreement(
					$page_id,
					$index,
					'heading_count',
					'Heading count disagrees with the capture.',
					array(
						'selector' => $selector,
						'source'   => count( $source_sizes ),
						'imported' => count( $imported_sizes ),
					)
				);
				break;
			}
			if ( self::materially_differs( $source_sizes[ $size_index ], $imported_sizes[ $size_index ], $tolerances['heading_px'], $tolerances['heading_ratio'] ) ) {
				$disagreements[] = self::disagreement(
					$page_id,
					$index,
					'heading_size',
					'Heading size disagrees with the capture.',
					array(
						'selector' => $selector,
						'index'    => $size_index,
						'source'   => $source_sizes[ $size_index ],
						'imported' => $imported_sizes[ $size_index ],
					)
				);
			}
		}

		$source_images   = self::filter_items( isset( $source['images'] ) && is_array( $source['images'] ) ? $source['images'] : array(), $omissions, $omitted, $page_id, $index, 'image' );
		$imported_images = self::filter_items( isset( $imported['images'] ) && is_array( $imported['images'] ) ? $imported['images'] : array(), $omissions, $omitted, $page_id, $index, 'image' );
		if ( count( $source_images ) !== count( $imported_images ) ) {
			$disagreements[] = self::disagreement(
				$page_id,
				$index,
				'image_count',
				'Section image count disagrees with the capture.',
				array(
					'selector' => $selector,
					'source'   => count( $source_images ),
					'imported' => count( $imported_images ),
				)
			);
		} else {
			foreach ( $source_images as $image_index => $source_image ) {
				$imported_image = $imported_images[ $image_index ];
				$source_box     = self::display_box( $source_image );
				$imported_box   = self::display_box( $imported_image );
				if ( null === $source_box || null === $imported_box ) {
					continue;
				}
				foreach ( array( 'width', 'height' ) as $edge ) {
					if ( self::materially_differs( $source_box[ $edge ], $imported_box[ $edge ], $tolerances['display_px'], $tolerances['display_ratio'] ) ) {
						$disagreements[] = self::disagreement(
							$page_id,
							$index,
							'image_display_' . $edge,
							'Image display size disagrees with the capture.',
							array(
								'selector' => $selector,
								'alt'      => self::scalar( $source_image, array( 'alt' ) ),
								'source'   => $source_box[ $edge ],
								'imported' => $imported_box[ $edge ],
							)
						);
					}
				}
			}
		}

		$source_fields   = self::visible_form_fields( $source, $omissions, $omitted, $page_id, $index );
		$imported_fields = self::visible_form_fields( $imported, $omissions, $omitted, $page_id, $index );
		if ( count( $source_fields ) !== count( $imported_fields ) ) {
			$disagreements[] = self::disagreement(
				$page_id,
				$index,
				'form_field_count',
				'Visible form field count disagrees with the capture.',
				array(
					'selector' => $selector,
					'source'   => count( $source_fields ),
					'imported' => count( $imported_fields ),
				)
			);
		}

		return array(
			'disagreements' => $disagreements,
			'omitted'       => $omitted,
		);
	}

	/**
	 * @param string                         $page_id     Page id.
	 * @param array<int,mixed>               $source      Capture landmarks.
	 * @param array<int,mixed>               $imported    Imported landmarks.
	 * @param float|null                     $header_top  First section top from capture.
	 * @param array<int,array<string,mixed>> $omissions   Deliberate removals.
	 * @param array<string,float>            $tolerances  Comparison tolerances.
	 * @return array{disagreements:array<int,array<string,mixed>>,omitted:array<int,array<string,mixed>>}
	 */
	private static function compare_landmarks( string $page_id, array $source, array $imported, ?float $header_top, array $omissions, array $tolerances ): array {
		$disagreements    = array();
		$omitted          = array();
		$imported_by_role = array();
		foreach ( $imported as $landmark ) {
			if ( ! is_array( $landmark ) ) {
				continue;
			}
			$role = self::scalar( $landmark, array( 'role', 'tag' ) );
			if ( '' !== $role ) {
				$imported_by_role[ $role ] = $landmark;
			}
		}

		foreach ( $source as $landmark ) {
			if ( ! is_array( $landmark ) ) {
				continue;
			}
			if ( self::is_omitted( $landmark, $omissions ) ) {
				$omitted[] = array(
					'page'     => $page_id,
					'kind'     => 'landmark',
					'selector' => self::scalar( $landmark, array( 'selector' ) ),
				);
				continue;
			}
			$role = self::scalar( $landmark, array( 'role', 'tag' ) );
			if ( '' === $role || ! isset( $imported_by_role[ $role ] ) ) {
				$disagreements[] = self::disagreement(
					$page_id,
					null,
					'missing_landmark',
					'Imported render is missing a captured landmark.',
					array(
						'role'     => $role,
						'selector' => self::scalar( $landmark, array( 'selector' ) ),
					)
				);
				continue;
			}
			$imported_landmark = $imported_by_role[ $role ];
			$source_media      = self::number( $landmark, array( 'mediaCount', 'media_count' ) );
			$imported_media    = self::number( $imported_landmark, array( 'mediaCount', 'media_count' ) );
			if ( null !== $source_media && null !== $imported_media && $source_media !== $imported_media ) {
				$disagreements[] = self::disagreement(
					$page_id,
					null,
					'landmark_media_count',
					'Landmark media count disagrees with the capture.',
					array(
						'role'     => $role,
						'source'   => $source_media,
						'imported' => $imported_media,
					)
				);
			}
			$source_height   = self::number( $landmark, array( 'height' ) );
			$imported_height = self::number( $imported_landmark, array( 'height' ) );
			if ( null === $source_height && 'header' === $role && null !== $header_top ) {
				$source_height = $header_top;
			}
			if ( null !== $source_height && null !== $imported_height && self::materially_differs( $source_height, $imported_height, $tolerances['geometry_px'], $tolerances['geometry_ratio'] ) ) {
				$disagreements[] = self::disagreement(
					$page_id,
					null,
					'landmark_height',
					'Landmark height disagrees with the capture.',
					array(
						'role'     => $role,
						'source'   => $source_height,
						'imported' => $imported_height,
					)
				);
			}
		}

		return array(
			'disagreements' => $disagreements,
			'omitted'       => $omitted,
		);
	}

	/**
	 * @param array<int,mixed>               $items     Candidate items.
	 * @param array<int,array<string,mixed>> $omissions Deliberate removals.
	 * @param array<int,array<string,mixed>> $omitted   Collected omissions.
	 * @param string                         $page_id   Page id.
	 * @param int                            $index     Section index.
	 * @param string                         $kind      Item kind.
	 * @return array<int,array<string,mixed>>
	 */
	private static function filter_items( array $items, array $omissions, array &$omitted, string $page_id, int $index, string $kind ): array {
		$kept = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( self::is_omitted( $item, $omissions ) ) {
				$omitted[] = array(
					'page'     => $page_id,
					'section'  => $index,
					'kind'     => $kind,
					'selector' => self::scalar( $item, array( 'selector', 'id' ) ),
					'alt'      => self::scalar( $item, array( 'alt' ) ),
				);
				continue;
			}
			$kept[] = $item;
		}

		return array_values( $kept );
	}

	/**
	 * @param array<string,mixed>            $section   Section record.
	 * @param array<int,array<string,mixed>> $omissions Deliberate removals.
	 * @param array<int,array<string,mixed>> $omitted   Collected omissions.
	 * @param string                         $page_id   Page id.
	 * @param int                            $index     Section index.
	 * @return array<int,array<string,mixed>>
	 */
	private static function visible_form_fields( array $section, array $omissions, array &$omitted, string $page_id, int $index ): array {
		$forms  = isset( $section['forms'] ) && is_array( $section['forms'] ) ? $section['forms'] : array();
		$fields = array();
		foreach ( $forms as $form ) {
			if ( ! is_array( $form ) ) {
				continue;
			}
			$rows = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : array();
			foreach ( $rows as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				if ( self::is_honeypot_field( $field ) || self::is_omitted( $field, $omissions ) ) {
					$omitted[] = array(
						'page'    => $page_id,
						'section' => $index,
						'kind'    => 'form_field',
						'name'    => self::scalar( $field, array( 'name', 'label' ) ),
					);
					continue;
				}
				$fields[] = $field;
			}
		}

		return $fields;
	}

	/**
	 * @param array<string,mixed> $field Form field.
	 */
	private static function is_honeypot_field( array $field ): bool {
		$name = strtolower( self::scalar( $field, array( 'name', 'label', 'id' ) ) );
		if ( in_array( $name, array( 'website', 'url', 'honeypot', 'fax' ), true ) ) {
			return true;
		}
		if ( ! empty( $field['ariaHidden'] ) || ! empty( $field['aria_hidden'] ) || 'true' === (string) ( $field['aria-hidden'] ?? '' ) ) {
			return true;
		}
		$tab = $field['tabindex'] ?? $field['tabIndex'] ?? null;
		if ( '-1' === (string) $tab || -1 === $tab ) {
			$width  = self::number( $field, array( 'width', 'displayWidth', 'display_width' ) );
			$height = self::number( $field, array( 'height', 'displayHeight', 'display_height' ) );
			if ( ( null !== $width && $width <= 1 ) || ( null !== $height && $height <= 1 ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed>            $item      Candidate node.
	 * @param array<int,array<string,mixed>> $omissions Deliberate removals.
	 */
	private static function is_omitted( array $item, array $omissions ): bool {
		$selector = self::scalar( $item, array( 'selector', 'id' ) );
		$url      = strtolower( self::scalar( $item, array( 'url', 'sourceUrl', 'src' ) ) );
		$alt      = strtolower( self::scalar( $item, array( 'alt' ) ) );
		foreach ( $omissions as $omission ) {
			$omit_selector = self::scalar( $omission, array( 'selector', 'id' ) );
			if ( '' !== $omit_selector && '' !== $selector && self::selector_matches( $selector, $omit_selector ) ) {
				return true;
			}
			if ( '' !== $omit_selector && ( str_contains( $url, strtolower( ltrim( $omit_selector, '#.' ) ) ) || str_contains( $alt, strtolower( ltrim( $omit_selector, '#.' ) ) ) ) ) {
				return true;
			}
		}

		return false;
	}

	private static function selector_matches( string $candidate, string $omission ): bool {
		$candidate = strtolower( trim( $candidate ) );
		$omission  = strtolower( trim( $omission ) );
		if ( $candidate === $omission ) {
			return true;
		}
		$omit_id = ltrim( $omission, '#' );
		if ( '' !== $omit_id && ( $candidate === '#' . $omit_id || str_starts_with( $candidate, '#' . $omit_id ) || str_contains( $candidate, '#' . $omit_id ) ) ) {
			return true;
		}

		return str_starts_with( $candidate, $omission );
	}

	/**
	 * Displayed box when both sides measured layout rather than intrinsic pixels.
	 *
	 * Capture `images[].width/height` are natural dimensions. Comparing those
	 * against imported display size is how a 36px logo and a 1024px PNG would
	 * false-fail. Only `displayWidth`/`displayHeight` (or `box`) participate.
	 *
	 * @param array<string,mixed> $image Image record.
	 * @return array{width:float,height:float}|null
	 */
	private static function display_box( array $image ): ?array {
		$box    = isset( $image['box'] ) && is_array( $image['box'] ) ? $image['box'] : $image;
		$width  = self::number( $box, array( 'displayWidth', 'display_width' ) );
		$height = self::number( $box, array( 'displayHeight', 'display_height' ) );
		if ( null === $width || null === $height ) {
			return null;
		}

		return array(
			'width'  => $width,
			'height' => $height,
		);
	}

	public static function materially_differs( float $source, float $imported, float $px, float $ratio ): bool {
		$delta     = abs( $source - $imported );
		$threshold = max( $px, $ratio * max( abs( $source ), abs( $imported ), 1 ) );

		return $delta > $threshold;
	}

	/**
	 * @param mixed $value Heading size list.
	 * @return array<int,float>
	 */
	private static function number_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$numbers = array();
		foreach ( $value as $item ) {
			if ( is_numeric( $item ) ) {
				$numbers[] = 0 + $item;
			}
		}

		return $numbers;
	}

	/**
	 * @param array<string,mixed> $row  Record.
	 * @param array<int,string>   $keys Candidate keys.
	 */
	private static function number( array $row, array $keys ): ?float {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) ) {
				return 0 + $row[ $key ];
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $row  Record.
	 * @param array<int,string>   $keys Candidate keys.
	 */
	private static function scalar( array $row, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ) {
				return trim( (string) $row[ $key ] );
			}
		}

		return '';
	}

	/**
	 * @param string               $page    Page id.
	 * @param int|null             $section Section index.
	 * @param string               $code    Disagreement code.
	 * @param string               $message Human message.
	 * @param array<string,mixed>  $context Evidence.
	 * @return array<string,mixed>
	 */
	private static function disagreement( string $page, ?int $section, string $code, string $message, array $context ): array {
		return array(
			'page'    => $page,
			'section' => $section,
			'code'    => $code,
			'message' => $message,
			'stage'   => self::STAGE,
			'context' => $context,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $disagreements Comparator output.
	 * @return array<int,array<string,mixed>>
	 */
	private static function diagnostics_from_disagreements( array $disagreements ): array {
		$diagnostics = array();
		foreach ( $disagreements as $disagreement ) {
			$diagnostics[] = array(
				'type'       => self::DIAGNOSTIC_TYPE,
				'kind'       => self::DIAGNOSTIC_TYPE,
				'code'       => (string) ( $disagreement['code'] ?? self::DIAGNOSTIC_TYPE ),
				'severity'   => 'warning',
				'stage'      => self::STAGE,
				'engine'     => 'static-site-importer',
				'owner'      => 'static-site-importer',
				'source'     => (string) ( $disagreement['page'] ?? '' ),
				'selector'   => isset( $disagreement['context']['selector'] ) ? (string) $disagreement['context']['selector'] : '',
				'message'    => (string) ( $disagreement['message'] ?? 'Imported section geometry disagrees with the capture.' ),
				'context'    => isset( $disagreement['context'] ) && is_array( $disagreement['context'] ) ? $disagreement['context'] : array(),
				'loss_class' => Static_Site_Importer_Diagnostic_Loss_Classes::IMPORTER_MATERIALIZATION_BUG,
			);
		}

		return $diagnostics;
	}
}

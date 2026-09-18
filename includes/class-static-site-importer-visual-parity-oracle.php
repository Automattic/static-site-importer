<?php
/**
 * Compares an imported WordPress render against a producer-neutral layout baseline.
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
 * Section-level visual parity oracle.
 *
 * SSI declares `source_reports.layout_baseline` and the schema it will consume.
 * Producers conform to that schema. Comparison is per section and per media box;
 * page-level aggregates are unused because they let errors cancel.
 */
final class Static_Site_Importer_Visual_Parity_Oracle {

	public const DIAGNOSTIC_TYPE      = 'visual_parity_mismatch';
	public const FAILURE_REASON       = 'visual_parity_mismatch';
	public const STAGE                = 'import_vs_baseline';
	public const VERIFICATION         = 'section_geometry';
	public const REPORT_SLOT          = 'layout_baseline';
	public const COMPILER_REPORT_PATH = 'source_reports.layout_baseline';
	public const SCHEMA               = 'static-site-importer/layout-baseline/v1';

	/**
	 * Sub-pixel and 1–2px deltas are normal across rendering contexts.
	 * A section that is materially taller or shorter, a heading whose size
	 * changed, or a missing or oversized image is not.
	 *
	 * @var array<string,float|int>
	 */
	public const DEFAULT_TOLERANCES = array(
		'geometry_px'    => 2,
		'geometry_ratio' => 0.01,
		'heading_px'     => 1,
		'heading_ratio'  => 0.05,
		'display_px'     => 2,
		'display_ratio'  => 0.05,
	);

	/**
	 * Validate the declared layout-baseline report slot.
	 *
	 * @param array<string,mixed> $compiled Compiler result or evaluation envelope.
	 * @return array<string,mixed>
	 */
	public static function contract_evidence( array $compiled ): array {
		$baseline = self::layout_baseline_from( $compiled );
		$missing  = array();
		if ( self::SCHEMA !== ( $baseline['schema'] ?? null ) ) {
			$missing[] = self::COMPILER_REPORT_PATH . ' schema ' . self::SCHEMA;
		}
		foreach ( array( 'viewports', 'pages', 'intentional_omissions' ) as $field ) {
			if ( ! isset( $baseline[ $field ] ) || ! is_array( $baseline[ $field ] ) ) {
				$missing[] = self::COMPILER_REPORT_PATH . '.' . $field;
			}
		}
		$pages = isset( $baseline['pages'] ) && is_array( $baseline['pages'] ) ? $baseline['pages'] : array();
		foreach ( $pages as $index => $page ) {
			if ( ! is_array( $page ) ) {
				$missing[] = self::COMPILER_REPORT_PATH . '.pages.' . $index;
				continue;
			}
			if ( ! isset( $page['id'] ) || ! is_scalar( $page['id'] ) || '' === trim( (string) $page['id'] ) ) {
				$missing[] = self::COMPILER_REPORT_PATH . '.pages.' . $index . '.id';
			}
			if ( ! isset( $page['viewport'] ) || ! is_array( $page['viewport'] ) ) {
				$missing[] = self::COMPILER_REPORT_PATH . '.pages.' . $index . '.viewport';
			}
			if ( ! isset( $page['sections'] ) || ! is_array( $page['sections'] ) ) {
				$missing[] = self::COMPILER_REPORT_PATH . '.pages.' . $index . '.sections';
			}
		}

		return array(
			'status'                => empty( $missing ) ? 'contract_present' : 'blocked_missing_compiler_contract',
			'compiler_report_path'  => self::COMPILER_REPORT_PATH,
			'expected_schema'       => self::SCHEMA,
			'missing_data_contract' => $missing,
		);
	}

	/**
	 * Evaluate a baseline against an imported render, or degrade to not_verified.
	 *
	 * @param array<string,mixed> $provided validation_artifacts and/or source_reports.
	 * @return array<string,mixed>
	 */
	public static function evaluate( array $provided ): array {
		$evidence = self::contract_evidence( $provided );
		if ( 'contract_present' !== $evidence['status'] ) {
			return self::not_verified_result(
				'Layout baseline contract is absent or malformed.',
				$evidence['missing_data_contract']
			);
		}

		$imported = self::imported_render_from( $provided );
		if ( null === $imported ) {
			return self::not_verified_result(
				'Imported layout record was not provided.',
				array()
			);
		}

		$baseline   = self::layout_baseline_from( $provided );
		$tolerances = self::tolerances( isset( $provided['tolerances'] ) && is_array( $provided['tolerances'] ) ? $provided['tolerances'] : array() );
		$omissions  = self::omissions( $baseline['intentional_omissions'] ?? array() );
		$source     = self::pages( $baseline['pages'] );
		$imported_pages = self::pages( $imported['pages'] );
		if ( array() === $source || array() === $imported_pages ) {
			return self::not_verified_result(
				'Imported layout record was not provided.',
				array()
			);
		}

		$disagreements = array();
		$omitted       = array();
		$matched       = array();
		foreach ( $source as $page_id => $source_page ) {
			if ( ! isset( $imported_pages[ $page_id ] ) ) {
				$disagreements[] = self::disagreement(
					$page_id,
					null,
					'missing_imported_page',
					'Imported render did not include this baseline page.',
					array(
						'source' => $page_id,
					)
				);
				continue;
			}
			$matched[ $page_id ] = true;
			$page_result         = self::compare_page( $page_id, $source_page, $imported_pages[ $page_id ], $omissions, $tolerances );
			$disagreements       = array_merge( $disagreements, $page_result['disagreements'] );
			$omitted             = array_merge( $omitted, $page_result['omitted'] );
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
			'status'                => $failed ? 'failed' : 'passed',
			'reason'                => $failed ? 'Imported section geometry disagrees with the layout baseline.' : 'Imported section geometry matches the layout baseline within tolerances.',
			'verification'          => self::VERIFICATION,
			'stage'                 => self::STAGE,
			'tolerances'            => $tolerances,
			'missing_data_contract' => array(),
			'compiler_report_path'  => self::COMPILER_REPORT_PATH,
			'expected_schema'       => self::SCHEMA,
			'disagreements'         => $disagreements,
			'omitted'               => $omitted,
			'omitted_count'         => count( $omitted ),
			'diagnostics'           => self::diagnostics_from_disagreements( $disagreements ),
			'artifact_refs'         => array(
				'visual_diff'    => array(
					'kind'          => 'visual_diff',
					'artifact_name' => 'visual-diff.json',
				),
				'browser_render' => array(
					'kind'          => 'browser_render_evidence',
					'artifact_name' => 'imported-layout-baseline.json',
				),
			),
			'summary'               => array(
				'section_disagreement_count' => count( $disagreements ),
				'omitted_count'              => count( $omitted ),
				'compared_page_count'        => count( $source ),
			),
			'visual_diff'           => $diff,
		);
	}

	/**
	 * @param array<string,mixed> $provided Envelope.
	 * @return array<string,mixed>
	 */
	public static function layout_baseline_from( array $provided ): array {
		foreach ( array( 'visual_parity', 'visual_parity_oracle' ) as $key ) {
			if ( isset( $provided[ $key ] ) && is_array( $provided[ $key ] ) ) {
				$nested = self::layout_baseline_from( $provided[ $key ] );
				if ( array() !== $nested ) {
					return $nested;
				}
			}
		}
		$reports = isset( $provided['source_reports'] ) && is_array( $provided['source_reports'] ) ? $provided['source_reports'] : array();
		if ( isset( $reports[ self::REPORT_SLOT ] ) && is_array( $reports[ self::REPORT_SLOT ] ) ) {
			return $reports[ self::REPORT_SLOT ];
		}
		if ( isset( $provided[ self::REPORT_SLOT ] ) && is_array( $provided[ self::REPORT_SLOT ] ) ) {
			return $provided[ self::REPORT_SLOT ];
		}

		return array();
	}

	/**
	 * @param array<string,mixed> $provided Envelope.
	 * @return array<string,mixed>|null
	 */
	public static function imported_render_from( array $provided ): ?array {
		foreach ( array( 'visual_parity', 'visual_parity_oracle' ) as $key ) {
			if ( isset( $provided[ $key ] ) && is_array( $provided[ $key ] ) ) {
				$nested = self::imported_render_from( $provided[ $key ] );
				if ( null !== $nested ) {
					return $nested;
				}
			}
		}
		foreach ( array( 'imported_render', 'imported_layout_baseline' ) as $key ) {
			if ( isset( $provided[ $key ] ) && is_array( $provided[ $key ] ) ) {
				$record = $provided[ $key ];
				if ( isset( $record['pages'] ) && is_array( $record['pages'] ) ) {
					return $record;
				}
				if ( isset( $record['sections'] ) && is_array( $record['sections'] ) ) {
					return array(
						'pages' => array( $record ),
					);
				}
			}
		}

		return null;
	}

	/**
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
	 * @param string             $reason  Skip reason.
	 * @param array<int,string>  $missing Missing contract entries.
	 * @return array<string,mixed>
	 */
	private static function not_verified_result( string $reason, array $missing ): array {
		return array(
			'status'                => 'not_verified',
			'reason'                => $reason,
			'verification'          => 'not_verified',
			'stage'                 => self::STAGE,
			'tolerances'            => self::DEFAULT_TOLERANCES,
			'missing_data_contract' => $missing,
			'compiler_report_path'  => self::COMPILER_REPORT_PATH,
			'expected_schema'       => self::SCHEMA,
			'disagreements'         => array(),
			'omitted'               => array(),
			'omitted_count'         => 0,
			'diagnostics'           => array(),
			'artifact_refs'         => array(),
			'summary'               => array(),
			'visual_diff'           => array(),
		);
	}

	/**
	 * @param mixed $value Page list.
	 * @return array<string,array<string,mixed>>
	 */
	private static function pages( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$pages = array();
		foreach ( $value as $key => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$page                 = self::page_record( $item, self::page_id( $item, is_string( $key ) ? $key : 'page' ) );
			$pages[ $page['id'] ] = $page;
		}

		return $pages;
	}

	/**
	 * @param array<string,mixed> $record Raw page.
	 * @param string              $fallback Fallback id.
	 */
	private static function page_id( array $record, string $fallback ): string {
		foreach ( array( 'id', 'page_id', 'page', 'slug', 'route' ) as $key ) {
			$value = self::scalar( $record, array( $key ) );
			if ( '' !== $value ) {
				return self::normalize_page_id( $value );
			}
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
	 * @param array<string,mixed> $record Raw page.
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
	 * @param mixed $value Intentional omission list.
	 * @return array<int,array<string,mixed>>
	 */
	private static function omissions( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$records = array();
		foreach ( $value as $item ) {
			if ( is_string( $item ) && '' !== trim( $item ) ) {
				$records[] = array( 'id' => trim( $item ) );
				continue;
			}
			if ( is_array( $item ) ) {
				$records[] = $item;
			}
		}

		return $records;
	}

	/**
	 * @param string                         $page_id    Page id.
	 * @param array<string,mixed>            $source     Baseline page.
	 * @param array<string,mixed>            $imported   Imported page.
	 * @param array<int,array<string,mixed>> $omissions  Declared omissions.
	 * @param array<string,float>            $tolerances Tolerances.
	 * @return array{disagreements:array<int,array<string,mixed>>,omitted:array<int,array<string,mixed>>}
	 */
	private static function compare_page( string $page_id, array $source, array $imported, array $omissions, array $tolerances ): array {
		$disagreements     = array();
		$omitted           = array();
		$source_sections   = self::ordered_sections( $source['sections'] );
		$imported_sections = self::ordered_sections( $imported['sections'] );
		$count             = max( count( $source_sections ), count( $imported_sections ) );

		for ( $index = 0; $index < $count; $index++ ) {
			$source_section   = $source_sections[ $index ] ?? null;
			$imported_section = $imported_sections[ $index ] ?? null;
			if ( null === $source_section ) {
				$disagreements[] = self::disagreement(
					$page_id,
					$index,
					'extra_section',
					'Imported render has a section the baseline does not.',
					array(
						'id' => self::scalar( $imported_section ?? array(), array( 'id' ) ),
					)
				);
				continue;
			}
			if ( null === $imported_section ) {
				$disagreements[] = self::disagreement(
					$page_id,
					$index,
					'missing_section',
					'Imported render is missing a baseline section.',
					array(
						'id' => self::scalar( $source_section, array( 'id' ) ),
					)
				);
				continue;
			}
			$section_result = self::compare_section( $page_id, $index, $source_section, $imported_section, $omissions, $tolerances );
			$disagreements  = array_merge( $disagreements, $section_result['disagreements'] );
			$omitted        = array_merge( $omitted, $section_result['omitted'] );
		}

		$landmark_result = self::compare_landmarks( $page_id, $source['landmarks'], $imported['landmarks'], $omissions, $tolerances );
		$disagreements   = array_merge( $disagreements, $landmark_result['disagreements'] );
		$omitted         = array_merge( $omitted, $landmark_result['omitted'] );

		return array(
			'disagreements' => $disagreements,
			'omitted'       => $omitted,
		);
	}

	/**
	 * @param array<int,mixed> $sections Raw sections.
	 * @return array<int,array<string,mixed>>
	 */
	private static function ordered_sections( array $sections ): array {
		$ordered = array();
		foreach ( array_values( $sections ) as $index => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$section['order'] = isset( $section['order'] ) && is_numeric( $section['order'] ) ? (int) $section['order'] : $index;
			$ordered[]        = $section;
		}
		usort(
			$ordered,
			static fn ( array $left, array $right ): int => ( $left['order'] ?? 0 ) <=> ( $right['order'] ?? 0 )
		);

		return array_values( $ordered );
	}

	/**
	 * @param string                         $page_id    Page id.
	 * @param int                            $index      Section order.
	 * @param array<string,mixed>            $source     Baseline section.
	 * @param array<string,mixed>            $imported   Imported section.
	 * @param array<int,array<string,mixed>> $omissions  Declared omissions.
	 * @param array<string,float>            $tolerances Tolerances.
	 * @return array{disagreements:array<int,array<string,mixed>>,omitted:array<int,array<string,mixed>>}
	 */
	private static function compare_section( string $page_id, int $index, array $source, array $imported, array $omissions, array $tolerances ): array {
		$disagreements = array();
		$omitted       = array();
		$id            = self::scalar( $source, array( 'id' ) );

		$source_top   = self::offset_top( $source );
		$imported_top = self::offset_top( $imported );
		if ( null !== $source_top && null !== $imported_top && self::materially_differs( $source_top, $imported_top, $tolerances['geometry_px'], $tolerances['geometry_ratio'] ) ) {
			$disagreements[] = self::disagreement(
				$page_id,
				$index,
				'section_offset',
				'Section offset disagrees with the layout baseline.',
				array(
					'id'       => $id,
					'source'   => $source_top,
					'imported' => $imported_top,
				)
			);
		}

		$source_height   = self::number( $source, array( 'height' ) );
		$imported_height = self::number( $imported, array( 'height' ) );
		if ( null !== $source_height && null !== $imported_height && self::materially_differs( $source_height, $imported_height, $tolerances['geometry_px'], $tolerances['geometry_ratio'] ) ) {
			$disagreements[] = self::disagreement(
				$page_id,
				$index,
				'section_height',
				'Section height disagrees with the layout baseline.',
				array(
					'id'       => $id,
					'source'   => $source_height,
					'imported' => $imported_height,
				)
			);
		}

		$source_headings   = self::typography_sizes( $source['headings'] ?? array() );
		$imported_headings = self::typography_sizes( $imported['headings'] ?? array() );
		$heading_count     = max( count( $source_headings ), count( $imported_headings ) );
		for ( $heading_index = 0; $heading_index < $heading_count; $heading_index++ ) {
			if ( ! isset( $source_headings[ $heading_index ] ) || ! isset( $imported_headings[ $heading_index ] ) ) {
				$disagreements[] = self::disagreement(
					$page_id,
					$index,
					'heading_count',
					'Heading count disagrees with the layout baseline.',
					array(
						'id'       => $id,
						'source'   => count( $source_headings ),
						'imported' => count( $imported_headings ),
					)
				);
				break;
			}
			if ( self::materially_differs( $source_headings[ $heading_index ], $imported_headings[ $heading_index ], $tolerances['heading_px'], $tolerances['heading_ratio'] ) ) {
				$disagreements[] = self::disagreement(
					$page_id,
					$index,
					'heading_size',
					'Heading size disagrees with the layout baseline.',
					array(
						'id'       => $id,
						'index'    => $heading_index,
						'source'   => $source_headings[ $heading_index ],
						'imported' => $imported_headings[ $heading_index ],
					)
				);
			}
		}

		$source_body   = self::typography_sizes( $source['body'] ?? array() );
		$imported_body = self::typography_sizes( $imported['body'] ?? array() );
		if ( array() !== $source_body && array() !== $imported_body ) {
			$count = min( count( $source_body ), count( $imported_body ) );
			for ( $body_index = 0; $body_index < $count; $body_index++ ) {
				if ( self::materially_differs( $source_body[ $body_index ], $imported_body[ $body_index ], $tolerances['heading_px'], $tolerances['heading_ratio'] ) ) {
					$disagreements[] = self::disagreement(
						$page_id,
						$index,
						'body_size',
						'Body typography disagrees with the layout baseline.',
						array(
							'id'       => $id,
							'index'    => $body_index,
							'source'   => $source_body[ $body_index ],
							'imported' => $imported_body[ $body_index ],
						)
					);
				}
			}
		}

		$media_result  = self::compare_media( $page_id, $index, $source['media'] ?? array(), $imported['media'] ?? array(), $omissions, $tolerances, $id );
		$disagreements = array_merge( $disagreements, $media_result['disagreements'] );
		$omitted       = array_merge( $omitted, $media_result['omitted'] );

		$form_result   = self::compare_forms( $page_id, $index, $source['forms'] ?? array(), $imported['forms'] ?? array(), $omissions, $tolerances, $id );
		$disagreements = array_merge( $disagreements, $form_result['disagreements'] );
		$omitted       = array_merge( $omitted, $form_result['omitted'] );

		return array(
			'disagreements' => $disagreements,
			'omitted'       => $omitted,
		);
	}

	/**
	 * @param string                         $page_id    Page id.
	 * @param array<int,mixed>               $source     Baseline landmarks.
	 * @param array<int,mixed>               $imported   Imported landmarks.
	 * @param array<int,array<string,mixed>> $omissions  Declared omissions.
	 * @param array<string,float>            $tolerances Tolerances.
	 * @return array{disagreements:array<int,array<string,mixed>>,omitted:array<int,array<string,mixed>>}
	 */
	private static function compare_landmarks( string $page_id, array $source, array $imported, array $omissions, array $tolerances ): array {
		$disagreements = array();
		$omitted       = array();
		$imported_map  = array();
		foreach ( $imported as $landmark ) {
			if ( ! is_array( $landmark ) ) {
				continue;
			}
			$key = self::landmark_key( $landmark );
			if ( '' !== $key ) {
				$imported_map[ $key ] = $landmark;
			}
		}

		foreach ( $source as $landmark ) {
			if ( ! is_array( $landmark ) ) {
				continue;
			}
			if ( self::is_omitted( $landmark, $omissions ) ) {
				$omitted[] = array(
					'page' => $page_id,
					'kind' => 'landmark',
					'id'   => self::scalar( $landmark, array( 'id', 'role' ) ),
				);
				continue;
			}
			$key = self::landmark_key( $landmark );
			if ( '' === $key || ! isset( $imported_map[ $key ] ) ) {
				$disagreements[] = self::disagreement(
					$page_id,
					null,
					'missing_landmark',
					'Imported render is missing a baseline landmark.',
					array(
						'id'   => self::scalar( $landmark, array( 'id' ) ),
						'role' => self::scalar( $landmark, array( 'role' ) ),
					)
				);
				continue;
			}
			$imported_landmark = $imported_map[ $key ];
			$source_height     = self::number( $landmark, array( 'height' ) );
			$imported_height   = self::number( $imported_landmark, array( 'height' ) );
			if ( null !== $source_height && null !== $imported_height && self::materially_differs( $source_height, $imported_height, $tolerances['geometry_px'], $tolerances['geometry_ratio'] ) ) {
				$disagreements[] = self::disagreement(
					$page_id,
					null,
					'landmark_height',
					'Landmark height disagrees with the layout baseline.',
					array(
						'id'       => self::scalar( $landmark, array( 'id' ) ),
						'role'     => self::scalar( $landmark, array( 'role' ) ),
						'source'   => $source_height,
						'imported' => $imported_height,
					)
				);
			}
			$media_result  = self::compare_media( $page_id, null, $landmark['media'] ?? array(), $imported_landmark['media'] ?? array(), $omissions, $tolerances, self::scalar( $landmark, array( 'id', 'role' ) ) );
			$disagreements = array_merge( $disagreements, $media_result['disagreements'] );
			$omitted       = array_merge( $omitted, $media_result['omitted'] );
		}

		return array(
			'disagreements' => $disagreements,
			'omitted'       => $omitted,
		);
	}

	/**
	 * @param array<string,mixed> $landmark Landmark record.
	 */
	private static function landmark_key( array $landmark ): string {
		$id = self::scalar( $landmark, array( 'id' ) );
		if ( '' !== $id ) {
			return 'id:' . $id;
		}
		$role = self::scalar( $landmark, array( 'role' ) );

		return '' !== $role ? 'role:' . $role : '';
	}

	/**
	 * @param string                         $page_id    Page id.
	 * @param int|null                       $index      Section order.
	 * @param mixed                          $source     Baseline media.
	 * @param mixed                          $imported   Imported media.
	 * @param array<int,array<string,mixed>> $omissions  Declared omissions.
	 * @param array<string,float>            $tolerances Tolerances.
	 * @param string                         $owner_id   Section or landmark id.
	 * @return array{disagreements:array<int,array<string,mixed>>,omitted:array<int,array<string,mixed>>}
	 */
	private static function compare_media( string $page_id, ?int $index, mixed $source, mixed $imported, array $omissions, array $tolerances, string $owner_id ): array {
		$disagreements   = array();
		$omitted         = array();
		$source_media   = self::filter_items( is_array( $source ) ? $source : array(), $omissions, $omitted, $page_id, $index, 'media' );
		$imported_media = self::filter_items( is_array( $imported ) ? $imported : array(), $omissions, $omitted, $page_id, $index, 'media' );
		if ( count( $source_media ) !== count( $imported_media ) ) {
			$disagreements[] = self::disagreement(
				$page_id,
				$index,
				'image_count',
				'Media count disagrees with the layout baseline.',
				array(
					'id'       => $owner_id,
					'source'   => count( $source_media ),
					'imported' => count( $imported_media ),
				)
			);

			return array(
				'disagreements' => $disagreements,
				'omitted'       => $omitted,
			);
		}

		foreach ( self::pair_items( $source_media, $imported_media ) as $pair ) {
			$source_box   = self::display_box( $pair[0] );
			$imported_box = self::display_box( $pair[1] );
			if ( null === $source_box || null === $imported_box ) {
				continue;
			}
			foreach ( array( 'width', 'height' ) as $edge ) {
				if ( self::materially_differs( $source_box[ $edge ], $imported_box[ $edge ], $tolerances['display_px'], $tolerances['display_ratio'] ) ) {
					$disagreements[] = self::disagreement(
						$page_id,
						$index,
						'image_display_' . $edge,
						'Media display size disagrees with the layout baseline.',
						array(
							'id'       => self::scalar( $pair[0], array( 'id' ) ),
							'role'     => self::scalar( $pair[0], array( 'role' ) ),
							'source'   => $source_box[ $edge ],
							'imported' => $imported_box[ $edge ],
						)
					);
				}
			}
		}

		return array(
			'disagreements' => $disagreements,
			'omitted'       => $omitted,
		);
	}

	/**
	 * @param string                         $page_id    Page id.
	 * @param int                            $index      Section order.
	 * @param mixed                          $source     Baseline forms.
	 * @param mixed                          $imported   Imported forms.
	 * @param array<int,array<string,mixed>> $omissions  Declared omissions.
	 * @param array<string,float>            $tolerances Tolerances.
	 * @param string                         $owner_id   Section id.
	 * @return array{disagreements:array<int,array<string,mixed>>,omitted:array<int,array<string,mixed>>}
	 */
	private static function compare_forms( string $page_id, int $index, mixed $source, mixed $imported, array $omissions, array $tolerances, string $owner_id ): array {
		$disagreements = array();
		$omitted       = array();
		$source_forms  = is_array( $source ) ? array_values( $source ) : array();
		$imported_forms = is_array( $imported ) ? array_values( $imported ) : array();
		if ( array() === $source_forms ) {
			return array(
				'disagreements' => $disagreements,
				'omitted'       => $omitted,
			);
		}
		$count = max( count( $source_forms ), count( $imported_forms ) );
		for ( $form_index = 0; $form_index < $count; $form_index++ ) {
			$source_form   = isset( $source_forms[ $form_index ] ) && is_array( $source_forms[ $form_index ] ) ? $source_forms[ $form_index ] : array();
			$imported_form = isset( $imported_forms[ $form_index ] ) && is_array( $imported_forms[ $form_index ] ) ? $imported_forms[ $form_index ] : array();
			self::compare_padding( $page_id, $index, $source_form['padding'] ?? array(), $imported_form['padding'] ?? array(), $tolerances, $owner_id, $disagreements );
			$source_fields   = self::filter_items( isset( $source_form['fields'] ) && is_array( $source_form['fields'] ) ? $source_form['fields'] : array(), $omissions, $omitted, $page_id, $index, 'form_field' );
			$imported_fields = self::filter_items( isset( $imported_form['fields'] ) && is_array( $imported_form['fields'] ) ? $imported_form['fields'] : array(), $omissions, $omitted, $page_id, $index, 'form_field' );
			if ( count( $source_fields ) !== count( $imported_fields ) ) {
				$disagreements[] = self::disagreement(
					$page_id,
					$index,
					'form_field_count',
					'Visible form field count disagrees with the layout baseline.',
					array(
						'id'       => $owner_id,
						'source'   => count( $source_fields ),
						'imported' => count( $imported_fields ),
					)
				);
				continue;
			}
			foreach ( self::pair_items( $source_fields, $imported_fields ) as $pair ) {
				$source_field   = $pair[0];
				$imported_field = $pair[1];
				self::compare_padding( $page_id, $index, $source_field['padding'] ?? array(), $imported_field['padding'] ?? array(), $tolerances, self::scalar( $source_field, array( 'id', 'name' ) ), $disagreements, 'field_padding_' );
				$source_box   = self::display_box( $source_field );
				$imported_box = self::display_box( $imported_field );
				if ( null === $source_box || null === $imported_box ) {
					continue;
				}
				foreach ( array( 'width', 'height' ) as $edge ) {
					if ( self::materially_differs( $source_box[ $edge ], $imported_box[ $edge ], $tolerances['display_px'], $tolerances['display_ratio'] ) ) {
						$disagreements[] = self::disagreement(
							$page_id,
							$index,
							'field_display_' . $edge,
							'Form field display size disagrees with the layout baseline.',
							array(
								'id'       => self::scalar( $source_field, array( 'id', 'name' ) ),
								'source'   => $source_box[ $edge ],
								'imported' => $imported_box[ $edge ],
							)
						);
					}
				}
			}
		}

		return array(
			'disagreements' => $disagreements,
			'omitted'       => $omitted,
		);
	}

	/**
	 * @param string                        $page_id        Page id.
	 * @param int                           $index          Section order.
	 * @param mixed                         $source_padding Baseline padding.
	 * @param mixed                         $imported_padding Imported padding.
	 * @param array<string,float>           $tolerances     Tolerances.
	 * @param string                        $owner_id       Owner id.
	 * @param array<int,array<string,mixed>> $disagreements Disagreements.
	 * @param string                        $code_prefix    Disagreement code prefix.
	 */
	private static function compare_padding( string $page_id, int $index, mixed $source_padding, mixed $imported_padding, array $tolerances, string $owner_id, array &$disagreements, string $code_prefix = 'form_padding_' ): void {
		if ( ! is_array( $source_padding ) || ! is_array( $imported_padding ) ) {
			return;
		}
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $edge ) {
			$source_value   = self::number( $source_padding, array( $edge ) );
			$imported_value = self::number( $imported_padding, array( $edge ) );
			if ( null === $source_value || null === $imported_value ) {
				continue;
			}
			if ( self::materially_differs( $source_value, $imported_value, $tolerances['geometry_px'], $tolerances['geometry_ratio'] ) ) {
				$disagreements[] = self::disagreement(
					$page_id,
					$index,
					$code_prefix . $edge,
					'Padding disagrees with the layout baseline.',
					array(
						'id'       => $owner_id,
						'edge'     => $edge,
						'source'   => $source_value,
						'imported' => $imported_value,
					)
				);
			}
		}
	}

	/**
	 * Pair baseline items to imported items by id, then by remaining order.
	 *
	 * @param array<int,array<string,mixed>> $source   Baseline items.
	 * @param array<int,array<string,mixed>> $imported Imported items.
	 * @return array<int,array{0:array<string,mixed>,1:array<string,mixed>}>
	 */
	private static function pair_items( array $source, array $imported ): array {
		$pairs        = array();
		$source_left  = array_values( $source );
		$imported_left = array_values( $imported );
		foreach ( $source_left as $source_index => $source_item ) {
			$source_id = self::scalar( $source_item, array( 'id', 'name' ) );
			if ( '' === $source_id ) {
				continue;
			}
			foreach ( $imported_left as $imported_index => $imported_item ) {
				if ( self::scalar( $imported_item, array( 'id', 'name' ) ) === $source_id ) {
					$pairs[] = array( $source_item, $imported_item );
					unset( $source_left[ $source_index ], $imported_left[ $imported_index ] );
					break;
				}
			}
		}
		$source_left   = array_values( $source_left );
		$imported_left = array_values( $imported_left );
		$count         = min( count( $source_left ), count( $imported_left ) );
		for ( $index = 0; $index < $count; $index++ ) {
			$pairs[] = array( $source_left[ $index ], $imported_left[ $index ] );
		}

		return $pairs;
	}

	/**
	 * @param array<int,mixed>               $items     Candidate items.
	 * @param array<int,array<string,mixed>> $omissions Declared omissions.
	 * @param array<int,array<string,mixed>> $omitted   Collected omissions.
	 * @param string                         $page_id   Page id.
	 * @param int|null                       $index     Section order.
	 * @param string                         $kind      Item kind.
	 * @return array<int,array<string,mixed>>
	 */
	private static function filter_items( array $items, array $omissions, array &$omitted, string $page_id, ?int $index, string $kind ): array {
		$kept = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( self::is_omitted( $item, $omissions ) ) {
				$omitted[] = array(
					'page'    => $page_id,
					'section' => $index,
					'kind'    => $kind,
					'id'      => self::scalar( $item, array( 'id', 'name' ) ),
				);
				continue;
			}
			$kept[] = $item;
		}

		return array_values( $kept );
	}

	/**
	 * @param array<string,mixed>            $item      Candidate.
	 * @param array<int,array<string,mixed>> $omissions Declared omissions.
	 */
	private static function is_omitted( array $item, array $omissions ): bool {
		$item_id       = self::scalar( $item, array( 'id', 'name' ) );
		$item_selector = self::scalar( $item, array( 'selector' ) );
		foreach ( $omissions as $omission ) {
			$omit_id       = self::scalar( $omission, array( 'id', 'name', 'selector' ) );
			$omit_selector = self::scalar( $omission, array( 'selector', 'id' ) );
			if ( '' !== $omit_id && $item_id === $omit_id ) {
				return true;
			}
			if ( '' !== $omit_selector && '' !== $item_selector && $item_selector === $omit_selector ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $image Media or field record.
	 * @return array{width:float,height:float}|null
	 */
	private static function display_box( array $image ): ?array {
		$box    = isset( $image['box'] ) && is_array( $image['box'] ) ? $image['box'] : $image;
		$width  = self::number( $box, array( 'display_width', 'displayWidth' ) );
		$height = self::number( $box, array( 'display_height', 'displayHeight' ) );
		if ( null === $width || null === $height ) {
			return null;
		}

		return array(
			'width'  => $width,
			'height' => $height,
		);
	}

	/**
	 * @param array<string,mixed> $record Section or landmark.
	 */
	private static function offset_top( array $record ): ?float {
		if ( isset( $record['offset'] ) && is_array( $record['offset'] ) ) {
			return self::number( $record['offset'], array( 'top' ) );
		}

		return self::number( $record, array( 'top' ) );
	}

	/**
	 * @param mixed $value Heading or body list.
	 * @return array<int,float>
	 */
	private static function typography_sizes( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$numbers = array();
		foreach ( $value as $item ) {
			if ( is_numeric( $item ) ) {
				$numbers[] = 0 + $item;
				continue;
			}
			if ( is_array( $item ) ) {
				$size = self::number( $item, array( 'font_size', 'size' ) );
				if ( null !== $size ) {
					$numbers[] = $size;
				}
			}
		}

		return $numbers;
	}

	public static function materially_differs( float $source, float $imported, float $px, float $ratio ): bool {
		$delta     = abs( $source - $imported );
		$threshold = max( $px, $ratio * max( abs( $source ), abs( $imported ), 1 ) );

		return $delta > $threshold;
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
	 * @param string              $page    Page id.
	 * @param int|null            $section Section order.
	 * @param string              $code    Disagreement code.
	 * @param string              $message Human message.
	 * @param array<string,mixed> $context Evidence.
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
				'selector'   => isset( $disagreement['context']['id'] ) ? (string) $disagreement['context']['id'] : '',
				'message'    => (string) ( $disagreement['message'] ?? 'Imported section geometry disagrees with the layout baseline.' ),
				'context'    => isset( $disagreement['context'] ) && is_array( $disagreement['context'] ) ? $disagreement['context'] : array(),
				'loss_class' => Static_Site_Importer_Diagnostic_Loss_Classes::IMPORTER_MATERIALIZATION_BUG,
			);
		}

		return $diagnostics;
	}
}

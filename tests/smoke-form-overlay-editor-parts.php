<?php
/**
 * Every part the front-end form overlay builder emits has an editor realisation.
 *
 * `Static_Site_Importer_Form_Presentation_Descriptor::editor_layout_target_map()`
 * derives the block-editor target map from each target's and destination's
 * declared `part`, realised either by the entry's own `editor` declaration
 * (the phone composite and the dropdown) or by `editor_surface_table()`. This
 * smoke enumerates the builder's branches (every control type crossed with
 * every presentation role set and label shape, and every layout-target kind)
 * and asserts that:
 *
 * - every emitted target and destination declares a part whose realisation is
 *   declared exactly once: by the entry or by the editor table, not both;
 * - every emission of a self-declared part declares the same realisation;
 * - every row of the editor table is emitted by some branch (no dead rows);
 * - an undeclared or missing part fails the editor derivation loudly;
 * - neither the canonical front-end map nor the editor map carries a part or
 *   a declared realisation, so both pass
 *   `Provider_Layout_Overlay::validate_map()`, which rejects a map that still
 *   carries a part.
 *
 * Run from the repository root:
 *   php tests/smoke-form-overlay-editor-parts.php
 *
 * @package StaticSiteImporter
 */

require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once __DIR__ . '/fixtures/form-overlay-declared-parts.php';

/**
 * Write text to a standard stream.
 *
 * @param string $stream `php://stdout` or `php://stderr`.
 * @param string $text   Text to write.
 */
function ssi_editor_parts_write( string $stream, string $text ): void {
	$handle = new SplFileObject( $stream, 'w' );
	$handle->fwrite( $text );
}

/**
 * Whether the editor derivation throws for a map.
 *
 * @param array<string,mixed> $target_map Map with a broken part.
 * @param string              $scope      Provider form scope class.
 * @param string              $reason     Expected exception message fragment.
 */
function ssi_editor_parts_derivation_throws( array $target_map, string $scope, string $reason = 'has no editor surface realisation' ): bool {
	try {
		Static_Site_Importer_Form_Presentation_Descriptor::editor_layout_target_map( $target_map, $scope );
	} catch ( LogicException $exception ) {
		return str_contains( $exception->getMessage(), $reason );
	}
	return false;
}

$ssi_parts_scope    = 'ssi-form-123456789abc';
$ssi_parts_table    = Static_Site_Importer_Form_Presentation_Descriptor::editor_surface_table();
$ssi_parts_failures = array();
$ssi_parts_checks   = 0;
$ssi_parts_emitted  = array();
$ssi_parts_declared = array();
$ssi_parts_graph    = ssi_editor_parts_graph();
$ssi_parts_maps     = ssi_editor_parts_target_maps( $ssi_parts_scope, $ssi_parts_graph );
$ssi_parts_entries  = ssi_editor_parts_descriptor_destinations( $ssi_parts_scope );
foreach ( $ssi_parts_maps as $ssi_parts_map ) {
	foreach ( $ssi_parts_map['targets'] as $ssi_parts_target ) {
		$ssi_parts_entries[] = $ssi_parts_target;
	}
	foreach ( $ssi_parts_map['presentation_targets'] as $ssi_parts_target ) {
		foreach ( $ssi_parts_target['destinations'] as $ssi_parts_destination ) {
			$ssi_parts_entries[] = $ssi_parts_destination;
		}
	}
}

// Every emitted part's editor realisation is declared exactly once: by the
// entry itself or by the editor surface table.
foreach ( $ssi_parts_entries as $ssi_parts_entry ) {
	++$ssi_parts_checks;
	$ssi_parts_part = $ssi_parts_entry['part'] ?? null;
	if ( ! is_string( $ssi_parts_part ) ) {
		$ssi_parts_failures[] = 'an emitted target or destination declares no part: ' . wp_json_encode( $ssi_parts_entry );
		continue;
	}
	$ssi_parts_emitted[ $ssi_parts_part ] = true;
	if ( ! array_key_exists( 'editor', $ssi_parts_entry ) ) {
		if ( ! isset( $ssi_parts_table[ $ssi_parts_part ] ) ) {
			$ssi_parts_failures[] = 'no editor realisation is declared for emitted part "' . $ssi_parts_part . '"';
		}
		continue;
	}
	if ( isset( $ssi_parts_table[ $ssi_parts_part ] ) ) {
		$ssi_parts_failures[] = 'emitted part "' . $ssi_parts_part . '" declares its editor realisation and also has an editor table row';
	}
	if ( ! is_array( $ssi_parts_entry['editor'] ) ) {
		$ssi_parts_failures[] = 'emitted part "' . $ssi_parts_part . '" declares a malformed editor realisation';
		continue;
	}
	if ( isset( $ssi_parts_declared[ $ssi_parts_part ] ) && $ssi_parts_declared[ $ssi_parts_part ] !== $ssi_parts_entry['editor'] ) {
		$ssi_parts_failures[] = 'emitted part "' . $ssi_parts_part . '" declares different editor realisations';
	}
	$ssi_parts_declared[ $ssi_parts_part ] = $ssi_parts_entry['editor'];
}

// The phone composite and the dropdown declare their own realisations; the
// editor table no longer lists them.
foreach ( array( 'phone_shell', 'phone_primary', 'phone_carrier', 'phone_prefix', 'phone_sibling_stacked', 'select_wrapper', 'select_control', 'select_sibling_stacked' ) as $ssi_parts_own ) {
	++$ssi_parts_checks;
	if ( ! isset( $ssi_parts_declared[ $ssi_parts_own ] ) || isset( $ssi_parts_table[ $ssi_parts_own ] ) ) {
		$ssi_parts_failures[] = 'part "' . $ssi_parts_own . '" does not declare its own editor realisation';
	}
}

// Every editor surface table row is emitted by some builder branch.
++$ssi_parts_checks;
$ssi_parts_dead = array_diff( array_keys( $ssi_parts_table ), array_keys( $ssi_parts_emitted ) );
if ( array() !== $ssi_parts_dead ) {
	$ssi_parts_failures[] = 'editor surface table rows never emitted by the builder: ' . implode( ', ', $ssi_parts_dead );
}

// The canonical and editor maps carry no part ids and validate; a map that
// still carries part ids is rejected.
foreach ( $ssi_parts_maps as $ssi_parts_map ) {
	$ssi_parts_candidates = array(
		'canonical' => Static_Site_Importer_Form_Presentation_Descriptor::without_parts( $ssi_parts_map ),
		'editor'    => Static_Site_Importer_Form_Presentation_Descriptor::editor_layout_target_map( $ssi_parts_map, $ssi_parts_scope, array( 1 => Static_Site_Importer_Form_Textarea_Rows::resolve( array( 'tag' => 'textarea' ), array( 'height' => '10rem' ), false ) ) ),
	);
	foreach ( $ssi_parts_candidates as $ssi_parts_kind => $ssi_parts_candidate ) {
		$ssi_parts_checks += 2;
		if ( str_contains( (string) wp_json_encode( $ssi_parts_candidate ), '"part"' ) || str_contains( (string) wp_json_encode( $ssi_parts_candidate ), '"editor":' ) ) {
			$ssi_parts_failures[] = 'the ' . $ssi_parts_kind . ' map carries part ids or declared realisations';
		}
		if ( ! isset( Static_Site_Importer_Provider_Layout_Overlay::validate_map( $ssi_parts_candidate, $ssi_parts_graph )['map'] ) ) {
			$ssi_parts_failures[] = 'the ' . $ssi_parts_kind . ' map does not validate';
		}
	}
	++$ssi_parts_checks;
	if ( ! isset( Static_Site_Importer_Provider_Layout_Overlay::validate_map( $ssi_parts_map, $ssi_parts_graph )['error'] ) ) {
		$ssi_parts_failures[] = 'validate_map() admits a map that still carries part ids';
	}
}

// An undeclared or missing part fails the editor derivation loudly.
$ssi_parts_bad_parts = array(
	'undeclared' => 'not_a_declared_part',
	'missing'    => null,
);
foreach ( $ssi_parts_bad_parts as $ssi_parts_case => $ssi_parts_bad_part ) {
	$ssi_parts_bad_destination = $ssi_parts_maps[0];
	$ssi_parts_bad_target      = $ssi_parts_maps[0];
	if ( null === $ssi_parts_bad_part ) {
		unset( $ssi_parts_bad_destination['presentation_targets'][0]['destinations'][0]['part'], $ssi_parts_bad_target['targets'][0]['part'] );
	} else {
		$ssi_parts_bad_destination['presentation_targets'][0]['destinations'][0]['part'] = $ssi_parts_bad_part;
		$ssi_parts_bad_target['targets'][0]['part']                                      = $ssi_parts_bad_part;
	}
	$ssi_parts_checks += 2;
	if ( ! ssi_editor_parts_derivation_throws( $ssi_parts_bad_destination, $ssi_parts_scope ) ) {
		$ssi_parts_failures[] = 'an ' . $ssi_parts_case . ' destination part does not fail the editor derivation';
	}
	if ( ! ssi_editor_parts_derivation_throws( $ssi_parts_bad_target, $ssi_parts_scope ) ) {
		$ssi_parts_failures[] = 'an ' . $ssi_parts_case . ' target part does not fail the editor derivation';
	}
}

// A part declared both by its entry and by the editor table is ambiguous, and
// a self-declared realisation still needs its part.
$ssi_parts_twice = $ssi_parts_maps[0];
$ssi_parts_twice['presentation_targets'][0]['destinations'][0]['editor'] = array();
$ssi_parts_partless = $ssi_parts_maps[0];
foreach ( $ssi_parts_partless['presentation_targets'] as $ssi_parts_index => $ssi_parts_target ) {
	foreach ( $ssi_parts_target['destinations'] as $ssi_parts_key => $ssi_parts_destination ) {
		if ( array_key_exists( 'editor', $ssi_parts_destination ) ) {
			unset( $ssi_parts_partless['presentation_targets'][ $ssi_parts_index ]['destinations'][ $ssi_parts_key ]['part'] );
			break 2;
		}
	}
}
$ssi_parts_checks += 2;
if ( ! ssi_editor_parts_derivation_throws( $ssi_parts_twice, $ssi_parts_scope, 'declares its editor surface realisation twice' ) ) {
	$ssi_parts_failures[] = 'a part declared by its entry and by the editor table does not fail the editor derivation';
}
if ( ! ssi_editor_parts_derivation_throws( $ssi_parts_partless, $ssi_parts_scope ) ) {
	$ssi_parts_failures[] = 'a self-declared realisation without a part does not fail the editor derivation';
}

if ( array() !== $ssi_parts_failures ) {
	ssi_editor_parts_write( 'php://stderr', 'FAIL form overlay editor parts: ' . implode( "\n", array_unique( $ssi_parts_failures ) ) . "\n" );
	exit( 1 );
}
ssi_editor_parts_write( 'php://stdout', 'PASS form overlay editor parts: ' . count( $ssi_parts_emitted ) . ' declared parts, ' . $ssi_parts_checks . " checks\n" );

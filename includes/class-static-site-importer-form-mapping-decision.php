<?php
/**
 * Provider support policy over a prepared form's fields, destinations and losses.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns the receipt acceptance policy used by planning and bounded loss accounting. */
final class Static_Site_Importer_Form_Mapping_Decision {
	/** @return array<string,mixed> The one disposition consumed by emission and reports. */
	public static function resolve( Static_Site_Importer_Form_Mapping_Plan $plan ): array {
		$receipt = $plan->report_facts['computed_layout_receipt'];
		$losses  = array();
		foreach ( $receipt['losses'] ?? array() as $loss ) {
			if ( ! is_array( $loss ) || ! self::requires_gate( $loss ) || self::provider_represents( $loss, $plan ) ) {
				continue;
			}
			// Proposed serialization is needed only by an installed waiver hook.
			if ( has_filter( 'static_site_importer_form_receipt_loss_accepted' )
				&& true === apply_filters( 'static_site_importer_form_receipt_loss_accepted', false, $loss, $plan->source_form, $plan->proposed_row() ) ) {
				continue;
			}
			$losses[] = $loss;
		}
		$overflow = (int) ( $receipt['gate_required_loss_overflow_count'] ?? 0 );
		if ( $overflow > 0 ) {
			$losses[] = array(
				'dimension'   => 'topology',
				'reason_code' => 'form_receipt_gate_loss_overflow',
				'loss_count'  => $overflow,
				'loss_hash'   => (string) ( $receipt['gate_required_loss_overflow_hash'] ?? '' ),
			);
		}
		return array(
			'status'                   => empty( $losses ) ? 'mapped' : 'declined',
			'supported_fields'         => array_values( $plan->report_facts['field_blocks'] ),
			'unsupported_capabilities' => array_values( array_unique( array_filter( $plan->report_facts['skipped_types'] ) ) ),
			'losses'                   => $losses,
		);
	}

	/**
	 * Append bounded receipt entries without discarding pre-existing overflow totals.
	 * The one receipt bounder: it keeps 32 entries and, when a gate-required
	 * loss overflows with none listed, lists it in the last slot so a decline
	 * names its own reason.
	 *
	 * @internal
	 */
	public static function append_receipt_entries( array &$receipt, string $key, array $entries ): void {
		$total_key             = 'operations' === $key ? 'operations_total' : 'losses_total';
		$count_key             = 'operations' === $key ? 'operation_count' : 'loss_count';
		$receipt[ $key ]       = isset( $receipt[ $key ] ) && is_array( $receipt[ $key ] ) ? $receipt[ $key ] : array();
		$receipt[ $total_key ] = isset( $receipt[ $total_key ] ) ? (int) $receipt[ $total_key ] : count( $receipt[ $key ] );
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			++$receipt[ $total_key ];
			if ( count( $receipt[ $key ] ) < 32 ) {
				$receipt[ $key ][] = $entry;
			} elseif ( 'losses' === $key && self::requires_gate( $entry ) ) {
				$receipt['gate_required_loss_overflow_count'] = (int) ( $receipt['gate_required_loss_overflow_count'] ?? 0 ) + 1;
				$receipt['gate_required_loss_overflow_hash']  = hash( 'sha256', (string) ( $receipt['gate_required_loss_overflow_hash'] ?? '' ) . wp_json_encode( $entry ) );
				if ( ! array_filter( $receipt[ $key ], array( self::class, 'requires_gate' ) ) ) {
					$receipt[ $key ][31] = $entry;
				}
			}
		}
		$receipt[ $count_key ] = min( 32, $receipt[ $total_key ] );
		$receipt['truncated']  = ! empty( $receipt['truncated'] ) || (int) ( $receipt['operations_total'] ?? 0 ) > 32 || (int) ( $receipt['losses_total'] ?? 0 ) > 32;
	}

	/** Keep receipt overflow accounting and the support decision on the same policy. */
	public static function requires_gate( array $loss ): bool {
		return 'unsupported_control_unrepresentable' === ( $loss['reason_code'] ?? '' )
			|| 'unsupported_control_attribute' === ( $loss['reason_code'] ?? '' )
			|| 'textarea_height_omitted' === ( $loss['reason_code'] ?? '' )
			|| in_array( $loss['dimension'] ?? '', array( 'semantic', 'topology' ), true )
			|| in_array( $loss['reason_code'] ?? '', array( 'provider_structure_mismatch', 'direct_child_relationship_unrepresentable' ), true );
	}

	/** Check actual planned provider destinations instead of emitted markup. */
	private static function provider_represents( array $loss, Static_Site_Importer_Form_Mapping_Plan $plan ): bool {
		$form         = $plan->source_form;
		$field_blocks = $plan->fields;
		if ( 'unsupported_control_unrepresentable' === ( $loss['reason_code'] ?? '' ) && is_int( $loss['control_index'] ?? null ) ) {
			$control = $form['controls'][ $loss['control_index'] ] ?? null;
			if ( is_array( $control ) && 'file' === strtolower( trim( (string) ( $control['type'] ?? '' ) ) ) && 'core/paragraph' === ( $field_blocks[ $loss['control_index'] ]['name'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}
}

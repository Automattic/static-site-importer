<?php
/**
 * Substitute provider fields into the producer's indexed, parsed block tree.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Native wrappers own topology; the provider owns only field semantics. */
class Static_Site_Importer_Form_Readable_Substitution {
	/** Older or partial producer trees must retain the projection path. */
	public static function supports( array $form ): bool {
		$tree = $form['readable_blocks'] ?? array();
		if ( ! is_array( $tree ) || 1 !== count( $tree ) || 'core/group' !== ( $tree[0]['blockName'] ?? '' ) || 'form' !== ( $tree[0]['attrs']['tagName'] ?? '' ) ) {
			return false;
		}
		$indices = array();
		$groups  = array();
		$valid   = true;
		$walk    = static function ( array $block ) use ( &$walk, &$indices, &$groups, &$valid, $form ): void {
			foreach ( array(
				'form_control_index'      => 'controls',
				'form_choice_group_index' => 'choice_groups',
			) as $key => $collection ) {
				if ( array_key_exists( $key, $block ) ) {
					$index = $block[ $key ];
					if ( ! is_int( $index ) || ! isset( $form[ $collection ][ $index ] ) ) {
						$valid = false;
						continue;
					}
					if ( 'controls' === $collection ) {
						$valid             = $valid && ! isset( $indices[ $index ] );
						$indices[ $index ] = true;
					} else {
						$valid            = $valid && ! isset( $groups[ $index ] );
						$groups[ $index ] = true;
					}
				}
			}
			foreach ( $block['innerBlocks'] ?? array() as $child ) {
				$walk( $child );
			}
		};
		$walk( $tree[0] );
		return $valid && count( $indices ) === count( $form['controls'] ?? array() ) && count( $groups ) === count( $form['choice_groups'] ?? array() );
	}

	/** Preserve saved wrapper fragments, changing only their child slots. */
	public static function substitute( array $block, Static_Site_Importer_Form_Plan_State $state ): ?array {
		if ( isset( $block['form_choice_group_index'] ) ) {
			$choice                      = $state->form['choice_groups'][ $block['form_choice_group_index'] ];
			$options                     = array_map(
				static fn( array $option ): array => array(
					'label' => $option['label'],
					'value' => $option['source_value'] ?? $option['label'],
				),
				$choice['choices']
			);
			$multiple                    = 'multiple' === ( $choice['group']['selection_mode'] ?? $choice['group']['mode'] ?? '' )
				|| 1 < count( array_filter( $choice['observed_transition']['selected'] ?? array(), static fn( mixed $selected ): bool => true === $selected ) );
			$field                       = Static_Site_Importer_Form_Field_Markup::field_block_from_control(
				'input',
				$multiple ? 'checkbox' : 'radio',
				array(
					'label'   => $choice['group']['label'] ?? '',
					'options' => $options,
				)
			);
			$field['name']               = $multiple ? 'jetpack/field-multiple-choice' : 'jetpack/field-single-choice';
			$token                       = Static_Site_Importer_Provider_Form_Runtime_V1::choice_token( $options );
			$field['attrs']['className'] = trim( 'is-style-button ' . $token );
			$state->mapped_types[]       = $field['name'];
			return Static_Site_Importer_Form_Field_Markup::parsed_block( $field );
		}
		if ( isset( $block['form_control_index'] ) ) {
			$index = $block['form_control_index'];
			if ( isset( $state->suppressed_controls[ $index ] ) || ! isset( $state->field_blocks[ $index ] ) ) {
				return null;
			}
			$field = $state->field_blocks[ $index ];
			if ( 'submit' === ( $state->controls[ $index ]['type'] ?? '' ) ) {
				$field['name'] = 'jetpack/button';
			}
			return Static_Site_Importer_Form_Field_Markup::parsed_block( $field );
		}
		$children = array();
		$slots    = array();
		$index    = 0;
		foreach ( $block['innerContent'] ?? array() as $part ) {
			if ( null !== $part ) {
				$slots[] = $part;
				continue;
			}
			$child = self::substitute( $block['innerBlocks'][ $index++ ], $state );
			if ( null !== $child ) {
				$children[] = $child;
				$slots[]    = null;
			}
		}
		$block['innerBlocks']  = $children;
		$block['innerContent'] = $slots;
		return $block;
	}
}

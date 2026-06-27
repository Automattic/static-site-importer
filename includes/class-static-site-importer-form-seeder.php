<?php
/**
 * Jetpack contact-form materialization for preserved form runtime islands.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns preserved <form> fallback metadata into working Jetpack Form blocks.
 *
 * Mirrors Static_Site_Importer_Woo_Product_Seeder: the registry owns the manifest
 * contract; this provider seeder only consumes the normalized shape after the
 * adapter validator has succeeded and emits real form-provider block markup so a
 * detected form gains submission handling, email notifications, and spam control
 * instead of staying a dead html_form_fallback runtime island.
 */
class Static_Site_Importer_Form_Seeder {

	/**
	 * Provider id this seeder materializes for.
	 */
	public const PROVIDER_ID = 'jetpack';

	/**
	 * Map a source control type to a Jetpack field block name.
	 *
	 * @return array<string,string>
	 */
	private static function field_block_map(): array {
		return array(
			'text'     => 'jetpack/field-text',
			'search'   => 'jetpack/field-text',
			'password' => 'jetpack/field-text',
			'number'   => 'jetpack/field-text',
			'email'    => 'jetpack/field-email',
			'tel'      => 'jetpack/field-telephone',
			'url'      => 'jetpack/field-url',
			'date'     => 'jetpack/field-date',
			'textarea' => 'jetpack/field-textarea',
			'select'   => 'jetpack/field-select',
			'checkbox' => 'jetpack/field-checkbox',
			'radio'    => 'jetpack/field-radio',
		);
	}

	/**
	 * Materialize Jetpack contact forms from a validated forms manifest.
	 *
	 * @param array<string, mixed> $manifest Validated forms manifest.
	 * @return array<string, mixed>
	 */
	public static function seed( array $manifest ): array {
		$forms  = self::manifest_forms( $manifest );
		$report = self::new_report( 'not_run' );

		if ( empty( $forms ) ) {
			$report['status'] = 'skipped';
			$report['reason'] = 'empty_validated_manifest';
			return $report;
		}

		$available             = self::jetpack_forms_available();
		$report['provider']    = self::PROVIDER_ID;
		$report['available']   = $available;
		$report['status']      = 'completed';

		foreach ( $forms as $form ) {
			$row                = self::seed_form( $form, $available );
			$report['forms'][]  = $row;

			$status = $row['status'] ?? 'error';
			if ( isset( $report['counts'][ $status ] ) ) {
				++$report['counts'][ $status ];
			} else {
				++$report['counts']['error'];
			}
		}

		return $report;
	}

	/**
	 * Build an initial report shape.
	 *
	 * @param string $status Report status.
	 * @return array<string, mixed>
	 */
	public static function new_report( string $status = 'skipped' ): array {
		return array(
			'status'    => $status,
			'reason'    => '',
			'provider'  => self::PROVIDER_ID,
			'available' => self::jetpack_forms_available(),
			'counts'    => array(
				'mapped'  => 0,
				'skipped' => 0,
				'error'   => 0,
			),
			'forms'     => array(),
		);
	}

	/**
	 * Determine whether the Jetpack Forms runtime is available to host seeded forms.
	 *
	 * Public so the registry availability callback and the dependency gate can run
	 * before forms are materialized into a runtime that can carry submissions.
	 *
	 * @return bool
	 */
	public static function jetpack_forms_available(): bool {
		if ( class_exists( 'Automattic\\Jetpack\\Forms\\ContactForm\\Contact_Form' ) ) {
			return true;
		}
		if ( class_exists( 'Grunion_Contact_Form' ) || class_exists( 'Contact_Form' ) ) {
			return true;
		}

		return function_exists( 'is_plugin_active' ) && is_plugin_active( 'jetpack/jetpack.php' );
	}

	/**
	 * Extract the validator-owned forms list from a manifest.
	 *
	 * @param array<string, mixed> $manifest Validated forms manifest.
	 * @return array<int, array<string, mixed>>
	 */
	private static function manifest_forms( array $manifest ): array {
		$forms = isset( $manifest['forms'] ) && is_array( $manifest['forms'] ) ? $manifest['forms'] : $manifest;

		return array_values(
			array_filter(
				$forms,
				static fn ( $form ): bool => is_array( $form )
			)
		);
	}

	/**
	 * Map one source form into Jetpack contact-form block markup.
	 *
	 * @param array<string, mixed> $form      Validated form row.
	 * @param bool                 $available Whether the Jetpack runtime is active.
	 * @return array<string, mixed>
	 */
	private static function seed_form( array $form, bool $available ): array {
		$controls = isset( $form['controls'] ) && is_array( $form['controls'] ) ? $form['controls'] : array();
		$selector = isset( $form['selector'] ) && is_scalar( $form['selector'] ) ? (string) $form['selector'] : '';

		$field_blocks = array();
		$mapped_types = array();
		$submit_text  = 'Submit';
		$skipped      = array();

		foreach ( $controls as $control ) {
			if ( ! is_array( $control ) ) {
				continue;
			}

			$type = strtolower( trim( (string) ( $control['type'] ?? '' ) ) );
			$tag  = strtolower( trim( (string) ( $control['tag'] ?? '' ) ) );

			if ( 'submit' === $type || ( 'button' === $tag && 'submit' === $type ) ) {
				$text        = self::control_text( $control );
				$submit_text = '' !== $text ? $text : $submit_text;
				continue;
			}

			$field_block = self::field_block_from_control( $tag, $type, $control );
			if ( null === $field_block ) {
				$skipped[] = '' !== $type ? $type : $tag;
				continue;
			}

			$field_blocks[] = $field_block;
			$mapped_types[] = $field_block['name'];
		}

		if ( empty( $field_blocks ) ) {
			return array(
				'selector'      => $selector,
				'provider'      => self::PROVIDER_ID,
				'block_name'    => 'jetpack/contact-form',
				'status'        => 'skipped',
				'reason'        => 'no_mappable_form_fields',
				'runtime_mapped' => false,
				'skipped_types' => array_values( array_unique( array_filter( $skipped ) ) ),
			);
		}

		$inner_blocks   = $field_blocks;
		$inner_blocks[] = self::submit_button_block( $submit_text );
		$form_attrs     = self::contact_form_attributes( $form );
		$markup         = self::serialize_block( 'jetpack/contact-form', $form_attrs, $inner_blocks );

		return array(
			'selector'       => $selector,
			'provider'       => self::PROVIDER_ID,
			'block_name'     => 'jetpack/contact-form',
			'status'         => 'mapped',
			'field_count'    => count( $field_blocks ),
			'field_blocks'   => $mapped_types,
			'skipped_types'  => array_values( array_unique( array_filter( $skipped ) ) ),
			'submit_text'    => $submit_text,
			'runtime_mapped' => true,
			'runtime_carried' => $available,
			'block_markup'   => $markup,
		);
	}

	/**
	 * Build a Jetpack field block definition from a source control.
	 *
	 * @param string               $tag     Source control tag.
	 * @param string               $type    Source control type.
	 * @param array<string, mixed> $control Source control metadata.
	 * @return array<string, mixed>|null
	 */
	private static function field_block_from_control( string $tag, string $type, array $control ): ?array {
		$map = self::field_block_map();

		$lookup = 'textarea' === $tag ? 'textarea' : ( 'select' === $tag ? 'select' : $type );
		if ( 'select-multiple' === $type ) {
			$lookup = 'select';
		}

		if ( ! isset( $map[ $lookup ] ) ) {
			return null;
		}

		$attrs = array();
		$label = self::control_text( $control );
		if ( '' !== $label ) {
			$attrs['label'] = $label;
		}
		if ( ! empty( $control['required'] ) ) {
			$attrs['required'] = true;
		}
		$placeholder = isset( $control['placeholder'] ) && is_scalar( $control['placeholder'] ) ? trim( (string) $control['placeholder'] ) : '';
		if ( '' !== $placeholder ) {
			$attrs['placeholder'] = $placeholder;
		}

		if ( in_array( $lookup, array( 'select', 'radio', 'checkbox' ), true ) ) {
			$options = self::option_labels( $control );
			if ( ! empty( $options ) ) {
				$attrs['options'] = $options;
			}
		}

		return array(
			'name'  => $map[ $lookup ],
			'attrs' => $attrs,
		);
	}

	/**
	 * Build the Jetpack submit button block.
	 *
	 * @param string $text Submit button label.
	 * @return array<string, mixed>
	 */
	private static function submit_button_block( string $text ): array {
		return array(
			'name'  => 'jetpack/button',
			'attrs' => array(
				'element' => 'button',
				'text'    => '' !== trim( $text ) ? trim( $text ) : 'Submit',
				'lock'    => array(
					'remove' => true,
					'move'   => false,
				),
			),
		);
	}

	/**
	 * Resolve the contact-form block attributes from source form metadata.
	 *
	 * @param array<string, mixed> $form Validated form row.
	 * @return array<string, mixed>
	 */
	private static function contact_form_attributes( array $form ): array {
		$attrs    = array();
		$metadata = isset( $form['form'] ) && is_array( $form['form'] ) ? $form['form'] : array();
		$action   = isset( $metadata['action'] ) && is_scalar( $metadata['action'] ) ? trim( (string) $metadata['action'] ) : '';

		if ( '' !== $action && 0 === stripos( $action, 'mailto:' ) ) {
			$recipient = trim( substr( $action, 7 ) );
			$recipient = explode( '?', $recipient, 2 )[0];
			if ( '' !== $recipient && self::is_email( $recipient ) ) {
				$attrs['to'] = $recipient;
			}
		}

		return $attrs;
	}

	/**
	 * Read a control label/text value.
	 *
	 * @param array<string, mixed> $control Source control metadata.
	 * @return string
	 */
	private static function control_text( array $control ): string {
		foreach ( array( 'label', 'value', 'placeholder', 'name' ) as $key ) {
			if ( isset( $control[ $key ] ) && is_scalar( $control[ $key ] ) && '' !== trim( (string) $control[ $key ] ) ) {
				return trim( (string) $control[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Extract option labels from a select/radio/checkbox control.
	 *
	 * @param array<string, mixed> $control Source control metadata.
	 * @return array<int, string>
	 */
	private static function option_labels( array $control ): array {
		$options = isset( $control['options'] ) && is_array( $control['options'] ) ? $control['options'] : array();
		$labels  = array();

		foreach ( $options as $option ) {
			if ( is_array( $option ) ) {
				$label = isset( $option['label'] ) && is_scalar( $option['label'] ) ? trim( (string) $option['label'] ) : '';
				if ( '' === $label && isset( $option['value'] ) && is_scalar( $option['value'] ) ) {
					$label = trim( (string) $option['value'] );
				}
			} else {
				$label = is_scalar( $option ) ? trim( (string) $option ) : '';
			}

			if ( '' !== $label ) {
				$labels[] = $label;
			}
		}

		return $labels;
	}

	/**
	 * Serialize a block tree to WordPress block-comment markup.
	 *
	 * @param string                          $name        Block name.
	 * @param array<string, mixed>            $attrs       Block attributes.
	 * @param array<int, array<string,mixed>> $inner_blocks Child block definitions.
	 * @return string
	 */
	private static function serialize_block( string $name, array $attrs, array $inner_blocks = array() ): string {
		$attr_json = '';
		if ( ! empty( $attrs ) ) {
			$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $attrs ) : json_encode( $attrs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			if ( is_string( $encoded ) && '' !== $encoded && '[]' !== $encoded ) {
				$attr_json = ' ' . $encoded;
			}
		}

		if ( empty( $inner_blocks ) ) {
			return '<!-- wp:' . $name . $attr_json . ' /-->';
		}

		$inner = array();
		foreach ( $inner_blocks as $block ) {
			if ( ! is_array( $block ) || empty( $block['name'] ) ) {
				continue;
			}
			$inner[] = self::serialize_block(
				(string) $block['name'],
				isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array(),
				isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array()
			);
		}

		return '<!-- wp:' . $name . $attr_json . ' -->' . "\n" . implode( "\n", $inner ) . "\n" . '<!-- /wp:' . $name . ' -->';
	}

	/**
	 * Validate a candidate recipient email without requiring WordPress helpers.
	 *
	 * @param string $email Candidate email.
	 * @return bool
	 */
	private static function is_email( string $email ): bool {
		if ( function_exists( 'is_email' ) ) {
			return (bool) is_email( $email );
		}

		return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
	}
}

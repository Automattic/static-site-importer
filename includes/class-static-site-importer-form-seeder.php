<?php
/**
 * Jetpack contact-form materialization for preserved form runtime islands.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-static-site-importer-computed-layout-strategy.php';

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

	/** Return the provider block emitted for one mapped form binding. */
	public static function binding_block_markup( array $entity, array $result ): string {
		unset( $entity );
		return ! empty( $result['runtime_mapped'] ) && is_string( $result['block_markup'] ?? null ) ? $result['block_markup'] : '';
	}

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
		// field-telephone rewrites its valid input child to an editor-invalid phone-input block.
		return array(
			'text'     => 'jetpack/field-text',
			'search'   => 'jetpack/field-text',
			'password' => 'jetpack/field-text',
			'number'   => 'jetpack/field-number',
			'email'    => 'jetpack/field-email',
			'tel'      => 'jetpack/field-text',
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

		$availability                   = self::jetpack_forms_availability_details();
		$available                      = ! empty( $availability['available'] );
		$report['provider']             = self::PROVIDER_ID;
		$report['available']            = $available;
		$report['availability_details'] = $availability;
		$report['status']               = 'completed';

		foreach ( $forms as $form ) {
			$row               = $available ? self::seed_form( $form, true ) : self::unavailable_form_row( $form );
			$report['forms'][] = $row;

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
	 * Report a form that cannot be materialized until its configured provider is active.
	 *
	 * @param array<string, mixed> $form Validated form row.
	 * @return array<string, mixed>
	 */
	private static function unavailable_form_row( array $form ): array {
		return array(
			'selector'       => isset( $form['selector'] ) && is_scalar( $form['selector'] ) ? (string) $form['selector'] : '',
			'source_path'    => isset( $form['source_path'] ) && is_scalar( $form['source_path'] ) ? (string) $form['source_path'] : '',
			'provider'       => self::PROVIDER_ID,
			'block_name'     => 'jetpack/contact-form',
			'status'         => 'skipped',
			'reason'         => 'provider_unavailable',
			'runtime_mapped' => false,
		);
	}

	/**
	 * Build an initial report shape.
	 *
	 * @param string $status Report status.
	 * @return array<string, mixed>
	 */
	public static function new_report( string $status = 'skipped' ): array {
		return array(
			'status'               => $status,
			'reason'               => '',
			'provider'             => self::PROVIDER_ID,
			'available'            => self::jetpack_forms_available(),
			'availability_details' => self::jetpack_forms_availability_details(),
			'counts'               => array(
				'mapped'  => 0,
				'skipped' => 0,
				'error'   => 0,
			),
			'forms'                => array(),
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
		$availability = self::jetpack_forms_availability_details();
		return ! empty( $availability['available'] );
	}

	/**
	 * Return the specific Jetpack Forms APIs present in the current runtime.
	 *
	 * @return array<string,bool>
	 */
	public static function jetpack_forms_availability_details(): array {
		$contact_form_class = class_exists( 'Automattic\\Jetpack\\Forms\\ContactForm\\Contact_Form' );
		$legacy_class       = class_exists( 'Grunion_Contact_Form' ) || class_exists( 'Contact_Form' );
		$contact_form_block = false;
		$field_text_block   = false;

		if ( class_exists( 'WP_Block_Type_Registry' ) ) {
			$registry           = WP_Block_Type_Registry::get_instance();
			$contact_form_block = $registry->is_registered( 'jetpack/contact-form' );
			$field_text_block   = $registry->is_registered( 'jetpack/field-text' );
		}

		return array(
			'available'          => ( $contact_form_class || $legacy_class || ( $contact_form_block && $field_text_block ) ),
			'contact_form_class' => $contact_form_class,
			'legacy_class'       => $legacy_class,
			'contact_form_block' => $contact_form_block,
			'field_text_block'   => $field_text_block,
		);
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
		$controls    = isset( $form['controls'] ) && is_array( $form['controls'] ) ? $form['controls'] : array();
		$selector    = isset( $form['selector'] ) && is_scalar( $form['selector'] ) ? (string) $form['selector'] : '';
		$source_path = isset( $form['source_path'] ) && is_scalar( $form['source_path'] ) ? (string) $form['source_path'] : '';

		$field_blocks = array();
		$mapped_types = array();
		$submit_text  = 'Submit';
		$skipped      = array();
		$has_topology = isset( $form['control_topology'] );
		$has_source_submit = false;

		foreach ( $controls as $control_index => $control ) {
			if ( ! is_array( $control ) ) {
				continue;
			}

			$type = strtolower( trim( (string) ( $control['type'] ?? '' ) ) );
			$tag  = strtolower( trim( (string) ( $control['tag'] ?? '' ) ) );

			if ( 'submit' === $type || ( 'button' === $tag && 'submit' === $type ) ) {
				$text        = self::control_text( $control );
				$submit_text = '' !== $text ? $text : $submit_text;
				$has_source_submit = true;
				if ( $has_topology ) {
					$field_blocks[ $control_index ] = self::submit_button_block( $submit_text );
				}
				continue;
			}

			$field_block = self::field_block_from_control( $tag, $type, $control );
			if ( null === $field_block ) {
				$skipped[] = '' !== $type ? $type : $tag;
				continue;
			}

			$field_blocks[ $control_index ] = $field_block;
			$mapped_types[] = $field_block['name'];
		}

		if ( empty( $field_blocks ) ) {
			return array(
				'selector'       => $selector,
				'source_path'    => $source_path,
				'provider'       => self::PROVIDER_ID,
				'block_name'     => 'jetpack/contact-form',
				'status'         => 'skipped',
				'reason'         => 'no_mappable_form_fields',
				'runtime_mapped' => false,
				'skipped_types'  => array_values( array_unique( array_filter( $skipped ) ) ),
			);
		}

		$inner_blocks   = self::topology_inner_blocks( $form, $field_blocks );
		if ( null === $inner_blocks ) {
			return array(
				'selector' => $selector, 'source_path' => $source_path, 'provider' => self::PROVIDER_ID,
				'block_name' => 'jetpack/contact-form', 'status' => 'skipped', 'reason' => 'unsupported_control_topology', 'runtime_mapped' => false,
			);
		}
		if ( ! $has_topology || ! $has_source_submit ) {
			$inner_blocks[] = self::submit_button_block( $submit_text );
		}
		$layout = Static_Site_Importer_Computed_Layout_Strategy::apply( $form, $inner_blocks );
		$inner_blocks = $layout['blocks'];
		$form_attrs     = self::contact_form_attributes( $form );
		$markup         = self::serialize_block( 'jetpack/contact-form', $form_attrs, $inner_blocks );

		return array(
			'selector'        => $selector,
			'source_path'     => $source_path,
			'provider'        => self::PROVIDER_ID,
			'block_name'      => 'jetpack/contact-form',
			'status'          => 'mapped',
			'field_count'     => count( $mapped_types ),
			'field_blocks'    => $mapped_types,
			'skipped_types'   => array_values( array_unique( array_filter( $skipped ) ) ),
			'submit_text'     => $submit_text,
			'runtime_mapped'  => true,
			'runtime_carried' => $available,
			'block_markup'    => $markup,
			'computed_layout_receipt' => $layout['receipt'],
		);
	}

	/**
	 * Map the validated generic tree to Gutenberg layout blocks. Jetpack fields
	 * remain provider-owned; groups only carry source presentation and parentage.
	 *
	 * @param array<int,array<string,mixed>> $field_blocks
	 * @return array<int,array<string,mixed>>|null
	 */
	private static function topology_inner_blocks( array $form, array $field_blocks ): ?array {
		if ( ! isset( $form['control_topology'] ) ) {
			return array_values( $field_blocks );
		}
		$nodes = $form['control_topology']['nodes'] ?? null;
		if ( ! is_array( $nodes ) ) {
			return null;
		}
		$children = array( '$root' => array() );
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || ! is_string( $node['id'] ?? null ) ) {
				return null;
			}
			$parent = isset( $node['parent'] ) && is_string( $node['parent'] ) ? $node['parent'] : '$root';
			$children[ $parent ][] = $node;
		}
		foreach ( $children as &$siblings ) {
			usort( $siblings, static fn ( array $left, array $right ): int => $left['order'] <=> $right['order'] );
		}
		unset( $siblings );
		$build = static function ( string $parent ) use ( &$build, $children, $field_blocks ): array {
			$blocks = array();
			foreach ( $children[ $parent ] ?? array() as $node ) {
				if ( 'control' === ( $node['kind'] ?? null ) ) {
					if ( isset( $field_blocks[ $node['control'] ?? -1 ] ) ) {
						$blocks[] = $field_blocks[ $node['control'] ];
					}
					continue;
				}
				$attrs = array();
				if ( isset( $node['class'] ) ) $attrs['className'] = $node['class'];
				if ( isset( $node['source_id'] ) ) $attrs['anchor'] = $node['source_id'];
				if ( isset( $node['tag'] ) && in_array( $node['tag'], array( 'article', 'aside', 'div', 'footer', 'header', 'main', 'nav', 'section' ), true ) ) $attrs['tagName'] = $node['tag'];
				$blocks[] = array( 'name' => 'core/group', 'attrs' => $attrs, 'innerBlocks' => $build( $node['id'] ), 'wrapper' => 'group', 'topologyId' => $node['id'], 'topologySourceTag' => $node['tag'] ?? 'div' );
			}
			return $blocks;
		};
		return $build( '$root' );
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
		if ( ! empty( $control['required'] ) ) {
			$attrs['required'] = true;
		}
		$id = isset( $control['id'] ) && is_scalar( $control['id'] ) ? trim( (string) $control['id'] ) : '';
		if ( '' !== $id ) {
			$attrs['id'] = $id;
		}
		$placeholder = isset( $control['placeholder'] ) && is_scalar( $control['placeholder'] ) ? trim( (string) $control['placeholder'] ) : '';

		if ( in_array( $lookup, array( 'select', 'radio', 'checkbox' ), true ) ) {
			$options = self::option_labels( $control );
			if ( ! empty( $options ) ) {
				$attrs['options'] = $options;
			}
		}

		$inner_blocks = array();
		if ( 'checkbox' === $lookup ) {
			$inner_blocks[] = array(
				'name'  => 'jetpack/option',
				'attrs' => array(
					'label'        => $label,
					'isStandalone' => true,
				),
			);
		} elseif ( '' !== $label ) {
			$label_attrs = array( 'label' => $label );
			if ( ! empty( $control['required'] ) ) {
				$label_attrs['requiredText'] = '*';
			}
			$inner_blocks[] = array(
				'name'  => 'jetpack/label',
				'attrs' => $label_attrs,
			);
		}
		if ( 'radio' === $lookup ) {
			$options = $attrs['options'] ?? array();
			if ( empty( $options ) && '' !== $label ) {
				$options = array( $label );
			}
			$option_blocks = array();
			foreach ( $options as $option ) {
				$option_blocks[] = array(
					'name'  => 'jetpack/option',
					'attrs' => array( 'label' => $option ),
				);
			}
			$inner_blocks[] = array(
				'name'        => 'jetpack/options',
				'attrs'       => array( 'type' => 'radio' ),
				'innerBlocks' => $option_blocks,
				'wrapper'     => 'ul',
			);
		}

		$input_attrs = array();
		if ( '' !== $placeholder ) {
			$input_attrs['placeholder'] = $placeholder;
		}
		if ( 'textarea' === $lookup ) {
			$input_attrs['type'] = 'textarea';
		} elseif ( 'select' === $lookup ) {
			$input_attrs['type'] = 'dropdown';
		} elseif ( ! in_array( $lookup, array( 'checkbox', 'radio' ), true ) ) {
			$input_attrs['type'] = $type;
		}

		if ( ! in_array( $lookup, array( 'checkbox', 'radio' ), true ) ) {
			$inner_blocks[] = array(
				'name'  => 'jetpack/input',
				'attrs' => $input_attrs,
			);
		}

		return array(
			'name'        => $map[ $lookup ],
			'attrs'       => $attrs,
			'innerBlocks' => $inner_blocks,
			'wrapper'     => 'div',
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
		$class    = isset( $metadata['class'] ) && is_scalar( $metadata['class'] ) ? trim( (string) $metadata['class'] ) : '';

		if ( '' !== $class ) {
			$attrs['className'] = $class;
		}

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
	 * @param string                          $wrapper     Persisted inner-block wrapper element.
	 * @return string
	 */
	private static function serialize_block( string $name, array $attrs, array $inner_blocks = array(), string $wrapper = '' ): string {
		$comment_name = str_starts_with( $name, 'core/' ) ? substr( $name, 5 ) : $name;
		$attr_json = '';
		if ( ! empty( $attrs ) ) {
			$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $attrs ) : json_encode( $attrs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			if ( is_string( $encoded ) && '[]' !== $encoded ) {
				$attr_json = ' ' . $encoded;
			}
		}

		if ( empty( $inner_blocks ) ) {
			return '<!-- wp:' . $comment_name . $attr_json . ' /-->';
		}

		$inner = array();
		foreach ( $inner_blocks as $block ) {
			if ( empty( $block['name'] ) ) {
				continue;
			}
			$inner[] = self::serialize_block(
				(string) $block['name'],
				isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array(),
				isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array(),
				isset( $block['wrapper'] ) && is_string( $block['wrapper'] ) ? $block['wrapper'] : ''
			);
		}

		$inner_markup = implode( "\n", $inner );
		if ( 'jetpack/contact-form' === $name ) {
			$classes = 'wp-block-jetpack-contact-form';
			if ( isset( $attrs['className'] ) && is_scalar( $attrs['className'] ) && '' !== trim( (string) $attrs['className'] ) ) {
				$classes .= ' ' . trim( (string) $attrs['className'] );
			}
			$inner_markup = '<div class="' . self::escape_attribute( $classes ) . '">' . $inner_markup . '</div>';
		} elseif ( 'group' === $wrapper ) {
			$classes = 'wp-block-group' . ( ! empty( $attrs['className'] ) ? ' ' . $attrs['className'] : '' );
			if ( 'flex' === ( $attrs['layout']['type'] ?? '' ) ) $classes .= ' is-layout-flex';
			$id      = ! empty( $attrs['anchor'] ) ? ' id="' . self::escape_attribute( (string) $attrs['anchor'] ) . '"' : '';
			$tag     = ! empty( $attrs['tagName'] ) ? (string) $attrs['tagName'] : 'div';
			$inner_markup = '<' . $tag . $id . ' class="' . self::escape_attribute( $classes ) . '">' . $inner_markup . '</' . $tag . '>';
		} elseif ( in_array( $wrapper, array( 'div', 'ul' ), true ) ) {
			$inner_markup = '<' . $wrapper . '>' . $inner_markup . '</' . $wrapper . '>';
		}

		return '<!-- wp:' . $comment_name . $attr_json . ' -->' . "\n" . $inner_markup . "\n" . '<!-- /wp:' . $comment_name . ' -->';
	}

	/**
	 * Escape a block wrapper attribute without requiring WordPress to be loaded.
	 *
	 * @param string $value Raw attribute value.
	 * @return string
	 */
	private static function escape_attribute( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
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

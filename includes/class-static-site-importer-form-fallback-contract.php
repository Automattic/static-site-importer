<?php
/**
 * Provider-neutral form fallback contract operations.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns operational form normalization, presentation, and reconciliation facts.
 */
class Static_Site_Importer_Form_Fallback_Contract {

	/**
	 * Project bounded presentation facts from producer form metadata.
	 *
	 * @param array<string,mixed> $metadata Producer form entity or finding.
	 * @return array<string,mixed>
	 */
	public static function presentation_from_metadata( array $metadata, string $selector = '', int $occurrence = 0 ): array {
		return self::analysis_from_metadata( $metadata, $selector, $occurrence )['presentation'];
	}

	/**
	 * Analyze one fallback binding from producer form metadata.
	 *
	 * @param array<string,mixed> $metadata Producer form entity or finding.
	 * @return array{manifest:array{form:array<string,string>,controls:array<int,array<string,mixed>>},presentation:array<string,mixed>}
	 */
	public static function analysis_from_metadata( array $metadata, string $selector = '', int $occurrence = 0 ): array {
		$manifest = self::manifest_from_metadata( $metadata );
		$form     = isset( $metadata['form'] ) && is_array( $metadata['form'] ) ? $metadata['form'] : array();
		$controls = $manifest['controls'];
		$before   = self::context_items( $form['context_before'] ?? ( $metadata['form_presentation']['context_before'] ?? array() ) );
		$after    = self::context_items( $form['context_after'] ?? ( $metadata['form_presentation']['context_after'] ?? array() ) );
		$heights  = array();
		foreach ( $controls as $index => $control ) {
			if ( isset( $control['height'] ) && is_string( $control['height'] ) && '' !== $control['height'] ) {
				$heights[ $index ] = $control['height'];
			}
		}
		$declared_heights = $metadata['form_presentation']['textarea_heights'] ?? ( $form['textarea_heights'] ?? array() );
		if ( is_array( $declared_heights ) && array() !== $declared_heights ) {
			$heights = array();
			foreach ( $declared_heights as $index => $height ) {
				if ( is_string( $height ) && '' !== $height ) {
					$heights[ (int) $index ] = $height;
				}
			}
		}
		$submit         = $form['submit_presentation'] ?? ( $metadata['form_presentation']['submit_presentation'] ?? null );
		$submit         = is_array( $submit ) ? self::submit_presentation( $submit ) : null;
		$fingerprint    = array(
			'class'               => $manifest['form']['class'] ?? '',
			'action'              => $manifest['form']['action'] ?? '',
			'method'              => $manifest['form']['method'] ?? '',
			'controls'            => array_map( static fn ( array $control ): array => array_intersect_key( $control, array_flip( array( 'tag', 'type', 'name', 'id', 'label' ) ) ), $manifest['controls'] ),
			'submit_text'         => is_array( $submit ) ? ( $submit['text'] ?? '' ) : '',
			'context_before_hash' => hash( 'sha256', (string) wp_json_encode( $before ) ),
			'context_after_hash'  => hash( 'sha256', (string) wp_json_encode( $after ) ),
		);
		$stored_heights = array_slice( $heights, 0, 16, true );
		$omitted        = isset( $form['textarea_height_omitted_count'] ) && is_int( $form['textarea_height_omitted_count'] )
			? $form['textarea_height_omitted_count']
			: ( isset( $metadata['form_presentation']['textarea_height_omitted_count'] ) && is_int( $metadata['form_presentation']['textarea_height_omitted_count'] ) ? $metadata['form_presentation']['textarea_height_omitted_count'] : max( 0, count( $heights ) - count( $stored_heights ) ) );
		$interleaved    = ! empty( $form['interleaved_context'] ) || ! empty( $metadata['form_presentation']['interleaved_context'] );
		$presentation   = array_filter(
			array(
				'schema'                        => 'generic/form-presentation/v1',
				'selector'                      => $selector,
				'document_ordinal'              => $occurrence > 0 ? $occurrence : null,
				'fingerprint'                   => hash( 'sha256', (string) wp_json_encode( $fingerprint ) ),
				'context_before'                => array_slice( $before, 0, 8 ),
				'context_after'                 => array_slice( $after, 0, 8 ),
				'interleaved_context'           => $interleaved,
				'submit_presentation'           => $submit,
				'textarea_heights'              => $stored_heights,
				'textarea_height_omitted_count' => $omitted,
			)
		);
		if ( isset( $metadata['form_presentation'] ) && is_array( $metadata['form_presentation'] ) && 'generic/form-presentation/v1' === ( $metadata['form_presentation']['schema'] ?? null ) ) {
			$declared = $metadata['form_presentation'];
			if ( '' !== $selector ) {
				$declared['selector'] = $selector;
			}
			if ( $occurrence > 0 ) {
				$declared['document_ordinal'] = $occurrence;
			}
			$presentation = array_filter( $declared );
		}
		return array(
			'manifest'     => $manifest,
			'presentation' => $presentation,
		);
	}

	/**
	 * Extract a provider-neutral form manifest from producer metadata.
	 *
	 * @param array<string,mixed> $metadata Producer form entity or finding.
	 * @return array{form:array<string,string>,controls:array<int,array<string,mixed>>}
	 */
	public static function manifest_from_metadata( array $metadata ): array {
		$form     = isset( $metadata['form'] ) && is_array( $metadata['form'] ) ? $metadata['form'] : array();
		$controls = isset( $metadata['controls'] ) && is_array( $metadata['controls'] ) ? $metadata['controls'] : array();
		$manifest = array(
			'form'     => array(),
			'controls' => array(),
		);
		foreach ( array( 'class', 'action', 'method' ) as $attribute ) {
			$value = isset( $form[ $attribute ] ) && is_scalar( $form[ $attribute ] ) ? trim( (string) $form[ $attribute ] ) : '';
			if ( '' !== $value ) {
				$manifest['form'][ $attribute ] = $value;
			}
		}
		foreach ( $controls as $control ) {
			if ( ! is_array( $control ) ) {
				continue;
			}
			$row = array(
				'tag'  => strtolower( trim( (string) ( $control['tag'] ?? '' ) ) ),
				'type' => strtolower( trim( (string) ( $control['type'] ?? '' ) ) ),
			);
			if ( 'button' === $row['tag'] && '' === $row['type'] ) {
				$row['type'] = 'submit';
			}
			if ( 'input' === $row['tag'] && '' === $row['type'] ) {
				$row['type'] = 'text';
			}
			foreach ( array( 'id', 'name', 'placeholder', 'label', 'height', 'aria-required' ) as $attribute ) {
				$value = isset( $control[ $attribute ] ) && is_scalar( $control[ $attribute ] ) ? trim( (string) $control[ $attribute ] ) : '';
				if ( '' !== $value ) {
					$row[ $attribute ] = $value;
				}
			}
			if ( ! empty( $control['required'] ) ) {
				$row['required'] = true;
			}
			if ( '' !== $row['tag'] ) {
				$manifest['controls'][] = $row;
			}
		}
		return $manifest;
	}

	/** @param array<string,mixed> $fallback */
	public static function reconciliation_hash( array $fallback ): string {
		$source = isset( $fallback['form'] ) || isset( $fallback['controls'] )
			? wp_json_encode(
				self::canonical_value(
					self::normalize_form_metadata( $fallback )
				)
			)
			: self::first_scalar( $fallback, array( 'source_html_preview', 'html_excerpt', 'excerpt' ) );
		return hash( 'sha256', (string) $source );
	}

	/**
	 * Apply this contract's normalized presentation facts to producer form metadata.
	 *
	 * This is the single normalization both reconciliation sides hash: a source
	 * finding's raw producer `form`/`controls` and a materialized provider
	 * entity normalize to the same representation, so a field the contract
	 * normalizes can never by itself leave a provider-materialized form
	 * unresolved.
	 *
	 * @param array<string,mixed> $metadata Producer form entity or finding.
	 * @return array{form:array<string,mixed>,controls:array<int,array<string,mixed>>}
	 */
	public static function normalize_form_metadata( array $metadata ): array {
		$form     = isset( $metadata['form'] ) && is_array( $metadata['form'] ) ? $metadata['form'] : array();
		$controls = isset( $metadata['controls'] ) && is_array( $metadata['controls'] ) ? $metadata['controls'] : array();
		foreach ( array( 'form', 'controls' ) as $key ) {
			if ( isset( $metadata[ $key ] ) && ! is_array( $metadata[ $key ] ) ) {
				return array(
					'form'     => array(),
					'controls' => array(),
				);
			}
		}
		$presentation = self::presentation_from_metadata( $metadata );
		if ( 'generic/form-presentation/v1' !== ( $presentation['schema'] ?? null ) ) {
			return array(
				'form'     => $form,
				'controls' => $controls,
			);
		}
		foreach ( array( 'context_before', 'context_after', 'submit_presentation' ) as $key ) {
			if ( isset( $presentation[ $key ] ) ) {
				$form[ $key ] = $presentation[ $key ];
			}
		}
		if ( ! empty( $presentation['interleaved_context'] ) ) {
			$form['interleaved_context'] = true;
		}
		if ( ! empty( $presentation['textarea_height_omitted_count'] ) ) {
			$form['textarea_height_omitted_count'] = (int) $presentation['textarea_height_omitted_count'];
		}
		foreach ( $presentation['textarea_heights'] ?? array() as $index => $height ) {
			if ( isset( $controls[ $index ] ) && is_string( $height ) ) {
				$controls[ $index ]['height'] = $height;
			}
		}
		return array(
			'form'     => $form,
			'controls' => $controls,
		);
	}

	/** Canonicalize associative metadata while retaining authored list order. */
	private static function canonical_value( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $key => $child ) {
			$value[ $key ] = self::canonical_value( $child );
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		return $value;
	}

	/** @param array<string,mixed> $fallback */
	public static function reconciliation_identity( array $fallback ): string {
		foreach ( array( 'source_fallback_identity', 'fallback_reconciliation_identity', 'fallback_identity' ) as $field ) {
			$identity = $fallback[ $field ] ?? null;
			if ( is_string( $identity ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $identity ) ) {
				return $identity;
			}
		}

		return hash( 'sha256', "static-site-importer/fallback-reconciliation/v1\n" . self::first_scalar( $fallback, array( 'source_path', 'source' ) ) . "\n" . self::first_scalar( $fallback, array( 'selector' ) ) . "\n" . self::reconciliation_hash( $fallback ) );
	}

	/** @param mixed $value */
	private static function context_class( mixed $value ): string {
		$tokens = array();
		if ( is_string( $value ) ) {
			$tokens = preg_split( '/\s+/', trim( $value ) );
		} elseif ( is_array( $value ) ) {
			$tokens = $value;
		}
		$classes = array();
		foreach ( false === $tokens ? array() : $tokens as $class_name ) {
			if ( is_string( $class_name ) && 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $class_name ) ) {
				$classes[] = $class_name;
			}
			if ( 8 <= count( $classes ) ) {
				break;
			}
		}
		return implode( ' ', array_values( array_unique( $classes ) ) );
	}

	/** @param mixed $items @return array<int,array<string,mixed>> */
	private static function context_items( mixed $items ): array {
		if ( ! is_array( $items ) ) {
			return array();
		}
		$context = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! is_string( $item['text'] ?? null ) || '' === trim( $item['text'] ) ) {
				continue;
			}
			$text = substr( preg_replace( '/\s+/', ' ', trim( $item['text'] ) ) ?? '', 0, 200 );
			if ( 'heading' === ( $item['type'] ?? '' ) ) {
				$row   = array(
					'type'  => 'heading',
					'level' => min( 6, max( 1, (int) ( $item['level'] ?? 2 ) ) ),
					'text'  => $text,
				);
				$class = self::context_class( $item['class'] ?? null );
				if ( '' !== $class ) {
					$row['class'] = $class;
				}
				$context[] = $row;
			} elseif ( 'paragraph' === ( $item['type'] ?? '' ) ) {
				$row   = array(
					'type' => 'paragraph',
					'text' => $text,
				);
				$class = self::context_class( $item['class'] ?? null );
				if ( '' !== $class ) {
					$row['class'] = $class;
				}
				$context[] = $row;
			}
		}
		return $context;
	}

	/** @param array<string,mixed> $presentation @return array{text:string,classes:array<int,string>}|null */
	private static function submit_presentation( array $presentation ): ?array {
		$text = isset( $presentation['text'] ) && is_scalar( $presentation['text'] ) ? trim( (string) $presentation['text'] ) : '';
		if ( '' === $text ) {
			return null;
		}
		$classes = array();
		if ( isset( $presentation['classes'] ) && is_array( $presentation['classes'] ) ) {
			foreach ( $presentation['classes'] as $class_name ) {
				if ( is_string( $class_name ) && 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $class_name ) ) {
					$classes[] = $class_name;
				}
			}
		}
		$row           = array(
			'text'    => substr( $text, 0, 200 ),
			'classes' => array_slice( array_values( array_unique( $classes ) ), 0, 8 ),
		);
		$label_classes = array();
		if ( isset( $presentation['label_classes'] ) && is_array( $presentation['label_classes'] ) ) {
			foreach ( $presentation['label_classes'] as $class_name ) {
				if ( is_string( $class_name ) && 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $class_name ) ) {
					$label_classes[] = $class_name;
				}
			}
		}
		if ( array() !== $label_classes ) {
			$row['label_classes'] = array_slice( array_values( array_unique( $label_classes ) ), 0, 8 );
		}
		return $row;
	}

	/** @param array<string,mixed> $row @param array<int,string> $keys */
	private static function first_scalar( array $row, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
				return (string) $row[ $key ];
			}
		}
		return '';
	}
}

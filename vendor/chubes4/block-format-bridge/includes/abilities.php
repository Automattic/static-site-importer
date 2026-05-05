<?php
/**
 * WordPress Abilities API integration.
 *
 * @package BlockFormatBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'BFB_ABILITY_CATEGORY' ) ) {
	define( 'BFB_ABILITY_CATEGORY', 'block-format-bridge' );
}

if ( ! function_exists( 'bfb_register_ability_category' ) ) {
	/**
	 * Register the Block Format Bridge ability category.
	 *
	 * @return void
	 */
	function bfb_register_ability_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			BFB_ABILITY_CATEGORY,
			array(
				'label'       => __( 'Block Format Bridge', 'block-format-bridge' ),
				'description' => __( 'Content format conversion and normalization capabilities.', 'block-format-bridge' ),
			)
		);
	}
}

if ( ! function_exists( 'bfb_register_abilities' ) ) {
	/**
	 * Register Block Format Bridge abilities when the Abilities API is present.
	 *
	 * @return void
	 */
	function bfb_register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'block-format-bridge/get-capabilities',
			array(
				'label'               => __( 'Get Block Format Bridge Capabilities', 'block-format-bridge' ),
				'description'         => __( 'Return the active content-format conversion substrate capabilities.', 'block-format-bridge' ),
				'category'            => BFB_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => 'bfb_ability_get_capabilities',
				'permission_callback' => 'bfb_ability_permission_callback',
				'meta'                => array( 'show_in_rest' => true ),
			)
		);

		wp_register_ability(
			'block-format-bridge/convert',
			array(
				'label'               => __( 'Convert Content Format', 'block-format-bridge' ),
				'description'         => __( 'Convert content between registered BFB formats through the block pivot.', 'block-format-bridge' ),
				'category'            => BFB_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'content' => array( 'type' => 'string' ),
						'from'    => array( 'type' => 'string' ),
						'to'      => array( 'type' => 'string' ),
						'options' => array( 'type' => 'object' ),
					),
					'required'   => array( 'content', 'from', 'to' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => 'bfb_ability_convert',
				'permission_callback' => 'bfb_ability_permission_callback',
				'meta'                => array( 'show_in_rest' => true ),
			)
		);

		wp_register_ability(
			'block-format-bridge/convert-fragment',
			array(
				'label'               => __( 'Convert Source Fragment', 'block-format-bridge' ),
				'description'         => __( 'Convert a standalone HTML fragment to block markup with fragment-scoped diagnostics and provenance.', 'block-format-bridge' ),
				'category'            => BFB_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'html'    => array( 'type' => 'string' ),
						'options' => array( 'type' => 'object' ),
					),
					'required'   => array( 'html' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => 'bfb_ability_convert_fragment',
				'permission_callback' => 'bfb_ability_permission_callback',
				'meta'                => array( 'show_in_rest' => true ),
			)
		);

		wp_register_ability(
			'block-format-bridge/normalize',
			array(
				'label'               => __( 'Normalize Content Format', 'block-format-bridge' ),
				'description'         => __( 'Normalize and validate content for a declared BFB format.', 'block-format-bridge' ),
				'category'            => BFB_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'content' => array( 'type' => 'string' ),
						'format'  => array( 'type' => 'string' ),
						'options' => array( 'type' => 'object' ),
					),
					'required'   => array( 'content', 'format' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => 'bfb_ability_normalize',
				'permission_callback' => 'bfb_ability_permission_callback',
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}
}

if ( ! function_exists( 'bfb_ability_permission_callback' ) ) {
	/**
	 * Permission callback for read-only conversion substrate abilities.
	 *
	 * @return bool
	 */
	function bfb_ability_permission_callback(): bool {
		return ! function_exists( 'current_user_can' ) || current_user_can( 'read' );
	}
}

if ( ! function_exists( 'bfb_ability_get_capabilities' ) ) {
	/**
	 * Ability callback for capability discovery.
	 *
	 * @return array<string, mixed>
	 */
	function bfb_ability_get_capabilities(): array {
		return bfb_capabilities();
	}
}

if ( ! function_exists( 'bfb_ability_convert' ) ) {
	/**
	 * Ability callback for content conversion.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	function bfb_ability_convert( array $input ): array {
		$content = isset( $input['content'] ) ? (string) $input['content'] : '';
		$from    = isset( $input['from'] ) ? (string) $input['from'] : '';
		$to      = isset( $input['to'] ) ? (string) $input['to'] : '';
		$options = isset( $input['options'] ) && is_array( $input['options'] ) ? $input['options'] : array();

		if ( '' === $from || '' === $to ) {
			return bfb_ability_error( 'bfb_missing_format', 'Both from and to formats are required.' );
		}

		$result = bfb_convert( $content, $from, $to, $options );
		if ( '' === $result && '' !== $content ) {
			return bfb_ability_error( 'bfb_conversion_failed', sprintf( 'BFB conversion failed for %s -> %s.', $from, $to ) );
		}

		return array(
			'success' => true,
			'from'    => $from,
			'to'      => $to,
			'content' => $result,
		);
	}
}

if ( ! function_exists( 'bfb_ability_convert_fragment' ) ) {
	/**
	 * Ability callback for source-fragment conversion.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	function bfb_ability_convert_fragment( array $input ): array {
		$html    = isset( $input['html'] ) ? (string) $input['html'] : '';
		$options = isset( $input['options'] ) && is_array( $input['options'] ) ? $input['options'] : array();

		$result = bfb_convert_fragment( $html, $options );
		if ( empty( $result['success'] ) && '' !== $html ) {
			return bfb_ability_error( 'bfb_fragment_conversion_failed', 'BFB fragment conversion failed.', $result );
		}

		return $result;
	}
}

if ( ! function_exists( 'bfb_ability_normalize' ) ) {
	/**
	 * Ability callback for declared-format normalization.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	function bfb_ability_normalize( array $input ): array {
		$content = isset( $input['content'] ) ? (string) $input['content'] : '';
		$format  = isset( $input['format'] ) ? (string) $input['format'] : '';
		$options = isset( $input['options'] ) && is_array( $input['options'] ) ? $input['options'] : array();

		if ( '' === $format ) {
			return bfb_ability_error( 'bfb_missing_format', 'The declared format is required.' );
		}

		$result = bfb_normalize( $content, $format, $options );
		if ( is_wp_error( $result ) ) {
			/** @var WP_Error $result */
			return bfb_ability_error( (string) $result->get_error_code(), $result->get_error_message(), $result->get_error_data() );
		}

		return array(
			'success' => true,
			'format'  => $format,
			'content' => $result,
		);
	}
}

if ( ! function_exists( 'bfb_ability_error' ) ) {
	/**
	 * Build a structured ability error envelope.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param mixed  $data    Optional error data.
	 * @return array<string, mixed>
	 */
	function bfb_ability_error( string $code, string $message, $data = null ): array {
		return array(
			'success' => false,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
				'data'    => $data,
			),
		);
	}
}

if ( doing_action( 'wp_abilities_api_categories_init' ) ) {
	bfb_register_ability_category();
} elseif ( ! did_action( 'wp_abilities_api_categories_init' ) ) {
	add_action( 'wp_abilities_api_categories_init', 'bfb_register_ability_category' );
}

if ( doing_action( 'wp_abilities_api_init' ) ) {
	bfb_register_abilities();
} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
	add_action( 'wp_abilities_api_init', 'bfb_register_abilities' );
}

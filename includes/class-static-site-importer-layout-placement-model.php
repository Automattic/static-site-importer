<?php
/**
 * Layout placement model validation and normalization.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates and normalizes vendor-neutral `static-site-importer/layout-placement/v1` models.
 *
 * A model carries the captured host and item boxes for one page section at the
 * canonical capture viewports (widest first). It stores no provider vocabulary;
 * layout adapters project their own attributes from it.
 */
final class Static_Site_Importer_Layout_Placement_Model {

	public const SCHEMA = 'static-site-importer/layout-placement/v1';

	private const BOX_FIELDS            = array( 'x', 'y', 'width', 'height' );
	private const VIEWPORT_MIN_WIDTH    = 200;
	private const VIEWPORT_MAX_WIDTH    = 7680;
	private const POSITION_MAX_ABS      = 1000000.0;
	private const SIZE_MAX              = 1000000.0;
	private const BOX_PRECISION         = 3;
	private const MAX_ITEMS             = 256;
	private const MAX_VIEWPORTS         = 8;
	private const PATH_MAX_BYTES        = 128;

	/**
	 * Validate and normalize a decoded placement model.
	 *
	 * @param mixed $data Decoded JSON model.
	 * @return array{model:array<string,mixed>,errors:array<int,array{path:string,message:string}>}
	 */
	public static function validate( mixed $data ): array {
		$errors = array();
		$model  = array();

		if ( ! is_array( $data ) || isset( $data[0] ) ) {
			return array(
				'model'  => array(),
				'errors' => array(
					array(
						'path'    => '$',
						'message' => 'layout placement model must be an object with schema, host, and items fields.',
					),
				),
			);
		}

		if ( self::SCHEMA !== ( $data['schema'] ?? null ) ) {
			$errors[] = array(
				'path'    => '$.schema',
				'message' => 'schema must be ' . self::SCHEMA . '.',
			);
		}

		$host = $data['host'] ?? null;
		if ( ! is_array( $host ) || isset( $host[0] ) ) {
			$errors[] = array(
				'path'    => '$.host',
				'message' => 'host must be an object with path and viewports fields.',
			);
		} else {
			$host_path = self::validated_path( $host['path'] ?? null );
			if ( null === $host_path ) {
				$errors[] = array(
					'path'    => '$.host.path',
					'message' => 'host path must be a dot-separated block tree path such as "0.2".',
				);
			}

			$host_viewports = self::validated_viewports( $host['viewports'] ?? null, '$.host.viewports', $errors );
			if ( null !== $host_path && null !== $host_viewports ) {
				$model['host'] = array(
					'path'      => $host_path,
					'viewports' => $host_viewports,
				);
			}
		}

		$items = $data['items'] ?? null;
		if ( ! is_array( $items ) || ! array_is_list( $items ) ) {
			$errors[] = array(
				'path'    => '$.items',
				'message' => 'items must be a JSON array.',
			);
			$items = null;
		} elseif ( count( $items ) > self::MAX_ITEMS ) {
			$errors[] = array(
				'path'    => '$.items',
				'message' => 'items must not exceed ' . (string) self::MAX_ITEMS . ' entries.',
			);
			$items = null;
		} else {
			$normalized_items = array();
			$seen_paths       = array();
			foreach ( $items as $index => $item ) {
				$prefix = '$.items[' . $index . ']';
				if ( ! is_array( $item ) || isset( $item[0] ) ) {
					$errors[] = array(
						'path'    => $prefix,
						'message' => 'item must be an object with path and viewports fields.',
					);
					continue;
				}
				$item_path = self::validated_path( $item['path'] ?? null );
				if ( null === $item_path ) {
					$errors[] = array(
						'path'    => $prefix . '.path',
						'message' => 'item path must be a dot-separated block tree path such as "0.2".',
					);
					continue;
				}
				if ( $item_path === ( $model['host']['path'] ?? null ) ) {
					$errors[] = array(
						'path'    => $prefix . '.path',
						'message' => 'item path must differ from the host path.',
					);
					continue;
				}
				if ( isset( $seen_paths[ $item_path ] ) ) {
					$errors[] = array(
						'path'    => $prefix . '.path',
						'message' => 'item path must be unique within one model.',
					);
					continue;
				}
				$seen_paths[ $item_path ] = true;

				$item_viewports = self::validated_viewports( $item['viewports'] ?? null, $prefix . '.viewports', $errors );
				if ( null === $item_viewports ) {
					continue;
				}
				$normalized_items[] = array(
					'path'      => $item_path,
					'viewports' => $item_viewports,
				);
			}
			if ( empty( $errors ) ) {
				$model['items'] = $normalized_items;
			}
		}

		if ( ! empty( $errors ) ) {
			return array(
				'model'  => array(),
				'errors' => $errors,
			);
		}

		$model['schema'] = self::SCHEMA;
		return array(
			'model'  => $model,
			'errors' => array(),
		);
	}

	/**
	 * Return the validated model or null with the error rows on a WP runtime.
	 *
	 * @param mixed $data Decoded JSON model.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function validated( mixed $data ) {
		$result = self::validate( $data );
		if ( ! empty( $result['errors'] ) ) {
			if ( function_exists( 'is_wp_error' ) ) {
				return new WP_Error( 'static_site_importer_layout_placement_invalid', 'Layout placement model failed validation.', array( 'errors' => $result['errors'] ) );
			}
			return array( 'errors' => $result['errors'] );
		}
		return $result['model'];
	}

	/**
	 * Viewport widths of a normalized model, widest first.
	 *
	 * @param array<string,mixed> $model Normalized model.
	 * @return array<int,int>
	 */
	public static function viewport_widths( array $model ): array {
		$viewports = isset( $model['viewports'] ) && is_array( $model['viewports'] ) ? $model['viewports'] : array();
		$widths    = array();
		foreach ( $viewports as $width => $_ ) {
			if ( is_int( $width ) ) {
				$widths[] = $width;
			}
		}
		return $widths;
	}

	/**
	 * Box row for one viewport width, or null when that viewport is absent.
	 *
	 * @param array<string,mixed> $subject Normalized host or item row.
	 * @param int                 $width   Viewport width.
	 * @return array<string,float>|null
	 */
	public static function box_at( array $subject, int $width ): ?array {
		$box = $subject['viewports'][ $width ] ?? null;
		return is_array( $box ) ? $box : null;
	}

	/**
	 * Validate one dotted block tree path.
	 *
	 * @param mixed $path Candidate path.
	 * @return string|null Canonical path, or null when invalid.
	 */
	private static function validated_path( mixed $path ): ?string {
		if ( ! is_string( $path ) || '' === $path || strlen( $path ) > self::PATH_MAX_BYTES ) {
			return null;
		}
		return 1 === preg_match( '/^(?:0|[1-9][0-9]*)(?:\.(?:0|[1-9][0-9]*))*$/D', $path ) ? $path : null;
	}

	/**
	 * Validate and normalize a viewport map.
	 *
	 * @param mixed                $data   Candidate viewports value.
	 * @param string               $prefix JSON path prefix for errors.
	 * @param array<int,mixed>     $errors Collected error rows.
	 * @return array<int,array<string,float>>|null
	 */
	private static function validated_viewports( mixed $data, string $prefix, array &$errors ): ?array {
		if ( ! is_array( $data ) || empty( $data ) ) {
			$errors[] = array(
				'path'    => $prefix,
				'message' => 'viewports must be a non-empty object keyed by viewport width.',
			);
			return null;
		}

		$widths = array();
		foreach ( array_keys( $data ) as $key ) {
			$width = is_int( $key ) ? $key : ( is_string( $key ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $key ) ? (int) $key : null );
			if ( null === $width || $width < self::VIEWPORT_MIN_WIDTH || $width > self::VIEWPORT_MAX_WIDTH ) {
				$errors[] = array(
					'path'    => $prefix . '.' . (string) $key,
					'message' => 'viewport key must be an integer width between ' . (string) self::VIEWPORT_MIN_WIDTH . ' and ' . (string) self::VIEWPORT_MAX_WIDTH . '.',
				);
				continue;
			}
			$widths[] = $width;
		}
		if ( empty( $widths ) ) {
			return null;
		}
		if ( count( $widths ) > self::MAX_VIEWPORTS ) {
			$errors[] = array(
				'path'    => $prefix,
				'message' => 'viewports must not exceed ' . (string) self::MAX_VIEWPORTS . ' entries.',
			);
			return null;
		}

		$normalized = array();
		rsort( $widths, SORT_NUMERIC );
		foreach ( $widths as $width ) {
			$box = self::validated_box( $data[ (string) $width ] ?? $data[ $width ] ?? null, $prefix . '.' . (string) $width, $errors );
			if ( null === $box ) {
				return null;
			}
			$normalized[ $width ] = $box;
		}
		return $normalized;
	}

	/**
	 * Validate and normalize one captured box.
	 *
	 * @param mixed            $data   Candidate box value.
	 * @param string           $prefix JSON path prefix for errors.
	 * @param array<int,mixed> $errors Collected error rows.
	 * @return array<string,float>|null
	 */
	private static function validated_box( mixed $data, string $prefix, array &$errors ): ?array {
		if ( ! is_array( $data ) || isset( $data[0] ) ) {
			$errors[] = array(
				'path'    => $prefix,
				'message' => 'viewport box must be an object with x, y, width, and height numbers.',
			);
			return null;
		}

		$box = array();
		foreach ( self::BOX_FIELDS as $field ) {
			$value = $data[ $field ] ?? null;
			if ( ! is_int( $value ) && ! is_float( $value ) ) {
				$errors[] = array(
					'path'    => $prefix . '.' . $field,
					'message' => 'box ' . $field . ' must be a number.',
				);
				return null;
			}
			$value = (float) $value;
			if ( ! is_finite( $value ) ) {
				$errors[] = array(
					'path'    => $prefix . '.' . $field,
					'message' => 'box ' . $field . ' must be a finite number.',
				);
				return null;
			}
			if ( 'width' === $field || 'height' === $field ) {
				if ( 0.0 > $value || self::SIZE_MAX < $value ) {
					$errors[] = array(
						'path'    => $prefix . '.' . $field,
						'message' => 'box ' . $field . ' must be between 0 and ' . (string) self::SIZE_MAX . '.',
					);
					return null;
				}
			} elseif ( self::POSITION_MAX_ABS < abs( $value ) ) {
				$errors[] = array(
					'path'    => $prefix . '.' . $field,
					'message' => 'box ' . $field . ' must be between -' . (string) self::POSITION_MAX_ABS . ' and ' . (string) self::POSITION_MAX_ABS . '.',
				);
				return null;
			}
			$box[ $field ] = round( $value, self::BOX_PRECISION );
		}
		return $box;
	}
}

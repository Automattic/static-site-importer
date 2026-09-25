<?php
/**
 * Layout projection engine for placement models.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Layout_Placement_Model' ) ) {
	require_once __DIR__ . '/class-static-site-importer-layout-placement-model.php';
}
if ( ! class_exists( 'Static_Site_Importer_Layout_Adapter' ) ) {
	require_once __DIR__ . '/class-static-site-importer-layout-adapter.php';
}

/**
 * Projects a validated placement model onto canonical block markup.
 *
 * Every projection parses the original markup fresh, rewrites only the host
 * block and the item placement attributes through one adapter, and reports
 * per-item losses. Re-projecting the same original markup with another
 * adapter, or with `none`, therefore always reproduces a clean result.
 */
final class Static_Site_Importer_Layout_Projector {

	public const PROJECTION_SCHEMA         = 'static-site-importer/layout-projection/v1';
	public const ORIGINAL_CONTENT_META_KEY = '_static_site_importer_layout_original_content';
	private const HIDDEN_SIZE_THRESHOLD    = 1.0;

	/**
	 * Project one model onto original canonical block markup.
	 *
	 * @param string                              $markup  Original block markup.
	 * @param array<string,mixed>                 $model   Normalized placement model.
	 * @param Static_Site_Importer_Layout_Adapter $adapter Layout adapter.
	 * @return array<string,mixed> Projection receipt with markup.
	 */
	public static function project( string $markup, array $model, Static_Site_Importer_Layout_Adapter $adapter ): array {
		$result = array(
			'schema'  => self::PROJECTION_SCHEMA,
			'adapter' => $adapter->id(),
			'applied' => false,
			'placed'  => 0,
			'reason'  => '',
			'losses'  => array(),
			'markup'  => $markup,
		);

		if ( 'none' === $adapter->id() ) {
			$result['applied'] = true;
			return $result;
		}

		$pieces = self::parse( $markup );
		if ( null === $pieces ) {
			return self::refused( $result, 'markup_unparseable' );
		}

		$host_model = is_array( $model['host'] ?? null ) ? $model['host'] : array();
		$host_path  = is_string( $host_model['path'] ?? null ) ? $host_model['path'] : '';
		$host_block = &self::resolve( $pieces, $host_path );
		if ( null === $host_block ) {
			return self::refused( $result, 'host_path_unresolved' );
		}

		$host_refusal = $adapter->host_refusal( $host_block, $model );
		if ( null !== $host_refusal ) {
			return self::refused( $result, $host_refusal );
		}

		$items = is_array( $model['items'] ?? null ) ? $model['items'] : array();
		if ( empty( $items ) ) {
			return self::refused( $result, 'model_without_items' );
		}

		$adapter->prepare( $model );
		$reencode   = array( $host_path => true );
		$host_class = $adapter->host_class();
		$placed     = 0;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$item_path = is_string( $item['path'] ?? null ) ? $item['path'] : '';
			// Placement applies to the host's direct children only: a grid track
			// or a canvas cell cannot hold a block nested inside another child.
			if ( 1 !== preg_match( '/^' . preg_quote( $host_path, '/' ) . '\\.[0-9]+$/D', $item_path ) ) {
				$result['losses'][] = array(
					'item'   => $item_path,
					'reason' => 'item_not_host_child',
				);
				continue;
			}
			$item_block = &self::resolve( $pieces, $item_path );
			if ( null === $item_block ) {
				$result['losses'][] = array(
					'item'   => $item_path,
					'reason' => 'item_path_unresolved',
				);
				continue;
			}
			$item_refusal = $adapter->item_refusal( $item_block );
			if ( null !== $item_refusal ) {
				$result['losses'][] = array(
					'item'   => $item_path,
					'reason' => $item_refusal,
				);
				continue;
			}
			$hidden_at = self::hidden_viewport( $item );
			if ( null !== $hidden_at ) {
				$result['losses'][] = array(
					'item'   => $item_path,
					'reason' => 'item_hidden_at_viewport',
					'detail' => (string) $hidden_at,
				);
				continue;
			}
			$adapter->place_item( $item_block, $item, $host_model );
			$reencode[ $item_path ] = true;
			++$placed;
		}

		if ( 0 === $placed ) {
			return self::refused( $result, 'no_placeable_items' );
		}

		$adapter->place_host( $host_block, $model );
		if ( '' !== $host_class && ! self::add_host_class( $host_block, $host_class ) ) {
			return self::refused( $result, 'host_wrapper_missing' );
		}

		$result['markup']  = self::serialize_pieces( $pieces, $reencode );
		$result['applied'] = true;
		$result['placed']  = $placed;
		return $result;
	}

	/**
	 * Parse canonical block markup into ordered pieces.
	 *
	 * @param string $markup Canonical block markup.
	 * @return array<int,mixed>|null Pieces of strings and block rows, or null when malformed.
	 */
	public static function parse( string $markup ): ?array {
		$pieces = array();
		$stack  = array();
		$cursor = 0;

		$matched = preg_match_all( '/<!--\s*(\/?)wp:([a-z0-9\/_-]+)(.*?)-->/s', $markup, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		if ( false === $matched ) {
			return null;
		}

		foreach ( $matches as $match ) {
			$before = substr( $markup, $cursor, $match[0][1] - $cursor );
			$cursor = $match[0][1] + strlen( $match[0][0] );
			self::append_text( $stack, $pieces, $before );

			$is_closing = '' !== $match[1][0];
			$name       = (string) $match[2][0];
			$rest       = trim( (string) $match[3][0] );
			if ( $is_closing ) {
				if ( empty( $stack ) ) {
					return null;
				}
				$block = array_pop( $stack );
				if ( self::comment_name( (string) $block['blockName'] ) !== $name ) {
					return null;
				}
				self::append_block( $stack, $pieces, $block );
				continue;
			}

			$is_self_closing = '' !== $rest && '/' === substr( $rest, -1 );
			$attr_source     = $is_self_closing ? rtrim( substr( $rest, 0, -1 ) ) : $rest;

			$block = array(
				'blockName'    => str_contains( $name, '/' ) ? $name : 'core/' . $name,
				'attrs'        => array(),
				'attrs_raw'    => null,
				'innerBlocks'  => array(),
				'innerContent' => array(),
			);

			if ( '' !== $attr_source && '{' === $attr_source[0] ) {
				$decoded = json_decode( $attr_source, true );
				if ( is_array( $decoded ) && ! isset( $decoded[0] ) ) {
					$block['attrs'] = $decoded;
				}
				$block['attrs_raw'] = $attr_source;
			}

			if ( $is_self_closing ) {
				self::append_block( $stack, $pieces, $block );
				continue;
			}

			$stack[] = $block;
		}

		self::append_text( $stack, $pieces, substr( $markup, $cursor ) );
		if ( ! empty( $stack ) ) {
			return null;
		}
		return $pieces;
	}

	/**
	 * Serialize pieces back to canonical block markup.
	 *
	 * @param array<int,mixed> $pieces Parsed pieces.
	 * @return string
	 */
	public static function serialize( array $pieces ): string {
		return self::serialize_pieces( $pieces, array() );
	}

	/**
	 * Mark one projection result as refused.
	 *
	 * @param array<string,mixed> $result Projection result.
	 * @param string              $reason Refusal reason.
	 * @return array<string,mixed>
	 */
	private static function refused( array $result, string $reason ): array {
		$result['applied']  = false;
		$result['reason']   = $reason;
		$result['losses'][] = array(
			'item'   => '',
			'reason' => $reason,
		);
		return $result;
	}

	/**
	 * First viewport width at which an item box is hidden, or null.
	 *
	 * @param array<string,mixed> $item Normalized item row of the model.
	 * @return int|null
	 */
	private static function hidden_viewport( array $item ): ?int {
		$viewports = is_array( $item['viewports'] ?? null ) ? $item['viewports'] : array();
		foreach ( $viewports as $width => $box ) {
			if ( ! is_array( $box ) ) {
				continue;
			}
			if ( self::HIDDEN_SIZE_THRESHOLD > (float) ( $box['width'] ?? 0.0 ) || self::HIDDEN_SIZE_THRESHOLD > (float) ( $box['height'] ?? 0.0 ) ) {
				return is_int( $width ) ? $width : (int) $width;
			}
		}
		return null;
	}

	/**
	 * Append one text run to the open frame or the top-level pieces.
	 *
	 * @param array<int,mixed> $stack  Open block frames.
	 * @param array<int,mixed> $pieces Top-level pieces.
	 * @param string           $text   Text run.
	 * @return void
	 */
	private static function append_text( array &$stack, array &$pieces, string $text ): void {
		if ( '' === $text ) {
			return;
		}
		if ( empty( $stack ) ) {
			$pieces[] = $text;
			return;
		}
		$stack[ count( $stack ) - 1 ]['innerContent'][] = $text;
	}

	/**
	 * Append one completed block to the open frame or the top-level pieces.
	 *
	 * @param array<int,mixed> $stack Open block frames.
	 * @param array<int,mixed> $pieces Top-level pieces.
	 * @param array<string,mixed> $block Completed block.
	 * @return void
	 */
	private static function append_block( array &$stack, array &$pieces, array $block ): void {
		if ( empty( $stack ) ) {
			$pieces[] = $block;
			return;
		}
		$stack[ count( $stack ) - 1 ]['innerContent'][] = null;
		$stack[ count( $stack ) - 1 ]['innerBlocks'][]  = $block;
	}

	/**
	 * Serialize pieces, re-encoding attributes only for the listed paths.
	 *
	 * @param array<int,mixed>    $pieces   Parsed pieces.
	 * @param array<string,true>  $reencode Block paths whose attributes were rewritten.
	 * @param string              $prefix   Path prefix of the current piece list.
	 * @return string
	 */
	private static function serialize_pieces( array $pieces, array $reencode, string $prefix = '' ): string {
		$output      = '';
		$block_index = 0;
		foreach ( $pieces as $piece ) {
			if ( is_string( $piece ) ) {
				$output .= $piece;
				continue;
			}
			$output .= self::serialize_block( $piece, $prefix . (string) $block_index, $reencode );
			++$block_index;
		}
		return $output;
	}

	/**
	 * Serialize one block.
	 *
	 * @param array<string,mixed> $block    Block row.
	 * @param string              $path     Block tree path.
	 * @param array<string,true>  $reencode Block paths whose attributes were rewritten.
	 * @return string
	 */
	private static function serialize_block( array $block, string $path, array $reencode ): string {
		$name  = self::comment_name( (string) ( $block['blockName'] ?? '' ) );
		$attrs = '';
		// Rewritten blocks, and blocks created by an adapter (which carry no raw
		// attribute source), serialize from their decoded attributes.
		if ( isset( $reencode[ $path ] ) || ! is_string( $block['attrs_raw'] ?? null ) || ! empty( $block['attrs_dirty'] ) ) {
			$decoded = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$json    = empty( $decoded ) ? '' : wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( is_string( $json ) && '' !== $json && '[]' !== $json ) {
				$attrs = ' ' . $json;
			}
		} else {
			$raw = $block['attrs_raw'];
			if ( '' !== $raw ) {
				$attrs = ' ' . $raw;
			}
		}

		$inner_content = is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : array();
		if ( empty( $inner_content ) ) {
			return "<!-- wp:{$name}{$attrs} /-->";
		}

		$inner       = '';
		$child_index = 0;
		foreach ( $inner_content as $chunk ) {
			if ( is_string( $chunk ) ) {
				$inner .= $chunk;
				continue;
			}
			$inner .= self::serialize_block( $block['innerBlocks'][ $child_index ], $path . '.' . (string) $child_index, $reencode );
			++$child_index;
		}
		return "<!-- wp:{$name}{$attrs} -->" . $inner . "<!-- /wp:{$name} -->";
	}

	/**
	 * Resolve one dotted block path to its block, by reference.
	 *
	 * @param array<int,mixed> $pieces Parsed pieces.
	 * @param string           $path   Dotted block path.
	 * @return array<string,mixed>|null
	 */
	private static function &resolve( array &$pieces, string $path ): ?array {
		$null     = null;
		$segments = '' === $path ? array() : explode( '.', $path );
		if ( empty( $segments ) ) {
			return $null;
		}

		$current = &$pieces;
		$block   = null;
		$last    = count( $segments ) - 1;
		foreach ( $segments as $index => $segment ) {
			if ( 1 !== preg_match( '/^(?:0|[1-9][0-9]*)$/D', $segment ) ) {
				return $null;
			}
			$piece_index = self::piece_index_for_block_index( $current, (int) $segment );
			if ( null === $piece_index ) {
				return $null;
			}
			$block = &$current[ $piece_index ];
			if ( $index < $last ) {
				$current = &$block['innerBlocks'];
			}
		}
		if ( null === $block ) {
			return $null;
		}
		return $block;
	}

	/**
	 * Map one block index to its piece position in a piece list.
	 *
	 * @param array<int,mixed> $pieces Parsed pieces.
	 * @param int              $block_index Block index in document order.
	 * @return int|null
	 */
	private static function piece_index_for_block_index( array $pieces, int $block_index ): ?int {
		if ( 0 > $block_index ) {
			return null;
		}
		foreach ( $pieces as $piece_index => $piece ) {
			if ( ! is_array( $piece ) || ! isset( $piece['blockName'] ) ) {
				continue;
			}
			if ( 0 === $block_index ) {
				return $piece_index;
			}
			--$block_index;
		}
		return null;
	}

	/**
	 * Map one tree block name to its comment form.
	 *
	 * Core block comments omit the `core/` namespace; foreign block comments
	 * keep their full slash name.
	 *
	 * @param string $block_name Tree block name.
	 * @return string
	 */
	private static function comment_name( string $block_name ): string {
		return str_starts_with( $block_name, 'core/' ) ? substr( $block_name, 5 ) : $block_name;
	}

	/**
	 * Add the generated host class to the host wrapper tag.
	 *
	 * @param array<string,mixed> $host_block Host block.
	 * @param string              $class_name      Generated host class.
	 * @return bool
	 */
	private static function add_host_class( array &$host_block, string $class_name ): bool {
		$inner_content = is_array( $host_block['innerContent'] ?? null ) ? $host_block['innerContent'] : array();
		foreach ( $inner_content as $index => $chunk ) {
			if ( ! is_string( $chunk ) || '' === $chunk ) {
				continue;
			}
			$marked = self::add_class_to_first_tag( $chunk, $class_name );
			if ( null !== $marked ) {
				$host_block['innerContent'][ $index ] = $marked;
				return true;
			}
			return false;
		}
		return false;
	}

	/**
	 * Add one class to the first opening tag of an HTML run.
	 *
	 * @param string $html   HTML run from block inner content.
	 * @param string $class_name  Class to add.
	 * @return string|null Rewritten run, or null when no opening tag exists.
	 */
	private static function add_class_to_first_tag( string $html, string $class_name ): ?string {
		$tag_start = strpos( $html, '<' );
		if ( false === $tag_start || '/' === substr( $html, $tag_start + 1, 1 ) || '!--' === substr( $html, $tag_start + 1, 3 ) ) {
			return null;
		}
		$tag_end = strpos( $html, '>', $tag_start );
		if ( false === $tag_end ) {
			return null;
		}

		$tag        = substr( $html, $tag_start + 1, $tag_end - $tag_start - 1 );
		$self_close = '/' === substr( rtrim( $tag ), -1 );
		if ( $self_close ) {
			$tag = rtrim( substr( rtrim( $tag ), 0, -1 ) );
		}

		$name  = $tag;
		$attrs = '';
		$split = strcspn( $tag, " \t\r\n" );
		if ( $split < strlen( $tag ) ) {
			$name  = substr( $tag, 0, $split );
			$attrs = ltrim( substr( $tag, $split ), " \t\r\n" );
		}

		if ( 1 === preg_match( '/(^|\s)class\s*=\s*"([^"]*)"/', $attrs, $matches, PREG_OFFSET_CAPTURE ) ) {
			$leading  = $matches[1][1] > 0 && '' === $matches[1][0] ? ' ' : $matches[1][0];
			$classes  = trim( $matches[2][0] . ' ' . $class_name );
			$position = $matches[0][1];
			$attrs    = substr( $attrs, 0, $position ) . $leading . 'class="' . $classes . '"' . substr( $attrs, $position + strlen( $matches[0][0] ) );
		} elseif ( 1 === preg_match( "/(^|\s)class\s*=\s*'([^']*)'/", $attrs, $matches, PREG_OFFSET_CAPTURE ) ) {
			$leading  = $matches[1][1] > 0 && '' === $matches[1][0] ? ' ' : $matches[1][0];
			$classes  = trim( $matches[2][0] . ' ' . $class_name );
			$position = $matches[0][1];
			$attrs    = substr( $attrs, 0, $position ) . $leading . 'class="' . $classes . '"' . substr( $attrs, $position + strlen( $matches[0][0] ) );
		} else {
			$attrs = 'class="' . $class_name . '"' . ( '' === $attrs ? '' : ' ' . $attrs );
		}

		$rebuilt = $name . ' ' . $attrs . ( $self_close ? ' /' : '' );
		return substr( $html, 0, $tag_start ) . '<' . $rebuilt . '>' . substr( $html, $tag_end + 1 );
	}
}

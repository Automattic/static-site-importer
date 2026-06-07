<?php
/**
 * Format adapter contract.
 *
 * Each adapter knows how to convert between its named format and the
 * canonical block representation (block arrays as produced by
 * parse_blocks() / consumed by serialize_blocks()).
 *
 * Blocks are the pivot — every cross-format conversion in the bridge
 * routes `from_format → blocks → to_format`. New formats become
 * available by registering a new adapter; the bridge core never grows.
 *
 * @package BlockFormatBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract every format adapter implements.
 */
interface BFB_Format_Adapter {

	/**
	 * Unique slug for this format.
	 *
	 * Used as the `$from` / `$to` argument to `bfb_convert()` and as the
	 * registry key. Lowercase, no spaces.
	 *
	 * @return string
	 */
	public function slug(): string;

	/**
	 * Convert content from this format into a block array.
	 *
	 * The returned array must be parse_blocks()-compatible — i.e. an
	 * array of arrays each with `blockName`, `attrs`, `innerBlocks`,
	 * `innerHTML`, and `innerContent` keys.
	 *
	 * @param string               $content Source content in this adapter's format.
	 * @param array<string, mixed> $options Per-call conversion options.
	 * @return array Block array.
	 */
	public function to_blocks( string $content, array $options = array() ): array;

	/**
	 * Convert a block array back into this adapter's format.
	 *
	 * @param array<int|string, array{blockName: string|null, attrs: array, innerBlocks: array<array>, innerHTML: string, innerContent: array}> $blocks  Block array (parse_blocks() shape).
	 * @param array<string, mixed>                                                                                                      $options Per-call conversion options.
	 * @return string Content in this adapter's format.
	 */
	public function from_blocks( array $blocks, array $options = array() ): string;
}

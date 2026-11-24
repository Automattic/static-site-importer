<?php
/**
 * Block structure of an imported site, measured by WordPress itself: every
 * published page, template and template part, through parse_blocks().
 *
 * Run with `wp eval-file structure.php`: prints one JSON object. Loaded with
 * SSI_CORPUS_STRUCTURE_LIBRARY defined, it only defines the functions.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Operator-only corpus measurement run through wp eval-file.

/**
 * Groups, single-child groups and nesting depth of parsed blocks. A
 * single-child group is a core/group with exactly one non-null inner block;
 * depth counts named blocks, a top-level block being depth 1.
 *
 * @param array $blocks parse_blocks() output.
 * @param int   $depth  Depth of the blocks' parent.
 * @return array{groups:int,single_child_groups:int,max_depth:int}
 */
function ssi_corpus_block_structure( array $blocks, int $depth = 0 ): array {
	$out = array( 'groups' => 0, 'single_child_groups' => 0, 'max_depth' => $depth );
	foreach ( $blocks as $block ) {
		if ( empty( $block['blockName'] ) ) {
			continue;
		}
		$inner = $block['innerBlocks'] ?? array();
		if ( 'core/group' === $block['blockName'] ) {
			++$out['groups'];
			$named = array_filter( $inner, static fn( $b ) => ! empty( $b['blockName'] ) );
			if ( 1 === count( $named ) ) {
				++$out['single_child_groups'];
			}
		}
		$child = ssi_corpus_block_structure( $inner, $depth + 1 );
		$out['groups'] += $child['groups'];
		$out['single_child_groups'] += $child['single_child_groups'];
		$out['max_depth'] = max( $out['max_depth'], $depth + 1, $child['max_depth'] );
	}
	return $out;
}

/**
 * Sums the structure of several markup documents: groups add up, depth is the deepest.
 *
 * @param array<string,string> $documents Markup keyed by source (`page:12`, `wp_template:index`).
 */
function ssi_corpus_structure( array $documents ): array {
	$total = array( 'groups' => 0, 'single_child_groups' => 0, 'max_depth' => 0, 'documents' => count( $documents ) );
	foreach ( $documents as $markup ) {
		$one = ssi_corpus_block_structure( parse_blocks( (string) $markup ) );
		$total['groups'] += $one['groups'];
		$total['single_child_groups'] += $one['single_child_groups'];
		$total['max_depth'] = max( $total['max_depth'], $one['max_depth'] );
	}
	return $total;
}

/** The documents an imported site renders: published pages, templates, template parts. */
function ssi_corpus_documents(): array {
	$documents = array();
	foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) ) as $page ) {
		$documents[ 'page:' . $page->ID ] = $page->post_content;
	}
	foreach ( array( 'wp_template', 'wp_template_part' ) as $type ) {
		foreach ( get_block_templates( array(), $type ) as $template ) {
			$documents[ $type . ':' . $template->slug ] = $template->content;
		}
	}
	return $documents;
}

if ( defined( 'ABSPATH' ) && ! defined( 'SSI_CORPUS_STRUCTURE_LIBRARY' ) ) {
	echo wp_json_encode( array( 'stylesheet' => get_stylesheet() ) + ssi_corpus_structure( ssi_corpus_documents() ) ) . "\n";
}

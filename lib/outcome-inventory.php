<?php
/** Execute inside the imported WordPress runtime; return JSON, not rendered output. */

/** parse_blocks() is deliberately permissive: separately account for every delimiter. */
function ssi_outcome_parse_document( $content ) {
	$blocks = parse_blocks( $content );
	$stack  = array();
	$errors = array();
	preg_match_all( '/<!--(.*?)-->/s', $content, $comments, PREG_OFFSET_CAPTURE );
	foreach ( $comments[1] as $comment ) {
		$text = trim( $comment[0] );
		if ( preg_match( '/^(\/?)wp:([a-z0-9_-]+(?:\/[a-z0-9_-]+)?)(?:\s+\{.*\})?\s*(\/?)$/s', $text, $match ) ) {
			if ( '/' === $match[1] ) {
				if ( array_pop( $stack ) !== $match[2] ) {
					$errors[] = array(
						'reason' => 'unmatched_close',
						'offset' => $comment[1],
					);
				}
			} elseif ( empty( $match[3] ) ) {
				$stack[] = $match[2];
			}
		} elseif ( preg_match( '/wp:|^\/$/', $text ) ) {
			$errors[] = array(
				'reason' => 'stray_delimiter',
				'offset' => $comment[1],
			);
		}
	}
	if ( $stack || preg_match( '/<!--\s*\/?wp:(?:(?!-->).)*$/s', $content ) ) {
		$errors[] = array( 'reason' => 'unclosed_delimiter' );
	}
	return array(
		'parser'           => 'parse_blocks',
		'valid'            => empty( $errors ),
		'errors'           => $errors,
		'top_level_blocks' => count( $blocks ),
	);
}

function ssi_collect_outcome_inventory() {
	$types     = array( 'post', 'page', 'wp_template', 'wp_template_part', 'wp_navigation' );
	$documents = array();
	foreach ( $types as $type ) {
		$posts = get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => array_values( get_post_stati() ),
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		foreach ( $posts as $post ) {
			$documents[] = array(
				'id'      => $post->ID,
				'type'    => $type,
				'content' => $post->post_content,
				'parse'   => ssi_outcome_parse_document( $post->post_content ),
			);
		}
	}
	// Include effective file-backed templates/parts, not only database overrides.
	foreach ( array( 'wp_template', 'wp_template_part' ) as $type ) {
		foreach ( get_block_templates( array(), $type ) as $template ) {
			$documents[] = array(
				'id'      => $template->id,
				'type'    => $type,
				'content' => $template->content,
				'parse'   => ssi_outcome_parse_document( $template->content ),
			);
		}
	}
	return array(
		'schema'    => 'static-site-importer/outcome-inventory/v1',
		'types'     => $types,
		'documents' => $documents,
	);
}

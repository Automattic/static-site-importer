<?php
/**
 * Token-scoped authoring probe endpoint staged only in disposable runtimes.
 *
 * @package Static_Site_Importer
 */

// This script owns its request scope; it is not loaded into the theme scope.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
require_once __DIR__ . '/wp-load.php';
header( 'Cache-Control: no-store' );
// The unguessable per-run token authorizes this disposable endpoint.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! hash_equals( 'SSI_AUTHORING_TOKEN', sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) ) ) ) {
	http_response_code( 403 );
	exit;
}
if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!doctype html><html><body></body></html>';
	exit;
}
header( 'Content-Type: application/json' );
$input = json_decode( (string) file_get_contents( 'php://input' ), true );
$input = is_array( $input ) ? $input : array();
/**
 * Reject a failed WordPress operation.
 *
 * @param mixed $value Operation result.
 * @return mixed Successful result.
 * @throws Exception When WordPress rejects the operation.
 */
function ssi_authoring_check( $value ) {
	if ( is_wp_error( $value ) ) {
		throw new Exception( esc_html( $value->get_error_message() ) );
	}
	return $value;
}
/**
 * Describe an imported or authored post.
 *
 * @param WP_Post $post Post to describe.
 * @return array Probe navigation target.
 */
function ssi_authoring_item( $post ) {
	return array(
		'id'    => $post->ID,
		'type'  => $post->post_type,
		'url'   => get_permalink( $post ),
		'title' => get_the_title( $post ),
	);
}
try {
	switch ( $input['operation'] ?? '' ) {
		case 'list':
			$posts = array();
			foreach ( get_posts(
				array(
					'post_type'   => get_post_types( array( 'public' => true ) ),
					'post_status' => 'publish',
					'numberposts' => -1,
					'orderby'     => 'ID',
					'order'       => 'ASC',
				)
			) as $post ) {
				if ( 'attachment' !== $post->post_type ) {
					$posts[] = ssi_authoring_item( $post );
				}
			}
			$terms = array();
			foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
				foreach ( ssi_authoring_check(
					get_terms(
						array(
							'taxonomy'   => $tax->name,
							'hide_empty' => true,
						)
					)
				) as $term ) {
					$url = get_term_link( $term );
					if ( ! is_wp_error( $url ) ) {
						$terms[] = array(
							'id'       => $term->term_id,
							'taxonomy' => $tax->name,
							'type'     => $tax->object_type[0],
							'url'      => $url,
							'title'    => $term->name,
						);
					}
				}
			}
			$result = array(
				'posts' => $posts,
				'terms' => $terms,
			);
			break;
		case 'create':
			$sibling = $input['sibling'];
			$source  = isset( $sibling['taxonomy'] ) ? null : get_post( (int) $sibling['id'] );
			$id      = ssi_authoring_check(
				wp_insert_post(
					array(
						'post_type'      => $source ? $source->post_type : $sibling['type'],
						'post_status'    => 'publish',
						'post_title'     => 'Authoring parity probe',
						'post_content'   => '<!-- wp:paragraph --><p>Probe body.</p><!-- /wp:paragraph -->',
						'comment_status' => $source ? $source->comment_status : 'closed',
						'meta_input'     => array( '_ssi_authoring_probe' => 'SSI_AUTHORING_TOKEN' ),
					),
					true
				)
			);
			if ( $source ) {
				foreach ( get_object_taxonomies( $source->post_type ) as $tax ) {
					ssi_authoring_check( wp_set_object_terms( $id, ssi_authoring_check( wp_get_object_terms( $source->ID, $tax, array( 'fields' => 'ids' ) ) ), $tax ) );
				}
				$result = ssi_authoring_item( get_post( $id ) );
			} else {
				$tax  = $sibling['taxonomy'];
				$term = ssi_authoring_check( wp_insert_term( 'Authoring parity probe ' . wp_generate_uuid4(), $tax, array( 'slug' => 'ssi-authoring-SSI_AUTHORING_TOKEN' ) ) );
				// Persist immediately so a browser failure cannot orphan the term.
				update_option( 'ssi_authoring_terms_SSI_AUTHORING_TOKEN', array( array( $term['term_id'], $tax ) ), false );
				ssi_authoring_check( wp_set_object_terms( $id, array( (int) $term['term_id'] ), $tax ) );
				$probe_term = get_term( $term['term_id'], $tax );
				if ( ! $probe_term instanceof WP_Term ) {
					throw new Exception( 'Probe term is unavailable' );
				}
				$result = array(
					'url'   => ssi_authoring_check( get_term_link( (int) $term['term_id'], $tax ) ),
					'title' => $probe_term->name,
				);
			}
			break;
		case 'clean':
			if ( ! function_exists( 'ssi_authoring_cleanup' ) ) {
				throw new Exception( 'Probe observer is unavailable' );
			}
			ssi_authoring_cleanup();
			$result = true;
			break;
		default:
			$result = true;
	}
	echo wp_json_encode( $result );
} catch ( Throwable $error ) {
	http_response_code( 500 );
	echo wp_json_encode( array( 'error' => $error->getMessage() ) );
}

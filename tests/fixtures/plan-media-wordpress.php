<?php
/**
 * Minimal filesystem-backed WordPress media API for the public plan smoke.
 */
function wp_insert_attachment( array $post, string $file, ...$args ): int {
	unset( $args );
	$id                                = wp_insert_post(
		array_merge(
			$post,
			array(
				'post_type' => 'attachment',
				'post_name' => basename( $file ),
			)
		),
		true
	);
	$GLOBALS['ssi_media_files'][ $id ] = $file;
	return $id;
}
function get_temp_dir(): string {
	return sys_get_temp_dir() . '/';
}
function wp_generate_uuid4(): string {
	return bin2hex( random_bytes( 16 ) );
}
function sanitize_file_name( string $name ): string {
	return basename( $name );
}
function wp_strip_all_tags( string $text ): string {
	return strip_tags( $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Stand-in for the core function itself.
}
function wp_upload_bits( string $name, $unused, string $bytes ): array {
	$dir = $GLOBALS['ssi_plan_root'] . '/uploads';
	wp_mkdir_p( $dir );
	$file = $dir . '/' . wp_unique_filename( $dir, $name );
	file_put_contents( $file, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Isolated upload filesystem stub.
	return array(
		'file'  => $file,
		'error' => false,
	);
}
function wp_unique_filename( string $dir, string $name ): string {
	while ( file_exists( $dir . '/' . $name ) ) {
		$name = 'copy-' . $name;
	}
	return $name;
}
function wp_check_filetype( string $file ): array {
	return array( 'type' => image_type_to_mime_type( exif_imagetype( $file ) ) );
}
function get_attached_file( int $id ): string {
	return $GLOBALS['ssi_media_files'][ $id ];
}
function wp_get_original_image_path( int $id ): string {
	return get_attached_file( $id );
}
function wp_get_attachment_url( int $id ): string {
	return 'https://example.test/uploads/' . basename( get_attached_file( $id ) );
}
function wp_get_original_image_url( int $id ): string {
	return wp_get_attachment_url( $id );
}
function wp_get_attachment_metadata( int $id ): array {
	return $GLOBALS['ssi_media_metadata'][ $id ] ?? array();
}
function wp_update_attachment_metadata( int $id, array $metadata ): void {
	$GLOBALS['ssi_media_metadata'][ $id ] = $metadata;
}
function wp_generate_attachment_metadata( int $id, string $file ): array {
	$size = getimagesize( $file );
	return array(
		'width'  => $size[0],
		'height' => $size[1],
		'file'   => basename( $file ),
		'sizes'  => array(),
	);
}
function wp_getimagesize( string $file ) {
	return getimagesize( $file );
}
function wp_image_matches_ratio( int $w, int $h, int $other_w, int $other_h ): bool {
	return abs( $w / $h - $other_w / $other_h ) < 0.01;
}

function remove_filter( ...$args ): void {
}

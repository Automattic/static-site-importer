<?php
/** Real image bytes prove resize grouping without collapsing distinct crops. */
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/class-static-site-importer-media-library-materializer.php';

$root   = sys_get_temp_dir() . '/ssi-renditions-' . uniqid();
$select = new ReflectionMethod( Static_Site_Importer_Media_Library_Materializer::class, 'largest_rendition' );
$write  = static function ( string $path, int $width, int $height ) use ( $root ): void {
	mkdir( dirname( $root . '/' . $path ), 0777, true );
	$image = imagecreatetruecolor( $width, $height );
	imagepng( $image, $root . '/' . $path );
	unset( $image );
};
$failures = 0;
$assert   = static function ( bool $ok, string $message ) use ( &$failures ): void {
	if ( ! $ok ) { ++$failures; fwrite( STDERR, 'FAIL: ' . $message . "\n" ); }
};
$small = 'media/photo.png/v1/fill/w_48,h_48,al_c/photo.png';
$large = 'media/photo.png/v1/fill/w_144,h_144,al_c/photo.png';
$crop  = 'media/photo.png/v1/crop/x_10,y_20,w_96,h_96/photo.png';
$focus = 'media/photo.png/v1/fill/w_288,h_288,al_t/photo.png';
$wide  = 'media/photo.png/v1/fill/w_192,h_96,al_c/photo.png';
$fit   = 'media/photo.png/v1/fit/w_320,h_320,al_c/photo.png';
try {
	$write( $small, 48, 48 );
	$write( $large, 144, 144 );
	$assert( $large === $select->invoke( null, $root, $small ), 'equivalent resize variants select the largest image' );
	$write( $crop, 96, 96 );
	$write( $focus, 288, 288 );
	$write( $wide, 192, 96 );
	$write( $fit, 320, 320 );
	$assert( $large === $select->invoke( null, $root, $small ), 'resize grouping excludes different crop, focal point, aspect ratio and mode' );
	$assert( $crop === $select->invoke( null, $root, $crop ), 'an explicit crop retains its captured composition' );
	$assert( $focus === $select->invoke( null, $root, $focus ), 'a changed focal point retains its captured composition' );
	$assert( $wide === $select->invoke( null, $root, $wide ), 'a changed aspect ratio retains its captured composition' );
	// The same transaction can bind native fallback images and explicit source
	// families. Their source-path cache must not substitute the promoted file.
	$bind        = new ReflectionMethod( Static_Site_Importer_Media_Library_Materializer::class, 'ensure_attachment' );
	$attachments = array( $small => 7, $large => 9 );
	$state       = $hashes = array();
	$error       = null;
	$arguments   = array( $root, $small, '', &$state, &$attachments, &$hashes, &$error, false );
	$assert( 9 === $bind->invokeArgs( null, $arguments ), 'a native source still selects the largest captured rendition' );
	$arguments[7] = true;
	$assert( 7 === $bind->invokeArgs( null, $arguments ), 'an authored candidate binds its exact rendition in the same transaction' );
	$arguments[7] = false;
	$assert( 9 === $bind->invokeArgs( null, $arguments ), 'an exact candidate cache cannot change subsequent native promotion' );
} finally {
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $root );
}
if ( $failures ) exit( 1 );
echo "Media rendition equivalence passed: 8 assertions\n";

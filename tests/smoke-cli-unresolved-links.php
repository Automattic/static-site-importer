<?php
/**
 * Run the real ZIP import unresolved-link regression in disposable WordPress.
 *
 * @package StaticSiteImporter
 */

passthru( 'node ' . escapeshellarg( __DIR__ . '/smoke-cli-unresolved-links.mjs' ), $status );
exit( $status );

<?php
/**
 * Run the real ZIP import receipt regression in disposable WordPress.
 *
 * @package StaticSiteImporter
 */

passthru( 'node ' . escapeshellarg( __DIR__ . '/smoke-cli-receipt-verdict.mjs' ), $status );
exit( $status );

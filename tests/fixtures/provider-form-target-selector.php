<?php
/**
 * Emit the provider `form` target selector for a scoped Jetpack form.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI fixture: stubs Jetpack and WordPress symbols outside a WordPress runtime.

require_once __DIR__ . '/form-seeder-cli-stubs.php';
$map = Static_Site_Importer_Form_Layout_Projection::provider_layout_target_map(
	array( 'layout_graph' => array( 'nodes' => array( array( 'id' => 'form' ) ) ) ),
	'ssi-form-123456789abc',
	array()
);
echo $map['targets'][0]['selector'] ?? '';

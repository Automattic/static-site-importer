<?php
/**
 * Runtime conversion capability checks.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Static_Site_Importer_Runtime_Capabilities {
	/** Whether the installed Blocks Engine runtime can convert a source format. */
	public static function supports_source_format( string $format ): bool {
		if ( 'markdown' !== $format && 'mdx' !== $format ) {
			return true;
		}

		$adapter = 'Automattic\\BlocksEngine\\PhpTransformer\\FormatBridge\\MarkdownAdapter';
		return class_exists( $adapter ) && $adapter::isAvailable();
	}
}

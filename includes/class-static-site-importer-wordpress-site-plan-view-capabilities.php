<?php
/**
 * Detect optional compact site-plan view support from the installed transformer.
 *
 * @package StaticSiteImporter
 */

final class Static_Site_Importer_WordPress_Site_Plan_View_Capabilities {
	private const VIEW_CLASS = '\\Automattic\\BlocksEngine\\PhpTransformer\\WordPressSitePlan\\WordPressSitePlanView';

	public static function supports_compaction(): bool {
		return class_exists( self::VIEW_CLASS ) && method_exists( self::VIEW_CLASS, 'compact' );
	}

	public static function supports_materialization(): bool {
		return class_exists( self::VIEW_CLASS ) && method_exists( self::VIEW_CLASS, 'materialize' );
	}

	/** @param array<string,mixed> $view @return array<string,mixed> */
	public static function compact( array $view ): array {
		return ( new ( self::VIEW_CLASS )() )->compact( $view );
	}

	/** @param array<string,mixed> $view @return array<string,mixed> */
	public static function materialize( array $view ): array {
		return ( self::VIEW_CLASS )::materialize( $view );
	}
}

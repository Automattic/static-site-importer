<?php
/**
 * Detect optional compact site-plan view support from the installed transformer.
 *
 * @package StaticSiteImporter
 */

final class Static_Site_Importer_WordPress_Site_Plan_View_Capabilities {
	private const VIEW_CLASS = '\\Automattic\\BlocksEngine\\PhpTransformer\\WordPressSitePlan\\WordPressSitePlanView';

	public static function supports_compaction(): bool {
		return class_exists( self::view_class() ) && method_exists( self::view_class(), 'compact' );
	}

	public static function supports_materialization(): bool {
		return class_exists( self::view_class() ) && method_exists( self::view_class(), 'materialize' );
	}

	/** @param array<string,mixed> $view @return array<string,mixed> */
	public static function compact( array $view ): array {
		$class    = self::view_class();
		$callback = array( new $class(), 'compact' );
		if ( ! is_callable( $callback ) ) {
			throw new RuntimeException( 'The installed Blocks Engine php-transformer cannot compact WordPress site plan views.' );
		}
		return call_user_func( $callback, $view );
	}

	/** @param array<string,mixed> $view @return array<string,mixed> */
	public static function materialize( array $view ): array {
		$callback = array( self::view_class(), 'materialize' );
		if ( ! is_callable( $callback ) ) {
			throw new RuntimeException( 'The installed Blocks Engine php-transformer cannot materialize compact WordPress site plan views.' );
		}
		return call_user_func( $callback, $view );
	}

	private static function view_class(): string {
		return self::VIEW_CLASS;
	}
}

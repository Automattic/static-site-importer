<?php
/**
 * The single owner of the Blocks Engine compiler contract for imports.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve compiler limits for every import source.
 *
 * Without a declared contract the compiler applies its own 500-file default and
 * drops the rest of a larger site, so every intake carries these ceilings. An
 * intake with a tighter verified bound passes it; bounds are clamped to the
 * compiler's hard caps and never widen them.
 */
final class Static_Site_Importer_Compiler_Limits {

	/** Blocks Engine ArtifactNormalizer hard caps. */
	private const HARD_CAPS = array(
		'max_files'       => 5000,
		'max_file_bytes'  => 10485760,
		'max_total_bytes' => 335544320,
	);

	/**
	 * @param array<string,int> $bounds Optional tighter intake bounds keyed like the result.
	 * @return array{max_files:int,max_file_bytes:int,max_total_bytes:int}
	 */
	public static function resolve( array $bounds = array() ): array {
		$limits = self::HARD_CAPS;
		foreach ( $limits as $key => $maximum ) {
			if ( isset( $bounds[ $key ] ) ) {
				$limits[ $key ] = min( $maximum, max( 1, (int) $bounds[ $key ] ) );
			}
		}

		return $limits;
	}
}

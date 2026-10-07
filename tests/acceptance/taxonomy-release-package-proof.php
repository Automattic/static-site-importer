<?php
/** Verify the immutable Composer package identity inside a release-proof workload. */
if ( ! function_exists( 'ssi_taxonomy_release_package_proof' ) ) {
	/**
	 * Read the package identity recorded by the Composer installation loaded by WordPress.
	 *
	 * @return array<string,string>|null
	 */
	function ssi_taxonomy_release_package_proof(): ?array {
		if ( ! defined( 'SSI_TAXONOMY_RELEASE_PACKAGE' ) || true !== SSI_TAXONOMY_RELEASE_PACKAGE ) {
			return null;
		}

		$package          = 'automattic/blocks-engine-php-transformer';
		$installed        = require dirname( __DIR__, 2 ) . '/vendor/composer/installed.php';
		$record           = $installed['versions'][ $package ] ?? array();
		$version          = (string) ( $record['pretty_version'] ?? '' );
		$source           = (string) ( $record['reference'] ?? '' );
		$expected_version = (string) ( defined( 'SSI_TAXONOMY_RELEASE_VERSION' ) ? SSI_TAXONOMY_RELEASE_VERSION : '' );
		$expected_source  = (string) ( defined( 'SSI_TAXONOMY_RELEASE_REFERENCE' ) ? SSI_TAXONOMY_RELEASE_REFERENCE : '' );

		if ( '' === $expected_version || '' === $expected_source || 'v' . $expected_version !== $version || $expected_source !== $source ) {
			throw new RuntimeException( 'The loaded PHP transformer package does not match the release proof lock identity.' );
		}

		return array(
			'package'          => $package,
			'version'          => $version,
			'source_reference' => $source,
		);
	}
}

<?php
/** Neutral examples carried through actual inline, filesystem, and ZIP intakes. */
// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone fixtures exercise actual filesystem readers without a site bootstrap.

/**
 * Exercise source admission using physical transport inputs.
 *
 * @param callable $check Intake assertion callback.
 */
function ssi_test_content_policy_intakes( callable $check ): void {
	$root = sys_get_temp_dir() . '/ssi-policy-intakes-' . bin2hex( random_bytes( 6 ) );
	mkdir( $root );
	$cases = array(
		'tutorial'      => array( '<!doctype html><html><body><h1>Tutorial</h1><button data-code="<?php echo 1; ?>">Copy</button></body></html>', true ),
		'php-source'    => array( '<!doctype html><html><body><?php echo 1; ?></body></html>', false ),
		'script-source' => array( '<!doctype html><html><body><script>const example = "<span data-code=\'<?php echo 1; ?>\'>";</script></body></html>', false ),
		'style-source'  => array( '<!doctype html><html><body><style>p::before { content: "<span data-code=\'<?php echo 1; ?>\'>"; }</style></body></html>', false ),
	);
	try {
		foreach ( $cases as $label => list( $html, $accepted ) ) {
			file_put_contents( $root . '/index.html', $html );
			$directory = static_site_importer_cli_request_bundle_files( $root );
			if ( is_wp_error( $directory ) ) {
				throw new RuntimeException( 'Cannot create directory test input: ' . $directory->get_error_code() );
			}
			$zip_path = $root . '/source.zip';
			$zip      = new ZipArchive();
			if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
				throw new RuntimeException( 'Cannot create ZIP test input.' );
			}
			$zip->addFromString( 'index.html', $html );
			$zip->close();
			$archive = array(
				'name'        => 'source.zip',
				'staged_path' => $zip_path,
			);

			$zip_files  = static_site_importer_staged_archive_files( $archive, true );
			$zip_reader = static_site_importer_staged_archive_payload_reader( $archive );
			if ( is_wp_error( $zip_files ) || is_wp_error( $zip_reader ) ) {
				throw new RuntimeException( 'Cannot read ZIP test input.' );
			}
			$inline_file = array(
				'path'    => 'index.html',
				'content' => $html,
			);

			$intakes = array(
				'inline'    => array( array( $inline_file ), null ),
				'directory' => array( $directory['files'], $directory['payload_reader'] ),
				'zip'       => array( $zip_files, $zip_reader ),
			);
			foreach ( $intakes as $transport => list( $files, $reader ) ) {
				$source  = array(
					'files'      => $files,
					'entrypoint' => 'index.html',
				);
				$runtime = static_site_importer_source_runtime( $source, $reader );
				// Preserve rejected bytes to exercise the later guards independently
				// of normalization. Their bytes still come from physical intake.
				$artifact_files = $files;
				foreach ( $artifact_files as &$file ) {
					$file['path'] = static_site_importer_rest_artifact_path( $file['path'] );
				}
				unset( $file );
				$artifact = array(
					'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
					'entrypoint' => 'website/index.html',
					'files'      => $artifact_files,
				);
				$check( $label . ':' . $transport, $accepted, $runtime, $artifact, $reader );
			}
			// ZIP intake normally references media only; exercise its same real
			// reader with referenced textual source as an independent contract.
			$zip_reference = array(
				'schema' => 'blocks-engine/payload-reference/v1',
				'id'     => 'zip-entry:index.html',
				'bytes'  => strlen( $html ),
				'sha256' => hash( 'sha256', $html ),
			);

			$reference_file = array(
				'path'              => 'index.html',
				'payload_reference' => $zip_reference,
			);

			$source  = array( 'files' => array( $reference_file ) );
			$runtime = static_site_importer_source_runtime( $source, $zip_reader );

			$source['files'][0]['path'] = 'website/index.html';

			$artifact = array(
				'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
				'entrypoint' => 'website/index.html',
				'files'      => $source['files'],
			);
			$check( $label . ':zip-reference', $accepted, $runtime, $artifact, $zip_reader );
			unlink( $zip_path );
		}
	} finally {
		foreach ( array( '/source.zip', '/index.html' ) as $path ) {
			if ( is_file( $root . $path ) ) {
				unlink( $root . $path );
			}
		}
		rmdir( $root );
	}
}

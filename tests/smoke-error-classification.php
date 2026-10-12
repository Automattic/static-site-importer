<?php
/**
 * Error classification guard.
 *
 * Every `new WP_Error(` and `throw new` site in includes/*.php must be
 * classified in tests/fixtures/error-classification.json, so that no new
 * limit can fail an import by accident. See Automattic/static-site-importer#2054.
 *
 * - A WP_Error with a literal first-argument code is keyed by that code.
 * - A WP_Error with a computed code, and every `throw new`, is keyed by
 *   `<file>#<normalized expression>` and carries a
 *   `// ssi-error-class: <class>` comment on its line or the line above.
 * - Registry entries whose site no longer exists fail (ratchet).
 * - Representability sites sit on a pending list. The pending list plus the
 *   converted list always equals REPRESENTABILITY_BASELINE, so the pending
 *   list can only shrink, and each removal needs a converted record naming the
 *   smoke that proves the finding reaches Receipt_Projection::compose().
 *
 * Run from the repository root (no WordPress needed):
 * php tests/smoke-error-classification.php
 * php tests/smoke-error-classification.php --list
 *
 * @package StaticSiteImporter
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions -- Standalone CLI smoke; WordPress is not loaded.

const SSI_ERROR_CLASSIFICATION_SCHEMA = 'static-site-importer/error-classification/v1';

/**
 * Representability registry keys known when the guard was introduced (45 keys
 * covering 72 sites). Pending plus converted must equal this number; lower it
 * only to retire a key whose site was deleted rather than converted.
 */
const SSI_REPRESENTABILITY_BASELINE = 45;

const SSI_ERROR_CLASSES = array( 'integrity', 'operational', 'representability', 'propagated', 'programmer' );
const SSI_ERROR_UNITS   = array( 'item', 'block', 'page', 'route', 'asset' );
const SSI_ERROR_LAYERS  = array( 'static-site-importer', 'blocks-engine', 'data-liberation-agent', 'wordpress' );

/**
 * Normalize a token list into a whitespace-free expression string.
 *
 * @param array<int,array{0:int|string,1:string}> $tokens Tokens.
 * @return string
 */
function ssi_error_classification_normalize( array $tokens ): string {
	$out = '';
	foreach ( $tokens as $token ) {
		$text = $token[1];
		if ( '' !== $out && preg_match( '/[\w$]$/', $out ) && preg_match( '/^[\w$]/', $text ) ) {
			$out .= ' ';
		}
		$out .= $text;
	}

	return $out;
}

/**
 * Find every classified site in one PHP file.
 *
 * @param string $relative Repository-relative path.
 * @param string $source   File contents.
 * @return array<int,array<string,mixed>>
 */
function ssi_error_classification_file_sites( string $relative, string $source ): array {
	$raw         = token_get_all( $source );
	$tokens      = array();
	$annotations = array();
	$line        = 1;
	foreach ( $raw as $token ) {
		if ( is_array( $token ) ) {
			list( $id, $text, $line ) = $token;
		} else {
			$id   = $token;
			$text = $token;
		}
		if ( T_COMMENT === $id || T_DOC_COMMENT === $id ) {
			if ( preg_match( '/ssi-error-class:\s*([a-z]+(?::[a-z]+)?)/', $text, $match ) ) {
				$annotations[ $line ] = $match[1];
			}
		} elseif ( T_WHITESPACE !== $id ) {
			$tokens[] = array( $id, $text, $line );
		}
		$line += substr_count( $text, "\n" );
	}

	$sites = array();
	$count = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		if ( T_NEW !== $tokens[ $i ][0] || ! isset( $tokens[ $i + 1 ] ) ) {
			continue;
		}
		$class    = ltrim( $tokens[ $i + 1 ][1], '\\' );
		$is_throw = $i > 0 && T_THROW === $tokens[ $i - 1 ][0];
		if ( ! $is_throw && 'WP_Error' !== $class ) {
			continue;
		}

		// Collect the first constructor argument.
		$first = array();
		if ( isset( $tokens[ $i + 2 ] ) && '(' === $tokens[ $i + 2 ][1] ) {
			$depth = 0;
			for ( $j = $i + 3; $j < $count; $j++ ) {
				$text = $tokens[ $j ][1];
				if ( in_array( $text, array( '(', '[', '{', '${' ), true ) || T_CURLY_OPEN === $tokens[ $j ][0] ) {
					++$depth;
				} elseif ( in_array( $text, array( ')', ']', '}' ), true ) ) {
					if ( 0 === $depth ) {
						break;
					}
					--$depth;
				} elseif ( ',' === $text && 0 === $depth ) {
					break;
				}
				$first[] = $tokens[ $j ];
			}
		}

		$site_line = $tokens[ $is_throw ? $i - 1 : $i ][2];
		$site      = array(
			'file'       => $relative,
			'line'       => $site_line,
			'kind'       => $is_throw ? 'throw' : 'wp_error',
			'annotation' => $annotations[ $site_line ] ?? $annotations[ $site_line - 1 ] ?? null,
		);
		if ( ! $is_throw && 1 === count( $first ) && T_CONSTANT_ENCAPSED_STRING === $first[0][0] ) {
			$site['key']     = stripcslashes( substr( $first[0][1], 1, -1 ) );
			$site['literal'] = true;
		} else {
			$expression      = ( $is_throw ? 'throw new ' . $class : 'new WP_Error' ) . '(' . ssi_error_classification_normalize( $first ) . ')';
			$site['key']     = $relative . '#' . $expression;
			$site['literal'] = false;
		}
		$sites[] = $site;
	}

	return $sites;
}

/**
 * Scan includes/*.php.
 *
 * @param string $root Repository root.
 * @return array<int,array<string,mixed>>
 */
function ssi_error_classification_sites( string $root ): array {
	$files = glob( $root . '/includes/*.php' );
	sort( $files );
	$sites = array();
	foreach ( $files as $file ) {
		$relative = substr( $file, strlen( $root ) + 1 );
		$sites    = array_merge( $sites, ssi_error_classification_file_sites( $relative, (string) file_get_contents( $file ) ) );
	}

	return $sites;
}

/**
 * The annotation string a registry entry requires.
 *
 * @param array<string,mixed> $entry Registry entry.
 * @return string
 */
function ssi_error_classification_annotation( array $entry ): string {
	return 'representability' === ( $entry['class'] ?? '' ) ? 'representability:' . ( $entry['unit'] ?? '' ) : (string) ( $entry['class'] ?? '' );
}

/**
 * Check sites against the registry.
 *
 * @param array<int,array<string,mixed>> $sites    Scanned sites.
 * @param array<string,mixed>            $registry Decoded registry.
 * @param string                         $root     Repository root.
 * @return array<int,string> Failures.
 */
function ssi_error_classification_check( array $sites, array $registry, string $root ): array {
	$failures  = array();
	$entries   = is_array( $registry['sites'] ?? null ) ? $registry['sites'] : array();
	$pending   = is_array( $registry['representability_pending'] ?? null ) ? $registry['representability_pending'] : array();
	$converted = is_array( $registry['representability_converted'] ?? null ) ? $registry['representability_converted'] : array();

	if ( SSI_ERROR_CLASSIFICATION_SCHEMA !== ( $registry['schema'] ?? null ) ) {
		$failures[] = 'registry schema must be ' . SSI_ERROR_CLASSIFICATION_SCHEMA;
	}

	foreach ( $entries as $key => $entry ) {
		$class = $entry['class'] ?? null;
		if ( ! in_array( $class, SSI_ERROR_CLASSES, true ) ) {
			$failures[] = sprintf( '%s: class must be one of %s', $key, implode( '|', SSI_ERROR_CLASSES ) );
		}
		if ( ! in_array( $entry['owning_layer'] ?? null, SSI_ERROR_LAYERS, true ) ) {
			$failures[] = sprintf( '%s: owning_layer must be one of %s', $key, implode( '|', SSI_ERROR_LAYERS ) );
		}
		if ( 'representability' === $class ) {
			if ( ! in_array( $entry['unit'] ?? null, SSI_ERROR_UNITS, true ) ) {
				$failures[] = sprintf( '%s: representability needs unit %s', $key, implode( '|', SSI_ERROR_UNITS ) );
			}
			if ( in_array( $key, $pending, true ) === isset( $converted[ $key ] ) ) {
				$failures[] = sprintf( '%s: representability must be on exactly one of representability_pending or representability_converted', $key );
			}
		} elseif ( isset( $entry['unit'] ) ) {
			$failures[] = sprintf( '%s: unit applies only to representability', $key );
		}
	}

	// Unregistered sites and annotations that are missing or disagree.
	$live = array();
	foreach ( $sites as $site ) {
		$where        = $site['file'] . ':' . $site['line'];
		$key          = $site['key'];
		$live[ $key ] = true;
		if ( ! isset( $entries[ $key ] ) ) {
			$failures[] = sprintf( '%s: unregistered error site %s; add it to tests/fixtures/error-classification.json', $where, json_encode( $key, JSON_UNESCAPED_SLASHES ) );
			continue;
		}
		$expected = ssi_error_classification_annotation( $entries[ $key ] );
		if ( ! $site['literal'] && null === $site['annotation'] ) {
			$failures[] = sprintf( '%s: computed or thrown error site needs "// ssi-error-class: %s" on its line or the line above', $where, $expected );
		} elseif ( null !== $site['annotation'] && $site['annotation'] !== $expected ) {
			$failures[] = sprintf( '%s: annotation "%s" disagrees with registry class "%s"', $where, $site['annotation'], $expected );
		}
	}

	foreach ( array_keys( $entries ) as $key ) {
		if ( ! isset( $live[ $key ] ) ) {
			$failures[] = sprintf( 'stale registry entry %s: its site no longer exists; remove it (record a representability conversion under representability_converted)', json_encode( (string) $key, JSON_UNESCAPED_SLASHES ) );
		}
	}

	// Representability ratchet.
	foreach ( $pending as $key ) {
		if ( 'representability' !== ( $entries[ $key ]['class'] ?? null ) ) {
			$failures[] = sprintf( 'representability_pending %s is not a live representability site', json_encode( $key, JSON_UNESCAPED_SLASHES ) );
		}
	}
	if ( count( $pending ) !== count( array_unique( $pending ) ) ) {
		$failures[] = 'representability_pending has duplicate keys';
	}
	$manifest = (string) file_get_contents( $root . '/test-manifest.json' );
	foreach ( $converted as $key => $record ) {
		foreach ( array( 'finding_code', 'unit', 'owning_layer', 'proof_smoke' ) as $field ) {
			if ( ! is_string( $record[ $field ] ?? null ) || '' === $record[ $field ] ) {
				$failures[] = sprintf( 'representability_converted %s: %s is required', $key, $field );
				continue 2;
			}
		}
		$proof = $root . '/' . $record['proof_smoke'];
		if ( ! is_file( $proof ) ) {
			$failures[] = sprintf( 'representability_converted %s: proof smoke %s does not exist', $key, $record['proof_smoke'] );
		} else {
			$proof_source = (string) file_get_contents( $proof );
			foreach ( array( $record['finding_code'], 'Receipt_Projection' ) as $needle ) {
				if ( ! str_contains( $proof_source, $needle ) ) {
					$failures[] = sprintf( 'representability_converted %s: proof smoke %s never mentions %s', $key, $record['proof_smoke'], $needle );
				}
			}
		}
		if ( ! str_contains( $manifest, '"' . $record['proof_smoke'] . '"' ) ) {
			$failures[] = sprintf( 'representability_converted %s: proof smoke %s is not registered in test-manifest.json', $key, $record['proof_smoke'] );
		}
	}
	$total = count( $pending ) + count( $converted );
	if ( SSI_REPRESENTABILITY_BASELINE !== $total ) {
		$failures[] = sprintf(
			'representability ratchet: pending (%d) + converted (%d) = %d, expected %d. A new representability site must degrade to a finding instead of failing; a removed pending site needs a representability_converted record with its proof smoke.',
			count( $pending ),
			count( $converted ),
			$total,
			SSI_REPRESENTABILITY_BASELINE
		);
	}

	return $failures;
}

$root  = dirname( __DIR__ );
$sites = ssi_error_classification_sites( $root );

if ( in_array( '--list', $argv, true ) ) {
	foreach ( $sites as $site ) {
		echo json_encode( $site, JSON_UNESCAPED_SLASHES ), "\n";
	}
	exit( 0 );
}

$registry = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/error-classification.json' ), true );
$failures = ssi_error_classification_check( $sites, is_array( $registry ) ? $registry : array(), $root );

$by_class = array_fill_keys( SSI_ERROR_CLASSES, array() );
$throws   = 0;
foreach ( $sites as $site ) {
	$class                = $registry['sites'][ $site['key'] ]['class'] ?? 'unregistered';
	$by_class[ $class ][] = $site['key'];
	$throws              += 'throw' === $site['kind'] ? 1 : 0;
}
printf( "error sites: %d (%d WP_Error, %d throw)\n", count( $sites ), count( $sites ) - $throws, $throws );
foreach ( $by_class as $class => $keys ) {
	printf( "  %-17s %3d sites, %3d keys\n", $class, count( $keys ), count( array_unique( $keys ) ) );
}
printf( "representability pending: %d; converted: %d\n", count( $registry['representability_pending'] ?? array() ), count( $registry['representability_converted'] ?? array() ) );

if ( array() !== $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, 'FAIL ' . $failure . "\n" );
	}
	exit( 1 );
}

echo "error classification guard passed\n";

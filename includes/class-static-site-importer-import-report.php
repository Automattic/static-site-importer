<?php
/**
 * Typed import-report envelope.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declares the import-report top-level schema and owns mutation of that envelope.
 *
 * Three factories historically built `static-site-importer/import-report/v1`
 * arrays (conversion, site-plan receipt, failed-plan) and then 8 classes
 * mutated the result by reference. This object is the single declared shape:
 * unknown top-level keys throw, known keys are writable, and `to_array()` is
 * the persistence/REST seam so serialized output stays an array.
 */
final class Static_Site_Importer_Import_Report implements ArrayAccess, JsonSerializable {

	public const SCHEMA = 'static-site-importer/import-report/v1';

	/**
	 * Every top-level key the import report may carry.
	 *
	 * Union of the conversion factory, the site-plan receipt factory, the
	 * failed-plan factory, and keys written during finalization. Nested
	 * structure inside these keys is owned by the writer; this list is the
	 * top-level contract.
	 *
	 * @var array<int,string>
	 */
	public const TOP_LEVEL_KEYS = array(
		'artifact_diagnostics',
		'asset_map',
		'assets',
		'blocks_engine',
		'client_script_policy',
		'commerce',
		'commerce_context',
		'compact_summary',
		'companion_plugin_materialization',
		'companion_plugins',
		'conversion_fragments',
		'diagnostic_count',
		'diagnostics',
		'diagnostics_truncated',
		'entity_lifecycle',
		'entry_file',
		'failure_context',
		'fallback_reconciliation',
		'finding_packets',
		'generated_theme',
		'import_run_id',
		'import_validation_result',
		'materialization_receipt',
		'materialized_content',
		'notes',
		'plan_identity',
		'plugin_materialization',
		'product_finding_seeding',
		'product_seeding',
		'quality',
		'quality_budget_admission',
		'quality_resolutions',
		'schema',
		'semantic_fidelity',
		'source',
		'source_artifact',
		'source_documents',
		'source_of_truth',
		'source_region_selection',
		'status',
		'theme_materialization',
		'theme_slug',
		'version',
		'visual_fidelity',
		'visual_parity_artifacts',
	);

	/**
	 * @var array<string,mixed>
	 */
	private array $data = array();

	/**
	 * Placeholder returned by reference when a known key is missing.
	 *
	 * @var mixed
	 */
	private $missing = null;

	/**
	 * @param array<string,mixed> $data Initial envelope.
	 */
	private function __construct( array $data ) {
		foreach ( $data as $key => $value ) {
			if ( ! is_string( $key ) ) {
				throw new InvalidArgumentException( 'Import report keys must be strings.' );
			}
			$this->data[ $key ] = $value;
		}
	}

	/**
	 * Wrap an existing array envelope.
	 *
	 * Unknown keys already present are preserved so older reports still load;
	 * subsequent writes of a *new* unknown key throw.
	 *
	 * @param array<string,mixed> $data Existing envelope.
	 */
	public static function from_array( array $data ): self {
		return new self( $data );
	}

	/**
	 * Coerce an array or report object to a report object.
	 *
	 * @param array<string,mixed>|self $report Envelope.
	 */
	public static function from( array|self $report ): self {
		return $report instanceof self ? $report : self::from_array( $report );
	}

	/**
	 * Persistable array form. Byte-identical to the historical envelope.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return $this->data;
	}

	/**
	 * Whether a top-level key is part of the declared schema.
	 */
	public static function is_known_key( string $key ): bool {
		return in_array( $key, self::TOP_LEVEL_KEYS, true );
	}

	/**
	 * Append a diagnostic row.
	 *
	 * @param array<string,mixed> $diagnostic Diagnostic row.
	 */
	public function append_diagnostic( array $diagnostic ): void {
		if ( ! isset( $this->data['diagnostics'] ) || ! is_array( $this->data['diagnostics'] ) ) {
			$this->data['diagnostics'] = array();
		}
		$this->data['diagnostics'][] = $diagnostic;
	}

	public function offsetExists( mixed $offset ): bool {
		return is_string( $offset ) && array_key_exists( $offset, $this->data );
	}

	public function &offsetGet( mixed $offset ): mixed {
		if ( ! is_string( $offset ) ) {
			throw new InvalidArgumentException( 'Import report keys must be strings.' );
		}
		if ( array_key_exists( $offset, $this->data ) ) {
			return $this->data[ $offset ];
		}
		$this->missing = null;
		return $this->missing;
	}

	public function offsetSet( mixed $offset, mixed $value ): void {
		if ( ! is_string( $offset ) ) {
			throw new InvalidArgumentException( 'Import report keys must be strings.' );
		}
		if ( ! self::is_known_key( $offset ) && ! array_key_exists( $offset, $this->data ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown import-report top-level key "%s".', $offset ) );
		}
		$this->data[ $offset ] = $value;
	}

	public function offsetUnset( mixed $offset ): void {
		if ( is_string( $offset ) ) {
			unset( $this->data[ $offset ] );
		}
	}
}

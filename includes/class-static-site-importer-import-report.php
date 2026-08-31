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
 * Reads may use ArrayAccess. Writes go through named mutators so nested
 * `$report['quality']['x'] = 0` cannot bypass the schema. `to_array()` is the
 * persistence/REST seam.
 */
final class Static_Site_Importer_Import_Report implements ArrayAccess, JsonSerializable {

	public const SCHEMA = 'static-site-importer/import-report/v1';

	/**
	 * Every top-level key the import report may carry.
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
		'owner_handoff_evidence',
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
	 * @param array<mixed> $data Initial envelope.
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
	 * @param array<mixed> $data Existing envelope.
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
	 * Persistable array form.
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

	public static function is_known_key( string $key ): bool {
		return in_array( $key, self::TOP_LEVEL_KEYS, true );
	}

	public function has( string $key ): bool {
		return array_key_exists( $key, $this->data );
	}

	/**
	 * Read a top-level value.
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $fallback;
	}

	/**
	 * Replace a top-level value. Only declared keys are writable.
	 *
	 * Undeclared keys carried in by {@see from_array()} stay readable and
	 * round-trip through {@see to_array()}, but cannot be written, so loading a
	 * stale envelope never launders a key into the schema.
	 */
	public function set( string $key, mixed $value ): void {
		if ( ! self::is_known_key( $key ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal schema violation message; this class runs before any WordPress escaping API is guaranteed.
			throw new InvalidArgumentException( sprintf( 'Unknown import-report top-level key "%s".', $key ) );
		}
		$this->data[ $key ] = $value;
	}

	/**
	 * Read a top-level array section.
	 *
	 * @return array<string,mixed>
	 */
	public function section( string $key ): array {
		$value = $this->get( $key, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Replace a top-level array section.
	 *
	 * @param array<string,mixed> $section Section payload.
	 */
	public function set_section( string $key, array $section ): void {
		$this->set( $key, $section );
	}

	/**
	 * Overlay keys onto a top-level array section.
	 *
	 * @param array<string,mixed> $values Values to merge.
	 */
	public function merge_section( string $key, array $values ): void {
		$this->set_section( $key, array_replace( $this->section( $key ), $values ) );
	}

	/**
	 * Set one child of a top-level array section.
	 */
	public function set_in_section( string $key, string $child, mixed $value ): void {
		$section           = $this->section( $key );
		$section[ $child ] = $value;
		$this->set_section( $key, $section );
	}

	/**
	 * Append a row onto a list nested under a top-level section.
	 *
	 * @param array<string,mixed> $row Row to append.
	 */
	public function append_to_section( string $key, string $list_key, array $row ): void {
		$section              = $this->section( $key );
		$list                 = isset( $section[ $list_key ] ) && is_array( $section[ $list_key ] ) ? $section[ $list_key ] : array();
		$list[]               = $row;
		$section[ $list_key ] = $list;
		$this->set_section( $key, $section );
	}

	/**
	 * Diagnostic rows. Entries are not type-narrowed: callers walk heterogeneous
	 * envelopes loaded from persisted JSON.
	 *
	 * @return array<int,mixed>
	 */
	public function diagnostics(): array {
		$diagnostics = $this->get( 'diagnostics', array() );
		return is_array( $diagnostics ) ? array_values( $diagnostics ) : array();
	}

	/**
	 * @param array<int,mixed> $diagnostics Diagnostic rows.
	 */
	public function set_diagnostics( array $diagnostics ): void {
		$this->set( 'diagnostics', array_values( $diagnostics ) );
	}

	/**
	 * @param array<string,mixed> $diagnostic Diagnostic row.
	 */
	public function append_diagnostic( array $diagnostic ): void {
		$diagnostics   = $this->diagnostics();
		$diagnostics[] = $diagnostic;
		$this->set_diagnostics( $diagnostics );
	}

	/**
	 * @param callable(mixed):bool $keep Predicate.
	 */
	public function filter_diagnostics( callable $keep ): void {
		$this->set_diagnostics( array_values( array_filter( $this->diagnostics(), $keep ) ) );
	}

	/**
	 * @param array<mixed> $diagnostic Replacement row.
	 */
	public function replace_diagnostic( int $index, array $diagnostic ): void {
		$diagnostics = $this->diagnostics();
		if ( ! array_key_exists( $index, $diagnostics ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal schema violation message; this class runs before any WordPress escaping API is guaranteed.
			throw new InvalidArgumentException( sprintf( 'Import report has no diagnostic at index %d.', $index ) );
		}
		$diagnostics[ $index ] = $diagnostic;
		$this->set_diagnostics( $diagnostics );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function quality(): array {
		return $this->section( 'quality' );
	}

	/**
	 * @param array<string,mixed> $quality Quality payload.
	 */
	public function set_quality( array $quality ): void {
		$this->set_section( 'quality', $quality );
	}

	/**
	 * @param array<string,mixed> $quality Quality overlay.
	 */
	public function merge_quality( array $quality ): void {
		$this->merge_section( 'quality', $quality );
	}

	public function increment_quality( string $metric, int $amount = 1 ): void {
		$quality            = $this->quality();
		$quality[ $metric ] = (int) ( $quality[ $metric ] ?? 0 ) + $amount;
		$this->set_quality( $quality );
	}

	public function offsetExists( mixed $offset ): bool {
		return is_string( $offset ) && array_key_exists( $offset, $this->data );
	}

	/**
	 * Read-only. Nested `$report['quality']['x'] = 0` does not persist; use mutators.
	 */
	public function offsetGet( mixed $offset ): mixed {
		if ( ! is_string( $offset ) ) {
			throw new InvalidArgumentException( 'Import report keys must be strings.' );
		}
		return $this->get( $offset );
	}

	public function offsetSet( mixed $offset, mixed $value ): void {
		if ( ! is_string( $offset ) ) {
			throw new InvalidArgumentException( 'Import report keys must be strings.' );
		}
		$this->set( $offset, $value );
	}

	public function offsetUnset( mixed $offset ): void {
		if ( is_string( $offset ) ) {
			unset( $this->data[ $offset ] );
		}
	}
}

<?php
/**
 * Client-script policy report.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records client-script dispositions under a closed key set.
 *
 * Replaces the previous `$report[ $disposition ][] = $row` dynamic write, which
 * allowed any string to become a top-level report key.
 */
final class Static_Site_Importer_Client_Script_Policy_Report {

	public const SCHEMA       = 'static-site-importer/client-script-policy-report/v1';
	public const DISPOSITIONS = array( 'dropped', 'quarantined', 'preserved' );

	/**
	 * @param string                         $policy      Applied policy name.
	 * @param string                         $trust       Trust classification.
	 * @param string                         $provenance  Provenance reference.
	 * @param array<int,array<string,mixed>> $dropped     Dropped rows.
	 * @param array<int,array<string,mixed>> $quarantined Quarantined rows.
	 * @param array<int,array<string,mixed>> $preserved   Preserved rows.
	 */
	public function __construct(
		private string $policy,
		private string $trust,
		private string $provenance,
		private array $dropped = array(),
		private array $quarantined = array(),
		private array $preserved = array()
	) {}

	/**
	 * Record one script row under a declared disposition.
	 *
	 * @param string              $disposition One of DISPOSITIONS.
	 * @param array<string,mixed> $row         Script row.
	 */
	public function record( string $disposition, array $row ): void {
		if ( ! in_array( $disposition, self::DISPOSITIONS, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal contract violation message; this class runs before any WordPress escaping API is guaranteed.
			throw new InvalidArgumentException( sprintf( 'Unknown client-script disposition "%s".', $disposition ) );
		}

		$this->{$disposition}[] = $row;
	}

	/**
	 * Persistable array form.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'schema'      => self::SCHEMA,
			'policy'      => $this->policy,
			'trust'       => $this->trust,
			'provenance'  => $this->provenance,
			'dropped'     => $this->dropped,
			'quarantined' => $this->quarantined,
			'preserved'   => $this->preserved,
		);
	}
}

<?php
/**
 * Prepared provider form and its single representability decision.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-static-site-importer-form-mapping-decision.php';

/** Keeps planning facts immutable while lazily serializing their proposed markup. */
final class Static_Site_Importer_Form_Mapping_Plan {
	/** @var array<string,mixed>|null Null for an early topology/control rejection. */
	private readonly ?array $decision;

	/** @var array<string,mixed>|null Cached only when markup is actually requested. */
	private ?array $proposed_row = null;

	/**
	 * @param array<string,mixed>      $source_form Normalized producer form.
	 * @param array<int,array>         $fields Planned blocks keyed by source control.
	 * @param array<string,mixed>|null $contact_form Complete, unserialized provider block.
	 * @param array<string,mixed>|null $source_shell Source-owned containing shell.
	 * @param array<string,mixed>      $report_facts Receipt, destinations and capabilities.
	 */
	private function __construct(
		public readonly array $source_form,
		public readonly array $fields,
		public readonly ?array $contact_form,
		public readonly ?array $source_shell,
		public readonly array $report_facts
	) {
		$this->decision = null === $contact_form ? null : Static_Site_Importer_Form_Mapping_Decision::resolve( $this );
	}

	/** Return a terminal control/topology rejection without an emission candidate. */
	public static function skipped( array $source_form, array $row ): self {
		return new self( $source_form, array(), null, null, $row );
	}

	/** Resolve support from planned fields, destinations and losses before emission. */
	public static function prepared( array $source_form, array $fields, array $contact_form, ?array $source_shell, array $report_facts ): self {
		return new self( $source_form, $fields, $contact_form, $source_shell, $report_facts );
	}

	/** @return array<string,mixed>|null Canonical support decision. */
	public function decision(): ?array {
		return $this->decision;
	}

	/** Source visual state is useful only when the provider representation was admitted. */
	public function is_mapped(): bool {
		return 'mapped' === ( $this->decision['status'] ?? '' );
	}

	/**
	 * The loss-waiver hook's established input includes proposed markup, but no
	 * decision. Serialize on demand for that hook and reuse the same bytes in the
	 * final report. Ordinary planning does not request this row.
	 *
	 * @return array<string,mixed>
	 */
	public function proposed_row(): array {
		if ( null === $this->proposed_row ) {
			$row = $this->report_facts;
			if ( null !== $this->contact_form ) {
				$row['block_markup'] = null === $this->source_shell
					? Static_Site_Importer_Form_Field_Markup::serialize_block( $this->contact_form )
					: Static_Site_Importer_Form_Field_Markup::serialize_in_shell( $this->contact_form, $this->source_shell );
			}
			$this->proposed_row = $row;
		}
		return $this->proposed_row;
	}

	/** Project emission and decline reporting from the same already-resolved decision. */
	public function to_report(): array {
		$row = $this->proposed_row();
		if ( null === $this->decision ) {
			return $row;
		}
		$row['mapping_decision'] = $this->decision;
		if ( ! $this->is_mapped() ) {
			// Retain proposed markup as evidence; binding callbacks only consume
			// runtime_mapped rows, so declined forms keep their source fallback.
			$row['provider_mapped']                = true;
			$row['runtime_mapped']                 = false;
			$row['status']                         = 'skipped';
			$row['reason']                         = 'form_receipt_loss_unaccepted';
			$row['form_receipt_unaccepted_losses'] = $this->decision['losses'];
			$row['unaccepted_receipt_loss_count']  = count( $this->decision['losses'] );
		}
		return $row;
	}
}

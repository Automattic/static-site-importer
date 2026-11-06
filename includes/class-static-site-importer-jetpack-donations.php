<?php
/**
 * Jetpack Donations materialization for donation-intent form fallbacks.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps a producer `form_intent: donation` form onto one `jetpack/donations` block.
 *
 * The block is attribute-only: its saved markup is empty until the site owner
 * connects a payment account and Jetpack assigns plan ids, so the emitted block
 * is self-closing and valid as saved. Source choices the block has no slot for
 * are reported as diagnostics on the provider row instead of keeping the form a
 * fallback.
 *
 * @phpstan-type DonationPlan array{amounts:array<int,float|int>,default_amount:?int,custom:bool,custom_text:string,currency:string,intervals:array<string,bool>,default_interval:string,submit_text:string,diagnostics:array<int,array<string,mixed>>}
 */
final class Static_Site_Importer_Jetpack_Donations {
	public const BLOCK_NAME = 'jetpack/donations';

	/** Interval attribute per recognized frequency, in the block's display order. */
	private const INTERVALS = array(
		'one-time' => 'oneTimeDonation',
		'1 month'  => 'monthlyDonation',
		'1 year'   => 'annualDonation',
	);

	/** Currency symbols the block's currency list can carry unambiguously enough to infer. */
	private const SYMBOLS = array(
		'$' => 'USD',
		'€' => 'EUR',
		'£' => 'GBP',
		'¥' => 'JPY',
		'₹' => 'INR',
	);

	/**
	 * Plan one validated donation form into a provider report row.
	 *
	 * @param array<string,mixed> $form      Validated form row.
	 * @param bool                $available Whether the provider runtime is active.
	 * @return array<string,mixed>
	 */
	public static function seed_form( array $form, bool $available ): array {
		$selector    = isset( $form['selector'] ) && is_scalar( $form['selector'] ) ? (string) $form['selector'] : '';
		$source_path = isset( $form['source_path'] ) && is_scalar( $form['source_path'] ) ? (string) $form['source_path'] : '';
		$plan        = self::plan( isset( $form['controls'] ) && is_array( $form['controls'] ) ? array_values( array_filter( $form['controls'], 'is_array' ) ) : array() );
		$row         = array(
			'selector'       => $selector,
			'source_path'    => $source_path,
			'provider'       => Static_Site_Importer_Form_Seeder::PROVIDER_ID,
			'block_name'     => self::BLOCK_NAME,
			'form_intent'    => 'donation',
			'status'         => 'skipped',
			'runtime_mapped' => false,
			'complete'       => false,
		);
		if ( array() === $plan['amounts'] ) {
			$row['reason'] = 'donation_amounts_unrecognized';
			return $row;
		}

		if ( ! empty( $form['readable_blocks'] ) ) {
			// The block renders its own amount, interval and total UI in place of
			// the source form's copy (legends, totals, helper text).
			$plan['diagnostics'][] = array(
				'reason_code' => 'donation_form_copy_replaced',
				'message'     => 'The source form\'s own text and layout inside the form are replaced by the Jetpack Donations block interface.',
			);
		}
		$readiness = self::runtime_readiness( $available );
		$attrs     = self::attributes( $plan );
		return array_merge(
			$row,
			array(
				'status'            => 'mapped',
				'runtime_mapped'    => true,
				'runtime_carried'   => $available,
				'complete'          => $available && 'ready' === $readiness['status'],
				'block_markup'      => serialize_block(
					array(
						'blockName'    => self::BLOCK_NAME,
						'attrs'        => $attrs,
						'innerBlocks'  => array(),
						'innerHTML'    => '',
						'innerContent' => array(),
					)
				),
				'donation'          => array(
					'currency'           => $attrs['currency'],
					'intervals'          => array_keys( $plan['intervals'] ),
					'amounts'            => $plan['amounts'],
					'show_custom_amount' => $plan['custom'],
					'submit_text'        => $plan['submit_text'],
				),
				'runtime_readiness' => $readiness,
				'diagnostics'       => $plan['diagnostics'],
				// Jetpack renders the block only after a payment account is connected
				// and a plan exists per interval; neither is a source fact.
				'handoff_items'     => array(
					array(
						'kind'        => 'payment_provider',
						'reason_code' => 'donation_payment_account_required',
						'selector'    => $selector,
						'source_path' => $source_path,
						'message'     => 'The donation form was materialized as a Jetpack Donations block. It renders for visitors only after the site owner connects a payment account and the block creates its donation plans.',
					),
				),
			)
		);
	}

	/**
	 * Provider block types a donation form materializes.
	 *
	 * @return array<int,string>
	 */
	public static function required_block_types(): array {
		return array( self::BLOCK_NAME );
	}

	/**
	 * Read amounts, intervals, custom choice, currency and submit text from controls.
	 *
	 * @param array<int,array<string,mixed>> $controls Producer controls.
	 * @return DonationPlan
	 */
	public static function plan( array $controls ): array {
		$plan        = array(
			'amounts'          => array(),
			'default_amount'   => null,
			'custom'           => false,
			'custom_text'      => '',
			'currency'         => '',
			'intervals'        => array(),
			'default_interval' => '',
			'submit_text'      => '',
			'diagnostics'      => array(),
		);
		$represented = array();
		$amount_name = self::amount_group_name( $controls );
		foreach ( $controls as $index => $control ) {
			$tag  = strtolower( (string) ( $control['tag'] ?? '' ) );
			$type = strtolower( (string) ( $control['type'] ?? '' ) );
			if ( 'submit' === $type || ( 'button' === $tag && in_array( $type, array( '', 'submit' ), true ) ) ) {
				$plan['submit_text'] = '' === $plan['submit_text'] ? self::text( $control['text'] ?? ( $control['label'] ?? ( $control['value'] ?? '' ) ) ) : $plan['submit_text'];
				$represented[]       = $index;
				continue;
			}
			if ( in_array( $type, array( 'hidden', 'reset' ), true ) ) {
				$represented[] = $index;
				continue;
			}
			$choices = self::choices( $control );
			if ( self::record_intervals( $plan, $choices ) ) {
				$represented[] = $index;
				continue;
			}
			if ( 'radio' === $type && '' !== $amount_name && (string) ( $control['name'] ?? '' ) === $amount_name ) {
				$represented[] = $index;
				self::record_amount( $plan, $choices[0] ?? array() );
				continue;
			}
			if ( 'number' === $type && '' !== $amount_name ) {
				// A free amount input is the block's custom amount field.
				$plan['custom'] = true;
				$represented[]  = $index;
			}
		}
		if ( array() === $plan['intervals'] ) {
			$plan['intervals']['one-time'] = true;
		}
		if ( '' === $plan['currency'] && array() !== $plan['amounts'] ) {
			$plan['diagnostics'][] = array(
				'reason_code' => 'donation_currency_unrecognized',
				'message'     => 'No currency symbol or code was recognized on the donation amounts; the block uses the site default currency.',
			);
		}
		foreach ( $controls as $index => $control ) {
			if ( in_array( $index, $represented, true ) ) {
				continue;
			}
			$plan['diagnostics'][] = array_filter(
				array(
					'reason_code' => 'donation_control_unrepresented',
					'message'     => 'The Jetpack Donations block has no field for this source control; it is not carried.',
					'tag'         => (string) ( $control['tag'] ?? '' ),
					'type'        => (string) ( $control['type'] ?? '' ),
					'name'        => (string) ( $control['name'] ?? ( $control['id'] ?? '' ) ),
					'label'       => self::text( $control['label'] ?? '' ),
					'selector'    => (string) ( $control['selector'] ?? '' ),
				)
			);
		}
		return $plan;
	}

	/**
	 * The radio group whose choices carry money amounts.
	 *
	 * @param array<int,array<string,mixed>> $controls Producer controls.
	 */
	private static function amount_group_name( array $controls ): string {
		$counts = array();
		foreach ( $controls as $control ) {
			if ( 'radio' !== strtolower( (string) ( $control['type'] ?? '' ) ) || ! is_string( $control['name'] ?? null ) ) {
				continue;
			}
			$choice = self::choices( $control )[0] ?? array();
			if ( null !== self::money( $choice['label'] ?? '' ) || 1 === preg_match( '/amount/i', $control['name'] ) ) {
				$counts[ $control['name'] ] = ( $counts[ $control['name'] ] ?? 0 ) + 1;
			}
		}
		arsort( $counts );
		return (string) ( array_key_first( $counts ) ?? '' );
	}

	/**
	 * Choice label/value/selected rows of one control.
	 *
	 * @param array<string,mixed> $control Producer control.
	 * @return array<int,array{label:string,value:string,selected:bool}>
	 */
	private static function choices( array $control ): array {
		if ( 'radio' === strtolower( (string) ( $control['type'] ?? '' ) ) ) {
			return array(
				array(
					'label'    => self::text( $control['label'] ?? '' ),
					'value'    => self::text( $control['value'] ?? '' ),
					'selected' => ! empty( $control['checked'] ),
				),
			);
		}
		$choices = array();
		foreach ( is_array( $control['options'] ?? null ) ? $control['options'] : array() as $option ) {
			if ( is_array( $option ) && empty( $option['placeholder'] ) ) {
				$choices[] = array(
					'label'    => self::text( $option['label'] ?? '' ),
					'value'    => self::text( $option['value'] ?? '' ),
					'selected' => ! empty( $option['selected'] ),
				);
			}
		}
		return $choices;
	}

	/**
	 * Record a frequency control's intervals when every choice names one.
	 *
	 * @param DonationPlan                                       $plan    Plan being built.
	 * @param array<int,array{label:string,value:string,selected:bool}> $choices Control choices.
	 */
	private static function record_intervals( array &$plan, array $choices ): bool {
		$intervals = array();
		$default   = '';
		foreach ( $choices as $choice ) {
			$interval = self::interval( $choice['value'] . ' ' . $choice['label'] );
			if ( '' === $interval ) {
				return false;
			}
			$intervals[ $interval ] = true;
			if ( $choice['selected'] && '' === $default ) {
				$default = $interval;
			}
		}
		if ( array() === $intervals ) {
			return false;
		}
		$plan['intervals']        = array_merge( $plan['intervals'], $intervals );
		$plan['default_interval'] = '' !== $default ? $default : $plan['default_interval'];
		return true;
	}

	/**
	 * The block interval a frequency choice names, or ''.
	 *
	 * @param string $text Choice value and label.
	 */
	private static function interval( string $text ): string {
		$text = strtolower( $text );
		if ( 1 === preg_match( '/one[\s_-]*time|\bonce\b|\bsingle\b/', $text ) ) {
			return 'one-time';
		}
		if ( 1 === preg_match( '/month/', $text ) ) {
			return '1 month';
		}
		if ( 1 === preg_match( '/year|annual/', $text ) ) {
			return '1 year';
		}
		return '';
	}

	/**
	 * Record one amount choice: a money label, a numeric value, or the custom choice.
	 *
	 * @param DonationPlan                               $plan   Plan being built.
	 * @param array{label?:string,value?:string,selected?:bool} $choice Amount choice.
	 */
	private static function record_amount( array &$plan, array $choice ): void {
		$label = (string) ( $choice['label'] ?? '' );
		$value = (string) ( $choice['value'] ?? '' );
		$money = self::money( $label );
		if ( null === $money && '' === $label && 1 === preg_match( '/^\d+(?:\.\d{1,2})?$/D', $value ) ) {
			$money = array(
				'amount'   => (float) $value,
				'currency' => '',
			);
		}
		if ( null === $money ) {
			$plan['custom']      = $plan['custom'] || 1 === preg_match( '/custom|other|enter/i', $label . ' ' . $value );
			$plan['custom_text'] = $plan['custom'] && '' === $plan['custom_text'] ? $label : $plan['custom_text'];
			return;
		}
		if ( ! empty( $choice['selected'] ) ) {
			$plan['default_amount'] = count( $plan['amounts'] );
		}
		$amount            = $money['amount'];
		$plan['amounts'][] = floor( $amount ) === $amount ? (int) $amount : $amount;
		$plan['currency']  = '' === $plan['currency'] ? $money['currency'] : $plan['currency'];
	}

	/**
	 * Parse a money label such as `$50.00`, `50 EUR` or `£1,000`.
	 *
	 * @param string $label Choice label.
	 * @return array{amount:float,currency:string}|null
	 */
	private static function money( string $label ): ?array {
		$symbols = implode( '|', array_map( static fn( string $symbol ): string => preg_quote( $symbol, '/' ), array_keys( self::SYMBOLS ) ) );
		if ( 1 !== preg_match( '/^\s*(?:(' . $symbols . ')|([A-Z]{3})\s*)?\s*(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d{1,2}))?\s*(?:([A-Z]{3})|(' . $symbols . '))?\s*$/u', $label, $match ) ) {
			return null;
		}
		$amount = (float) ( str_replace( ',', '', $match[3] ) . ( isset( $match[4] ) && '' !== $match[4] ? '.' . $match[4] : '' ) );
		if ( $amount <= 0 ) {
			return null;
		}
		$symbol = '' !== ( $match[1] ?? '' ) ? $match[1] : ( $match[6] ?? '' );
		$code   = '' !== ( $match[2] ?? '' ) ? $match[2] : ( $match[5] ?? '' );
		return array(
			'amount'   => $amount,
			'currency' => '' !== $code ? $code : ( self::SYMBOLS[ $symbol ] ?? '' ),
		);
	}

	/**
	 * Block attributes for a recognized donation plan.
	 *
	 * @param DonationPlan $plan Donation plan.
	 * @return array<string,mixed>
	 */
	private static function attributes( array $plan ): array {
		$attrs = array( 'currency' => $plan['currency'] );
		foreach ( self::INTERVALS as $interval => $attribute ) {
			$donation = array(
				'show'    => isset( $plan['intervals'][ $interval ] ),
				'planId'  => null,
				'amounts' => $plan['amounts'],
			);
			if ( '' !== $plan['submit_text'] ) {
				$donation['buttonText'] = $plan['submit_text'];
			}
			if ( null !== $plan['default_amount'] ) {
				$donation['defaultAmountIndex'] = $plan['default_amount'];
			}
			$attrs[ $attribute ] = $donation;
		}
		$attrs['showCustomAmount'] = $plan['custom'];
		if ( $plan['custom'] && '' !== $plan['custom_text'] ) {
			$attrs['customAmountText'] = $plan['custom_text'];
		}
		if ( '' !== $plan['default_interval'] ) {
			$attrs['defaultInterval'] = $plan['default_interval'];
		}
		return $attrs;
	}

	/**
	 * Prove the donations block is registered in this runtime.
	 *
	 * @param bool $available Whether the provider runtime is active.
	 * @return array{status:string,reason_code:string,required_block_types:array<int,string>,unregistered_block_types:array<int,string>}
	 */
	private static function runtime_readiness( bool $available ): array {
		$unregistered = array();
		if ( class_exists( 'WP_Block_Type_Registry' ) ) {
			foreach ( self::required_block_types() as $block_name ) {
				if ( ! WP_Block_Type_Registry::get_instance()->is_registered( $block_name ) ) {
					$unregistered[] = $block_name;
				}
			}
		}
		$reason = ! $available ? 'provider_unavailable' : ( array() !== $unregistered ? 'provider_blocks_not_registered' : ( self::editor_available() ? '' : 'provider_editor_unavailable' ) );
		return array(
			'status'                   => '' === $reason ? 'ready' : 'missing_capability',
			'reason_code'              => $reason,
			'required_block_types'     => self::required_block_types(),
			'unregistered_block_types' => $unregistered,
		);
	}

	/**
	 * Whether Jetpack offers the block in the editor.
	 *
	 * The block registers on every front-end and CLI request, but Jetpack keeps
	 * monetize blocks out of the editor on offline-mode sites that are not
	 * WordPress.com Simple sites (`Jetpack_Memberships::should_enable_monetize_blocks_in_editor()`),
	 * where the imported block opens as an unsupported block.
	 */
	private static function editor_available(): bool {
		if ( ! class_exists( \Automattic\Jetpack\Status::class ) ) {
			return true;
		}
		if ( class_exists( \Automattic\Jetpack\Status\Host::class ) && ( new \Automattic\Jetpack\Status\Host() )->is_wpcom_simple() ) {
			return true;
		}
		return ! ( new \Automattic\Jetpack\Status() )->is_offline_mode();
	}

	/**
	 * One-line trimmed text.
	 *
	 * @param mixed $value Scalar source text.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) preg_replace( '/\s+/u', ' ', (string) $value ) ) : '';
	}
}

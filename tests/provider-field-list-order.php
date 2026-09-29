<?php
/**
 * Standalone regression for the provider field-list ordering contract.
 *
 * Covers Static_Site_Importer_Provider_Form_Runtime_V1::project_field_list_wrapper()
 * without WordPress or Composer: the runtime method needs only PHP's DOMDocument
 * and an ABSPATH stub.
 *
 * A source newsletter row may interleave controls — field, submit, field. The
 * runtime must wrap each contiguous run of field children in place so the
 * rendered DOM keeps the authored order (email, then Subscribe submit, then
 * the required checkbox), while a plain field list followed by one final
 * submit keeps the single wrapper behavior.
 *
 * Run from the repository root:
 * php tests/provider-field-list-order.php
 *
 * @package StaticSiteImporter
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-provider-form-runtime.php';

	$failures   = array();
	$assertions = 0;
	$assert     = static function ( bool $condition, string $label ) use ( &$assertions, &$failures ): void {
		++$assertions;
		if ( ! $condition ) {
			$failures[] = 'FAIL [' . $label . ']';
		}
	};

	/** Run the runtime filter the way Jetpack's render_block hook invokes it. */
	$project = static function ( string $container_classes, string $children ): string {
		$html = '<div class="jetpack-contact-form-container"><form class="jetpack-contact-form__form" method="post" action="/wp-admin/admin-ajax.php"><div class="wp-block-jetpack-contact-form ' . $container_classes . '">' . $children . '</div></form></div>';
		return \Static_Site_Importer_Provider_Form_Runtime_V1::project_field_list_wrapper( $html, array( 'attrs' => array( 'className' => $container_classes ) ) );
	};

	$container_of = static function ( string $html ): ?\DOMElement {
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $document->loadHTML( '<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return null;
		}
		foreach ( $document->getElementsByTagName( '*' ) as $element ) {
			$classes = preg_split( '/\s+/', trim( $element->getAttribute( 'class' ) ) );
			if ( is_array( $classes ) && 'div' === strtolower( $element->tagName ) && in_array( 'wp-block-jetpack-contact-form', $classes, true ) ) {
				return $element;
			}
		}
		return null;
	};

	$by_class    = static function ( \DOMElement $root, string $class ): array {
		$found = array();
		foreach ( $root->getElementsByTagName( '*' ) as $element ) {
			$classes = preg_split( '/\s+/', trim( $element->getAttribute( 'class' ) ) );
			if ( is_array( $classes ) && in_array( $class, $classes, true ) ) {
				$found[] = $element;
			}
		}
		return $found;
	};

	$direct_elements = static function ( \DOMElement $parent ): array {
		$found = array();
		foreach ( $parent->childNodes as $node ) {
			if ( $node instanceof \DOMElement ) {
				$found[] = $node;
			}
		}
		return $found;
	};

	$has_class = static function ( \DOMElement $element, string $class ): bool {
		$classes = preg_split( '/\s+/', trim( $element->getAttribute( 'class' ) ) );
		return is_array( $classes ) && in_array( $class, $classes, true );
	};

	$precedes = static function ( \DOMNode $first, \DOMNode $second ): bool {
		return 0 !== ( $first->compareDocumentPosition( $second ) & \DOMNode::DOCUMENT_POSITION_FOLLOWING );
	};

	// Provider-rendered controls the way Jetpack emits them: direct children of
	// .wp-block-jetpack-contact-form in serialized block order.
	$email_field     = '<div class="grunion-field-email-wrap"><label for="ssi-9-email">Email<span class="required">(required)</span></label><input type="email" id="ssi-9-email" name="ssi-9-email" class="jetpack-field__input" required aria-required="true"></div>';
	$submit_button   = '<div class="wp-block-button form-button-submit is-submit"><button type="submit" class="wp-block-button__link">Subscribe Now</button></div>';
	$checkbox_field  = '<div class="grunion-field-checkbox-wrap"><label><input type="checkbox" name="ssi-9-newsletter" class="jetpack-field__checkbox" required aria-required="true"> Yes, subscribe me to your newsletter.</label></div>';
	$text_field      = '<div class="grunion-field-text-wrap"><label for="ssi-9-name">Name</label><input type="text" id="ssi-9-name" name="ssi-9-name" class="jetpack-field__input"></div>';

	// Regression: an interleaved source row (field, submit, field) must render in
	// authored order. The form carries only the neutral source field-list marker.
	$out        = $project( 'ssi-source-field-list ssi-form-0123456789ab', $email_field . $submit_button . $checkbox_field );
	$container  = $container_of( $out );
	$assert( $container instanceof \DOMElement, 'interleaved-form-container-found' );
	if ( $container instanceof \DOMElement ) {
		$email_wraps    = $by_class( $container, 'grunion-field-email-wrap' );
		$checkbox_wraps = $by_class( $container, 'grunion-field-checkbox-wrap' );
		$submit_divs    = $by_class( $container, 'form-button-submit' );
		$assert( 1 === count( $email_wraps ) && 1 === count( $checkbox_wraps ) && 1 === count( $submit_divs ), 'interleaved-controls-unique' );
		$children = $direct_elements( $container );
		$assert( 3 === count( $children ), 'interleaved-keeps-three-form-children' );
		if ( 1 === count( $email_wraps ) && 1 === count( $checkbox_wraps ) && 1 === count( $submit_divs ) ) {
			$assert( $precedes( $email_wraps[0], $submit_divs[0] ), 'interleaved-email-precedes-submit' );
			$assert( $precedes( $submit_divs[0], $checkbox_wraps[0] ), 'interleaved-submit-precedes-checkbox' );
			$email_wrapper    = $email_wraps[0]->parentNode;
			$checkbox_wrapper = $checkbox_wraps[0]->parentNode;
			$assert( $email_wrapper instanceof \DOMElement && $checkbox_wrapper instanceof \DOMElement, 'interleaved-fields-sit-in-wrappers' );
			if ( $email_wrapper instanceof \DOMElement && $checkbox_wrapper instanceof \DOMElement ) {
				$assert( $email_wrapper !== $checkbox_wrapper, 'interleaved-field-runs-get-distinct-wrappers' );
				$assert( $email_wrapper->parentNode === $container && $checkbox_wrapper->parentNode === $container, 'interleaved-wrappers-stay-form-children' );
				$assert( $has_class( $email_wrapper, 'ssi-source-field-list' ) && $has_class( $checkbox_wrapper, 'ssi-source-field-list' ), 'interleaved-wrappers-carry-field-list' );
				$assert( 1 === count( $direct_elements( $email_wrapper ) ) && 1 === count( $direct_elements( $checkbox_wrapper ) ), 'interleaved-wrappers-are-contiguous-runs' );
			}
			$assert( $submit_divs[0]->parentNode === $container, 'interleaved-submit-stays-form-sibling' );
			$assert( $has_class( $submit_divs[0], 'is-submit' ) && $has_class( $submit_divs[0], 'form-button-submit' ), 'interleaved-submit-keeps-presentation-classes' );
		}
		$assert( ! $has_class( $container, 'ssi-source-field-list' ) && $has_class( $container, 'ssi-form-0123456789ab' ), 'interleaved-container-keeps-form-identity' );
		$assert( 1 === substr_count( $out, '<form ' ) && str_contains( $out, 'jetpack-contact-form__form' ) && str_contains( $out, 'admin-ajax.php' ), 'interleaved-jetpack-form-runtime-intact' );
		$assert( str_contains( $out, 'type="email"' ) && str_contains( $out, 'type="checkbox"' ) && str_contains( $out, 'Subscribe Now' ), 'interleaved-controls-intact' );
	}

	// Neighbor case: a plain field list followed by one final submit keeps the
	// single field-list wrapper, its authored field order, and its classes.
	$out2       = $project( 'grid gap-6 ssi-source-field-list ssi-form-0123456789ab', $text_field . $email_field . $submit_button );
	$container2 = $container_of( $out2 );
	$assert( $container2 instanceof \DOMElement, 'neighbor-form-container-found' );
	if ( $container2 instanceof \DOMElement ) {
		$children2  = $direct_elements( $container2 );
		$wrappers2  = $by_class( $container2, 'ssi-source-field-list' );
		$submit2    = $by_class( $container2, 'form-button-submit' );
		$assert( 2 === count( $children2 ) && 1 === count( $wrappers2 ) && 1 === count( $submit2 ), 'neighbor-stays-list-then-submit' );
		if ( 1 === count( $wrappers2 ) && 1 === count( $submit2 ) ) {
			$assert( $precedes( $wrappers2[0], $submit2[0] ), 'neighbor-wrapper-precedes-submit' );
			$assert( $submit2[0]->parentNode === $container2, 'neighbor-submit-stays-form-sibling' );
			$assert( 'grid gap-6 ssi-source-field-list' === trim( $wrappers2[0]->getAttribute( 'class' ) ), 'neighbor-wrapper-carries-list-classes' );
			$inner = $direct_elements( $wrappers2[0] );
			$assert( 2 === count( $inner ) && $has_class( $inner[0], 'grunion-field-text-wrap' ) && $has_class( $inner[1], 'grunion-field-email-wrap' ), 'neighbor-keeps-authored-field-order' );
		}
		$assert( ! $has_class( $container2, 'grid' ) && ! $has_class( $container2, 'gap-6' ) && $has_class( $container2, 'ssi-form-0123456789ab' ), 'neighbor-container-keeps-form-identity' );
	}

	// Existing early exits stay in place: no submit at all leaves the authored
	// markup untouched, and an unmarked block never matches the hook.
	$fields_only  = $email_field . $checkbox_field;
	$marked       = 'ssi-source-field-list ssi-form-0123456789ab';
	$plain_html   = '<div class="jetpack-contact-form-container"><form class="jetpack-contact-form__form" method="post"><div class="wp-block-jetpack-contact-form ' . $marked . '">' . $fields_only . '</div></form></div>';
	$out3         = \Static_Site_Importer_Provider_Form_Runtime_V1::project_field_list_wrapper( $plain_html, array( 'attrs' => array( 'className' => $marked ) ) );
	$assert( $out3 === $plain_html, 'fields-only-form-is-untouched' );
	$out4         = $project( 'ssi-source-field-list', $email_field . $submit_button . $checkbox_field );
	$plain_html2  = '<div class="jetpack-contact-form-container"><form class="jetpack-contact-form__form" method="post" action="/wp-admin/admin-ajax.php"><div class="wp-block-jetpack-contact-form ssi-source-field-list">' . $email_field . $submit_button . $checkbox_field . '</div></form></div>';
	$assert( $out4 === $plain_html2, 'unmarked-block-is-untouched' );

	// Nested submit matching stays bounded: only direct children are classified,
	// a deeply nested submit keeps its host child out of the field list, and a
	// non-submit trigger button never reclassifies a field as a submit.
	$deep_submit  = '<div class="grunion-field-consent-wrap"><div class="consent-inner"><button type="submit">Accept</button></div></div>';
	$out5         = $project( $marked, $email_field . $deep_submit . $checkbox_field );
	$container5   = $container_of( $out5 );
	$assert( $container5 instanceof \DOMElement, 'nested-submit-container-found' );
	if ( $container5 instanceof \DOMElement ) {
		$consent5   = $by_class( $container5, 'grunion-field-consent-wrap' );
		$email5     = $by_class( $container5, 'grunion-field-email-wrap' );
		$checkbox5  = $by_class( $container5, 'grunion-field-checkbox-wrap' );
		$assert( 1 === count( $consent5 ) && 1 === count( $email5 ) && 1 === count( $checkbox5 ), 'nested-submit-controls-unique' );
		if ( 1 === count( $consent5 ) && 1 === count( $email5 ) && 1 === count( $checkbox5 ) ) {
			$assert( $consent5[0]->parentNode === $container5, 'nested-submit-host-stays-form-sibling' );
			$assert( $precedes( $email5[0], $consent5[0] ) && $precedes( $consent5[0], $checkbox5[0] ), 'nested-submit-keeps-authored-order' );
		}
	}
	$select_trigger = '<div class="grunion-field-select-wrap"><select name="ssi-9-country"><option>US</option></select><button class="jetpack-combobox-trigger" aria-haspopup="listbox"></button></div>';
	$out6           = $project( $marked, $email_field . $select_trigger . $submit_button );
	$container6     = $container_of( $out6 );
	$assert( $container6 instanceof \DOMElement, 'trigger-button-container-found' );
	if ( $container6 instanceof \DOMElement ) {
		$select6  = $by_class( $container6, 'grunion-field-select-wrap' );
		$submit6  = $by_class( $container6, 'form-button-submit' );
		$assert( 1 === count( $select6 ) && 1 === count( $submit6 ), 'trigger-button-controls-unique' );
		if ( 1 === count( $select6 ) && 1 === count( $submit6 ) ) {
			$assert( $select6[0]->parentNode instanceof \DOMElement && $has_class( $select6[0]->parentNode, 'ssi-source-field-list' ), 'non-submit-trigger-stays-a-field' );
			$assert( $precedes( $select6[0], $submit6[0] ), 'non-submit-trigger-keeps-authored-order' );
		}
	}

	if ( $failures ) {
		fwrite( STDERR, implode( "\n", $failures ) . "\n" );
		exit( 1 );
	}

	echo 'OK: provider field-list order regression passed (' . $assertions . " assertions)\n";
}

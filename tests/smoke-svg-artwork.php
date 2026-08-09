<?php
/**
 * Smoke coverage for the svg-artwork sanitizer.
 *
 * Run from the repository root:
 * php tests/smoke-svg-artwork.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

foreach (
	array(
		'esc_attr' => static fn( string $value ): string => htmlspecialchars( $value, ENT_QUOTES ),
	) as $function => $implementation
) {
	if ( ! function_exists( $function ) ) {
		eval( 'function ' . $function . '( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }' );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-svg-artwork.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$harness = new class {
	public function assert_class_exists(): void {
		$GLOBALS['ssi_assert'][] = array( 'class_exists', class_exists( 'Static_Site_Importer_Svg_Artwork' ) );
	}
	public function sanitize_preserves_complex_dom(): void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><linearGradient id="g" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#88c"/><stop offset="1" stop-color="#224"/></linearGradient><clipPath id="c"><rect width="50" height="50"/></clipPath><mask id="m"><rect width="100" height="100" fill="white"/><circle cx="50" cy="50" r="20" fill="black"/></mask><filter id="f"><feGaussianBlur stdDeviation="2"/></filter><symbol id="s" viewBox="0 0 10 10"><circle cx="5" cy="5" r="3"/></symbol></defs><g clip-path="url(#c)" mask="url(#m)" filter="url(#f)"><rect width="100" height="100" fill="url(#g)"/><use href="#s" x="0" y="0"/></g><title>Test</title><desc>Test desc</desc></svg>';
		$out = Static_Site_Importer_Svg_Artwork::sanitize( $svg );
		$GLOBALS['ssi_assert'][] = array( 'preserves-linearGradient', str_contains( $out, '<linearGradient' ) );
		$GLOBALS['ssi_assert'][] = array( 'preserves-clipPath', str_contains( $out, '<clipPath' ) );
		$GLOBALS['ssi_assert'][] = array( 'preserves-mask', str_contains( $out, '<mask' ) );
		$GLOBALS['ssi_assert'][] = array( 'preserves-filter', str_contains( $out, '<filter' ) );
		$GLOBALS['ssi_assert'][] = array( 'preserves-feGaussianBlur', str_contains( $out, '<feGaussianBlur' ) );
		$GLOBALS['ssi_assert'][] = array( 'preserves-symbol', str_contains( $out, '<symbol' ) );
		$GLOBALS['ssi_assert'][] = array( 'preserves-local-use', str_contains( $out, 'href="#s"' ) );
		$GLOBALS['ssi_assert'][] = array( 'preserves-title', str_contains( $out, '<title' ) );
		$GLOBALS['ssi_assert'][] = array( 'preserves-desc', str_contains( $out, '<desc' ) );
		$GLOBALS['ssi_assert'][] = array( 'preserves-viewBox', str_contains( $out, 'viewBox="0 0 100 100"' ) );
		$GLOBALS['ssi_assert'][] = array( 'preserves-camelCase', str_contains( $out, 'viewBox=' ) );
	}
	public function sanitize_strips_unsafe(): void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" onload="alert(1)"><script>alert(1)</script><style>body{background:url("javascript:alert(1)")}</style><foreignObject width="10" height="10"><div onmouseover="bad()">x</div></foreignObject><rect width="10" height="10" fill="#000" onclick="bad()" href="javascript:bad()" xlink:href="https://evil.example/"/></svg>';
		$out = Static_Site_Importer_Svg_Artwork::sanitize( $svg );
		$GLOBALS['ssi_assert'][] = array( 'strips-script', ! str_contains( $out, '<script' ) );
		$GLOBALS['ssi_assert'][] = array( 'strips-style', ! str_contains( $out, '<style' ) );
		$GLOBALS['ssi_assert'][] = array( 'strips-foreignObject', ! str_contains( $out, '<foreignObject' ) );
		$GLOBALS['ssi_assert'][] = array( 'strips-onload', ! str_contains( $out, 'onload' ) );
		$GLOBALS['ssi_assert'][] = array( 'strips-onclick', ! str_contains( $out, 'onclick' ) );
		$GLOBALS['ssi_assert'][] = array( 'strips-onmouseover', ! str_contains( $out, 'onmouseover' ) );
		$GLOBALS['ssi_assert'][] = array( 'strips-javascript-href', ! str_contains( $out, 'javascript:' ) );
		$GLOBALS['ssi_assert'][] = array( 'strips-external-href', ! str_contains( $out, 'evil.example' ) );
		$GLOBALS['ssi_assert'][] = array( 'preserves-drawable-rect', str_contains( $out, '<rect' ) );
	}
	public function sanitize_strips_animation(): void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="red"><animate attributeName="fill" from="red" to="blue"/></rect><animateTransform attributeName="transform" type="rotate" from="0" to="360"/></svg>';
		$out = Static_Site_Importer_Svg_Artwork::sanitize( $svg );
		$GLOBALS['ssi_assert'][] = array( 'strips-animate', ! str_contains( $out, '<animate' ) );
		$GLOBALS['ssi_assert'][] = array( 'strips-animateTransform', ! str_contains( $out, '<animateTransform' ) );
		$GLOBALS['ssi_assert'][] = array( 'preserves-rect', str_contains( $out, '<rect' ) );
	}
	public function sanitize_rejects_unsafe_only(): void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><script>bad()</script></svg>';
		$out = Static_Site_Importer_Svg_Artwork::sanitize( $svg );
		$GLOBALS['ssi_assert'][] = array( 'rejects-unsafe-only', '' === $out );
	}
	public function sanitize_rejects_malformed(): void {
		$out = Static_Site_Importer_Svg_Artwork::sanitize( 'not svg at all' );
		$GLOBALS['ssi_assert'][] = array( 'rejects-malformed', '' === $out );
	}
	public function sanitize_rejects_oversize(): void {
		$oversize = str_repeat( 'a', Static_Site_Importer_Svg_Artwork::MAX_INPUT_BYTES + 1 );
		$out      = Static_Site_Importer_Svg_Artwork::sanitize( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>' . $oversize );
		$GLOBALS['ssi_assert'][] = array( 'rejects-oversize', '' === $out );
	}
	public function view_box_uses_typed_attribute(): void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>';
		$out = Static_Site_Importer_Svg_Artwork::view_box( $svg, '0 0 50 50' );
		$GLOBALS['ssi_assert'][] = array( 'typed-viewBox', '0 0 50 50' === $out );
	}
	public function view_box_falls_back_to_root(): void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 12 12"><rect width="12" height="12"/></svg>';
		$out = Static_Site_Importer_Svg_Artwork::view_box( $svg, '' );
		$GLOBALS['ssi_assert'][] = array( 'root-viewBox', '0 0 12 12' === $out );
	}
	public function view_box_rejects_invalid(): void {
		$out = Static_Site_Importer_Svg_Artwork::view_box( '', 'not a box' );
		$GLOBALS['ssi_assert'][] = array( 'invalid-viewBox', '' === $out );
	}
	public function preserve_aspect_default(): void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>';
		$out = Static_Site_Importer_Svg_Artwork::preserve_aspect_ratio( $svg, '' );
		$GLOBALS['ssi_assert'][] = array( 'par-default', 'xMidYMid meet' === $out );
	}
	public function preserve_aspect_typed(): void {
		$out = Static_Site_Importer_Svg_Artwork::preserve_aspect_ratio( '<svg/>', 'xMaxYMin slice' );
		$GLOBALS['ssi_assert'][] = array( 'par-typed', 'xMaxYMin slice' === $out );
	}
	public function preserve_aspect_rejects(): void {
		$out = Static_Site_Importer_Svg_Artwork::preserve_aspect_ratio( '<svg/>', 'unknown value' );
		$GLOBALS['ssi_assert'][] = array( 'par-rejects-invalid', 'xMidYMid meet' === $out );
	}
	public function accessibility_label_only(): void {
		$result = Static_Site_Importer_Svg_Artwork::accessibility_attributes( 'Title', '' );
		$GLOBALS['ssi_assert'][] = array( 'a11y-label', ( $result['attrs']['aria-label'] ?? '' ) === 'Title' );
		$GLOBALS['ssi_assert'][] = array( 'a11y-label-no-ids', empty( $result['ids'] ) );
	}
	public function accessibility_title_and_description(): void {
		$result = Static_Site_Importer_Svg_Artwork::accessibility_attributes( 'T', 'D' );
		$GLOBALS['ssi_assert'][] = array( 'a11y-labelledby', '' !== ( $result['attrs']['aria-labelledby'] ?? '' ) );
		$GLOBALS['ssi_assert'][] = array( 'a11y-describedby', '' !== ( $result['attrs']['aria-describedby'] ?? '' ) );
		$GLOBALS['ssi_assert'][] = array( 'a11y-title-id', isset( $result['ids']['title'] ) );
		$GLOBALS['ssi_assert'][] = array( 'a11y-desc-id', isset( $result['ids']['description'] ) );
		$GLOBALS['ssi_assert'][] = array( 'a11y-no-aria-label', ! isset( $result['attrs']['aria-label'] ) );
	}
	public function accessibility_empty(): void {
		$result = Static_Site_Importer_Svg_Artwork::accessibility_attributes( '', '' );
		$GLOBALS['ssi_assert'][] = array( 'a11y-empty', ( $result['attrs']['role'] ?? '' ) === 'img' && ! isset( $result['attrs']['aria-label'] ) );
	}
	public function sanitize_strips_extra_animation(): void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="red"><set attributeName="fill" to="blue"/><animateMotion path="M0,0 L10,10"/><animateColor attributeName="fill" from="red" to="blue"/><mpath href="#x"/></rect><circle cx="5" cy="5" r="3"><animate attributeName="r" values="1;5;1"/></circle></svg>';
		$out = Static_Site_Importer_Svg_Artwork::sanitize( $svg );
		$GLOBALS['ssi_assert'][] = array( 'strips-set', ! str_contains( $out, '<set' ) );
		$GLOBALS['ssi_assert'][] = array( 'strips-animateMotion', ! str_contains( $out, '<animateMotion' ) );
		$GLOBALS['ssi_assert'][] = array( 'strips-animateColor', ! str_contains( $out, '<animateColor' ) );
		$GLOBALS['ssi_assert'][] = array( 'strips-mpath', ! str_contains( $out, '<mpath' ) );
		$GLOBALS['ssi_assert'][] = array( 'strips-animate-extra', ! str_contains( $out, '<animate' ) );
		$GLOBALS['ssi_assert'][] = array( 'preserves-rect-extra', str_contains( $out, '<rect' ) );
		$GLOBALS['ssi_assert'][] = array( 'preserves-circle-extra', str_contains( $out, '<circle' ) );
	}
	public function sanitize_rejects_vbscript_href(): void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><a href="vbscript:bad()"><rect width="10" height="10"/></a></svg>';
		$out = Static_Site_Importer_Svg_Artwork::sanitize( $svg );
		$GLOBALS['ssi_assert'][] = array( 'strips-vbscript', ! str_contains( $out, 'vbscript' ) );
	}
	public function sanitize_allows_data_image_href(): void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><image width="10" height="10" href="data:image/png;base64,iVBORw0KGgo="/></svg>';
		$out = Static_Site_Importer_Svg_Artwork::sanitize( $svg );
		$GLOBALS['ssi_assert'][] = array( 'preserves-data-image', str_contains( $out, 'data:image/png' ) );
	}
	public function sanitize_rejects_data_html_href(): void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><a href="data:text/html,<script>alert(1)</script>"><rect width="10" height="10"/></a></svg>';
		$out = Static_Site_Importer_Svg_Artwork::sanitize( $svg );
		$GLOBALS['ssi_assert'][] = array( 'rejects-data-html', ! str_contains( $out, 'data:text/html' ) );
	}
	public function sanitize_strips_style_custom_property(): void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" style="--x:url(javascript:bad()); fill:url(#g); color:red"/></svg>';
		$out = Static_Site_Importer_Svg_Artwork::sanitize( $svg );
		$GLOBALS['ssi_assert'][] = array( 'strips-custom-prop', ! str_contains( $out, '--x' ) );
		$GLOBALS['ssi_assert'][] = array( 'strips-js-in-style', ! str_contains( $out, 'javascript' ) );
	}
	public function sanitize_strips_bom(): void {
		$svg   = "\xEF\xBB\xBF<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 10 10\"><rect width=\"10\" height=\"10\"/></svg>";
		$out   = Static_Site_Importer_Svg_Artwork::sanitize( $svg );
		$GLOBALS['ssi_assert'][] = array( 'bom-preserves-rect', str_contains( $out, '<rect' ) );
	}
	public function view_box_rejects_oversize_component(): void {
		$out = Static_Site_Importer_Svg_Artwork::view_box( '', '0 0 1000001 1000001' );
		$GLOBALS['ssi_assert'][] = array( 'viewBox-oversize-rejected', '' === $out );
	}
	public function view_box_accepts_max_component(): void {
		$out = Static_Site_Importer_Svg_Artwork::view_box( '', '0 0 1000000 1000000' );
		$GLOBALS['ssi_assert'][] = array( 'viewBox-max-accepted', '0 0 1000000 1000000' === $out );
	}
	public function run(): void {
		$this->assert_class_exists();
		$this->sanitize_preserves_complex_dom();
		$this->sanitize_strips_unsafe();
		$this->sanitize_strips_animation();
		$this->sanitize_rejects_unsafe_only();
		$this->sanitize_rejects_malformed();
		$this->sanitize_rejects_oversize();
		$this->view_box_uses_typed_attribute();
		$this->view_box_falls_back_to_root();
		$this->view_box_rejects_invalid();
		$this->preserve_aspect_default();
		$this->preserve_aspect_typed();
		$this->preserve_aspect_rejects();
		$this->accessibility_label_only();
		$this->accessibility_title_and_description();
		$this->accessibility_empty();
		$this->sanitize_strips_extra_animation();
		$this->sanitize_rejects_vbscript_href();
		$this->sanitize_allows_data_image_href();
		$this->sanitize_rejects_data_html_href();
		$this->sanitize_strips_style_custom_property();
		$this->sanitize_strips_bom();
		$this->view_box_rejects_oversize_component();
		$this->view_box_accepts_max_component();
	}
};

$GLOBALS['ssi_assert'] = array();
$harness->run();

foreach ( $GLOBALS['ssi_assert'] as $check ) {
	$assert( (bool) $check[1], $check[0] );
}

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'PASS smoke-svg-artwork.php (' . $assertions . " assertions)\n";

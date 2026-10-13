<?php
/**
 * Capture evidence names this importer reads from a website artifact.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one place SSI names capture evidence written into captured HTML.
 *
 * Blocks Engine owns the producer-neutral capture vocabulary: any capture
 * producer writes these names, and every consumer reads them. The contract is
 * Automattic/blocks-engine `docs/contracts/capture-evidence.md`, published in
 * php-transformer as `Contract\CaptureEvidence`. Names follow its rule: an
 * attribute is `data-blocks-engine-capture-<name>`, a class is
 * `blocks-engine-capture-<name>`; values and semantics belong to the contract.
 *
 * The literals stand in until the locked Blocks Engine package ships
 * `Contract\CaptureEvidence`; then each constant points at its Blocks Engine
 * counterpart and this holder stays the single SSI reference.
 */
final class Static_Site_Importer_Capture_Evidence {

	/** Marker on the script that selects a device document and activates its styles and viewport. */
	public const DEVICE_SELECTION = 'data-blocks-engine-capture-device-selection';

	/** Marker on the script that applies the selected device document's `<body>` attributes. */
	public const DEVICE_BODY = 'data-blocks-engine-capture-device-body';

	/** Device document key the selection script stamps on `<html>`. */
	public const SELECTED_DOCUMENT = 'data-blocks-engine-capture-selected-document';

	/** The viewport `<meta>` the selection script rewrites for the selected document. */
	public const SELECTED_VIEWPORT = 'data-blocks-engine-capture-selected-viewport';

	/** Device document key recorded on `<html>` when that document has no viewport. */
	public const DOCUMENT_UNAVAILABLE = 'data-blocks-engine-capture-document-unavailable';

	/** Device document key an inert device stylesheet belongs to. */
	public const DEVICE_STYLE = 'data-blocks-engine-capture-device-style';

	/** Original `media` of an inert device stylesheet. */
	public const SOURCE_MEDIA = 'data-blocks-engine-capture-source-media';

	/** Provider of a source member sign-in control. */
	public const MEMBER_LOGIN = 'data-blocks-engine-capture-member-login';

	/** Class prefix for a member sign-in control: `<prefix><provider>`, kept by block conversion. */
	public const MEMBER_LOGIN_CLASS_PREFIX = 'blocks-engine-capture-member-login-';

	/**
	 * Inline every name this holder defines into PHP source as a literal.
	 *
	 * Generated themes and companion plugins carry their own copy of a runtime
	 * and run without this plugin, so the copy cannot reference this class.
	 *
	 * @param string $source PHP source referencing `Static_Site_Importer_Capture_Evidence::<NAME>`.
	 * @return string
	 */
	public static function inline( string $source ): string {
		$literals = array();
		foreach ( ( new ReflectionClass( self::class ) )->getConstants() as $name => $value ) {
			$literals[ self::class . '::' . $name ] = var_export( $value, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Generates PHP source.
		}

		return strtr( $source, $literals );
	}
}

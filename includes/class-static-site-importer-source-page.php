<?php
/**
 * Static site source page model.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Represents one importable source document.
 */
class Static_Site_Importer_Source_Page {

	/**
	 * Source path relative to the static site root.
	 *
	 * @var string
	 */
	private string $source_key;

	/**
	 * Absolute source file path.
	 *
	 * @var string
	 */
	private string $path;

	/**
	 * Source kind.
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * HTML document used by the importer.
	 *
	 * @var Static_Site_Importer_Document
	 */
	private Static_Site_Importer_Document $document;

	/**
	 * Serialized block markup for the document body.
	 *
	 * @var string
	 */
	private string $body;

	/**
	 * Body markup format.
	 *
	 * @var string
	 */
	private string $body_format;

	/**
	 * Parsed document metadata.
	 *
	 * @var array<string,string>
	 */
	private array $metadata;

	/**
	 * Constructor.
	 *
	 * @param string                        $source_key  Source key.
	 * @param string                        $path        Absolute source path.
	 * @param string                        $type        Source type.
	 * @param Static_Site_Importer_Document $document    Parsed HTML document.
	 * @param string                        $body        Serialized body markup.
	 * @param string                        $body_format Body markup format.
	 * @param array<string,string>          $metadata    Parsed metadata.
	 */
	private function __construct( string $source_key, string $path, string $type, Static_Site_Importer_Document $document, string $body, string $body_format, array $metadata = array() ) {
		$this->source_key  = $source_key;
		$this->path        = $path;
		$this->type        = $type;
		$this->document    = $document;
		$this->body        = $body;
		$this->body_format = $body_format;
		$this->metadata    = $metadata;
	}

	/**
	 * Create a source page from a WordPress document artifact.
	 *
	 * @param array<string,mixed> $artifact WordPress document artifact.
	 * @return self|WP_Error
	 */
	public static function from_wordpress_document_artifact( array $artifact ) {
		$source_key = self::normalize_artifact_source_key( isset( $artifact['source_path'] ) ? (string) $artifact['source_path'] : (string) ( $artifact['path'] ?? '' ) );
		if ( '' === $source_key ) {
			$source_key = self::normalize_artifact_source_key( isset( $artifact['slug'] ) ? (string) $artifact['slug'] : '' );
		}
		if ( '' === $source_key ) {
			return new WP_Error( 'static_site_importer_document_artifact_missing_source', 'WordPress document artifact is missing a source_path/path/slug.' );
		}

		$content = '';
		foreach ( array( 'block_markup', 'content', 'post_content' ) as $key ) {
			if ( isset( $artifact[ $key ] ) && is_scalar( $artifact[ $key ] ) && '' !== trim( (string) $artifact[ $key ] ) ) {
				$content = (string) $artifact[ $key ];
				break;
			}
		}
		if ( '' === trim( $content ) ) {
			return new WP_Error( 'static_site_importer_document_artifact_empty_content', sprintf( 'WordPress document artifact is missing block markup: %s', $source_key ) );
		}

		$metadata = array();
		foreach ( array( 'title', 'slug', 'status', 'post_type', 'entrypoint' ) as $key ) {
			if ( isset( $artifact[ $key ] ) && is_scalar( $artifact[ $key ] ) && '' !== trim( (string) $artifact[ $key ] ) ) {
				$metadata[ $key ] = (string) $artifact[ $key ];
			}
		}
		if ( isset( $artifact['metadata'] ) && is_array( $artifact['metadata'] ) ) {
			foreach ( $artifact['metadata'] as $key => $value ) {
				if ( is_string( $key ) && is_scalar( $value ) ) {
					$metadata[ strtolower( str_replace( '-', '_', $key ) ) ] = (string) $value;
				}
			}
		}

		$title    = $metadata['title'] ?? '';
		$document = new Static_Site_Importer_Document( '<!doctype html><html><head><title>' . esc_html( $title ) . '</title></head><body><main>' . $content . '</main></body></html>' );

		return new self( $source_key, $source_key, 'wordpress_document_artifact', $document, $content, 'blocks', $metadata );
	}

	/**
	 * Create a source page from a Blocks Engine materialization-plan page row.
	 *
	 * @param array<string,mixed> $page Materialization-plan page row.
	 * @return self|WP_Error
	 */
	public static function from_materialization_plan_page( array $page ) {
		$source_key = self::normalize_artifact_source_key( isset( $page['source_path'] ) ? (string) $page['source_path'] : '' );
		if ( '' === $source_key ) {
			return new WP_Error( 'static_site_importer_materialization_plan_page_missing_source', 'Blocks Engine materialization-plan page is missing source_path.' );
		}

		$content = isset( $page['block_markup'] ) && is_scalar( $page['block_markup'] ) ? (string) $page['block_markup'] : '';
		if ( '' === trim( $content ) ) {
			return new WP_Error( 'static_site_importer_materialization_plan_page_empty_content', sprintf( 'Blocks Engine materialization-plan page is missing block markup: %s', $source_key ) );
		}

		$metadata = array();
		foreach ( array( 'title', 'slug', 'status', 'post_type', 'entrypoint', 'body_format', 'route_key', 'route_path' ) as $key ) {
			if ( isset( $page[ $key ] ) && is_scalar( $page[ $key ] ) && '' !== trim( (string) $page[ $key ] ) ) {
				$metadata[ $key ] = (string) $page[ $key ];
			}
		}
		if ( isset( $page['metadata'] ) && is_array( $page['metadata'] ) ) {
			foreach ( $page['metadata'] as $key => $value ) {
				if ( is_string( $key ) && is_scalar( $value ) ) {
					$metadata[ strtolower( str_replace( '-', '_', $key ) ) ] = (string) $value;
				}
			}
		}

		$title       = $metadata['title'] ?? '';
		$body_format = 'blocks';
		$document    = new Static_Site_Importer_Document( '<!doctype html><html><head><title>' . esc_html( $title ) . '</title></head><body><main>' . $content . '</main></body></html>' );

		return new self( $source_key, $source_key, 'materialization_plan_page', $document, $content, $body_format, $metadata );
	}

	/**
	 * Source key used by result maps and reports.
	 *
	 * @return string
	 */
	public function source_key(): string {
		return $this->source_key;
	}

	/**
	 * Absolute source file path.
	 *
	 * @return string
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Source type.
	 *
	 * @return string
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Parsed document.
	 *
	 * @return Static_Site_Importer_Document
	 */
	public function document(): Static_Site_Importer_Document {
		return $this->document;
	}

	/**
	 * Serialized body markup.
	 *
	 * @return string
	 */
	public function body(): string {
		return $this->body;
	}

	/**
	 * Body markup format.
	 *
	 * @return string
	 */
	public function body_format(): string {
		return $this->body_format;
	}

	/**
	 * Parsed document metadata.
	 *
	 * @return array<string,string>
	 */
	public function metadata(): array {
		return $this->metadata;
	}

	/**
	 * Get one parsed document metadata value.
	 *
	 * @param string $key Metadata key.
	 * @return string
	 */
	public function metadata_value( string $key ): string {
		$key = strtolower( str_replace( '-', '_', $key ) );

		return $this->metadata[ $key ] ?? '';
	}

	/**
	 * Normalize a document source key for report/result maps.
	 *
	 * @param string $source Source path or slug.
	 * @return string
	 */
	private static function normalize_artifact_source_key( string $source ): string {
		$source = str_replace( '\\', '/', trim( $source ) );
		$source = preg_replace( '/\0+/', '', $source );
		if ( ! is_string( $source ) || '' === $source || str_starts_with( $source, '/' ) || preg_match( '#^[a-z][a-z0-9+.-]*:#i', $source ) ) {
			return '';
		}

		$segments = array();
		foreach ( explode( '/', $source ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				return '';
			}
			$segments[] = preg_replace( '/[^A-Za-z0-9._-]/', '-', $segment );
		}

		return implode( '/', array_filter( $segments ) );
	}
}

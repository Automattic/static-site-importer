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
 * Represents one importable source page, regardless of source format.
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
	 * Body passed to Block Format Bridge for content conversion.
	 *
	 * @var string
	 */
	private string $body;

	/**
	 * Body format passed to Block Format Bridge.
	 *
	 * @var string
	 */
	private string $body_format;

	/**
	 * Raw frontmatter.
	 *
	 * @var string
	 */
	private string $frontmatter;

	/**
	 * Parsed conservative frontmatter metadata.
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
	 * @param string                        $body        Conversion body.
	 * @param string                        $body_format Conversion body format.
	 * @param string                        $frontmatter Raw frontmatter.
	 * @param array<string,string>          $metadata    Parsed metadata.
	 */
	private function __construct( string $source_key, string $path, string $type, Static_Site_Importer_Document $document, string $body, string $body_format, string $frontmatter = '', array $metadata = array() ) {
		$this->source_key  = $source_key;
		$this->path        = $path;
		$this->type        = $type;
		$this->document    = $document;
		$this->body        = $body;
		$this->body_format = $body_format;
		$this->frontmatter = $frontmatter;
		$this->metadata    = $metadata;
	}

	/**
	 * Create a source page from an HTML file.
	 *
	 * @param string $site_dir Static site root.
	 * @param string $path     Absolute HTML file path.
	 * @return self|WP_Error
	 */
	public static function from_html_file( string $site_dir, string $path ) {
		$document = Static_Site_Importer_Document::from_file( $path );
		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$fragments = $document->fragments();

		return new self( self::relative_source_key( $site_dir, $path ), $path, 'html', $document, $fragments['main'], 'html' );
	}

	/**
	 * Create a source page from a Markdown file.
	 *
	 * @param string $site_dir Static site root.
	 * @param string $path     Absolute Markdown file path.
	 * @return self|WP_Error
	 */
	public static function from_markdown_file( string $site_dir, string $path ) {
		if ( ! is_file( $path ) ) {
			return new WP_Error( 'static_site_importer_unreadable_file', sprintf( 'Markdown file is not readable: %s', $path ) );
		}

		$markdown = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local static-site Markdown source files from the import directory.
		if ( false === $markdown || '' === trim( $markdown ) ) {
			return new WP_Error( 'static_site_importer_empty_file', sprintf( 'Markdown file is empty: %s', $path ) );
		}

		$parts = self::parse_markdown_source( $markdown, basename( $path ) );
		if ( is_wp_error( $parts ) ) {
			return $parts;
		}

		$content     = $parts['content'];
		$frontmatter = $parts['frontmatter'];
		$metadata    = $parts['metadata'];
		$html        = self::markdown_to_html( $content );
		if ( is_wp_error( $html ) ) {
			return $html;
		}

		return new self( self::relative_source_key( $site_dir, $path ), $path, 'markdown', new Static_Site_Importer_Document( '<main>' . $html . '</main>' ), $content, 'markdown', $frontmatter, $metadata );
	}

	/**
	 * Create a source page from a BAC WordPress document artifact.
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
			return new WP_Error( 'static_site_importer_bac_document_missing_source', 'BAC document artifact is missing a source_path/path/slug.' );
		}

		$content = '';
		foreach ( array( 'block_markup', 'content', 'post_content' ) as $key ) {
			if ( isset( $artifact[ $key ] ) && is_scalar( $artifact[ $key ] ) && '' !== trim( (string) $artifact[ $key ] ) ) {
				$content = (string) $artifact[ $key ];
				break;
			}
		}
		if ( '' === trim( $content ) ) {
			return new WP_Error( 'static_site_importer_bac_document_empty_content', sprintf( 'BAC document artifact is missing block markup: %s', $source_key ) );
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

		return new self( $source_key, $source_key, 'bac_document', $document, $content, 'blocks', '', $metadata );
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
	 * Body passed to BFB for content conversion.
	 *
	 * @return string
	 */
	public function body(): string {
		return $this->body;
	}

	/**
	 * Body format passed to BFB for content conversion.
	 *
	 * @return string
	 */
	public function body_format(): string {
		return $this->body_format;
	}

	/**
	 * Raw frontmatter.
	 *
	 * @return string
	 */
	public function frontmatter(): string {
		return $this->frontmatter;
	}

	/**
	 * Parsed frontmatter metadata.
	 *
	 * @return array<string,string>
	 */
	public function metadata(): array {
		return $this->metadata;
	}

	/**
	 * Get one parsed frontmatter metadata value.
	 *
	 * @param string $key Metadata key.
	 * @return string
	 */
	public function metadata_value( string $key ): string {
		$key = strtolower( str_replace( '-', '_', $key ) );

		return $this->metadata[ $key ] ?? '';
	}

	/**
	 * Build a normalized source key relative to the site root.
	 *
	 * @param string $site_dir Static site root.
	 * @param string $path     Absolute source path.
	 * @return string
	 */
	private static function relative_source_key( string $site_dir, string $path ): string {
		$root = realpath( $site_dir );
		$file = realpath( $path );
		if ( false !== $root && false !== $file && str_starts_with( $file, trailingslashit( $root ) ) ) {
			return str_replace( DIRECTORY_SEPARATOR, '/', substr( $file, strlen( trailingslashit( $root ) ) ) );
		}

		return basename( $path );
	}

	/**
	 * Normalize a BAC document source key for report/result maps.
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

	/**
	 * Parse Markdown body and conservative YAML-style frontmatter metadata.
	 *
	 * @param string $markdown Markdown source.
	 * @param string $source   Source label for diagnostics.
	 * @return array{frontmatter:string,content:string,metadata:array<string,string>}|WP_Error
	 */
	private static function parse_markdown_source( string $markdown, string $source ) {
		$markdown = preg_replace( "/\r\n?|\n/", "\n", $markdown ) ?? $markdown;
		$markdown = preg_replace( '/^\xEF\xBB\xBF/', '', $markdown ) ?? $markdown;
		$lines    = explode( "\n", $markdown );

		if ( '---' !== trim( $lines[0] ) ) {
			return array(
				'frontmatter' => '',
				'content'     => $markdown,
				'metadata'    => array(),
			);
		}

		$closing_line = null;
		$count        = count( $lines );
		for ( $i = 1; $i < $count; $i++ ) {
			if ( '---' === trim( $lines[ $i ] ) ) {
				$closing_line = $i;
				break;
			}
		}

		if ( null === $closing_line ) {
			return new WP_Error( 'static_site_importer_malformed_markdown_frontmatter', sprintf( 'Malformed frontmatter in %s: missing closing --- delimiter.', $source ) );
		}

		$frontmatter_lines = array_slice( $lines, 1, $closing_line - 1 );
		$metadata          = self::parse_frontmatter_lines( $frontmatter_lines, $source );
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}

		return array(
			'frontmatter' => implode( "\n", $frontmatter_lines ),
			'content'     => ltrim( implode( "\n", array_slice( $lines, $closing_line + 1 ) ), "\n" ),
			'metadata'    => $metadata,
		);
	}

	/**
	 * Parse conservative key/value YAML frontmatter lines.
	 *
	 * @param array<int,string> $lines  Frontmatter lines.
	 * @param string            $source Source label for diagnostics.
	 * @return array<string,string>|WP_Error
	 */
	private static function parse_frontmatter_lines( array $lines, string $source ) {
		$metadata = array();
		foreach ( $lines as $index => $line ) {
			$trimmed = trim( $line );
			if ( '' === $trimmed || str_starts_with( $trimmed, '#' ) ) {
				continue;
			}

			if ( ! preg_match( '/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $matches ) ) {
				return new WP_Error( 'static_site_importer_malformed_markdown_frontmatter', sprintf( 'Malformed frontmatter in %s on line %d: expected "key: value".', $source, $index + 2 ) );
			}

			$key   = strtolower( str_replace( '-', '_', $matches[1] ) );
			$value = trim( $matches[2] );
			if ( '' !== $value && ( '[' === $value[0] || '{' === $value[0] ) ) {
				return new WP_Error( 'static_site_importer_malformed_markdown_frontmatter', sprintf( 'Malformed frontmatter in %s on line %d: only scalar metadata values are supported.', $source, $index + 2 ) );
			}

			$metadata[ $key ] = self::unquote_scalar( $value );
		}

		return $metadata;
	}

	/**
	 * Unquote a simple YAML scalar value.
	 *
	 * @param string $value Scalar value.
	 * @return string
	 */
	private static function unquote_scalar( string $value ): string {
		$length = strlen( $value );
		if ( $length >= 2 ) {
			$quote = $value[0];
			if ( ( '"' === $quote || "'" === $quote ) && $quote === $value[ $length - 1 ] ) {
				return substr( $value, 1, -1 );
			}
		}

		return $value;
	}

	/**
	 * Convert Markdown to HTML using the bundled CommonMark runtime.
	 *
	 * @param string $markdown Markdown source.
	 * @return string|WP_Error
	 */
	private static function markdown_to_html( string $markdown ) {
		if ( ! class_exists( '\League\CommonMark\CommonMarkConverter' ) ) {
			return new WP_Error( 'static_site_importer_missing_commonmark', 'League CommonMark is required to import Markdown content files.' );
		}

		$converter = new \League\CommonMark\CommonMarkConverter();
		return (string) $converter->convert( $markdown );
	}
}

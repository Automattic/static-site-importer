<?php
/**
 * Bundle-aware structured-data source.
 *
 * Many static site bundles render their catalog client-side from a static data
 * file (for example `js/products.js` exporting an array of product objects).
 * The rendered HTML for those pages imports empty because the content only
 * exists in the data file. This source reads those `.js`/`.json` data files
 * directly — without executing JavaScript — and recognizes entity-shaped arrays
 * by DATA SHAPE alone (a name-like field plus a price-like field), never by
 * variable names or site-specific keys.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extracts product candidates from static data files carried in a website artifact.
 */
class Static_Site_Importer_Bundle_Data_Source {

	/**
	 * Name-like field synonyms (normalized: lowercased, alphanumerics only).
	 *
	 * @var array<int,string>
	 */
	private const NAME_KEYS = array( 'name', 'title', 'productname', 'producttitle', 'label' );

	/**
	 * Price-like field synonyms.
	 *
	 * @var array<int,string>
	 */
	private const PRICE_KEYS = array( 'price', 'amount', 'cost', 'regularprice', 'baseprice', 'unitprice' );

	/**
	 * Sale-price field synonyms.
	 *
	 * @var array<int,string>
	 */
	private const SALE_PRICE_KEYS = array( 'saleprice', 'discountprice', 'specialprice' );

	/**
	 * Description field synonyms.
	 *
	 * @var array<int,string>
	 */
	private const DESCRIPTION_KEYS = array( 'description', 'desc', 'body', 'summary', 'details', 'notes', 'about', 'blurb' );

	/**
	 * Image field synonyms.
	 *
	 * @var array<int,string>
	 */
	private const IMAGE_KEYS = array( 'image', 'img', 'src', 'thumbnail', 'thumb', 'photo', 'picture', 'imageurl' );

	/**
	 * Category field synonyms.
	 *
	 * @var array<int,string>
	 */
	private const CATEGORY_KEYS = array( 'category', 'categories', 'type', 'collection', 'department', 'tags' );

	/**
	 * Explicit URL-slug field synonyms used to derive a stable slug.
	 *
	 * Internal identifiers (id/sku) are intentionally excluded: they are rarely
	 * URL slugs, and slugging from the name keeps the row dedupe-compatible with
	 * the DOM product detector, which slugs from the product name/URL.
	 *
	 * @var array<int,string>
	 */
	private const SLUG_KEYS = array( 'slug', 'handle', 'permalink', 'seoslug', 'urlkey' );

	/**
	 * Minimum fraction of array elements that must be product-shaped for the
	 * array to be treated as a product catalog.
	 */
	private const PRODUCT_SHAPE_RATIO = 0.6;

	/**
	 * Build generic product report rows from the data files in a website artifact.
	 *
	 * Rows use the canonical keys consumed by the transformer adapter's
	 * `normalize_product_report_row()` so the existing products-manifest/v1 path
	 * and WooCommerce seeder are reused.
	 *
	 * @param array<string,mixed> $artifact Website artifact bundle.
	 * @return array<int,array<string,mixed>>
	 */
	public static function product_rows_from_artifact( array $artifact ): array {
		$rows = array();
		foreach ( self::data_files_from_artifact( $artifact ) as $file ) {
			foreach ( self::object_arrays_from_source( $file['source'], $file['ext'] ) as $object_array ) {
				if ( ! self::is_product_shaped_array( $object_array ) ) {
					continue;
				}
				foreach ( $object_array as $object ) {
					$row = self::product_row_from_object( $object, $file['path'] );
					if ( array() !== $row ) {
						$rows[] = $row;
					}
				}
			}
		}

		return $rows;
	}

	/**
	 * Return decoded `.js`/`.json` data files from a website artifact.
	 *
	 * @param array<string,mixed> $artifact Website artifact bundle.
	 * @return array<int,array{path:string,ext:string,source:string}>
	 */
	private static function data_files_from_artifact( array $artifact ): array {
		$files  = isset( $artifact['files'] ) && is_array( $artifact['files'] ) ? $artifact['files'] : array();
		$result = array();
		foreach ( $files as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}

			$path = isset( $file['path'] ) && is_scalar( $file['path'] ) ? (string) $file['path'] : '';
			$ext  = self::data_extension( $path, $file );
			if ( '' === $ext ) {
				continue;
			}

			$source = self::file_content( $file );
			if ( '' === trim( $source ) ) {
				continue;
			}

			$result[] = array(
				'path'   => $path,
				'ext'    => $ext,
				'source' => $source,
			);
		}

		return $result;
	}

	/**
	 * Resolve a data-file extension (`js` or `json`) from path or declared type.
	 *
	 * @param string              $path Artifact path.
	 * @param array<string,mixed> $file Artifact file entry.
	 * @return string Empty string when the file is not a data file.
	 */
	private static function data_extension( string $path, array $file ): string {
		$lower = strtolower( $path );
		if ( str_ends_with( $lower, '.json' ) ) {
			return 'json';
		}
		if ( str_ends_with( $lower, '.mjs' ) || str_ends_with( $lower, '.cjs' ) || str_ends_with( $lower, '.js' ) ) {
			return 'js';
		}

		$kind = isset( $file['kind'] ) && is_scalar( $file['kind'] ) ? strtolower( (string) $file['kind'] ) : '';
		$mime = isset( $file['mime_type'] ) && is_scalar( $file['mime_type'] ) ? strtolower( (string) $file['mime_type'] ) : '';
		if ( 'json' === $kind || str_contains( $mime, 'json' ) ) {
			return 'json';
		}
		if ( 'js' === $kind || 'javascript' === $kind || str_contains( $mime, 'javascript' ) || str_contains( $mime, 'ecmascript' ) ) {
			return 'js';
		}

		return '';
	}

	/**
	 * Decode a website artifact file payload to text.
	 *
	 * @param array<string,mixed> $file Artifact file entry.
	 * @return string
	 */
	private static function file_content( array $file ): string {
		if ( isset( $file['content'] ) && is_scalar( $file['content'] ) ) {
			return (string) $file['content'];
		}
		if ( isset( $file['content_base64'] ) && is_scalar( $file['content_base64'] ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Website artifacts may carry trusted data payloads as base64.
			$decoded = base64_decode( (string) $file['content_base64'], true );
			return false !== $decoded ? $decoded : '';
		}

		return '';
	}

	/**
	 * Extract every array-of-objects literal from a data source.
	 *
	 * For `.json` the document is decoded strictly first; for `.js` (or invalid
	 * JSON) the lenient literal parser is used. The decoded structure is walked
	 * to collect any nested list whose elements are objects.
	 *
	 * @param string $source Raw file source.
	 * @param string $ext    Data extension (`js` or `json`).
	 * @return array<int,array<int,array<string,mixed>>>
	 */
	private static function object_arrays_from_source( string $source, string $ext ): array {
		/** @var array<int,array<int,array<string,mixed>>> $collected */
		$collected = array();

		if ( 'json' === $ext ) {
			$decoded = json_decode( $source, true );
			if ( null !== $decoded ) {
				self::collect_object_arrays( $decoded, $collected );
				return $collected;
			}
		}

		foreach ( self::literals_from_js( $source ) as $value ) {
			self::collect_object_arrays( $value, $collected );
		}

		return $collected;
	}

	/**
	 * Recursively collect lists whose elements are associative arrays.
	 *
	 * @param mixed                                       $value     Decoded value.
	 * @param array<int,array<int,array<string,mixed>>> &$collected Accumulator.
	 * @return void
	 */
	private static function collect_object_arrays( mixed $value, array &$collected ): void {
		if ( ! is_array( $value ) ) {
			return;
		}

		if ( self::is_object_list( $value ) ) {
			/** @var array<int,array<string,mixed>> $objects */
			$objects = array();
			foreach ( $value as $entry ) {
				if ( is_array( $entry ) && ! array_is_list( $entry ) ) {
					$object = array();
					foreach ( $entry as $key => $item ) {
						$object[ (string) $key ] = $item;
					}
					$objects[] = $object;
				}
			}
			if ( array() !== $objects ) {
				$collected[] = $objects;
			}
		}

		foreach ( $value as $child ) {
			self::collect_object_arrays( $child, $collected );
		}
	}

	/**
	 * Check whether a value is a non-empty list containing object entries.
	 *
	 * @param array<int|string,mixed> $value Decoded value.
	 * @return bool
	 */
	private static function is_object_list( array $value ): bool {
		if ( array() === $value || ! array_is_list( $value ) ) {
			return false;
		}

		foreach ( $value as $entry ) {
			if ( is_array( $entry ) && ! array_is_list( $entry ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Parse object/array literals out of arbitrary JavaScript source.
	 *
	 * Comments are stripped first; the scanner then skips string literals and
	 * attempts to parse a value at each top-level `{`/`[`, discarding fragments
	 * that do not parse as data literals (for example function bodies).
	 *
	 * @param string $source JavaScript source.
	 * @return array<int,mixed>
	 */
	private static function literals_from_js( string $source ): array {
		$source = self::strip_js_comments( $source );
		$length = strlen( $source );
		$values = array();
		$index  = 0;

		while ( $index < $length ) {
			$char = $source[ $index ];

			if ( '"' === $char || "'" === $char || '`' === $char ) {
				$index = self::skip_string( $source, $index );
				continue;
			}

			if ( '{' === $char || '[' === $char ) {
				$cursor = $index;
				$value  = self::parse_value( $source, $cursor );
				if ( self::PARSE_FAILED !== $value ) {
					$values[] = $value;
					$index    = $cursor;
					continue;
				}
			}

			++$index;
		}

		return $values;
	}

	/**
	 * Sentinel returned when a literal fails to parse.
	 */
	private const PARSE_FAILED = "\0__ssi_parse_failed__\0";

	/**
	 * Recursive-descent parse of one JavaScript data value.
	 *
	 * @param string $source JavaScript source (comment-stripped).
	 * @param int   &$index  Cursor; advanced past the parsed value on success.
	 * @return mixed Parsed value, or self::PARSE_FAILED.
	 */
	private static function parse_value( string $source, int &$index ): mixed {
		self::skip_whitespace( $source, $index );
		if ( $index >= strlen( $source ) ) {
			return self::PARSE_FAILED;
		}

		$char = $source[ $index ];
		if ( '{' === $char ) {
			return self::parse_object( $source, $index );
		}
		if ( '[' === $char ) {
			return self::parse_array( $source, $index );
		}
		if ( '"' === $char || "'" === $char || '`' === $char ) {
			return self::parse_string( $source, $index );
		}
		if ( '-' === $char || '+' === $char || '.' === $char || ctype_digit( $char ) ) {
			return self::parse_number( $source, $index );
		}

		return self::parse_keyword_or_reference( $source, $index );
	}

	/**
	 * Parse a JavaScript object literal into an associative array.
	 *
	 * @param string $source JavaScript source.
	 * @param int   &$index  Cursor positioned at `{`.
	 * @return mixed Associative array, or self::PARSE_FAILED.
	 */
	private static function parse_object( string $source, int &$index ): mixed {
		$length = strlen( $source );
		++$index; // Consume '{'.
		$object = array();

		while ( $index < $length ) {
			self::skip_whitespace( $source, $index );
			if ( $index >= $length ) {
				return self::PARSE_FAILED;
			}
			if ( '}' === $source[ $index ] ) {
				++$index;
				return $object;
			}

			$key = self::parse_key( $source, $index );
			if ( self::PARSE_FAILED === $key ) {
				return self::PARSE_FAILED;
			}

			self::skip_whitespace( $source, $index );
			if ( $index >= $length || ':' !== $source[ $index ] ) {
				return self::PARSE_FAILED;
			}
			++$index; // Consume ':'.

			$value = self::parse_value( $source, $index );
			if ( self::PARSE_FAILED === $value ) {
				return self::PARSE_FAILED;
			}
			$object[ $key ] = $value;

			self::skip_whitespace( $source, $index );
			if ( $index < $length && ',' === $source[ $index ] ) {
				++$index;
				continue;
			}
			if ( $index < $length && '}' === $source[ $index ] ) {
				++$index;
				return $object;
			}

			return self::PARSE_FAILED;
		}

		return self::PARSE_FAILED;
	}

	/**
	 * Parse a JavaScript array literal into a list.
	 *
	 * @param string $source JavaScript source.
	 * @param int   &$index  Cursor positioned at `[`.
	 * @return mixed List array, or self::PARSE_FAILED.
	 */
	private static function parse_array( string $source, int &$index ): mixed {
		$length = strlen( $source );
		++$index; // Consume '['.
		$list = array();

		while ( $index < $length ) {
			self::skip_whitespace( $source, $index );
			if ( $index >= $length ) {
				return self::PARSE_FAILED;
			}
			if ( ']' === $source[ $index ] ) {
				++$index;
				return $list;
			}

			$value = self::parse_value( $source, $index );
			if ( self::PARSE_FAILED === $value ) {
				return self::PARSE_FAILED;
			}
			$list[] = $value;

			self::skip_whitespace( $source, $index );
			if ( $index < $length && ',' === $source[ $index ] ) {
				++$index;
				continue;
			}
			if ( $index < $length && ']' === $source[ $index ] ) {
				++$index;
				return $list;
			}

			return self::PARSE_FAILED;
		}

		return self::PARSE_FAILED;
	}

	/**
	 * Parse an object key (quoted string or bareword identifier).
	 *
	 * @param string $source JavaScript source.
	 * @param int   &$index  Cursor.
	 * @return string|mixed Key string, or self::PARSE_FAILED.
	 */
	private static function parse_key( string $source, int &$index ): mixed {
		$char = $source[ $index ] ?? '';
		if ( '"' === $char || "'" === $char || '`' === $char ) {
			return self::parse_string( $source, $index );
		}

		$length = strlen( $source );
		$start  = $index;
		while ( $index < $length ) {
			$current = $source[ $index ];
			if ( ctype_alnum( $current ) || '_' === $current || '$' === $current ) {
				++$index;
				continue;
			}
			break;
		}

		if ( $index === $start ) {
			return self::PARSE_FAILED;
		}

		return substr( $source, $start, $index - $start );
	}

	/**
	 * Parse a quoted string literal (single, double, or backtick quotes).
	 *
	 * @param string $source JavaScript source.
	 * @param int   &$index  Cursor positioned at the opening quote.
	 * @return string
	 */
	private static function parse_string( string $source, int &$index ): string {
		$length = strlen( $source );
		$quote  = $source[ $index ];
		++$index; // Consume opening quote.
		$value = '';

		while ( $index < $length ) {
			$char = $source[ $index ];
			if ( '\\' === $char && $index + 1 < $length ) {
				$value .= self::unescape_sequence( $source[ $index + 1 ] );
				$index += 2;
				continue;
			}
			if ( $char === $quote ) {
				++$index; // Consume closing quote.
				break;
			}
			$value .= $char;
			++$index;
		}

		return $value;
	}

	/**
	 * Translate a backslash escape sequence into its literal character.
	 *
	 * @param string $escaped Character following the backslash.
	 * @return string
	 */
	private static function unescape_sequence( string $escaped ): string {
		return match ( $escaped ) {
			'n'     => "\n",
			't'     => "\t",
			'r'     => "\r",
			default => $escaped,
		};
	}

	/**
	 * Parse a numeric literal into an int or float.
	 *
	 * @param string $source JavaScript source.
	 * @param int   &$index  Cursor.
	 * @return mixed Number, or self::PARSE_FAILED.
	 */
	private static function parse_number( string $source, int &$index ): mixed {
		$length = strlen( $source );
		$start  = $index;
		if ( '-' === $source[ $index ] || '+' === $source[ $index ] ) {
			++$index;
		}
		$has_digit = false;
		while ( $index < $length ) {
			$char = $source[ $index ];
			if ( ctype_digit( $char ) ) {
				$has_digit = true;
				++$index;
				continue;
			}
			if ( '.' === $char || 'e' === $char || 'E' === $char || '+' === $char || '-' === $char ) {
				++$index;
				continue;
			}
			break;
		}

		if ( ! $has_digit ) {
			return self::PARSE_FAILED;
		}

		$raw = substr( $source, $start, $index - $start );
		if ( ! is_numeric( $raw ) ) {
			return self::PARSE_FAILED;
		}

		return ( floor( (float) $raw ) === (float) $raw && ! str_contains( $raw, '.' ) && ! str_contains( strtolower( $raw ), 'e' ) )
			? (int) $raw
			: (float) $raw;
	}

	/**
	 * Parse `true`/`false`/`null`, or skip an unresolvable reference/expression.
	 *
	 * Variable references, member expressions, and calls cannot be resolved
	 * without executing JavaScript; they are consumed and treated as null so the
	 * surrounding literal still parses.
	 *
	 * @param string $source JavaScript source.
	 * @param int   &$index  Cursor.
	 * @return mixed Boolean, null, or self::PARSE_FAILED.
	 */
	private static function parse_keyword_or_reference( string $source, int &$index ): mixed {
		$length = strlen( $source );
		$start  = $index;
		while ( $index < $length ) {
			$char = $source[ $index ];
			if ( ctype_alnum( $char ) || '_' === $char || '$' === $char ) {
				++$index;
				continue;
			}
			break;
		}

		if ( $index === $start ) {
			return self::PARSE_FAILED;
		}

		$word = strtolower( substr( $source, $start, $index - $start ) );
		if ( 'true' === $word ) {
			$resolved = true;
		} elseif ( 'false' === $word ) {
			$resolved = false;
		} else {
			$resolved = null; // null, undefined, NaN, or an unresolvable reference.
		}

		// Consume any member access, subscript, or call chain on the reference.
		self::skip_reference_chain( $source, $index );

		return $resolved;
	}

	/**
	 * Skip a trailing `.member`, `[...]`, or `(...)` chain after a reference.
	 *
	 * @param string $source JavaScript source.
	 * @param int   &$index  Cursor.
	 * @return void
	 */
	private static function skip_reference_chain( string $source, int &$index ): void {
		$length = strlen( $source );
		while ( $index < $length ) {
			self::skip_whitespace( $source, $index );
			$char = $source[ $index ] ?? '';
			if ( '.' === $char ) {
				++$index;
				while ( $index < $length ) {
					$current = $source[ $index ];
					if ( ctype_alnum( $current ) || '_' === $current || '$' === $current ) {
						++$index;
						continue;
					}
					break;
				}
				continue;
			}
			if ( '[' === $char || '(' === $char ) {
				self::skip_balanced( $source, $index );
				continue;
			}
			break;
		}
	}

	/**
	 * Skip a balanced bracket/paren group, respecting nested strings.
	 *
	 * @param string $source JavaScript source.
	 * @param int   &$index  Cursor positioned at the opening bracket.
	 * @return void
	 */
	private static function skip_balanced( string $source, int &$index ): void {
		$length = strlen( $source );
		$open   = $source[ $index ];
		$close  = '[' === $open ? ']' : ')';
		$depth  = 0;
		while ( $index < $length ) {
			$char = $source[ $index ];
			if ( '"' === $char || "'" === $char || '`' === $char ) {
				$index = self::skip_string( $source, $index );
				continue;
			}
			if ( $char === $open ) {
				++$depth;
			} elseif ( $char === $close ) {
				--$depth;
				if ( 0 === $depth ) {
					++$index;
					return;
				}
			}
			++$index;
		}
	}

	/**
	 * Advance past a string literal starting at the opening quote.
	 *
	 * @param string $source Source text.
	 * @param int    $index  Cursor positioned at the opening quote.
	 * @return int Index just past the closing quote.
	 */
	private static function skip_string( string $source, int $index ): int {
		$length = strlen( $source );
		$quote  = $source[ $index ];
		++$index;
		while ( $index < $length ) {
			$char = $source[ $index ];
			if ( '\\' === $char ) {
				$index += 2;
				continue;
			}
			++$index;
			if ( $char === $quote ) {
				break;
			}
		}

		return $index;
	}

	/**
	 * Advance the cursor past whitespace.
	 *
	 * @param string $source Source text.
	 * @param int   &$index  Cursor.
	 * @return void
	 */
	private static function skip_whitespace( string $source, int &$index ): void {
		$length = strlen( $source );
		while ( $index < $length && ctype_space( $source[ $index ] ) ) {
			++$index;
		}
	}

	/**
	 * Remove `//` line and block comments while preserving string contents.
	 *
	 * @param string $source JavaScript source.
	 * @return string
	 */
	private static function strip_js_comments( string $source ): string {
		$length = strlen( $source );
		$out    = '';
		$index  = 0;
		while ( $index < $length ) {
			$char = $source[ $index ];
			$next = $index + 1 < $length ? $source[ $index + 1 ] : '';

			if ( '"' === $char || "'" === $char || '`' === $char ) {
				$end   = self::skip_string( $source, $index );
				$out  .= substr( $source, $index, $end - $index );
				$index = $end;
				continue;
			}

			if ( '/' === $char && '/' === $next ) {
				$index += 2;
				while ( $index < $length && "\n" !== $source[ $index ] ) {
					++$index;
				}
				continue;
			}

			if ( '/' === $char && '*' === $next ) {
				$index += 2;
				while ( $index + 1 < $length && ! ( '*' === $source[ $index ] && '/' === $source[ $index + 1 ] ) ) {
					++$index;
				}
				$index += 2;
				continue;
			}

			$out .= $char;
			++$index;
		}

		return $out;
	}

	/**
	 * Determine whether a list of objects is product-shaped.
	 *
	 * An object is product-shaped when it has a name-like field plus a
	 * price-like field. The list qualifies when a strong majority of its
	 * objects are product-shaped, which rejects config maps and navigation
	 * arrays that lack a price signal.
	 *
	 * @param array<int,array<string,mixed>> $objects Object list.
	 * @return bool
	 */
	private static function is_product_shaped_array( array $objects ): bool {
		$total = count( $objects );
		if ( 0 === $total ) {
			return false;
		}

		$matches = 0;
		foreach ( $objects as $item ) {
			if ( self::is_product_shaped_object( $item ) ) {
				++$matches;
			}
		}

		return $matches > 0 && ( $matches / $total ) >= self::PRODUCT_SHAPE_RATIO;
	}

	/**
	 * Determine whether a single object carries product-shaped fields.
	 *
	 * @param array<string,mixed> $item Object.
	 * @return bool
	 */
	private static function is_product_shaped_object( array $item ): bool {
		$normalized = self::normalized_keys( $item );
		$name       = self::first_key_value( $item, $normalized, self::NAME_KEYS );
		$price      = self::first_key_value( $item, $normalized, self::PRICE_KEYS );

		return '' !== self::scalar_text( $name ) && '' !== self::price_to_decimal( $price );
	}

	/**
	 * Map a product-shaped object to a generic product report row.
	 *
	 * @param array<string,mixed> $item        Source object.
	 * @param string              $source_path Artifact source path.
	 * @return array<string,mixed>
	 */
	private static function product_row_from_object( array $item, string $source_path ): array {
		$normalized = self::normalized_keys( $item );

		$name  = self::scalar_text( self::first_key_value( $item, $normalized, self::NAME_KEYS ) );
		$price = self::price_to_decimal( self::first_key_value( $item, $normalized, self::PRICE_KEYS ) );
		if ( '' === $name || '' === $price ) {
			return array();
		}

		$row = array(
			'kind'             => 'product',
			'name'             => $name,
			'slug'             => self::slug_from_object( $item, $normalized, $name ),
			'regular_price'    => $price,
			'source_path'      => $source_path,
			'source_selectors' => array( 'bundle-data:' . self::basename_path( $source_path ) ),
		);

		$sale = self::price_to_decimal( self::first_key_value( $item, $normalized, self::SALE_PRICE_KEYS ) );
		if ( '' !== $sale ) {
			$row['sale_price'] = $sale;
		}

		$description = self::scalar_text( self::first_key_value( $item, $normalized, self::DESCRIPTION_KEYS ) );
		if ( '' !== $description ) {
			$row['description'] = $description;
		}

		$image = self::scalar_text( self::first_key_value( $item, $normalized, self::IMAGE_KEYS ) );
		if ( '' !== $image ) {
			$row['image'] = $image;
		}

		$categories = self::string_collection( self::first_key_value( $item, $normalized, self::CATEGORY_KEYS ) );
		if ( array() !== $categories ) {
			$row['categories'] = $categories;
		}

		return $row;
	}

	/**
	 * Derive a slug from an identifier-like field, falling back to the name.
	 *
	 * @param array<string,mixed>   $item       Source object.
	 * @param array<string,string>  $normalized Normalized-key map.
	 * @param string                $name       Resolved product name.
	 * @return string
	 */
	private static function slug_from_object( array $item, array $normalized, string $name ): string {
		$candidate = self::scalar_text( self::first_key_value( $item, $normalized, self::SLUG_KEYS ) );
		$source    = '' !== $candidate ? $candidate : $name;

		return self::slugify( $source );
	}

	/**
	 * Build a lowercase URL slug.
	 *
	 * @param string $value Source text.
	 * @return string
	 */
	private static function slugify( string $value ): string {
		if ( function_exists( 'sanitize_title' ) ) {
			return sanitize_title( $value );
		}

		return trim( strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '-', $value ) ), '-' );
	}

	/**
	 * Map an object's keys to their normalized forms (lowercase alphanumerics).
	 *
	 * @param array<string,mixed> $item Source object.
	 * @return array<string,string> Normalized key => original key.
	 */
	private static function normalized_keys( array $item ): array {
		$map = array();
		foreach ( array_keys( $item ) as $key ) {
			$map[ self::normalize_key( (string) $key ) ] = (string) $key;
		}

		return $map;
	}

	/**
	 * Normalize a field name to lowercase alphanumerics for synonym matching.
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	private static function normalize_key( string $key ): string {
		return strtolower( (string) preg_replace( '/[^a-z0-9]/i', '', $key ) );
	}

	/**
	 * Return the first value whose normalized key matches a synonym.
	 *
	 * @param array<string,mixed>  $item       Source object.
	 * @param array<string,string> $normalized Normalized-key map.
	 * @param array<int,string>    $synonyms   Normalized synonym list.
	 * @return mixed
	 */
	private static function first_key_value( array $item, array $normalized, array $synonyms ): mixed {
		foreach ( $synonyms as $synonym ) {
			if ( isset( $normalized[ $synonym ] ) ) {
				$original = $normalized[ $synonym ];
				if ( array_key_exists( $original, $item ) ) {
					return $item[ $original ];
				}
			}
		}

		return null;
	}

	/**
	 * Return a trimmed scalar string, or an empty string for non-scalars.
	 *
	 * @param mixed $value Candidate value.
	 * @return string
	 */
	private static function scalar_text( mixed $value ): string {
		if ( is_bool( $value ) || null === $value || is_array( $value ) ) {
			return '';
		}

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Convert a numeric or currency-formatted price into a plain decimal string.
	 *
	 * @param mixed $value Candidate price.
	 * @return string Empty string when no price can be extracted.
	 */
	private static function price_to_decimal( mixed $value ): string {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		if ( ! is_string( $value ) ) {
			return '';
		}

		$text = str_replace( ',', '', $value );
		if ( preg_match( '/-?\d+(?:\.\d+)?/', $text, $matches ) ) {
			return $matches[0];
		}

		return '';
	}

	/**
	 * Normalize a category-like value into a list of non-empty strings.
	 *
	 * @param mixed $value Category value (string or list).
	 * @return array<int,string>
	 */
	private static function string_collection( mixed $value ): array {
		$values = array();
		if ( is_array( $value ) ) {
			foreach ( $value as $entry ) {
				$text = self::scalar_text( $entry );
				if ( '' !== $text ) {
					$values[] = $text;
				}
			}
		} else {
			$text = self::scalar_text( $value );
			if ( '' !== $text ) {
				$values[] = $text;
			}
		}

		return array_values( array_unique( $values ) );
	}

	/**
	 * Return the file basename for provenance labelling.
	 *
	 * @param string $path Artifact path.
	 * @return string
	 */
	private static function basename_path( string $path ): string {
		$base = basename( $path );

		return '' !== $base ? $base : $path;
	}
}

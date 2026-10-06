<?php
/** HTML API boundary for the standalone save-shape fixture; real WP tests cover binding. */
class WP_HTML_Tag_Processor {
	private DOMDocument $document;
	private ?DOMElement $image = null;
	private string $original   = '';

	public function __construct( private string $html ) {
		$this->document = new DOMDocument();
	}

	public function next_tag( array $query ): bool {
		if ( 'IMG' !== ( $query['tag_name'] ?? null ) || ! preg_match( '/<img\b[^>]*>/i', $this->html, $match ) ) {
			return false;
		}
		$this->original = $match[0];
		$this->document->loadHTML( $this->original, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		$this->image = $this->document->getElementsByTagName( 'img' )->item( 0 );
		return null !== $this->image;
	}

	public function get_attribute( string $name ): string {
		return $this->image->getAttribute( $name );
	}

	public function set_attribute( string $name, string $value ): void {
		$this->image->setAttribute( $name, $value );
	}

	private function class_names(): array {
		$names = preg_split( '/\s+/', trim( $this->get_attribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $names ) ? $names : array();
	}

	public function remove_class( string $class_name ): void {
		$this->set_attribute( 'class', implode( ' ', array_diff( $this->class_names(), array( $class_name ) ) ) );
	}

	public function add_class( string $class_name ): void {
		$this->set_attribute( 'class', implode( ' ', array_unique( array_merge( $this->class_names(), array( $class_name ) ) ) ) );
	}

	public function get_updated_html(): string {
		return str_replace( $this->original, $this->document->saveHTML( $this->image ), $this->html );
	}
}

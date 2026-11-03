<?php
/** Neutral whole-page event handoffs publish TEC's theme-origin document template. */
require __DIR__ . '/smoke-tec-whole-page-media.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-tec-event-seeder.php';
function post_type_exists( $type ) { return true; }
function get_posts( $args ) { return array(); }
function get_option( $key, $default = false ) { return $default; }
function get_permalink( $id ) { return 'https://example.test/event/sample/'; }
function wp_mkdir_p( $path ) { return is_dir( $path ) || mkdir( $path, 0777, true ); }
function tribe_venues() { return tribe_events(); }
function tribe_events() {
	return new class {
		private array $fields = array();
		public function set_args( $fields ) { $this->fields = $fields; return $this; }
		public function create() {
			$post = new WP_Post( 102, 'publish', $this->fields['content'] );
			$GLOBALS['posts'][102] = $post;
			return $post;
		}
	};
}
$manifest = array( 'events' => array( $row + array(
	'name' => 'Sample event', 'start_date' => '2027-04-03T18:00:00Z', 'end_date' => '2027-04-03T20:00:00Z',
) ) );
$args = array(
	'declaration_reconciliation_identity' => 'declaration-events',
	'whole_page_documents' => Static_Site_Importer_Whole_Page_Handoff::documents( $pages ),
);
$body = "<!-- wp:post-content /-->\n";
$write = array(
	'kind' => 'theme_template', 'source_path' => 'templates/page.html', 'target_path' => 'templates/page.html',
	'payload' => array( 'encoding' => 'utf8', 'data' => $body ), 'payload_hash' => hash( 'sha256', $body ),
);
$directory = sys_get_temp_dir() . '/ssi-event-template-' . bin2hex( random_bytes( 8 ) );
try {
	foreach ( array( false, true ) as $commit ) {
		$report = Static_Site_Importer_TEC_Event_Seeder::seed( $manifest, $commit ? $args : array() );
		$fixture = array(
			'args' => array( 'whole_page_provider_results' => $report['whole_page_results'] ?? array() ),
			'ordered_pages' => $pages, 'diagnostics' => array(), 'source_route_aliases' => array(),
			'applied' => array( 'runtime_declarations' => array( 'entity_bindings' => array() ) ),
			'resolved' => array( 'writes' => array( $write ) ),
		);
		$arguments = array( &$fixture );
		$assert( true === $apply->invokeArgs( null, $arguments ), 'Handoff must be admitted.' );
		// Exercise the same alias expansion, journal and writer used by materialization.
		if ( method_exists( Static_Site_Importer_Site_Plan_Persistence::class, 'append_handoff_template_aliases' ) ) {
			Static_Site_Importer_Site_Plan_Persistence::append_handoff_template_aliases( $fixture );
		}
		foreach ( $fixture['resolved']['writes'] as $file ) {
			Static_Site_Importer_Site_Plan_Persistence::journal_file( $fixture, $directory . '/' . $file['target_path'] );
			$assert( ! is_wp_error( Static_Site_Importer_Site_Plan_Persistence::write_file( $directory, $file ) ), 'Template write succeeds.' );
		}
		$event_path = $directory . '/templates/single-event.html';
		$assert( $commit === is_file( $event_path ), 'Only committed event handoffs write single-event.html.' );
		if ( $commit ) {
			$assert( file_get_contents( $directory . '/templates/page.html' ) === file_get_contents( $event_path ), 'Event and page templates share exactly the same body.' );
			$assert( isset( $fixture['rollback']['files'][ $event_path ] ), 'Event template participates in file rollback journaling.' );
		}
	}
} finally {
	foreach ( glob( $directory . '/templates/*' ) ?: array() as $file ) { unlink( $file ); }
	if ( is_dir( $directory . '/templates' ) ) { rmdir( $directory . '/templates' ); }
	if ( is_dir( $directory ) ) { rmdir( $directory ); }
}
echo "PASS TEC single-event theme template\n";

<?php
/** Neutral generic/events/v1 consumer regression. Run: php tests/smoke-tec-event-seeder.php */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
class WP_Error {
	public function __construct( private string $code, private string $message ) {}
	public function get_error_code(): string {
		return $this->code; }
}
function is_wp_error( $value ): bool {
	return $value instanceof WP_Error; }
function post_type_exists( $type ): bool {
	return ! empty( $GLOBALS['tec_test_available'] ); }
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
$assert  = static function ( $condition, $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); }
};
$rows    = array(
	array(
		'source_path'  => 'website/events/upcoming.html',
		'source_route' => 'events/upcoming',
		'name'         => 'Upcoming',
		'start_date'   => '2027-04-03T18:00:00-04:00',
		'end_date'     => '2027-04-03T20:00:00-04:00',
		'venue'        => array(
			'name'    => 'Hall A',
			'address' => array( 'streetAddress' => '1 Main St' ),
		),
		'image'        => 'https://example.org/upcoming.jpg',
	),
	array(
		'source_path'  => 'website/events/past.html',
		'source_route' => 'events/past',
		'name'         => 'Past',
		'start_date'   => '2020-01-03T18:00:00Z',
		'end_date'     => '2020-01-03T20:00:00Z',
	),
);
$adapter = Static_Site_Importer_Entity_Materializer_Registry::adapter_for_capability( 'events' );
$assert( 'the-events-calendar' === ( $adapter['provider'] ?? '' ), 'TEC is the selected events provider' );
$validation = Static_Site_Importer_Entity_Materializer_Registry::validate_manifest_generic( $adapter, array( 'events' => $rows ) );
$assert( empty( $validation['errors'] ) && 2 === count( $validation['events'] ?? array() ), 'past/upcoming and missing optionals validate' );
$portable = $rows[0];
$portable['image'] = '/media/upcoming.avif';
$assert( empty( Static_Site_Importer_Entity_Materializer_Registry::validate_manifest_generic( $adapter, array( 'events' => array( $portable ) ) )['errors'] ), 'producer portable media reference validates without inventing an attachment' );
$portable['image'] = '/media/../private.avif';
$assert( ! empty( Static_Site_Importer_Entity_Materializer_Registry::validate_manifest_generic( $adapter, array( 'events' => array( $portable ) ) )['errors'] ), 'portable media reference cannot traverse directories' );
$assert( ! isset( $validation['events'][1]['venue'], $validation['events'][1]['image'] ), 'optionals stay absent' );
$duplicate = Static_Site_Importer_Entity_Materializer_Registry::validate_manifest_generic( $adapter, array( 'events' => array( $rows[0], $rows[0] ) ) );
$assert( ! empty( $duplicate['errors'] ), 'duplicate source identity rejected' );
$plan      = array(
	'runtime_declarations' => array(
		array(
			'kind'                    => 'entity_collection',
			'type'                    => 'events',
			'reconciliation_identity' => str_repeat( 'a', 64 ),
			'payload'                 => array(
				'schema'   => 'generic/events/v1',
				'entities' => $rows,
			),
		),
	),
);
$lifecycle = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle( $plan, array() );
$assert( ! is_wp_error( $lifecycle ) && ! empty( $lifecycle['entities'][ str_repeat( 'a', 64 ) ]['required'] ) && ! empty( $lifecycle['dependencies'][ str_repeat( 'a', 64 ) ]['required'] ), 'unbound typed events request provider and dependency' );
$bad             = $rows[0];
$bad['end_date'] = '2027-04-03T17:00:00-04:00';
$assert( ! empty( Static_Site_Importer_Entity_Materializer_Registry::validate_manifest_generic( $adapter, array( 'events' => array( $bad ) ) )['errors'] ), 'reversed dates rejected' );
$result = Static_Site_Importer_Entity_Materializer_Registry::materialize( $adapter, $validation );
$assert( false === ( $result['provider_available'] ?? true ) && 'failed' === ( $result['status'] ?? '' ), 'unavailable TEC fails explicitly' );

/** Deliberately small ORM double; it tests ownership and compensation, not TEC occurrence tables. */
class WP_Post {
	public function __construct( public int $ID, public string $post_title, public string $post_content = '', public string $post_status = 'publish' ) {}
}
class Test_Repository {
	private array $args = array();
	private int $id     = 0;
	public function __construct( private string $type ) {}
	public function set_args( array $args ): self {
		$this->args = $args;
		return $this; }
	public function where( string $field, int $id ): self {
		$this->id = $id;
		return $this; }
	public function set( string $field, $value ): self {
		$this->args[ $field ] = $value;
		return $this; }
	public function create(): WP_Post {
		$id                          = ++$GLOBALS['tec_next_id'];
		$post                        = new WP_Post( $id, $this->args['title'], $this->args['content'] ?? '' );
		$GLOBALS['tec_posts'][ $id ] = array(
			'type' => $this->type,
			'post' => $post,
			'args' => $this->args,
			'meta' => array(
				'_EventStartDate' => $this->args['start_date'] ?? '',
				'_EventEndDate'   => $this->args['end_date'] ?? '',
				'_EventTimezone'  => $this->args['timezone'] ?? '',
			),
		);
		return $post;
	}
	public function save(): array {
		$post                                      = $GLOBALS['tec_posts'][ $this->id ]['post'];
		$post->post_title                          = $this->args['title'] ?? $post->post_title;
		$post->post_content                        = $this->args['content'] ?? $post->post_content;
		$GLOBALS['tec_posts'][ $this->id ]['args'] = array_merge( $GLOBALS['tec_posts'][ $this->id ]['args'], $this->args );
		foreach ( array( 'start_date' => '_EventStartDate', 'end_date' => '_EventEndDate', 'timezone' => '_EventTimezone' ) as $field => $meta ) {
			if ( isset( $this->args[ $field ] ) ) {
				$GLOBALS['tec_posts'][ $this->id ]['meta'][ $meta ] = $this->args[ $field ];
			}
		}
		return array( $this->id => true );
	}
}
function tribe_events(): Test_Repository {
	return new Test_Repository( 'tribe_events' ); }
function tribe_venues(): Test_Repository {
	return new Test_Repository( 'tribe_venue' ); }
function get_posts( array $query ): array {
	$found = array();
	foreach ( $GLOBALS['tec_posts'] as $id => $row ) {
		if ( $row['type'] === $query['post_type'] && ( $row['meta'][ $query['meta_key'] ] ?? null ) === $query['meta_value'] ) {
			$found[] = $id; }
	}
	return $found;
}
function get_post( int $id ): WP_Post {
	return $GLOBALS['tec_posts'][ $id ]['post']; }
function get_post_meta( int $id, string $key ): string {
	return (string) ( $GLOBALS['tec_posts'][ $id ]['meta'][ $key ] ?? '' ); }
function update_post_meta( int $id, string $key, string $value ): bool {
	$GLOBALS['tec_posts'][ $id ]['meta'][ $key ] = $value;
	return true; }
function get_permalink( int $id ): string {
	return 'https://example.org/event/' . $id; }
function wp_delete_post( int $id, bool $force ): bool {
	unset( $GLOBALS['tec_posts'][ $id ] );
	return true; }
$GLOBALS['tec_test_available'] = true;
$GLOBALS['tec_next_id']        = 0;
$GLOBALS['tec_posts']          = array();
$first                         = Static_Site_Importer_Entity_Materializer_Registry::materialize( $adapter, $validation );
$assert( 'completed' === $first['status'] && 2 === $first['counts']['created'], 'two source-owned events created' );
$assert( 3 === count( $GLOBALS['tec_posts'] ), 'venue and both events created' );
$assert( 'UTC' === $GLOBALS['tec_posts'][ $first['events'][0]['id'] ]['args']['timezone'] && '2027-04-03 22:00:00' === $GLOBALS['tec_posts'][ $first['events'][0]['id'] ]['args']['start_date'], 'source offset is converted to a supported UTC timezone without changing the instant' );
$assert( 'Upcoming' === $GLOBALS['tec_posts'][ $first['events'][0]['id'] ]['args']['title'], 'event uses the supported TEC title alias' );
$assert( ! isset( $GLOBALS['tec_posts'][ $first['events'][1]['id'] ]['args']['venue'] ), 'minimal past event has no invented venue' );
$updated = $validation;
$updated['events'][0]['name'] = 'Upcoming revised';
$updated['events'][0]['start_date'] = '2027-04-03T19:00:00-04:00';
$second = Static_Site_Importer_Entity_Materializer_Registry::materialize( $adapter, $updated );
$assert( 2 === $second['counts']['updated'] && 3 === count( $GLOBALS['tec_posts'] ), 'repeat import updates exact source identities' );
$assert( 'Upcoming revised' === get_post( $first['events'][0]['id'] )->post_title, 'update changes the owned event' );
$assert( 'rolled_back' === Static_Site_Importer_TEC_Event_Seeder::rollback( $second )['status'] && 3 === count( $GLOBALS['tec_posts'] ), 'update rollback preserves preexisting entities' );
$assert( 'Upcoming' === get_post( $first['events'][0]['id'] )->post_title && '2027-04-03 22:00:00' === get_post_meta( $first['events'][0]['id'], '_EventStartDate' ), 'update rollback restores title and date' );
$assert( 'rolled_back' === Static_Site_Importer_TEC_Event_Seeder::rollback( $first )['status'] && empty( $GLOBALS['tec_posts'] ), 'create rollback removes owned events and venue' );
echo "OK: TEC neutral event contract\n";

<?php
/** Typed event admission and unavailable-provider checks; writes use real TEC acceptance. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
class WP_Error {
	public function __construct( private string $code, private string $message ) {}
	public function get_error_code(): string {
		return $this->code;
	}
}
function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
$assert  = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$rows    = array(
	array(
		'id'           => 'future',
		'source_path'  => 'website/events/future.html',
		'source_route' => '/events/future',
		'name'         => 'Future',
		'start_date'   => '2027-04-03T18:00:00-04:00',
		'end_date'     => '2027-04-03T20:00:00-04:00',
		'venue'        => array(
			'name'    => 'Hall',
			'address' => array( 'streetAddress' => '1 Main St' ),
		),
		'image'        => '/media/future.png',
	),
	array(
		'id'           => 'past',
		'source_path'  => 'website/events/past.html',
		'source_route' => '/events/past',
		'name'         => 'Past',
		'start_date'   => '2020-01-03T18:00:00Z',
		'end_date'     => '2020-01-03T20:00:00Z',
	),
);
$adapter = Static_Site_Importer_Entity_Materializer_Registry::adapter_for_capability( 'events' );
$assert( 'the-events-calendar' === ( $adapter['provider'] ?? '' ), 'Typed events select TEC.' );
$validation = Static_Site_Importer_Entity_Materializer_Registry::validate_manifest_generic( $adapter, array( 'events' => $rows ) );
$assert( empty( $validation['errors'] ) && 2 === count( $validation['events'] ), 'Source-backed event dates, venue and image validate.' );
$assert( ! isset( $validation['events'][1]['venue'], $validation['events'][1]['image'] ), 'Absent optionals are not invented.' );
$duplicate = Static_Site_Importer_Entity_Materializer_Registry::validate_manifest_generic( $adapter, array( 'events' => array( $rows[0], $rows[0] ) ) );
$assert( ! empty( $duplicate['errors'] ), 'Duplicate source ownership is rejected.' );
$bad          = $rows[0];
$bad['image'] = '/media/../private.png';
$assert( ! empty( Static_Site_Importer_TEC_Event_Seeder::validate( array( 'events' => array( $bad ) ) )['errors'] ), 'Source image paths cannot escape the artifact.' );
$bad             = $rows[0];
$bad['end_date'] = '2027-04-03T17:00:00-04:00';
$assert( ! empty( Static_Site_Importer_TEC_Event_Seeder::validate( array( 'events' => array( $bad ) ) )['errors'] ), 'Reversed instants are rejected.' );
$bad['end_date'] = $bad['start_date'];
$assert( empty( Static_Site_Importer_TEC_Event_Seeder::validate( array( 'events' => array( $bad ) ) )['errors'] ), 'Instant events match the producer date contract.' );
$result = Static_Site_Importer_Entity_Materializer_Registry::materialize( $adapter, $validation );
$assert( false === $result['provider_available'] && 'failed' === $result['status'], 'Missing TEC is an explicit failed outcome.' );
echo "OK: typed event admission; native writes require real-plugin acceptance\n";

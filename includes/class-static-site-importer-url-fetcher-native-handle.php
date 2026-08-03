<?php
/**
 * Mutable state for one nonblocking, validated URL fetch socket.
 *
 * @package StaticSiteImporter
 */

class Static_Site_Importer_URL_Fetcher_Native_Handle {
	public mixed $socket;
	public array $target;
	public array $options;
	public float $started;
	public string $outbound;
	public string $raw;
	public bool $crypto;
	public string $error;
	public int $ip_index;
}

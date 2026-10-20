<?php
/**
 * HTTP client abstraction for translation provider adapters.
 *
 * @package LocalePilot\Translation
 */

declare(strict_types=1);

namespace LocalePilot\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for executing outbound HTTPS requests to translation APIs.
 */
interface HttpClientInterface {

	/**
	 * Send an HTTP request and return a normalized response array.
	 *
	 * Expected return shape:
	 * - `status`: int (HTTP status code, or 0 on transport failure/timeout)
	 * - `body`: string (raw response body)
	 * - `headers`: array<string, string>
	 * - `error`: string (non-empty transport error message when failed)
	 * - `is_timeout`: bool (whether the error was a network/request timeout)
	 *
	 * @param string               $method HTTP method (`POST`, `GET`).
	 * @param string               $url    Target HTTPS endpoint.
	 * @param array<string, mixed> $args   Request headers, body, and timeout options.
	 * @return array{status:int, body:string, headers:array<string,string>, error:string, is_timeout:bool}
	 */
	public function request( string $method, string $url, array $args = array() ): array;
}

<?php
/**
 * WordPress native HTTP client implementation using `wp_remote_request()`.
 *
 * @package LocalePilot\Translation
 */

declare(strict_types=1);

namespace LocalePilot\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Executes outbound HTTPS requests via WordPress HTTP API without requiring
 * Composer or third-party HTTP libraries on merchant servers. Enforces timeout
 * bounds and response body size limits.
 */
final class WpHttpClient implements HttpClientInterface {

	/**
	 * Default request timeout in seconds.
	 */
	public const DEFAULT_TIMEOUT = 15;

	/**
	 * Default maximum response body size in bytes (1 MB).
	 */
	public const DEFAULT_MAX_RESPONSE_BYTES = 1048576;

	/**
	 * Minimum configurable response body size limit in bytes (1 KB).
	 */
	public const MIN_MAX_RESPONSE_BYTES = 1024;

	/**
	 * Hard ceiling for response body size in bytes (2 MB).
	 */
	public const HARD_MAX_RESPONSE_BYTES = 2097152;

	/**
	 * Configured maximum response size in bytes.
	 *
	 * @var int
	 */
	private int $max_response_bytes;

	/**
	 * Construct the WordPress HTTP client with bounded response size limit.
	 *
	 * @param int $max_response_bytes Desired maximum response size in bytes.
	 */
	public function __construct( int $max_response_bytes = self::DEFAULT_MAX_RESPONSE_BYTES ) {
		$this->max_response_bytes = self::clamp_max_response_bytes( $max_response_bytes );
	}

	/**
	 * Clamp a requested maximum response size to `[MIN_MAX_RESPONSE_BYTES, HARD_MAX_RESPONSE_BYTES]`.
	 *
	 * @param int $bytes Requested byte limit.
	 * @return int Clamped byte limit.
	 */
	public static function clamp_max_response_bytes( int $bytes ): int {
		if ( $bytes < self::MIN_MAX_RESPONSE_BYTES ) {
			return self::MIN_MAX_RESPONSE_BYTES;
		}

		if ( $bytes > self::HARD_MAX_RESPONSE_BYTES ) {
			return self::HARD_MAX_RESPONSE_BYTES;
		}

		return $bytes;
	}

	/**
	 * Return the configured maximum response body size in bytes.
	 *
	 * @return int
	 */
	public function get_max_response_bytes(): int {
		return $this->max_response_bytes;
	}

	/**
	 * Send an HTTP request via `wp_remote_request()`.
	 *
	 * @param string               $method HTTP method (`POST`, `GET`).
	 * @param string               $url    Target HTTPS endpoint.
	 * @param array<string, mixed> $args   Request arguments (`headers`, `body`, `timeout`, `max_response_bytes`).
	 * @return array{status:int, body:string, headers:array<string,string>, error:string, is_timeout:bool}
	 */
	public function request( string $method, string $url, array $args = array() ): array {
		if ( ! function_exists( 'wp_remote_request' ) ) {
			return array(
				'status'     => 0,
				'body'       => '',
				'headers'    => array(),
				'error'      => 'WordPress HTTP API (wp_remote_request) is unavailable.',
				'is_timeout' => false,
			);
		}

		$limit_bytes = isset( $args['max_response_bytes'] )
			? self::clamp_max_response_bytes( (int) $args['max_response_bytes'] )
			: $this->max_response_bytes;

		$timeout = isset( $args['timeout'] ) ? (int) $args['timeout'] : self::DEFAULT_TIMEOUT;
		if ( $timeout < 1 ) {
			$timeout = 1;
		} elseif ( $timeout > 60 ) {
			$timeout = 60;
		}

		$request_args = array(
			'method'              => strtoupper( $method ),
			'timeout'             => $timeout,
			'redirection'         => 0,
			'sslverify'           => true,
			'limit_response_size' => $limit_bytes,
			'headers'             => isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array(),
		);

		if ( isset( $args['body'] ) ) {
			$request_args['body'] = $args['body'];
		}

		$response = wp_remote_request( $url, $request_args );

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			$error_msg  = (string) $response->get_error_message();
			$is_timeout = false !== stripos( $error_msg, 'timed out' )
				|| false !== stripos( $error_msg, 'timeout' )
				|| false !== stripos( $error_msg, 'cURL error 28' );

			return array(
				'status'     => 0,
				'body'       => '',
				'headers'    => array(),
				'error'      => $error_msg,
				'is_timeout' => $is_timeout,
			);
		}

		$status = function_exists( 'wp_remote_retrieve_response_code' )
			? (int) wp_remote_retrieve_response_code( $response )
			: 0;
		$body   = function_exists( 'wp_remote_retrieve_body' )
			? (string) wp_remote_retrieve_body( $response )
			: '';

		if ( strlen( $body ) > $limit_bytes ) {
			return array(
				'status'     => $status,
				'body'       => '',
				'headers'    => array(),
				'error'      => sprintf( 'Response body exceeded maximum allowed size (%d bytes).', $limit_bytes ),
				'is_timeout' => false,
			);
		}

		return array(
			'status'     => $status,
			'body'       => $body,
			'headers'    => array(),
			'error'      => '',
			'is_timeout' => false,
		);
	}
}

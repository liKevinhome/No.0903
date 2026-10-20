<?php
/**
 * Subscription Entitlement & Progressive Degradation State Manager.
 *
 * Implements the subscription state rules from PRODUCT-PLAN.md §5 & COMPATIBILITY.md §4:
 * - `active` / `grace_period`: New machine translation tasks and background queue
 *   processing are permitted.
 * - `expired`: Stops new outbound machine translation tasks; 100% preserves all
 *   locally stored translations so storefront rendering, language switching,
 *   cart, checkout, and order transactional emails continue operating normally.
 * - Never performs outbound SaaS entitlement HTTP requests during storefront
 *   visitor requests.
 *
 * @package LocalePilot\Admin
 */

declare(strict_types=1);

namespace LocalePilot\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Manages local site subscription status and progressive degradation checks.
 */
final class SubscriptionStateManager {

	/**
	 * WordPress option key storing local subscription entitlement state.
	 */
	public const OPTION_KEY = '_localepilot_subscription_state';

	/**
	 * Subscription status constants.
	 */
	public const STATUS_ACTIVE       = 'active';
	public const STATUS_GRACE_PERIOD = 'grace_period';
	public const STATUS_EXPIRED      = 'expired';
	public const STATUS_UNCONNECTED  = 'unconnected';

	/**
	 * Allowed subscription statuses.
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_STATUSES = array(
		self::STATUS_ACTIVE,
		self::STATUS_GRACE_PERIOD,
		self::STATUS_EXPIRED,
		self::STATUS_UNCONNECTED,
	);

	/**
	 * Allowed site environment types.
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_ENVIRONMENTS = array(
		'production',
		'staging',
	);

	/**
	 * In-memory fallback state for standalone unit tests.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $memory_state = null;

	/**
	 * Reset in-memory state (for unit tests).
	 *
	 * @return void
	 */
	public static function reset_memory_state(): void {
		self::$memory_state = null;
	}

	/**
	 * Return default subscription state structure.
	 *
	 * Defaults to `active` (BYOK local mode) until a merchant binds or expires a subscription.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return array(
			'status'       => self::STATUS_ACTIVE,
			'site_id'      => '',
			'environment'  => 'production',
			'billing_mode' => 'byok',
			'updated_at'   => '',
		);
	}

	/**
	 * Retrieve current sanitized subscription state.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_state(): array {
		$raw = null;

		if ( function_exists( 'get_option' ) ) {
			$opt = get_option( self::OPTION_KEY, null );
			if ( is_array( $opt ) ) {
				$raw = $opt;
			}
		}

		if ( null === $raw && null !== self::$memory_state ) {
			$raw = self::$memory_state;
		}

		return self::sanitize_state( is_array( $raw ) ? $raw : array() );
	}

	/**
	 * Persist updated subscription state locally (`autoload = false`).
	 *
	 * @param array<string, mixed> $new_state Raw state input.
	 * @return array<string, mixed> Sanitized persisted state.
	 */
	public static function update_state( array $new_state ): array {
		$current   = self::get_state();
		$merged    = array_merge( $current, $new_state );
		$sanitized = self::sanitize_state( $merged );

		self::$memory_state = $sanitized;

		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_KEY, $sanitized, false );
		}

		return $sanitized;
	}

	/**
	 * Sanitize raw subscription state array.
	 *
	 * @param array<string, mixed> $input Raw state array.
	 * @return array<string, mixed>
	 */
	public static function sanitize_state( array $input ): array {
		$defaults = self::get_defaults();

		$status = isset( $input['status'] ) && is_string( $input['status'] )
			? strtolower( trim( $input['status'] ) )
			: $defaults['status'];
		if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
			$status = $defaults['status'];
		}

		$environment = isset( $input['environment'] ) && is_string( $input['environment'] )
			? strtolower( trim( $input['environment'] ) )
			: $defaults['environment'];
		if ( ! in_array( $environment, self::ALLOWED_ENVIRONMENTS, true ) ) {
			$environment = $defaults['environment'];
		}

		$site_id = isset( $input['site_id'] ) && is_string( $input['site_id'] )
			? (string) preg_replace( '/[^a-zA-Z0-9_\-\.]/', '', trim( $input['site_id'] ) )
			: '';

		$billing_mode = isset( $input['billing_mode'] ) && is_string( $input['billing_mode'] )
			? strtolower( trim( $input['billing_mode'] ) )
			: 'byok';
		if ( ! in_array( $billing_mode, array( 'byok', 'included_quota' ), true ) ) {
			$billing_mode = 'byok';
		}

		$updated_at = isset( $input['updated_at'] ) && is_string( $input['updated_at'] ) && '' !== trim( $input['updated_at'] )
			? trim( $input['updated_at'] )
			: gmdate( 'Y-m-d H:i:s' );

		return array(
			'status'       => $status,
			'site_id'      => substr( $site_id, 0, 64 ),
			'environment'  => $environment,
			'billing_mode' => $billing_mode,
			'updated_at'   => $updated_at,
		);
	}

	/**
	 * Check whether new machine translation API tasks may be dispatched.
	 *
	 * Returns `false` when the subscription status is `expired`, while keeping
	 * all existing local translations and storefront/checkout rendering active.
	 *
	 * @param array<string, mixed>|null $state Optional state override.
	 * @return bool
	 */
	public static function can_dispatch_new_translations( ?array $state = null ): bool {
		$current = $state ?? self::get_state();
		$status  = isset( $current['status'] ) ? (string) $current['status'] : self::STATUS_ACTIVE;

		return self::STATUS_EXPIRED !== $status;
	}

	/**
	 * Confirm that storefront rendering of existing approved translations is ALWAYS
	 * permitted regardless of subscription expiration (RB-05 guarantee).
	 *
	 * @return bool Always true.
	 */
	public static function can_render_existing_storefront_translations(): bool {
		return true;
	}
}

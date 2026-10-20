<?php
/**
 * Value object representing a translation request with deduplication key and
 * privacy context validation.
 *
 * @package LocalePilot\Translation
 */

declare(strict_types=1);

namespace LocalePilot\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Encapsulates source text, locale pair, context type, glossary terms, and protected tokens.
 */
final class TranslationRequest {

	/**
	 * Prohibited context prefixes/types that contain customer or order PII
	 * and must never be transmitted to external translation providers.
	 *
	 * @var array<int, string>
	 */
	private const PROHIBITED_CONTEXTS = array(
		'order',
		'order_note',
		'customer',
		'customer_name',
		'customer_email',
		'customer_phone',
		'billing',
		'billing_address',
		'shipping_address',
		'payment',
		'payment_meta',
		'comment_author',
		'review_author',
		'user_profile',
	);

	/**
	 * Source text to translate.
	 *
	 * @var string
	 */
	private string $source_text;

	/**
	 * Source locale code (e.g. `en_US`).
	 *
	 * @var string
	 */
	private string $source_locale;

	/**
	 * Target locale code (e.g. `fr_FR`, `zh_CN`).
	 *
	 * @var string
	 */
	private string $target_locale;

	/**
	 * Content context type (e.g. `product_title`, `product_description`, `taxonomy_term`).
	 *
	 * @var string
	 */
	private string $context_type;

	/**
	 * Merchant glossary map (`source_term => locked_target_term`).
	 *
	 * @var array<string, string>
	 */
	private array $glossary;

	/**
	 * Explicit non-translatable tokens (such as SKUs or brand identifiers).
	 *
	 * @var array<int, string>
	 */
	private array $protected_tokens;

	/**
	 * Construct a new TranslationRequest.
	 *
	 * @param string                $source_text      Source text.
	 * @param string                $source_locale    Source locale.
	 * @param string                $target_locale    Target locale.
	 * @param string                $context_type     Content context identifier.
	 * @param array<string, string> $glossary         Optional merchant glossary map.
	 * @param array<int, string>    $protected_tokens Optional explicit tokens (e.g. SKUs) to protect.
	 */
	public function __construct(
		string $source_text,
		string $source_locale,
		string $target_locale,
		string $context_type = 'product_field',
		array $glossary = array(),
		array $protected_tokens = array()
	) {
		$this->source_text      = $source_text;
		$this->source_locale    = trim( $source_locale );
		$this->target_locale    = trim( $target_locale );
		$this->context_type     = strtolower( trim( $context_type ) );
		$this->glossary         = $glossary;
		$this->protected_tokens = array_values( array_filter( $protected_tokens, 'is_string' ) );
	}

	/**
	 * Return the raw source text.
	 *
	 * @return string
	 */
	public function get_source_text(): string {
		return $this->source_text;
	}

	/**
	 * Return the source locale.
	 *
	 * @return string
	 */
	public function get_source_locale(): string {
		return $this->source_locale;
	}

	/**
	 * Return the target locale.
	 *
	 * @return string
	 */
	public function get_target_locale(): string {
		return $this->target_locale;
	}

	/**
	 * Return the context type.
	 *
	 * @return string
	 */
	public function get_context_type(): string {
		return $this->context_type;
	}

	/**
	 * Return the glossary map.
	 *
	 * @return array<string, string>
	 */
	public function get_glossary(): array {
		return $this->glossary;
	}

	/**
	 * Return explicit protected tokens (e.g. SKUs).
	 *
	 * @return array<int, string>
	 */
	public function get_protected_tokens(): array {
		return $this->protected_tokens;
	}

	/**
	 * Compute the SHA-256 hash of the normalized source text.
	 *
	 * @return string
	 */
	public function get_source_hash(): string {
		return hash( 'sha256', trim( $this->source_text ) );
	}

	/**
	 * Compute the unique metering/deduplication key (`source_hash:target_locale`)
	 * per PRODUCT-PLAN.md §4 ("唯一计量键为 源文本哈希 × 目标语言").
	 *
	 * @return string
	 */
	public function get_dedup_key(): string {
		$normalized_target = strtolower( str_replace( '-', '_', $this->target_locale ) );
		return $this->get_source_hash() . ':' . $normalized_target;
	}

	/**
	 * Check whether the context type represents prohibited customer/order PII data.
	 *
	 * Public store labels (`shipping_method_label`, `payment_gateway_title`,
	 * `payment_gateway_description`, `checkout_field_label`) are permitted, while
	 * customer/order PII (`order*`, `customer*`, `billing*`, `shipping_address*`,
	 * `payment_meta*`, `payment_token*`, `comment_author*`, `user_profile`) are blocked.
	 *
	 * @return bool
	 */
	public function is_prohibited_pii_context(): bool {
		$allowed_store_labels = array(
			'shipping_method_label',
			'payment_gateway_title',
			'payment_gateway_description',
			'checkout_field_label',
			'coupon_message',
		);
		if ( in_array( $this->context_type, $allowed_store_labels, true ) ) {
			return false;
		}

		if ( in_array( $this->context_type, self::PROHIBITED_CONTEXTS, true ) ) {
			return true;
		}

		foreach ( array( 'order_', 'customer_', 'billing_', 'shipping_', 'payment_', 'comment_author' ) as $prefix ) {
			if ( 0 === strpos( $this->context_type, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}

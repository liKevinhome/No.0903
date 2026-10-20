<?php
/**
 * Protects non-translatable tokens (HTML tags, shortcodes, URLs, printf/brace
 * placeholders, prices, physical units, SKUs, and glossary locks) before
 * sending text to translation providers, and restores them afterward.
 *
 * @package LocalePilot\Translation
 */

declare(strict_types=1);

namespace LocalePilot\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Masks and restores protected tokens during machine translation.
 */
final class TokenProtector {

	/**
	 * Mask non-translatable segments in source text with deterministic placeholders
	 * `[[LP_TOK_0]]`, `[[LP_TOK_1]]`, etc.
	 *
	 * Protected elements per PRODUCT-PLAN.md §4:
	 * - Explicit protected tokens (e.g. SKUs) and merchant glossary terms.
	 * - HTML tags and comments (`<a ...>`, `</a>`, `<img .../>`).
	 * - WordPress shortcodes (`[shortcode attr="val"]`).
	 * - URLs (`https://...`, `http://...`).
	 * - Placeholders (`%1$s`, `%s`, `%d`, `{price}`, `{{name}}`).
	 * - Currency prices (`$19.99`, `€120.00`, `£45`, `¥199`, `USD 29.00`).
	 * - Numbers with measurement units (`250ml`, `1.5kg`, `100g`, `50cm`, `12oz`).
	 *
	 * @param string                $text             Input text.
	 * @param array<string, string> $glossary         Merchant glossary (`source_term => target_replacement`).
	 * @param array<int, string>    $protected_tokens Explicit tokens (e.g. SKUs) to preserve unchanged.
	 * @return array{masked_text:string, token_map:array<string,string>}
	 */
	public static function protect(
		string $text,
		array $glossary = array(),
		array $protected_tokens = array()
	): array {
		if ( '' === $text ) {
			return array(
				'masked_text' => '',
				'token_map'   => array(),
			);
		}

		$token_map = array();
		$counter   = 0;

		$register_replacement = static function ( string $replacement_value ) use ( &$token_map, &$counter ): string {
			$placeholder               = '[[LP_TOK_' . $counter . ']]';
			$token_map[ $placeholder ] = $replacement_value;
			++$counter;
			return $placeholder;
		};

		$working = $text;

		// 1. Explicit protected tokens (such as SKUs) — preserved verbatim.
		foreach ( $protected_tokens as $explicit_token ) {
			$trimmed = trim( $explicit_token );
			if ( '' === $trimmed || false === strpos( $working, $trimmed ) ) {
				continue;
			}
			$placeholder = $register_replacement( $trimmed );
			$working     = str_replace( $trimmed, $placeholder, $working );
		}

		// 2. Merchant glossary terms — replaced with locked target term upon restore.
		if ( ! empty( $glossary ) ) {
			uksort(
				$glossary,
				static function ( string $a, string $b ): int {
					return strlen( $b ) <=> strlen( $a );
				}
			);
			foreach ( $glossary as $source_term => $target_term ) {
				if ( '' === trim( $source_term ) || false === strpos( $working, $source_term ) ) {
					continue;
				}
				$locked_value = '' !== trim( $target_term ) ? $target_term : $source_term;
				$placeholder  = $register_replacement( $locked_value );
				$working      = str_replace( $source_term, $placeholder, $working );
			}
		}

		// 3. Regex patterns for HTML, shortcodes, URLs, placeholders, prices, and units.
		$patterns = array(
			// HTML comments and tags.
			'/<!--[\s\S]*?-->|<\/?[a-zA-Z][^>]*>/u',
			// WordPress shortcodes (avoiding our own [[LP_TOK_N]]).
			'/(?<!\[)\[[a-zA-Z_][a-zA-Z0-9_-]*(?:\s+[^\]]*)?\](?!\])/u',
			// HTTP/HTTPS URLs.
			'/https?:\/\/[^\s<>"\']+/i',
			// printf / sprintf placeholders (%1$s, %s, %d, %.2f) and brace placeholders ({item}, {{item}}).
			'/%(?:\d+\$)?[-+]?[0-9]*\.?[0-9]*[bcdeEfFgGosuxX]|\{\{[a-zA-Z0-9_.-]+\}\}|\{[a-zA-Z0-9_.-]+\}/u',
			// Currency prices ($19.99, €120,00, £45.50, ¥199, USD 29.00, EUR 99.50).
			'/(?:[\$€£¥₹]\s*\d+(?:[.,]\d{1,2})?|\b(?:USD|EUR|GBP|JPY|CNY|CAD|AUD)\s+\d+(?:[.,]\d{1,2})?)/u',
			// Physical units & dimensions (250ml, 1.5kg, 100g, 50cm, 15mm, 12oz, 24V, 500W).
			'/\b\d+(?:\.\d+)?\s*(?:ml|mL|L|kg|g|mg|cm|mm|m|oz|lb|lbs|inch|in|ft|V|W|mAh|Hz)\b/u',
		);

		foreach ( $patterns as $pattern ) {
			$working = (string) preg_replace_callback(
				$pattern,
				static function ( array $matches ) use ( $register_replacement ): string {
					return $register_replacement( $matches[0] );
				},
				$working
			);
		}

		return array(
			'masked_text' => $working,
			'token_map'   => $token_map,
		);
	}

	/**
	 * Restore protected tokens (`[[LP_TOK_N]]`) back into the translated text.
	 *
	 * Tolerates minor whitespace variations inserted by translation engines around
	 * placeholder brackets (e.g. `[[ LP_TOK_0 ]]`).
	 *
	 * @param string               $translated_text Translated text containing placeholders.
	 * @param array<string,string> $token_map       Map of `[[LP_TOK_N]] => original/locked value`.
	 * @return string Restored text.
	 */
	public static function restore( string $translated_text, array $token_map ): string {
		if ( '' === $translated_text || empty( $token_map ) ) {
			return $translated_text;
		}

		$restored = $translated_text;
		foreach ( $token_map as $placeholder => $original_value ) {
			if ( preg_match( '/^\[\[LP_TOK_(\d+)\]\]$/', $placeholder, $m ) ) {
				$idx      = $m[1];
				$pattern  = '/\[\[\s*LP_TOK_' . preg_quote( $idx, '/' ) . '\s*\]\]/i';
				$restored = (string) preg_replace_callback(
					$pattern,
					static function () use ( $original_value ): string {
						return $original_value;
					},
					$restored
				);
			} else {
				$restored = str_replace( $placeholder, $original_value, $restored );
			}
		}

		return $restored;
	}

	/**
	 * Redact sensitive API credentials from any log or error message string.
	 *
	 * @param string            $message Raw error message.
	 * @param array<int,string> $secrets List of sensitive keys/secrets to scrub.
	 * @return string Sanitized message.
	 */
	public static function redact_secrets( string $message, array $secrets ): string {
		$sanitized = $message;
		foreach ( $secrets as $secret ) {
			$trimmed = trim( $secret );
			if ( '' !== $trimmed && strlen( $trimmed ) >= 4 ) {
				$sanitized = str_replace( $trimmed, '[REDACTED]', $sanitized );
			}
		}

		return $sanitized;
	}
}

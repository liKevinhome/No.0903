<?php
/**
 * WordPress `$wpdb` / `dbDelta` persistent repository for local translations.
 *
 * Stores machine-translated and merchant-reviewed content locally, enforces
 * cross-request deduplication by `object / source_hash / target_locale / provider`,
 * manages `pending_review` and `approved` statuses, and strictly rejects customer
 * or order PII fields.
 *
 * @package LocalePilot\Translation
 */

declare(strict_types=1);

namespace LocalePilot\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Persists translations in `{prefix}localepilot_translations` via `$wpdb` with
 * an isolated in-memory fallback for standalone CLI unit tests.
 */
final class TranslationRepository {

	/**
	 * Base table name without WordPress `$wpdb->prefix`.
	 */
	public const TABLE_SUFFIX = 'localepilot_translations';

	/**
	 * Whitelisted content object/field types eligible for translation storage.
	 *
	 * Customer, order, billing, shipping, payment, and user profile fields are
	 * strictly prohibited.
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_CONTENT_TYPES = array(
		'product_title',
		'product_short_description',
		'product_description',
		'product_attribute',
		'variation_option',
		'taxonomy_term',
		'shipping_method_label',
		'payment_gateway_title',
		'payment_gateway_description',
		'checkout_field_label',
		'coupon_message',
		'ui_string',
	);

	/**
	 * In-memory fallback rows for standalone PHPUnit tests when `$wpdb` is unavailable.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $memory_rows = array();

	/**
	 * Auto-increment counter for in-memory fallback rows.
	 *
	 * @var int
	 */
	private static int $memory_auto_id = 1;

	/**
	 * Optional injected `$wpdb`-compatible database object.
	 *
	 * @var object|null
	 */
	private ?object $db;

	/**
	 * Construct the translation repository.
	 *
	 * @param object|null $db Optional `$wpdb` instance override.
	 */
	public function __construct( ?object $db = null ) {
		if ( null !== $db ) {
			$this->db = $db;
			return;
		}

		global $wpdb;
		$this->db = ( isset( $wpdb ) && is_object( $wpdb ) ) ? $wpdb : null;
	}

	/**
	 * Reset in-memory fallback storage (intended for isolated unit tests).
	 *
	 * @return void
	 */
	public static function reset_memory_store(): void {
		self::$memory_rows    = array();
		self::$memory_auto_id = 1;
	}

	/**
	 * Check whether a content/object type is permitted for translation and storage.
	 *
	 * @param string $content_type Content type identifier.
	 * @return bool
	 */
	public static function is_allowed_content_type( string $content_type ): bool {
		$clean = strtolower( trim( $content_type ) );
		if ( '' === $clean ) {
			return false;
		}

		$probe = new TranslationRequest( 'probe', 'en_US', 'zh_CN', $clean );
		if ( $probe->is_prohibited_pii_context() ) {
			return false;
		}

		return in_array( $clean, self::ALLOWED_CONTENT_TYPES, true );
	}

	/**
	 * Return the full database table name including `$wpdb->prefix`.
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		$prefix = ( null !== $this->db && isset( $this->db->prefix ) && is_string( $this->db->prefix ) )
			? $this->db->prefix
			: 'wp_';

		return $prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Return the `CREATE TABLE` SQL statement compatible with WordPress `dbDelta()`.
	 *
	 * @return string
	 */
	public function get_schema_sql(): string {
		$table_name      = $this->get_table_name();
		$charset_collate = '';

		if ( null !== $this->db && method_exists( $this->db, 'get_charset_collate' ) ) {
			$charset_collate = (string) $this->db->get_charset_collate();
		}

		return "CREATE TABLE {$table_name} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	object_type varchar(64) NOT NULL,
	object_id bigint(20) unsigned NOT NULL DEFAULT 0,
	field_key varchar(128) NOT NULL,
	source_locale varchar(32) NOT NULL,
	target_locale varchar(32) NOT NULL,
	source_hash char(64) NOT NULL,
	provider_id varchar(32) NOT NULL,
	translated_text longtext NOT NULL,
	review_status varchar(32) NOT NULL DEFAULT 'pending_review',
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY lp_obj_field_locale (object_type, object_id, field_key, target_locale),
	KEY lp_hash_locale_provider (source_hash, target_locale, provider_id),
	KEY lp_review_status (review_status)
) {$charset_collate};";
	}

	/**
	 * Create or upgrade the translations table via WordPress `dbDelta()` (or `$wpdb->query()`).
	 *
	 * @return bool True when schema creation succeeded or in-memory store is active.
	 */
	public function ensure_table(): bool {
		if ( null === $this->db ) {
			return true;
		}

		$sql = $this->get_schema_sql();

		if ( defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			if ( function_exists( 'dbDelta' ) ) {
				dbDelta( $sql );
				return true;
			}
		}

		if ( method_exists( $this->db, 'query' ) ) {
			$if_not_exists_sql = preg_replace( '/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $sql, 1 );
			return false !== $this->db->query( (string) $if_not_exists_sql );
		}

		return false;
	}

	/**
	 * Find an existing persisted translation matching `source_hash` + `target_locale`
	 * (and optionally `provider_id` and/or `object_type` + `object_id` + `field_key`).
	 *
	 * @param string $source_hash   SHA-256 hash of normalized source text.
	 * @param string $target_locale Target locale code.
	 * @param string $provider_id   Optional provider ID filter.
	 * @param string $object_type   Optional object type filter.
	 * @param int    $object_id     Optional object ID filter.
	 * @param string $field_key     Optional field key filter.
	 * @return array<string, mixed>|null Matching row or null.
	 */
	public function find_by_dedup(
		string $source_hash,
		string $target_locale,
		string $provider_id = '',
		string $object_type = '',
		int $object_id = 0,
		string $field_key = ''
	): ?array {
		$clean_hash   = strtolower( trim( $source_hash ) );
		$clean_locale = trim( $target_locale );
		$clean_prov   = strtolower( trim( $provider_id ) );
		$clean_type   = strtolower( trim( $object_type ) );
		$clean_field  = trim( $field_key );

		if ( '' === $clean_hash || '' === $clean_locale ) {
			return null;
		}

		if ( null !== $this->db && method_exists( $this->db, 'get_row' ) && method_exists( $this->db, 'prepare' ) ) {
			$table = $this->get_table_name();

			if ( '' !== $clean_type && '' !== $clean_field ) {
				$query = $this->db->prepare(
					"SELECT * FROM {$table} WHERE object_type = %s AND object_id = %d AND field_key = %s AND target_locale = %s AND source_hash = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal constant prefix.
					$clean_type,
					$object_id,
					$clean_field,
					$clean_locale,
					$clean_hash
				);
				$row   = $this->db->get_row( $query, ARRAY_A );
				if ( is_array( $row ) ) {
					return $this->normalize_row( $row );
				}
			}

			if ( '' !== $clean_prov ) {
				$query = $this->db->prepare(
					"SELECT * FROM {$table} WHERE source_hash = %s AND target_locale = %s AND provider_id = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal constant prefix.
					$clean_hash,
					$clean_locale,
					$clean_prov
				);
			} else {
				$query = $this->db->prepare(
					"SELECT * FROM {$table} WHERE source_hash = %s AND target_locale = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal constant prefix.
					$clean_hash,
					$clean_locale
				);
			}

			$row = $this->db->get_row( $query, ARRAY_A );
			return is_array( $row ) ? $this->normalize_row( $row ) : null;
		}

		// In-memory store lookup for standalone CLI unit tests.
		foreach ( array_reverse( self::$memory_rows, true ) as $row ) {
			if ( $row['source_hash'] !== $clean_hash || $row['target_locale'] !== $clean_locale ) {
				continue;
			}
			if ( '' !== $clean_prov && $row['provider_id'] !== $clean_prov ) {
				continue;
			}
			return $row;
		}

		return null;
	}

	/**
	 * Find a persisted translation row by its primary ID.
	 *
	 * @param int $id Translation row ID.
	 * @return array<string, mixed>|null
	 */
	public function find_by_id( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}

		if ( null !== $this->db && method_exists( $this->db, 'get_row' ) && method_exists( $this->db, 'prepare' ) ) {
			$table = $this->get_table_name();
			$query = $this->db->prepare(
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal constant prefix.
				$id
			);
			$row   = $this->db->get_row( $query, ARRAY_A );
			return is_array( $row ) ? $this->normalize_row( $row ) : null;
		}

		return self::$memory_rows[ $id ] ?? null;
	}

	/**
	 * Find an approved (`review_status = 'approved'`) translation for storefront rendering.
	 *
	 * Never returns `pending_review` rows. If `$source_hash` is provided, verifies that
	 * the stored translation matches the current source text hash.
	 *
	 * @param string $object_type   Content object type (`product_title`, `product_description`, etc.).
	 * @param int    $object_id     Object ID (`0` when matching by `source_hash` only).
	 * @param string $field_key     Field key (`post_title`, `post_content`, `name`, etc.).
	 * @param string $target_locale Target storefront locale.
	 * @param string $source_hash   Optional SHA-256 hash of current source text.
	 * @return string|null Approved translated text, or null when missing / pending_review / stale.
	 */
	public function find_approved_for_storefront(
		string $object_type,
		int $object_id,
		string $field_key,
		string $target_locale,
		string $source_hash = ''
	): ?string {
		$clean_type   = strtolower( trim( $object_type ) );
		$clean_field  = '' !== trim( $field_key ) ? trim( $field_key ) : $clean_type;
		$clean_locale = trim( $target_locale );
		$clean_hash   = strtolower( trim( $source_hash ) );

		if ( '' === $clean_type || '' === $clean_locale || ! self::is_allowed_content_type( $clean_type ) ) {
			return null;
		}

		if ( null !== $this->db && method_exists( $this->db, 'get_row' ) && method_exists( $this->db, 'prepare' ) ) {
			$table = $this->get_table_name();
			$row   = null;

			if ( $object_id > 0 ) {
				$query = $this->db->prepare(
					"SELECT translated_text, source_hash, review_status FROM {$table} WHERE object_type = %s AND object_id = %d AND field_key = %s AND target_locale = %s AND review_status = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal constant prefix.
					$clean_type,
					$object_id,
					$clean_field,
					$clean_locale,
					TranslationResult::STATUS_APPROVED
				);
				$row   = $this->db->get_row( $query, ARRAY_A );
			}

			if ( ! is_array( $row ) && '' !== $clean_hash ) {
				$query = $this->db->prepare(
					"SELECT translated_text, source_hash, review_status FROM {$table} WHERE object_type = %s AND source_hash = %s AND target_locale = %s AND review_status = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal constant prefix.
					$clean_type,
					$clean_hash,
					$clean_locale,
					TranslationResult::STATUS_APPROVED
				);
				$row   = $this->db->get_row( $query, ARRAY_A );
			}

			if ( ! is_array( $row ) || TranslationResult::STATUS_APPROVED !== ( $row['review_status'] ?? '' ) ) {
				return null;
			}

			$stored_hash = isset( $row['source_hash'] ) ? strtolower( (string) $row['source_hash'] ) : '';
			if ( '' !== $clean_hash && '' !== $stored_hash && $stored_hash !== $clean_hash ) {
				return null;
			}

			$translated = isset( $row['translated_text'] ) ? (string) $row['translated_text'] : '';
			return '' !== $translated ? $translated : null;
		}

		// In-memory store lookup for standalone CLI unit tests.
		if ( $object_id > 0 ) {
			foreach ( array_reverse( self::$memory_rows, true ) as $row ) {
				if (
					$row['object_type'] === $clean_type
					&& $row['object_id'] === $object_id
					&& $row['field_key'] === $clean_field
					&& $row['target_locale'] === $clean_locale
				) {
					if ( TranslationResult::STATUS_APPROVED !== $row['review_status'] ) {
						return null;
					}
					if ( '' !== $clean_hash && '' !== $row['source_hash'] && $clean_hash !== $row['source_hash'] ) {
						return null;
					}
					return '' !== (string) $row['translated_text'] ? (string) $row['translated_text'] : null;
				}
			}
		}

		if ( '' !== $clean_hash ) {
			foreach ( array_reverse( self::$memory_rows, true ) as $row ) {
				if (
					$row['object_type'] === $clean_type
					&& $row['source_hash'] === $clean_hash
					&& $row['target_locale'] === $clean_locale
					&& TranslationResult::STATUS_APPROVED === $row['review_status']
				) {
					return '' !== (string) $row['translated_text'] ? (string) $row['translated_text'] : null;
				}
			}
		}

		return null;
	}

	/**
	 * Save (insert or update) a translation record.
	 *
	 * Strictly rejects prohibited PII object types (`order`, `customer`, `billing`, etc.).
	 *
	 * @param array<string, mixed> $record Translation record fields.
	 * @return int Saved row ID (`0` when rejected).
	 */
	public function save( array $record ): int {
		$object_type = isset( $record['object_type'] ) ? strtolower( trim( (string) $record['object_type'] ) ) : 'product_title';
		if ( ! self::is_allowed_content_type( $object_type ) ) {
			return 0;
		}

		$object_id       = isset( $record['object_id'] ) ? max( 0, (int) $record['object_id'] ) : 0;
		$field_key       = isset( $record['field_key'] ) && '' !== trim( (string) $record['field_key'] )
			? trim( (string) $record['field_key'] )
			: $object_type;
		$source_locale   = isset( $record['source_locale'] ) ? trim( (string) $record['source_locale'] ) : 'en_US';
		$target_locale   = isset( $record['target_locale'] ) ? trim( (string) $record['target_locale'] ) : '';
		$source_hash     = isset( $record['source_hash'] ) ? strtolower( trim( (string) $record['source_hash'] ) ) : '';
		$provider_id     = isset( $record['provider_id'] ) ? strtolower( trim( (string) $record['provider_id'] ) ) : '';
		$translated_text = isset( $record['translated_text'] ) ? (string) $record['translated_text'] : '';
		$review_status   = isset( $record['review_status'] ) && TranslationResult::STATUS_APPROVED === (string) $record['review_status']
			? TranslationResult::STATUS_APPROVED
			: TranslationResult::STATUS_PENDING_REVIEW;

		if ( '' === $target_locale || '' === $source_hash || '' === $provider_id ) {
			return 0;
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		if ( null !== $this->db && method_exists( $this->db, 'get_row' ) && method_exists( $this->db, 'prepare' ) ) {
			$table    = $this->get_table_name();
			$existing = $this->db->get_row(
				$this->db->prepare(
					"SELECT id FROM {$table} WHERE object_type = %s AND object_id = %d AND field_key = %s AND target_locale = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal constant prefix.
					$object_type,
					$object_id,
					$field_key,
					$target_locale
				),
				ARRAY_A
			);

			if ( is_array( $existing ) && isset( $existing['id'] ) ) {
				$row_id = (int) $existing['id'];
				$this->db->update(
					$table,
					array(
						'source_locale'   => $source_locale,
						'source_hash'     => $source_hash,
						'provider_id'     => $provider_id,
						'translated_text' => $translated_text,
						'review_status'   => $review_status,
						'updated_at'      => $now,
					),
					array( 'id' => $row_id ),
					array( '%s', '%s', '%s', '%s', '%s', '%s' ),
					array( '%d' )
				);
				return $row_id;
			}

			$inserted = $this->db->insert(
				$table,
				array(
					'object_type'     => $object_type,
					'object_id'       => $object_id,
					'field_key'       => $field_key,
					'source_locale'   => $source_locale,
					'target_locale'   => $target_locale,
					'source_hash'     => $source_hash,
					'provider_id'     => $provider_id,
					'translated_text' => $translated_text,
					'review_status'   => $review_status,
					'created_at'      => $now,
					'updated_at'      => $now,
				),
				array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			return false !== $inserted && isset( $this->db->insert_id ) ? (int) $this->db->insert_id : 0;
		}

		// In-memory upsert for standalone unit tests.
		foreach ( self::$memory_rows as $id => $row ) {
			if (
				$row['object_type'] === $object_type
				&& $row['object_id'] === $object_id
				&& $row['field_key'] === $field_key
				&& $row['target_locale'] === $target_locale
			) {
				self::$memory_rows[ $id ]['source_locale']   = $source_locale;
				self::$memory_rows[ $id ]['source_hash']     = $source_hash;
				self::$memory_rows[ $id ]['provider_id']     = $provider_id;
				self::$memory_rows[ $id ]['translated_text'] = $translated_text;
				self::$memory_rows[ $id ]['review_status']   = $review_status;
				self::$memory_rows[ $id ]['updated_at']      = $now;
				return $id;
			}
		}

		$new_id                       = self::$memory_auto_id++;
		self::$memory_rows[ $new_id ] = array(
			'id'              => $new_id,
			'object_type'     => $object_type,
			'object_id'       => $object_id,
			'field_key'       => $field_key,
			'source_locale'   => $source_locale,
			'target_locale'   => $target_locale,
			'source_hash'     => $source_hash,
			'provider_id'     => $provider_id,
			'translated_text' => $translated_text,
			'review_status'   => $review_status,
			'created_at'      => $now,
			'updated_at'      => $now,
		);

		return $new_id;
	}

	/**
	 * Update the review status (`pending_review` or `approved`) of an existing translation row.
	 *
	 * @param int    $id            Translation row ID.
	 * @param string $review_status Target review status (`pending_review` or `approved`).
	 * @return bool True when updated, false when row not found or invalid status.
	 */
	public function update_review_status( int $id, string $review_status ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		if ( ! in_array( $review_status, array( TranslationResult::STATUS_PENDING_REVIEW, TranslationResult::STATUS_APPROVED ), true ) ) {
			return false;
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		if ( null !== $this->db && method_exists( $this->db, 'update' ) ) {
			$existing = $this->find_by_id( $id );
			if ( null === $existing ) {
				return false;
			}

			$updated = $this->db->update(
				$this->get_table_name(),
				array(
					'review_status' => $review_status,
					'updated_at'    => $now,
				),
				array( 'id' => $id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			return false !== $updated;
		}

		if ( ! isset( self::$memory_rows[ $id ] ) ) {
			return false;
		}

		self::$memory_rows[ $id ]['review_status'] = $review_status;
		self::$memory_rows[ $id ]['updated_at']    = $now;
		return true;
	}

	/**
	 * List recent translation records ordered by ID descending.
	 *
	 * @param int $limit Maximum rows to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_recent( int $limit = 20 ): array {
		$clamped_limit = max( 1, min( 100, $limit ) );

		if ( null !== $this->db && method_exists( $this->db, 'get_results' ) && method_exists( $this->db, 'prepare' ) ) {
			$table = $this->get_table_name();
			$query = $this->db->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal constant prefix.
				$clamped_limit
			);
			$rows  = $this->db->get_results( $query, ARRAY_A );
			if ( ! is_array( $rows ) ) {
				return array();
			}

			return array_map( array( $this, 'normalize_row' ), $rows );
		}

		$reversed = array_reverse( array_values( self::$memory_rows ) );
		return array_slice( $reversed, 0, $clamped_limit );
	}

	/**
	 * Normalize database row types.
	 *
	 * @param array<string, mixed> $row Raw DB row.
	 * @return array<string, mixed>
	 */
	private function normalize_row( array $row ): array {
		$row['id']        = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$row['object_id'] = isset( $row['object_id'] ) ? (int) $row['object_id'] : 0;
		return $row;
	}
}

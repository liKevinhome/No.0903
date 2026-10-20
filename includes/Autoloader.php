<?php
/**
 * Built-in PSR-4 autoloader for LocalePilot runtime classes.
 *
 * Ensures the plugin installs and runs as a self-contained WordPress plugin
 * without requiring Composer or a vendor directory on merchant servers.
 *
 * @package LocalePilot
 */

declare(strict_types=1);

namespace LocalePilot;

defined( 'ABSPATH' ) || exit;

/**
 * Self-contained PSR-4 autoloader for the LocalePilot namespace.
 */
final class Autoloader {

	/**
	 * Namespace prefix handled by this autoloader.
	 */
	public const PREFIX = 'LocalePilot\\';

	/**
	 * Base directory for the namespace prefix.
	 *
	 * @var string
	 */
	private static string $base_dir = '';

	/**
	 * Whether the autoloader has been registered with SPL.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Register the autoloader with SPL.
	 *
	 * @param string $base_dir Absolute path to the includes directory.
	 * @return bool
	 */
	public static function register( string $base_dir ): bool {
		self::$base_dir = rtrim( $base_dir, '/\\' ) . DIRECTORY_SEPARATOR;

		if ( self::$registered ) {
			return true;
		}

		self::$registered = spl_autoload_register( array( self::class, 'autoload' ) );

		return self::$registered;
	}

	/**
	 * Check whether the autoloader has been registered with SPL.
	 *
	 * @return bool
	 */
	public static function is_registered(): bool {
		return self::$registered;
	}

	/**
	 * Resolve a fully-qualified class name to its expected file path.
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @param string $base_dir   Optional base directory override.
	 * @return string|null Absolute file path or null if outside namespace/unsafe.
	 */
	public static function resolve_path( string $class_name, string $base_dir = '' ): ?string {
		$prefix_length = strlen( self::PREFIX );
		if ( 0 !== strncmp( self::PREFIX, $class_name, $prefix_length ) ) {
			return null;
		}

		$relative_class = substr( $class_name, $prefix_length );
		if ( '' === $relative_class || false !== strpos( $relative_class, '..' ) ) {
			return null;
		}

		$dir = '' !== $base_dir
			? rtrim( $base_dir, '/\\' ) . DIRECTORY_SEPARATOR
			: self::$base_dir;

		if ( '' === $dir ) {
			return null;
		}

		$relative_path = str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class ) . '.php';

		return $dir . $relative_path;
	}

	/**
	 * Autoload callback for SPL.
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @return void
	 */
	public static function autoload( string $class_name ): void {
		$file = self::resolve_path( $class_name );
		if ( null !== $file && is_readable( $file ) ) {
			require_once $file;
		}
	}
}

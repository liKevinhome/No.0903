<?php
/**
 * PHPUnit bootstrap file for LocalePilot unit tests.
 *
 * @package LocalePilot\Tests
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require_once dirname( __DIR__ ) . '/localepilot.php';

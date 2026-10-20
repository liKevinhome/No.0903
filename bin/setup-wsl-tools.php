<?php
/**
 * Downloads and verifies project-local Composer PHAR in .tools/ using official
 * getcomposer.org SHA-384 / SHA-256 checksums.
 *
 * Development/testing use only. Excluded from plugin distribution ZIP via .distignore.
 *
 * @package LocalePilot\Bin
 */

declare(strict_types=1);

$project_root = dirname( __DIR__ );
$tools_dir    = $project_root . '/.tools';

if ( ! is_dir( $tools_dir ) && ! mkdir( $tools_dir, 0755, true ) && ! is_dir( $tools_dir ) ) {
	fwrite( STDERR, "ERROR: Failed to create .tools directory.\n" );
	exit( 1 );
}

echo "1. Fetching official SHA-384 checksum from https://getcomposer.org/download/ ...\n";
$download_page = file_get_contents( 'https://getcomposer.org/download/' );
if ( false === $download_page ) {
	fwrite( STDERR, "ERROR: Unable to fetch https://getcomposer.org/download/\n" );
	exit( 1 );
}

if ( ! preg_match( "/hash_file\(\s*'sha384'\s*,\s*'composer-setup\.php'\s*\)\s*===\s*'([0-9a-f]{96})'/i", $download_page, $matches ) ) {
	fwrite( STDERR, "ERROR: Could not extract official SHA-384 checksum from getcomposer.org/download/\n" );
	exit( 1 );
}

$expected_installer_sha384 = strtolower( $matches[1] );
echo '   Official getcomposer.org Installer SHA-384: ' . $expected_installer_sha384 . "\n";

$installer_path = $tools_dir . '/composer-setup.php';
echo "2. Downloading https://getcomposer.org/installer to .tools/composer-setup.php ...\n";
if ( ! copy( 'https://getcomposer.org/installer', $installer_path ) ) {
	fwrite( STDERR, "ERROR: Failed to download composer installer.\n" );
	exit( 1 );
}

$actual_installer_sha384 = strtolower( (string) hash_file( 'sha384', $installer_path ) );
echo '   Computed WSL SHA-384:                       ' . $actual_installer_sha384 . "\n";

if ( ! hash_equals( $expected_installer_sha384, $actual_installer_sha384 ) ) {
	unlink( $installer_path );
	fwrite( STDERR, "ERROR: SHA-384 mismatch! Deleted temporary installer and aborting.\n" );
	exit( 1 );
}

echo "   SHA-384 EXACT MATCH VERIFIED.\n";

echo "3. Running verified Composer installer to produce .tools/composer.phar ...\n";
$cmd = sprintf(
	'%s %s --install-dir=%s --filename=composer.phar',
	escapeshellarg( PHP_BINARY ),
	escapeshellarg( $installer_path ),
	escapeshellarg( $tools_dir )
);
passthru( $cmd, $exit_code );
if ( file_exists( $installer_path ) ) {
	unlink( $installer_path );
}

$phar_path = $tools_dir . '/composer.phar';
if ( 0 !== $exit_code || ! is_file( $phar_path ) ) {
	if ( file_exists( $phar_path ) ) {
		unlink( $phar_path );
	}
	fwrite( STDERR, "ERROR: Composer installer execution failed.\n" );
	exit( 1 );
}

$phar_sha384 = strtolower( (string) hash_file( 'sha384', $phar_path ) );
$phar_sha256 = strtolower( (string) hash_file( 'sha256', $phar_path ) );
echo '   .tools/composer.phar SHA-384: ' . $phar_sha384 . "\n";
echo '   .tools/composer.phar SHA-256: ' . $phar_sha256 . "\n";

$sha256_raw = file_get_contents( 'https://getcomposer.org/download/latest-stable/composer.phar.sha256sum' );
if ( false !== $sha256_raw && preg_match( '/^([0-9a-f]{64})\b/i', trim( $sha256_raw ), $m256 ) ) {
	$expected_phar_sha256 = strtolower( $m256[1] );
	echo '   Official composer.phar SHA-256: ' . $expected_phar_sha256 . "\n";
	if ( ! hash_equals( $expected_phar_sha256, $phar_sha256 ) ) {
		unlink( $phar_path );
		fwrite( STDERR, "ERROR: composer.phar SHA-256 mismatch! Deleted .tools/composer.phar and aborting.\n" );
		exit( 1 );
	}
	echo "   composer.phar SHA-256 EXACT MATCH VERIFIED.\n";
}

echo "SUCCESS: Verified .tools/composer.phar is ready.\n";

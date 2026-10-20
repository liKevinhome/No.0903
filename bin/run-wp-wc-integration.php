<?php
/**
 * Isolated local WordPress + WooCommerce + HPOS integration verifier for WSL.
 *
 * Safety & Scope Guarantees:
 * - Binds isolated MariaDB strictly to 127.0.0.1:13307; aborts immediately if
 *   port 13307 or socket is already in use by any existing process.
 * - Uses only isolated test databases (`localepilot_isolated_wp_test` or
 *   `lp_iso_wp{wp}_wc{wc}`) and isolated directories under `.tools/wp-test-site*`
 *   + `/tmp/localepilot_isolated_mariadb`.
 * - Dynamically queries official `https://api.wordpress.org/core/version-check/1.7/`
 *   for the requested WordPress release (`latest`, `previous-major`/`l-1`, or explicit
 *   version), verifies its official SHA-1 from `https://wordpress.org/wordpress-{version}.zip.sha1`
 *   before extraction, and deletes the temporary archive + aborts on mismatch.
 * - Dynamically queries official `https://api.wordpress.org/plugins/info/1.0/woocommerce.json`
 *   for the requested WooCommerce release (`latest`, `previous-major`/`l-1`, or explicit
 *   version) and downloads from official `downloads.wordpress.org`.
 * - Never executes `rm -rf`, never overwrites existing `wp-config.php`, and
 *   never connects to any existing/production database.
 *
 * Development/testing use only. Excluded from distribution ZIP via .distignore.
 *
 * @package LocalePilot\Bin
 */

declare(strict_types=1);

$project_root = dirname( __DIR__ );
$tools_dir    = $project_root . '/.tools';
$dist_zip     = $project_root . '/dist/localepilot.zip';
$db_base_dir  = '/tmp/localepilot_isolated_mariadb';
$db_data_dir  = $db_base_dir . '/data';
$db_socket    = $db_base_dir . '/mysqld.sock';
$db_pid_file  = $db_base_dir . '/mysqld.pid';
$db_log_file  = $db_base_dir . '/mysqld.log';
$db_host      = '127.0.0.1';
$db_port      = 13307;
$db_user      = 'lp_isolated_user';
$db_pass      = 'lp_isolated_local_pass';

// Parse optional explicit version/track parameters via CLI flags or environment variables.
$cli_opts = getopt( '', array( 'wp-version:', 'wc-version:' ) );
$req_wp   = trim( (string) ( $cli_opts['wp-version'] ?? getenv( 'LP_WP_VERSION' ) ?: 'latest' ) );
$req_wc   = trim( (string) ( $cli_opts['wc-version'] ?? getenv( 'LP_WC_VERSION' ) ?: 'latest' ) );

if ( ! is_file( $dist_zip ) ) {
	fwrite( STDERR, "ERROR: dist/localepilot.zip not found. Build it first.\n" );
	exit( 1 );
}

if ( ! is_dir( $tools_dir ) && ! mkdir( $tools_dir, 0755, true ) && ! is_dir( $tools_dir ) ) {
	fwrite( STDERR, "ERROR: Failed to create .tools directory.\n" );
	exit( 1 );
}

/**
 * Execute a shell command and return exit code.
 *
 * @param string $cmd Command string.
 * @return int
 */
function lp_run_cmd( string $cmd ): int {
	echo ">> $cmd\n";
	passthru( $cmd, $code );
	return (int) $code;
}

/**
 * Download a URL using curl with timeout and fail-on-error.
 *
 * @param string $url  Source HTTPS URL.
 * @param string $dest Destination file path.
 * @return bool
 */
function lp_curl_download( string $url, string $dest ): bool {
	$cmd = sprintf(
		'curl -fsSL --connect-timeout 15 --max-time 240 %s -o %s',
		escapeshellarg( $url ),
		escapeshellarg( $dest )
	);
	return 0 === lp_run_cmd( $cmd );
}

/**
 * Resolve requested WordPress version ('latest', 'previous-major' / 'l-1', or explicit x.y[.z])
 * against official https://api.wordpress.org/core/version-check/1.7/.
 *
 * @param string $requested Requested track or version.
 * @param string $tools_dir Isolated .tools directory path.
 * @return array{version:string,is_default_latest:bool}
 */
function lp_resolve_wp_version( string $requested, string $tools_dir ): array {
	$norm = strtolower( trim( $requested ) );
	if ( preg_match( '/^[0-9]+\.[0-9]+(\.[0-9]+)?$/', $norm ) ) {
		return array(
			'version'           => $norm,
			'is_default_latest' => false,
		);
	}
	$wp_api_tmp = $tools_dir . '/wp-version-check.json.tmp';
	if ( ! lp_curl_download( 'https://api.wordpress.org/core/version-check/1.7/', $wp_api_tmp ) ) {
		if ( file_exists( $wp_api_tmp ) ) {
			unlink( $wp_api_tmp );
		}
		fwrite( STDERR, "ERROR: Failed to query api.wordpress.org for WordPress version list.\n" );
		exit( 1 );
	}
	$wp_api_data = json_decode( (string) file_get_contents( $wp_api_tmp ), true );
	unlink( $wp_api_tmp );

	$offers         = is_array( $wp_api_data['offers'] ?? null ) ? $wp_api_data['offers'] : array();
	$latest_version = (string) ( $offers[0]['version'] ?? '' );
	if ( ! preg_match( '/^[0-9]+\.[0-9]+(\.[0-9]+)?$/', $latest_version ) ) {
		fwrite( STDERR, "ERROR: Invalid latest WordPress version returned from api.wordpress.org.\n" );
		exit( 1 );
	}
	if ( '' === $norm || 'latest' === $norm ) {
		return array(
			'version'           => $latest_version,
			'is_default_latest' => true,
		);
	}
	if ( in_array( $norm, array( 'previous-major', 'prev-major', 'l-1' ), true ) ) {
		$parts        = explode( '.', $latest_version );
		$latest_major = $parts[0] . '.' . ( $parts[1] ?? '0' );
		foreach ( $offers as $offer ) {
			$ver = (string) ( $offer['version'] ?? '' );
			if ( ! preg_match( '/^[0-9]+\.[0-9]+(\.[0-9]+)?$/', $ver ) ) {
				continue;
			}
			$v_parts = explode( '.', $ver );
			$v_major = $v_parts[0] . '.' . ( $v_parts[1] ?? '0' );
			if ( $v_major !== $latest_major ) {
				return array(
					'version'           => $ver,
					'is_default_latest' => false,
				);
			}
		}
		fwrite( STDERR, "ERROR: Could not resolve previous-major WordPress version from official offers.\n" );
		exit( 1 );
	}
	fwrite( STDERR, "ERROR: Unsupported WordPress version specifier '{$requested}'.\n" );
	exit( 1 );
}

/**
 * Resolve requested WooCommerce version ('latest', 'previous-major' / 'l-1', or explicit x.y.z)
 * against official https://api.wordpress.org/plugins/info/1.0/woocommerce.json.
 *
 * @param string $requested Requested track or version.
 * @param string $tools_dir Isolated .tools directory path.
 * @return array{version:string,download_url:string,is_default_latest:bool}
 */
function lp_resolve_wc_version( string $requested, string $tools_dir ): array {
	$norm       = strtolower( trim( $requested ) );
	$wc_api_tmp = $tools_dir . '/wc-plugin-info.json.tmp';
	if ( ! lp_curl_download( 'https://api.wordpress.org/plugins/info/1.0/woocommerce.json', $wc_api_tmp ) ) {
		if ( file_exists( $wc_api_tmp ) ) {
			unlink( $wc_api_tmp );
		}
		fwrite( STDERR, "ERROR: Failed to query api.wordpress.org for WooCommerce plugin info.\n" );
		exit( 1 );
	}
	$wc_api_data = json_decode( (string) file_get_contents( $wc_api_tmp ), true );
	unlink( $wc_api_tmp );

	$latest_version = (string) ( $wc_api_data['version'] ?? '' );
	$versions_map   = is_array( $wc_api_data['versions'] ?? null ) ? $wc_api_data['versions'] : array();
	if ( ! preg_match( '/^[0-9]+\.[0-9]+(\.[0-9]+)?$/', $latest_version ) ) {
		fwrite( STDERR, "ERROR: Invalid WooCommerce version returned from api.wordpress.org.\n" );
		exit( 1 );
	}

	if ( '' === $norm || 'latest' === $norm ) {
		$dl = (string) ( $wc_api_data['download_link'] ?? '' );
		return array(
			'version'           => $latest_version,
			'download_url'      => $dl,
			'is_default_latest' => true,
		);
	}

	if ( in_array( $norm, array( 'previous-major', 'prev-major', 'l-1' ), true ) ) {
		$parts        = explode( '.', $latest_version );
		$latest_major = $parts[0] . '.' . ( $parts[1] ?? '0' );
		$all_vers     = array_keys( $versions_map );
		rsort( $all_vers, SORT_NATURAL );
		foreach ( $all_vers as $ver ) {
			$ver_str = (string) $ver;
			if (
				false !== stripos( $ver_str, 'rc' )
				|| false !== stripos( $ver_str, 'beta' )
				|| 'trunk' === strtolower( $ver_str )
				|| ! preg_match( '/^[0-9]+\.[0-9]+(\.[0-9]+)?$/', $ver_str )
			) {
				continue;
			}
			$v_parts = explode( '.', $ver_str );
			$v_major = $v_parts[0] . '.' . ( $v_parts[1] ?? '0' );
			if ( $v_major !== $latest_major ) {
				return array(
					'version'           => $ver_str,
					'download_url'      => (string) ( $versions_map[ $ver_str ] ?? "https://downloads.wordpress.org/plugin/woocommerce.{$ver_str}.zip" ),
					'is_default_latest' => false,
				);
			}
		}
		fwrite( STDERR, "ERROR: Could not resolve previous-major WooCommerce version from official release list.\n" );
		exit( 1 );
	}

	if ( preg_match( '/^[0-9]+\.[0-9]+(\.[0-9]+)?$/', $norm ) ) {
		$dl = (string) ( $versions_map[ $norm ] ?? "https://downloads.wordpress.org/plugin/woocommerce.{$norm}.zip" );
		return array(
			'version'           => $norm,
			'download_url'      => $dl,
			'is_default_latest' => false,
		);
	}

	fwrite( STDERR, "ERROR: Unsupported WooCommerce version specifier '{$requested}'.\n" );
	exit( 1 );
}

// Reuse existing default .tools/wp-test-site when both tracks are default 'latest' and already present;
// otherwise provision/reuse isolated version-tagged directory under .tools/ and isolated DB name.
$default_latest_site = ( 'latest' === strtolower( $req_wp ) && 'latest' === strtolower( $req_wc ) );
if ( $default_latest_site && is_file( $tools_dir . '/wp-test-site/wp-load.php' ) && is_file( $tools_dir . '/wp-test-site/wp-content/plugins/woocommerce/woocommerce.php' ) ) {
	$wp_dir            = $tools_dir . '/wp-test-site';
	$db_name           = 'localepilot_isolated_wp_test';
	$target_wp_version = '';
	$target_wc_version = '';
	$target_wc_dl_url  = '';
} else {
	$resolved_wp       = lp_resolve_wp_version( $req_wp, $tools_dir );
	$resolved_wc       = lp_resolve_wc_version( $req_wc, $tools_dir );
	$target_wp_version = $resolved_wp['version'];
	$target_wc_version = $resolved_wc['version'];
	$target_wc_dl_url  = $resolved_wc['download_url'];

	if ( $resolved_wp['is_default_latest'] && $resolved_wc['is_default_latest'] ) {
		$wp_dir  = $tools_dir . '/wp-test-site';
		$db_name = 'localepilot_isolated_wp_test';
	} else {
		$slug_wp = str_replace( '.', '_', $target_wp_version );
		$slug_wc = str_replace( '.', '_', $target_wc_version );
		$wp_dir  = $tools_dir . "/wp-test-site-wp{$slug_wp}-wc{$slug_wc}";
		$db_name = "lp_iso_wp{$slug_wp}_wc{$slug_wc}";
	}
	echo "0. Target Matrix Instance: WordPress {$target_wp_version} + WooCommerce {$target_wc_version}\n";
	echo "   Isolated Site Dir: {$wp_dir}\n";
	echo "   Isolated DB Name:  {$db_name}\n";
}

// 1. Dynamically query and verify the requested WordPress release into $wp_dir.
if ( ! is_file( $wp_dir . '/wp-load.php' ) ) {
	$latest_wp_version = $target_wp_version;
	if ( '' === $latest_wp_version ) {
		$res_wp            = lp_resolve_wp_version( 'latest', $tools_dir );
		$latest_wp_version = $res_wp['version'];
	}
	echo "1. Preparing official WordPress {$latest_wp_version} release ...\n";

	$wp_zip_url  = "https://wordpress.org/wordpress-{$latest_wp_version}.zip";
	$wp_sha1_url = "https://wordpress.org/wordpress-{$latest_wp_version}.zip.sha1";

	echo "   Fetching official SHA-1 checksum from $wp_sha1_url ...\n";
	$wp_sha1_file = $tools_dir . "/wordpress-{$latest_wp_version}.zip.sha1.tmp";
	if ( ! lp_curl_download( $wp_sha1_url, $wp_sha1_file ) ) {
		if ( file_exists( $wp_sha1_file ) ) {
			unlink( $wp_sha1_file );
		}
		fwrite( STDERR, "ERROR: Failed to fetch WordPress {$latest_wp_version} SHA-1 checksum from wordpress.org.\n" );
		exit( 1 );
	}
	$expected_wp_sha1 = strtolower( trim( (string) file_get_contents( $wp_sha1_file ) ) );
	unlink( $wp_sha1_file );

	if ( ! preg_match( '/^[0-9a-f]{40}$/', $expected_wp_sha1 ) ) {
		fwrite( STDERR, "ERROR: Invalid SHA-1 format returned from wordpress.org.\n" );
		exit( 1 );
	}
	echo "   Official WordPress {$latest_wp_version} SHA-1: $expected_wp_sha1\n";

	$wp_zip_tmp = $tools_dir . "/wordpress-{$latest_wp_version}.zip.tmp";
	echo "   Downloading $wp_zip_url to .tools/wordpress-{$latest_wp_version}.zip.tmp ...\n";
	if ( ! lp_curl_download( $wp_zip_url, $wp_zip_tmp ) ) {
		if ( file_exists( $wp_zip_tmp ) ) {
			unlink( $wp_zip_tmp );
		}
		fwrite( STDERR, "ERROR: Failed to download WordPress {$latest_wp_version} archive.\n" );
		exit( 1 );
	}

	$actual_wp_sha1 = strtolower( (string) hash_file( 'sha1', $wp_zip_tmp ) );
	echo "   Computed WSL SHA-1:             $actual_wp_sha1\n";
	if ( ! hash_equals( $expected_wp_sha1, $actual_wp_sha1 ) ) {
		unlink( $wp_zip_tmp );
		fwrite( STDERR, "ERROR: WordPress {$latest_wp_version} SHA-1 mismatch! Deleted temporary file and aborting.\n" );
		exit( 1 );
	}
	echo "   WordPress {$latest_wp_version} SHA-1 EXACT MATCH VERIFIED.\n";

	$extract_parent = $tools_dir . '/wp-extract-stage-' . str_replace( '.', '_', $latest_wp_version );
	if ( ! is_dir( $extract_parent ) ) {
		mkdir( $extract_parent, 0755, true );
	}
	$unzip_wp_cmd = sprintf( 'unzip -q %s -d %s', escapeshellarg( $wp_zip_tmp ), escapeshellarg( $extract_parent ) );
	$rc           = lp_run_cmd( $unzip_wp_cmd );
	unlink( $wp_zip_tmp );
	if ( 0 !== $rc || ! is_dir( $extract_parent . '/wordpress' ) ) {
		fwrite( STDERR, "ERROR: Failed to extract WordPress archive.\n" );
		exit( 1 );
	}
	rename( $extract_parent . '/wordpress', $wp_dir );
	@rmdir( $extract_parent );
} else {
	echo "1. WordPress core already present in {$wp_dir}.\n";
}

// 2. Dynamically query and download the requested WooCommerce release into $wp_dir/wp-content/plugins/woocommerce.
$wc_plugin_dir = $wp_dir . '/wp-content/plugins/woocommerce';
if ( ! is_file( $wc_plugin_dir . '/woocommerce.php' ) ) {
	$latest_wc_version = $target_wc_version;
	$wc_download_url   = $target_wc_dl_url;
	if ( '' === $latest_wc_version || '' === $wc_download_url ) {
		$res_wc            = lp_resolve_wc_version( $req_wc, $tools_dir );
		$latest_wc_version = $res_wc['version'];
		$wc_download_url   = $res_wc['download_url'];
	}
	if ( ! preg_match( '/^[0-9]+\.[0-9]+(\.[0-9]+)?$/', $latest_wc_version ) || 0 !== strpos( $wc_download_url, 'https://downloads.wordpress.org/plugin/' ) ) {
		fwrite( STDERR, "ERROR: Invalid WooCommerce version or download_link returned from api.wordpress.org.\n" );
		exit( 1 );
	}
	echo "2. Preparing official WooCommerce {$latest_wc_version} release ...\n";
	echo "   Official Download URL: $wc_download_url\n";

	$wc_zip_tmp = $tools_dir . "/woocommerce.{$latest_wc_version}.zip.tmp";
	if ( ! lp_curl_download( $wc_download_url, $wc_zip_tmp ) ) {
		if ( file_exists( $wc_zip_tmp ) ) {
			unlink( $wc_zip_tmp );
		}
		fwrite( STDERR, "ERROR: Failed to download WooCommerce {$latest_wc_version} from downloads.wordpress.org.\n" );
		exit( 1 );
	}
	$plugins_dir  = $wp_dir . '/wp-content/plugins';
	$unzip_wc_cmd = sprintf( 'unzip -q %s -d %s', escapeshellarg( $wc_zip_tmp ), escapeshellarg( $plugins_dir ) );
	$rc           = lp_run_cmd( $unzip_wc_cmd );
	unlink( $wc_zip_tmp );
	if ( 0 !== $rc || ! is_file( $wc_plugin_dir . '/woocommerce.php' ) ) {
		fwrite( STDERR, "ERROR: Failed to extract WooCommerce into isolated plugins directory.\n" );
		exit( 1 );
	}
	echo "   WooCommerce {$latest_wc_version} extracted into {$wc_plugin_dir}.\n";
} else {
	echo "2. WooCommerce already present in {$wc_plugin_dir}.\n";
}

// 3. Extract self-contained dist/localepilot.zip into .tools/wp-test-site/wp-content/plugins/localepilot.
$lp_plugin_dir = $wp_dir . '/wp-content/plugins/localepilot';
if ( ! is_dir( $lp_plugin_dir ) ) {
	echo "3. Extracting self-contained dist/localepilot.zip into .tools/wp-test-site/wp-content/plugins ...\n";
	$unzip_lp_cmd = sprintf(
		'unzip -q %s -d %s',
		escapeshellarg( $dist_zip ),
		escapeshellarg( $wp_dir . '/wp-content/plugins' )
	);
	if ( 0 !== lp_run_cmd( $unzip_lp_cmd ) || ! is_file( $lp_plugin_dir . '/localepilot.php' ) ) {
		fwrite( STDERR, "ERROR: Failed to extract dist/localepilot.zip.\n" );
		exit( 1 );
	}
} else {
	echo "3. Updating runtime files from dist/localepilot.zip into .tools/wp-test-site/wp-content/plugins/localepilot ...\n";
	$unzip_lp_cmd = sprintf(
		'unzip -qo %s -d %s',
		escapeshellarg( $dist_zip ),
		escapeshellarg( $wp_dir . '/wp-content/plugins' )
	);
	if ( 0 !== lp_run_cmd( $unzip_lp_cmd ) ) {
		fwrite( STDERR, "ERROR: Failed to update localepilot from dist/localepilot.zip.\n" );
		exit( 1 );
	}
}

foreach ( array( 'vendor', 'composer.json', 'composer.lock', 'composer.phar', 'wp-cli.phar', '.tools', 'tests' ) as $forbidden ) {
	if ( file_exists( $lp_plugin_dir . '/' . $forbidden ) ) {
		fwrite( STDERR, "ERROR: Forbidden dev item '{$forbidden}' found in installed plugin directory!\n" );
		exit( 1 );
	}
}
echo "   Verified installed plugin directory contains ZERO Composer/vendor/test files.\n";

// 4. Pre-flight check: ensure 127.0.0.1:13307 and socket are NOT occupied by any external service.
echo "4. Checking that {$db_host}:{$db_port} is free before starting isolated MariaDB ...\n";
if ( file_exists( $db_socket ) ) {
	$prev_stop = sprintf( 'mariadb-admin --socket=%s -u root shutdown > /dev/null 2>&1', escapeshellarg( $db_socket ) );
	exec( $prev_stop );
	for ( $w = 0; $w < 10; $w++ ) {
		if ( ! file_exists( $db_socket ) ) {
			break;
		}
		usleep( 300000 );
	}
}
$existing_conn = @fsockopen( $db_host, $db_port, $errno, $errstr, 0.5 );
if ( is_resource( $existing_conn ) ) {
	fclose( $existing_conn );
	fwrite( STDERR, "ERROR: Port {$db_host}:{$db_port} is already occupied by an existing process. Aborting to protect existing services.\n" );
	exit( 1 );
}
if ( file_exists( $db_socket ) ) {
	fwrite( STDERR, "ERROR: Socket {$db_socket} already exists. Aborting to avoid touching an active MariaDB instance.\n" );
	exit( 1 );
}

if ( ! is_dir( $db_data_dir ) || ! is_dir( $db_data_dir . '/mysql' ) ) {
	if ( ! is_dir( $db_base_dir ) ) {
		mkdir( $db_base_dir, 0777, true );
	}
	if ( ! is_dir( $db_data_dir ) ) {
		mkdir( $db_data_dir, 0777, true );
	}
	chmod( $db_base_dir, 0777 );
	chmod( $db_data_dir, 0777 );

	$init_cmd = sprintf(
		'mariadb-install-db --user=mysql --datadir=%s --auth-root-authentication-method=normal --skip-test-db',
		escapeshellarg( $db_data_dir )
	);
	if ( 0 !== lp_run_cmd( $init_cmd ) ) {
		fwrite( STDERR, "ERROR: mariadb-install-db failed.\n" );
		exit( 1 );
	}
}

$started_by_script = false;
$start_db_cmd      = sprintf(
	'mariadbd --user=mysql --datadir=%s --socket=%s --pid-file=%s --log-error=%s --bind-address=%s --port=%d --skip-networking=0 > /dev/null 2>&1 &',
	escapeshellarg( $db_data_dir ),
	escapeshellarg( $db_socket ),
	escapeshellarg( $db_pid_file ),
	escapeshellarg( $db_log_file ),
	escapeshellarg( $db_host ),
	$db_port
);
lp_run_cmd( $start_db_cmd );
$started_by_script = true;

$db_ready = false;
for ( $i = 0; $i < 20; $i++ ) {
	usleep( 500000 );
	if ( file_exists( $db_socket ) ) {
		$ping_cmd = sprintf( 'mariadb-admin --socket=%s -u root ping > /dev/null 2>&1', escapeshellarg( $db_socket ) );
		exec( $ping_cmd, $out, $rc );
		if ( 0 === $rc ) {
			$db_ready = true;
			break;
		}
	}
}

try {
	if ( ! $db_ready ) {
		if ( is_file( $db_log_file ) ) {
			fwrite( STDERR, (string) file_get_contents( $db_log_file ) );
		}
		fwrite( STDERR, "ERROR: Isolated MariaDB instance failed to become ready on {$db_host}:{$db_port}.\n" );
		exit( 1 );
	}
	echo "   Isolated MariaDB is listening strictly on {$db_host}:{$db_port}.\n";

	$sql = sprintf(
		"CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS '%s'@'127.0.0.1' IDENTIFIED BY '%s'; GRANT ALL PRIVILEGES ON `%s`.* TO '%s'@'127.0.0.1'; FLUSH PRIVILEGES;",
		$db_name,
		$db_user,
		$db_pass,
		$db_name,
		$db_user
	);
	$sql_cmd = sprintf(
		'mariadb --socket=%s -u root -e %s',
		escapeshellarg( $db_socket ),
		escapeshellarg( $sql )
	);
	if ( 0 !== lp_run_cmd( $sql_cmd ) ) {
		fwrite( STDERR, "ERROR: Failed to provision isolated test database.\n" );
		exit( 1 );
	}

	// 5. Write wp-config.php inside .tools/wp-test-site if not already present (no overwrite).
	$wp_config_path = $wp_dir . '/wp-config.php';
	if ( ! is_file( $wp_config_path ) ) {
		echo "5. Creating isolated .tools/wp-test-site/wp-config.php connected to 127.0.0.1:{$db_port} ...\n";
		$wp_config_content = "<?php\n"
			. "define( 'DB_NAME', '" . addslashes( $db_name ) . "' );\n"
			. "define( 'DB_USER', '" . addslashes( $db_user ) . "' );\n"
			. "define( 'DB_PASSWORD', '" . addslashes( $db_pass ) . "' );\n"
			. "define( 'DB_HOST', '" . addslashes( "{$db_host}:{$db_port}" ) . "' );\n"
			. "define( 'DB_CHARSET', 'utf8mb4' );\n"
			. "define( 'DB_COLLATE', '' );\n"
			. "define( 'AUTH_KEY', 'lp-isolated-test-key-1' );\n"
			. "define( 'SECURE_AUTH_KEY', 'lp-isolated-test-key-2' );\n"
			. "define( 'LOGGED_IN_KEY', 'lp-isolated-test-key-3' );\n"
			. "define( 'NONCE_KEY', 'lp-isolated-test-key-4' );\n"
			. "define( 'AUTH_SALT', 'lp-isolated-test-salt-1' );\n"
			. "define( 'SECURE_AUTH_SALT', 'lp-isolated-test-salt-2' );\n"
			. "define( 'LOGGED_IN_SALT', 'lp-isolated-test-salt-3' );\n"
			. "define( 'NONCE_SALT', 'lp-isolated-test-salt-4' );\n"
			. "\$table_prefix = 'wptest_';\n"
			. "define( 'WP_DEBUG', true );\n"
			. "if ( ! defined( 'ABSPATH' ) ) {\n"
			. "\tdefine( 'ABSPATH', __DIR__ . '/' );\n"
			. "}\n"
			. "require_once ABSPATH . 'wp-settings.php';\n";
		file_put_contents( $wp_config_path, $wp_config_content );
	}

	// 6. Run native WordPress install, WooCommerce + HPOS activation, and LocalePilot activation/deactivation in a clean subprocess.
	echo "6. Executing real WordPress + WooCommerce + HPOS + LocalePilot lifecycle verification ...\n";
	$runner_code = '
		define( "WP_INSTALLING", true );
		$_SERVER["HTTP_HOST"]   = "127.0.0.1:18089";
		$_SERVER["SERVER_NAME"] = "127.0.0.1";
		$_SERVER["REQUEST_URI"] = "/";
		$_SERVER["REQUEST_METHOD"] = "GET";
		require_once ' . var_export( $wp_dir . '/wp-load.php', true ) . ';
		require_once ABSPATH . "wp-admin/includes/upgrade.php";
		require_once ABSPATH . "wp-admin/includes/plugin.php";
		if ( ! is_blog_installed() ) {
			wp_install(
				"LocalePilot Isolated Test",
				"admin",
				"admin@example.com",
				true,
				"",
				"lp_admin_local_pass"
			);
			echo "WP_CORE_INSTALLED=true\n";
		} else {
			echo "WP_CORE_INSTALLED=already_installed\n";
		}
	';
	$install_proc_cmd = sprintf( '%s -r %s', escapeshellarg( PHP_BINARY ), escapeshellarg( $runner_code ) );
	if ( 0 !== lp_run_cmd( $install_proc_cmd ) ) {
		fwrite( STDERR, "ERROR: WordPress database installation failed.\n" );
		exit( 1 );
	}

	$verify_code = '
		$_SERVER["HTTP_HOST"]   = "127.0.0.1:18089";
		$_SERVER["SERVER_NAME"] = "127.0.0.1";
		$_SERVER["REQUEST_URI"] = "/wp-admin/";
		$_SERVER["REQUEST_METHOD"] = "GET";
		require_once ' . var_export( $wp_dir . '/wp-load.php', true ) . ';
		require_once ABSPATH . "wp-admin/includes/plugin.php";

		$wc_res = activate_plugin( "woocommerce/woocommerce.php" );
		if ( is_wp_error( $wc_res ) ) {
			fwrite( STDERR, "ERROR activating WooCommerce: " . $wc_res->get_error_message() . "\n" );
			exit( 1 );
		}
		update_option( "woocommerce_custom_orders_table_enabled", "yes" );

		$lp_res = activate_plugin( "localepilot/localepilot.php" );
		if ( is_wp_error( $lp_res ) ) {
			fwrite( STDERR, "ERROR activating LocalePilot: " . $lp_res->get_error_message() . "\n" );
			exit( 1 );
		}
		echo "PLUGIN_ACTIVATED=true\n";
	';
	$activate_proc_cmd = sprintf( '%s -r %s', escapeshellarg( PHP_BINARY ), escapeshellarg( $verify_code ) );
	if ( 0 !== lp_run_cmd( $activate_proc_cmd ) ) {
		fwrite( STDERR, "ERROR: Plugin activation in WordPress failed.\n" );
		exit( 1 );
	}

	$boot_check_code = '
		$_SERVER["HTTP_HOST"]   = "127.0.0.1:18089";
		$_SERVER["SERVER_NAME"] = "127.0.0.1";
		$_SERVER["REQUEST_URI"] = "/";
		$_SERVER["REQUEST_METHOD"] = "GET";
		require_once ' . var_export( $wp_dir . '/wp-load.php', true ) . ';
		require_once ABSPATH . "wp-admin/includes/plugin.php";

		if ( ! function_exists( "localepilot" ) || ! localepilot()->is_booted() ) {
			fwrite( STDERR, "ERROR: localepilot() did not boot on plugins_loaded.\n" );
			exit( 1 );
		}
		$matrix = \LocalePilot\Compatibility::get_feature_matrix();
		if ( true !== ( $matrix["custom_order_tables"] ?? null ) || true !== ( $matrix["cart_checkout_blocks"] ?? null ) ) {
			fwrite( STDERR, "ERROR: Feature matrix mismatch.\n" );
			exit( 1 );
		}
		if ( ! class_exists( "\Automattic\WooCommerce\Utilities\FeaturesUtil" ) ) {
			fwrite( STDERR, "ERROR: WooCommerce FeaturesUtil class not loaded.\n" );
			exit( 1 );
		}
		$compat = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_plugins_for_feature( "custom_order_tables" );
		$compatible_list = $compat["compatible"] ?? array();
		if ( ! in_array( "localepilot/localepilot.php", $compatible_list, true ) ) {
			fwrite( STDERR, "ERROR: localepilot/localepilot.php not registered as compatible with HPOS in FeaturesUtil.\n" );
			exit( 1 );
		}
		$blocks_compat = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_plugins_for_feature( "cart_checkout_blocks" );
		$blocks_list   = $blocks_compat["compatible"] ?? array();
		if ( ! in_array( "localepilot/localepilot.php", $blocks_list, true ) ) {
			fwrite( STDERR, "ERROR: localepilot/localepilot.php not registered as compatible with cart_checkout_blocks in FeaturesUtil.\n" );
			exit( 1 );
		}
		echo "WC_VERSION=" . WC_VERSION . "\n";
		echo "WP_VERSION=" . get_bloginfo( "version" ) . "\n";
		echo "LOCALEPILOT_BOOTED=true\n";
		echo "HPOS_FEATURESUTIL_REGISTERED=true\n";
		echo "BLOCKS_FEATURESUTIL_REGISTERED=true\n";

		// Verify Stage 2 Admin UI bilingual resolution & per-admin user override in real WordPress DB.
		\LocalePilot\Admin\AdminLocaleManager::reset_memory_state();
		$admin_u1 = 1;
		$admin_u2 = username_exists( "admin_two" );
		if ( ! $admin_u2 ) {
			$admin_u2 = wp_create_user( "admin_two", "lp_admin2_local_pass", "admin2@example.com" );
		}
		$u2_obj = new \WP_User( $admin_u2 );
		$u2_obj->set_role( "administrator" );

		// 1) Default follows admin user profile locale (`zh_CN`) when site is `en_US`.
		$simulated_site_wplang = "en_US";
		add_filter(
			"pre_option_WPLANG",
			static function () use ( &$simulated_site_wplang ): string {
				return $simulated_site_wplang;
			}
		);
		update_user_meta( $admin_u1, "locale", "zh_CN" );
		update_user_meta( $admin_u1, \LocalePilot\Admin\AdminLocaleManager::USER_META_KEY, "auto" );
		\LocalePilot\Admin\AdminLocaleManager::reset_memory_state();
		if ( "zh_CN" !== \LocalePilot\Admin\AdminLocaleManager::resolve_admin_locale( $admin_u1 ) ) {
			fwrite( STDERR, "ERROR: Failed to follow admin user profile locale zh_CN in real WP.\n" );
			exit( 1 );
		}

		// 2) When admin user profile is "Site Default" (empty string), falls back to site WPLANG (`zh_CN`).
		$simulated_site_wplang = "zh_CN";
		update_user_meta( $admin_u1, "locale", "" );
		\LocalePilot\Admin\AdminLocaleManager::reset_memory_state();
		if ( "zh_CN" !== \LocalePilot\Admin\AdminLocaleManager::resolve_admin_locale( $admin_u1 ) ) {
			fwrite( STDERR, "ERROR: Failed to fall back to site WPLANG zh_CN when user profile is Site Default.\n" );
			exit( 1 );
		}

		// 3) Per-admin manual override via real WP nonce & capability check, isolated per admin account.
		$simulated_site_wplang = "en_US";
		update_user_meta( $admin_u1, "locale", "" );
		update_user_meta( $admin_u2, "locale", "" );
		update_user_meta( $admin_u2, \LocalePilot\Admin\AdminLocaleManager::USER_META_KEY, "auto" );
		\LocalePilot\Admin\AdminLocaleManager::reset_memory_state();

		wp_set_current_user( $admin_u1 );
		$real_nonce = wp_create_nonce( \LocalePilot\Admin\AdminLocaleManager::NONCE_ACTION );
		$admin_page = localepilot()->get_admin_page() ?? new \LocalePilot\Admin\AdminPage();
		$switched   = $admin_page->handle_locale_switch_submission(
			array(
				"localepilot_action"                              => \LocalePilot\Admin\AdminPage::FORM_ACTION,
				\LocalePilot\Admin\AdminPage::PARAM_MODE          => "zh_CN",
				\LocalePilot\Admin\AdminLocaleManager::NONCE_NAME => $real_nonce,
			)
		);
		if ( ! $switched ) {
			fwrite( STDERR, "ERROR: Real WP nonce/capability submission for admin locale override failed.\n" );
			exit( 1 );
		}

		\LocalePilot\Admin\AdminLocaleManager::reset_memory_state();
		if ( "zh_CN" !== \LocalePilot\Admin\AdminLocaleManager::resolve_admin_locale( $admin_u1 ) ) {
			fwrite( STDERR, "ERROR: Admin user 1 manual override zh_CN did not persist in WP usermeta.\n" );
			exit( 1 );
		}
		if ( "en_US" !== \LocalePilot\Admin\AdminLocaleManager::resolve_admin_locale( (int) $admin_u2 ) ) {
			fwrite( STDERR, "ERROR: Admin user 2 locale was affected by Admin user 1 override.\n" );
			exit( 1 );
		}
		if ( "en_US" !== get_option( "WPLANG" ) ) {
			fwrite( STDERR, "ERROR: Site WPLANG was mutated by admin UI override.\n" );
			exit( 1 );
		}

		$u1_html = $admin_page->get_page_html( $admin_u1 );
		$u2_html = $admin_page->get_page_html( (int) $admin_u2 );
		if ( false === strpos( $u1_html, "data-lp-admin-locale=\"zh_CN\"" ) || false === strpos( $u2_html, "data-lp-admin-locale=\"en_US\"" ) ) {
			fwrite( STDERR, "ERROR: Bilingual HTML output mismatch for admin users 1 and 2.\n" );
			exit( 1 );
		}
		echo "ADMIN_BILINGUAL_AND_OVERRIDE_VERIFIED=true\n";
		echo "STOREFRONT_WPLANG_ISOLATED=true\n";

		// Verify Stage 3 Translation Provider Settings, Secret Masking, $wpdb/dbDelta Persistence, Dedup & Review Workflow.
		$repo = new \LocalePilot\Translation\TranslationRepository();
		if ( ! $repo->ensure_table() ) {
			fwrite( STDERR, "ERROR: TranslationRepository::ensure_table() failed in real WordPress DB.\n" );
			exit( 1 );
		}
		global $wpdb;
		$wpdb->query( "DELETE FROM " . $repo->get_table_name() );

		wp_set_current_user( $admin_u1 );
		$prov_nonce    = wp_create_nonce( \LocalePilot\Admin\ProviderSettingsManager::NONCE_ACTION );
		$raw_test_key  = "deepl_live_wp_secret_key_998877:fx";
		$saved_setting = $admin_page->handle_provider_settings_submission(
			array(
				"localepilot_action"                                 => \LocalePilot\Admin\AdminPage::FORM_ACTION_SAVE_PROVIDERS,
				"active_provider"                                    => "deepl",
				"deepl_api_key"                                      => $raw_test_key,
				\LocalePilot\Admin\ProviderSettingsManager::NONCE_NAME => $prov_nonce,
			)
		);
		if ( ! $saved_setting ) {
			fwrite( STDERR, "ERROR: Failed to save provider settings in real WordPress options.\n" );
			exit( 1 );
		}

		$rendered_admin_html = $admin_page->get_page_html( $admin_u1 );
		if ( false !== strpos( $rendered_admin_html, $raw_test_key ) || false === strpos( $rendered_admin_html, "••••••••" ) ) {
			fwrite( STDERR, "ERROR: Provider secret masking check failed in real WP admin HTML.\n" );
			exit( 1 );
		}
		echo "PROVIDER_SETTINGS_MASKED_NO_PLAINTEXT_ECHO=true\n";

		$mock_http_calls = 0;
		$mock_http       = new class( $mock_http_calls ) implements \LocalePilot\Translation\HttpClientInterface {
			private int $calls;
			public function __construct( int &$calls ) {
				$this->calls = &$calls;
			}
			public function request( string $method, string $url, array $args = array() ): array {
				++$this->calls;
				return array(
					"status"     => 200,
					"body"       => "{\"translations\":[{\"text\":\"Manteau imperméable\"}]}",
					"headers"    => array(),
					"error"      => "",
					"is_timeout" => false,
				);
			}
		};

		// 1) Server-side connection test via mock HTTP.
		$conn_res = $admin_page->handle_provider_connection_test(
			array(
				"localepilot_action"                                 => \LocalePilot\Admin\AdminPage::FORM_ACTION_TEST_CONNECTION,
				"provider_id"                                        => "deepl",
				\LocalePilot\Admin\ProviderSettingsManager::NONCE_NAME => $prov_nonce,
			),
			null,
			null,
			$mock_http
		);
		if ( null === $conn_res || ! $conn_res->is_success() ) {
			fwrite( STDERR, "ERROR: Provider connection test failed in real WP.\n" );
			exit( 1 );
		}
		echo "PROVIDER_CONNECTION_TEST_VERIFIED=true\n";

		// 2) Submit product_title translation -> persisted via $wpdb with pending_review, then deduplicated on 2nd call.
		$calls_before_submit = $mock_http_calls;
		$sub_payload         = array(
			"localepilot_action"                                 => \LocalePilot\Admin\AdminPage::FORM_ACTION_SUBMIT_TRANSLATION,
			"provider_id"                                        => "deepl",
			"content_type"                                       => "product_title",
			"object_id"                                          => 501,
			"field_key"                                          => "post_title",
			"source_locale"                                      => "en_US",
			"target_locale"                                      => "fr_FR",
			"source_text"                                        => "Waterproof Coat",
			\LocalePilot\Admin\ProviderSettingsManager::NONCE_NAME => $prov_nonce,
		);
		$sub1 = $admin_page->handle_manual_translation_submission( $sub_payload, null, null, $mock_http, $repo );
		if ( null === $sub1 || ! $sub1->is_success() || "pending_review" !== $sub1->get_review_status() ) {
			fwrite( STDERR, "ERROR: First manual translation submission failed in real WP.\n" );
			exit( 1 );
		}
		$db_rows = $repo->list_recent();
		if ( 1 !== count( $db_rows ) ) {
			fwrite( STDERR, "ERROR: Translation was not persisted into MariaDB table.\n" );
			exit( 1 );
		}
		$row_id = (int) $db_rows[0]["id"];

		// Approve translation in MariaDB.
		$approved_ok = $admin_page->handle_review_status_submission(
			array(
				"localepilot_action"                                 => \LocalePilot\Admin\AdminPage::FORM_ACTION_UPDATE_REVIEW_STATUS,
				"translation_id"                                     => $row_id,
				"review_status"                                      => "approved",
				\LocalePilot\Admin\ProviderSettingsManager::NONCE_NAME => $prov_nonce,
			),
			null,
			null,
			$repo
		);
		if ( ! $approved_ok ) {
			fwrite( STDERR, "ERROR: Review status transition to approved failed in MariaDB.\n" );
			exit( 1 );
		}

		// Submit identical text again -> must hit MariaDB dedup without making another HTTP call.
		$sub2 = $admin_page->handle_manual_translation_submission( $sub_payload, null, null, $mock_http, new \LocalePilot\Translation\TranslationRepository() );
		if ( null === $sub2 || ! $sub2->is_success() || "approved" !== $sub2->get_review_status() || ( $calls_before_submit + 1 ) !== $mock_http_calls ) {
			fwrite( STDERR, "ERROR: Persistent MariaDB deduplication or approved status check failed.\n" );
			exit( 1 );
		}
		echo "WPDB_PERSISTENCE_DEDUP_AND_REVIEW_VERIFIED=true\n";

		// 3) Storefront guard check.
		$registry  = \LocalePilot\Translation\ProviderRegistry::create_default( \LocalePilot\Admin\ProviderSettingsManager::get_raw_settings(), $mock_http, false );
		$providers = $registry->get_all();
		$sf_req    = new \LocalePilot\Translation\TranslationRequest( "Waterproof Coat $99.00", "en_US", "fr_FR", "product_title" );
		$sf_res    = $providers["gemini"]->translate( $sf_req );
		if ( $sf_res->is_success() || \LocalePilot\Translation\TranslationResult::ERROR_STOREFRONT_BLOCKED !== $sf_res->get_error_code() ) {
			fwrite( STDERR, "ERROR: Storefront execution guard failed in live WP environment.\n" );
			exit( 1 );
		}
		echo "TRANSLATION_PROVIDERS_REGISTERED=gemini,deepl,google_cloud,baidu\n";
		echo "TRANSLATION_STOREFRONT_GUARD_VERIFIED=true\n";

		// Verify Stage 4 Same-URL Storefront Language Resolution, Approved-Only Rendering & Cache Isolation.
		\LocalePilot\Storefront\StorefrontLocaleResolver::update_settings(
			array(
				"default_locale"    => "en_US",
				"enabled_locales"   => array( "en_US", "fr_FR", "de_DE", "es_ES" ),
				"trust_proxy_geoip" => true,
				"geoip_country_map" => array( "FR" => "fr_FR", "DE" => "de_DE" ),
				"cookie_ttl_days"   => 30,
				"cache_mode"        => "safe_bypass",
			)
		);

		$test_product = new \WC_Product_Simple();
		$test_product->set_name( "Waterproof Hiking Jacket" );
		$test_product->set_short_description( "Lightweight breathable shell." );
		$test_product->set_description( "Full 3-layer membrane jacket." );
		$test_product->set_regular_price( "149.00" );
		$test_product->set_status( "publish" );
		$product_id = $test_product->save();
		if ( $product_id <= 0 ) {
			fwrite( STDERR, "ERROR: Failed to create synthetic WC_Product_Simple in isolated WP.\n" );
			exit( 1 );
		}

		// Save approved fr_FR translations and pending_review de_DE translation for this product.
		$repo->save(
			array(
				"object_type"     => "product_title",
				"object_id"       => $product_id,
				"field_key"       => "post_title",
				"source_locale"   => "en_US",
				"target_locale"   => "fr_FR",
				"source_hash"     => hash( "sha256", "Waterproof Hiking Jacket" ),
				"provider_id"     => "deepl",
				"translated_text" => "Veste de randonnée imperméable",
				"review_status"   => "approved",
			)
		);
		$repo->save(
			array(
				"object_type"     => "product_short_description",
				"object_id"       => $product_id,
				"field_key"       => "post_excerpt",
				"source_locale"   => "en_US",
				"target_locale"   => "fr_FR",
				"source_hash"     => hash( "sha256", "Lightweight breathable shell." ),
				"provider_id"     => "deepl",
				"translated_text" => "Coquille légère et respirante.",
				"review_status"   => "approved",
			)
		);
		$repo->save(
			array(
				"object_type"     => "product_description",
				"object_id"       => $product_id,
				"field_key"       => "post_content",
				"source_locale"   => "en_US",
				"target_locale"   => "fr_FR",
				"source_hash"     => hash( "sha256", "Full 3-layer membrane jacket." ),
				"provider_id"     => "deepl",
				"translated_text" => "Veste à membrane 3 couches.",
				"review_status"   => "approved",
			)
		);
		$repo->save(
			array(
				"object_type"     => "product_title",
				"object_id"       => $product_id,
				"field_key"       => "post_title",
				"source_locale"   => "en_US",
				"target_locale"   => "de_DE",
				"source_hash"     => hash( "sha256", "Waterproof Hiking Jacket" ),
				"provider_id"     => "deepl",
				"translated_text" => "Wasserdichte Wanderjacke",
				"review_status"   => "pending_review",
			)
		);

		$calls_before_storefront = $mock_http_calls;
		$permalink_en            = get_permalink( $product_id ) . "?utm_source=meta&color=navy";

		// 1) English cookie on same URL -> English product text, price 149.00.
		$_COOKIE["lp_lang"] = "en_US";
		$loaded_en          = wc_get_product( $product_id );
		if ( "Waterproof Hiking Jacket" !== $loaded_en->get_name() || "149.00" !== $loaded_en->get_regular_price() ) {
			fwrite( STDERR, "ERROR: English storefront product name or price mismatch.\n" );
			exit( 1 );
		}

		// 2) French cookie (`approved`) on exact same URL -> French product text, identical permalink/query, price 149.00, 0 API calls.
		$_COOKIE["lp_lang"] = "fr_FR";
		$permalink_fr       = get_permalink( $product_id ) . "?utm_source=meta&color=navy";
		$loaded_fr          = wc_get_product( $product_id );
		if (
			$permalink_en !== $permalink_fr
			|| false !== strpos( $permalink_fr, "/fr/" )
			|| "Veste de randonnée imperméable" !== $loaded_fr->get_name()
			|| "Coquille légère et respirante." !== $loaded_fr->get_short_description()
			|| "Veste à membrane 3 couches." !== $loaded_fr->get_description()
			|| "149.00" !== $loaded_fr->get_regular_price()
		) {
			fwrite( STDERR, "ERROR: French same-URL storefront rendering or permalink/price preservation failed.\n" );
			exit( 1 );
		}

		// 3) German cookie (`pending_review`) on same URL -> falls back to original English text.
		$_COOKIE["lp_lang"] = "de_DE";
		$loaded_de          = wc_get_product( $product_id );
		if ( "Waterproof Hiking Jacket" !== $loaded_de->get_name() ) {
			fwrite( STDERR, "ERROR: Pending review de_DE translation leaked to storefront instead of falling back.\n" );
			exit( 1 );
		}

		// 4) Invalid cookie + Accept-Language header + GeoIP hint (verifying currency & price remain untouched).
		unset( $_COOKIE["lp_lang"] );
		$_SERVER["HTTP_ACCEPT_LANGUAGE"] = "fr-FR,fr;q=0.9";
		$_SERVER["HTTP_CF_IPCOUNTRY"]    = "DE";
		$currency_before                 = get_woocommerce_currency();
		$loaded_accept                   = wc_get_product( $product_id );
		if ( "Veste de randonnée imperméable" !== $loaded_accept->get_name() || $currency_before !== get_woocommerce_currency() ) {
			fwrite( STDERR, "ERROR: Accept-Language resolution or WooCommerce currency isolation failed.\n" );
			exit( 1 );
		}

		// 5) Cache isolation guard & WP Rocket filter verification.
		$_COOKIE["lp_lang"] = "fr_FR";
		$cache_guard        = localepilot()->get_cache_isolation_guard() ?? new \LocalePilot\Storefront\CacheIsolationGuard();
		$cache_decision     = $cache_guard->apply_storefront_cache_guard();
		$rocket_cookies     = apply_filters( "rocket_cache_dynamic_cookies", array() );
		if (
			! $cache_decision["bypass_shared_cache"]
			|| ! defined( "DONOTCACHEPAGE" )
			|| true !== constant( "DONOTCACHEPAGE" )
			|| ! in_array( "lp_lang", $rocket_cookies, true )
			|| $calls_before_storefront !== $mock_http_calls
		) {
			fwrite( STDERR, "ERROR: Storefront cache guard or zero-API-call guarantee failed in live WP.\n" );
			exit( 1 );
		}
		echo "SAME_URL_MULTILINGUAL_STOREFRONT_VERIFIED=true\n";
		echo "STOREFRONT_APPROVED_ONLY_AND_PENDING_FALLBACK=true\n";
		echo "STOREFRONT_ZERO_EXTERNAL_API_CALLS=true\n";
		echo "CACHE_ISOLATION_GUARD_AND_DONOTCACHEPAGE=true\n";

		// Verify Stage 5 WooCommerce Cart, Coupon, Shipping Rate, Payment Gateways, Store API/Blocks, HPOS Order Locale Snapshot & Order Emails.
		if ( function_exists( "wc_load_cart" ) ) {
			wc_load_cart();
		}
		WC()->cart->empty_cart();

		// Create or update a real WC_Coupon ($20.00 fixed cart discount).
		$coupon_code        = "lptest20";
		$existing_coupon_id = wc_get_coupon_id_by_code( $coupon_code );
		$test_coupon        = $existing_coupon_id > 0 ? new \WC_Coupon( $existing_coupon_id ) : new \WC_Coupon();
		$test_coupon->set_code( $coupon_code );
		$test_coupon->set_discount_type( "fixed_cart" );
		$test_coupon->set_amount( 20.00 );
		$test_coupon->save();

		// Add 2 units of $test_product ($149.00 each => $298.00 subtotal) and apply $20.00 coupon (=> $278.00 total).
		$_COOKIE["lp_lang"] = "en_US";
		$cart_item_key      = WC()->cart->add_to_cart( $product_id, 2 );
		if ( ! $cart_item_key ) {
			fwrite( STDERR, "ERROR: Failed to add product to real WC_Cart.\n" );
			exit( 1 );
		}
		if ( ! WC()->cart->apply_coupon( $coupon_code ) ) {
			fwrite( STDERR, "ERROR: Failed to apply real WC_Coupon to WC_Cart.\n" );
			exit( 1 );
		}
		WC()->cart->calculate_totals();

		$en_subtotal = (float) WC()->cart->get_subtotal();
		$en_discount = (float) WC()->cart->get_discount_total();
		$en_total    = (float) WC()->cart->get_cart_contents_total();
		$cart_items  = WC()->cart->get_cart();
		$cart_item   = $cart_items[ $cart_item_key ];
		$en_item_str = apply_filters( "woocommerce_cart_item_name", $cart_item["data"]->get_name(), $cart_item, $cart_item_key );

		// Switch visitor language on same URL to French (`fr_FR`, `approved`) and recalculate cart totals.
		$_COOKIE["lp_lang"] = "fr_FR";
		WC()->cart->calculate_totals();
		$fr_subtotal = (float) WC()->cart->get_subtotal();
		$fr_discount = (float) WC()->cart->get_discount_total();
		$fr_total    = (float) WC()->cart->get_cart_contents_total();
		$fr_item_str = apply_filters( "woocommerce_cart_item_name", $cart_item["data"]->get_name(), $cart_item, $cart_item_key );
		$fr_cpn_msg  = apply_filters( "woocommerce_coupon_message", "Coupon code applied successfully.", 200, $test_coupon );
		$fr_cpn_err  = apply_filters( "woocommerce_coupon_error", "Coupon \"BADCODE\" does not exist!", 105, null );

		// Switch visitor language to German (`de_DE`, `pending_review`) -> cart item name must fall back to English while amounts stay identical.
		$_COOKIE["lp_lang"] = "de_DE";
		WC()->cart->calculate_totals();
		$de_total    = (float) WC()->cart->get_cart_contents_total();
		$de_item_str = apply_filters( "woocommerce_cart_item_name", $cart_item["data"]->get_name(), $cart_item, $cart_item_key );

		if (
			"Waterproof Hiking Jacket" !== $en_item_str
			|| "Veste de randonnée imperméable" !== $fr_item_str
			|| "Waterproof Hiking Jacket" !== $de_item_str
			|| 298.00 !== $en_subtotal
			|| 298.00 !== $fr_subtotal
			|| 20.00 !== $en_discount
			|| 20.00 !== $fr_discount
			|| 278.00 !== $en_total
			|| 278.00 !== $fr_total
			|| 278.00 !== $de_total
			|| 2 !== (int) $cart_item["quantity"]
			|| "Code promo appliqué avec succès." !== $fr_cpn_msg
			|| "Le code promo « BADCODE » n’existe pas !" !== $fr_cpn_err
		) {
			fwrite( STDERR, "ERROR: Real WC_Cart item localization, coupon localization, or zero-amount-mutation check failed.\n" );
			exit( 1 );
		}
		echo "WC_CART_AND_COUPON_MULTILINGUAL_ZERO_AMOUNT_DIFF=true\n";

		// Real wc-ajax mini-cart HTML render (`woocommerce_mini_cart()`) + real invalid coupon notice via `WC()->cart->apply_coupon()`.
		$_COOKIE["lp_lang"]     = "fr_FR";
		$_GET["wc-ajax"]        = "get_refreshed_fragments";
		$_SERVER["REQUEST_URI"] = "/?wc-ajax=get_refreshed_fragments";
		ob_start();
		woocommerce_mini_cart();
		$mini_cart_html = (string) ob_get_clean();
		unset( $_GET["wc-ajax"] );
		$_SERVER["REQUEST_URI"] = "/";

		wc_clear_notices();
		WC()->cart->apply_coupon( "nonexistent_code_999" );
		$err_notices     = wc_get_notices( "error" );
		$first_err_notic = (string) ( $err_notices[0]["notice"] ?? "" );
		wc_clear_notices();

		if (
			false === strpos( $mini_cart_html, "Veste de randonnée imperméable" )
			|| false === strpos( $first_err_notic, "nonexistent_code_999" )
			|| false === strpos( $first_err_notic, "n’existe pas" )
		) {
			fwrite( STDERR, "ERROR: Real wc-ajax mini-cart HTML or WC_Coupon error notice check failed.\n" );
			exit( 1 );
		}
		echo "WC_AJAX_MINI_CART_AND_REAL_COUPON_NOTICE_VERIFIED=true\n";

		// Real WC_Shipping_Rate and Payment Gateways verification in `fr_FR`.
		$_COOKIE["lp_lang"] = "fr_FR";
		$real_shipping_rate = new \WC_Shipping_Rate( "flat_rate:1", "Flat rate", 15.00, array( 1 => 3.00 ), "flat_rate", 1 );
		$filtered_rates     = apply_filters( "woocommerce_package_rates", array( "flat_rate:1" => $real_shipping_rate ), array() );
		$rate_after         = $filtered_rates["flat_rate:1"];
		if (
			"Forfait" !== $rate_after->get_label()
			|| "flat_rate:1" !== $rate_after->get_id()
			|| 15.00 !== (float) $rate_after->get_cost()
			|| array( 1 => 3.00 ) !== $rate_after->get_taxes()
		) {
			fwrite( STDERR, "ERROR: Real WC_Shipping_Rate label localization or cost/tax preservation failed.\n" );
			exit( 1 );
		}

		$bacs_title = apply_filters( "woocommerce_gateway_title", "Direct bank transfer", "bacs" );
		$cod_title  = apply_filters( "woocommerce_gateway_title", "Cash on delivery", "cod" );
		$cod_desc   = apply_filters( "woocommerce_gateway_description", "Pay with cash upon delivery.", "cod" );
		$chk_fields = apply_filters(
			"woocommerce_checkout_fields",
			array(
				"billing" => array(
					"billing_first_name" => array(
						"label"    => "First name",
						"required" => true,
					),
				),
			)
		);
		$val_notice = apply_filters( "woocommerce_add_error", "<strong>Billing First name</strong> is a required field." );
		if (
			"Virement bancaire" !== $bacs_title
			|| "Paiement à la livraison" !== $cod_title
			|| "Payer en espèces à la livraison." !== $cod_desc
			|| "Prénom" !== ( $chk_fields["billing"]["billing_first_name"]["label"] ?? "" )
			|| "<strong>Facturation Prénom</strong> est un champ obligatoire." !== $val_notice
		) {
			fwrite( STDERR, "ERROR: Shipping/Payment/Checkout field/Validation notice localization failed in live WC.\n" );
			exit( 1 );
		}
		echo "WC_SHIPPING_PAYMENT_CHECKOUT_FIELDS_VERIFIED=true\n";

		// Real HPOS Order Creation (Classic Checkout + Store API / Checkout Blocks) and Order Email Locale Switching.
		$_COOKIE["lp_lang"] = "fr_FR";
		$classic_order      = wc_create_order();
		$classic_order->add_product( wc_get_product( $product_id ), 2 );
		$classic_order->set_currency( get_woocommerce_currency() );
		$classic_order->set_billing_country( "US" );
		$classic_order->set_shipping_country( "US" );
		$classic_order->calculate_totals();
		do_action( "woocommerce_checkout_create_order", $classic_order, array() );
		$classic_order->save();

		$reloaded_classic_order = wc_get_order( $classic_order->get_id() );
		$hpos_meta_val          = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d AND meta_key = %s LIMIT 1",
				$classic_order->get_id(),
				"_localepilot_order_locale"
			)
		);
		$legacy_postmeta_cnt    = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
				$classic_order->get_id(),
				"_localepilot_order_locale"
			)
		);
		if (
			"fr_FR" !== $reloaded_classic_order->get_meta( "_localepilot_order_locale", true )
			|| "fr_FR" !== \LocalePilot\Storefront\OrderLocaleCoordinator::get_order_locale( $reloaded_classic_order )
			|| "fr_FR" !== (string) $hpos_meta_val
			|| 0 !== $legacy_postmeta_cnt
			|| 298.00 !== (float) $reloaded_classic_order->get_total()
			|| "US" !== $reloaded_classic_order->get_shipping_country()
		) {
			fwrite( STDERR, "ERROR: Classic Checkout HPOS order locale snapshot or order integrity check failed.\n" );
			exit( 1 );
		}

		// Store API / Checkout Blocks real REST controller (`/wc/store/v1/cart`) + order hook verification.
		$_SERVER["REQUEST_URI"] = "/wp-json/wc/store/v1/cart";
		$_COOKIE["lp_lang"]     = "fr_FR";
		$store_cart_res_fr      = rest_do_request( new \WP_REST_Request( "GET", "/wc/store/v1/cart" ) );
		$store_cart_data_fr     = json_decode( (string) wp_json_encode( $store_cart_res_fr->get_data() ), true );
		$store_item_name_fr     = $store_cart_data_fr["items"][0]["name"] ?? "";
		$store_total_items_fr   = $store_cart_data_fr["totals"]["total_items"] ?? "";

		$_COOKIE["lp_lang"]   = "de_DE";
		$store_cart_res_de    = rest_do_request( new \WP_REST_Request( "GET", "/wc/store/v1/cart" ) );
		$store_cart_data_de   = json_decode( (string) wp_json_encode( $store_cart_res_de->get_data() ), true );
		$store_item_name_de   = $store_cart_data_de["items"][0]["name"] ?? "";
		$store_total_items_de = $store_cart_data_de["totals"]["total_items"] ?? "";

		if (
			200 !== $store_cart_res_fr->get_status()
			|| "Veste de randonnée imperméable" !== $store_item_name_fr
			|| "Waterproof Hiking Jacket" !== $store_item_name_de
			|| $store_total_items_fr !== $store_total_items_de
		) {
			fwrite( STDERR, "ERROR: Real WooCommerce Store API /wc/store/v1/cart response check failed.\n" );
			exit( 1 );
		}
		echo "WC_STORE_API_BLOCKS_CART_ENDPOINT_VERIFIED=true\n";

		$_SERVER["REQUEST_URI"] = "/wp-json/wc/store/v1/checkout";
		$_COOKIE["lp_lang"]     = "fr_FR";
		$blocks_order           = wc_create_order();
		$blocks_order->add_product( wc_get_product( $product_id ), 1 );
		$blocks_order->calculate_totals();
		$store_api_req = new \WP_REST_Request( "POST", "/wc/store/v1/checkout" );
		do_action( "woocommerce_store_api_checkout_update_order_from_request", $blocks_order, $store_api_req );
		$blocks_order->save();
		$_SERVER["REQUEST_URI"] = "/";

		$reloaded_blocks_order = wc_get_order( $blocks_order->get_id() );
		if (
			"fr_FR" !== $reloaded_blocks_order->get_meta( "_localepilot_order_locale", true )
			|| 149.00 !== (float) $reloaded_blocks_order->get_total()
		) {
			fwrite( STDERR, "ERROR: Store API / Checkout Blocks HPOS order locale snapshot failed.\n" );
			exit( 1 );
		}
		echo "HPOS_ORDER_LOCALE_SNAPSHOT_CLASSIC_AND_BLOCKS_VERIFIED=true\n";

		// Order Transactional Email rendering when current request cookie is English (`en_US`).
		$_COOKIE["lp_lang"] = "en_US";
		$order_items        = $reloaded_classic_order->get_items();
		$first_order_item   = reset( $order_items );

		do_action( "woocommerce_email_before_order_table", $reloaded_classic_order, false, false, null );
		$email_item_name    = apply_filters( "woocommerce_order_item_name", $first_order_item->get_name(), $first_order_item );
		$email_active_loc   = \LocalePilot\Storefront\StorefrontLocaleResolver::resolve_visitor_locale();
		do_action( "woocommerce_email_after_order_table", $reloaded_classic_order, false, false, null );
		$restored_loc       = \LocalePilot\Storefront\StorefrontLocaleResolver::resolve_visitor_locale();

		// Also render full real WC_Email_Customer_Processing_Order HTML template while ambient cookie is en_US.
		$wc_emails        = WC()->mailer()->get_emails();
		$processing_email = $wc_emails["WC_Email_Customer_Processing_Order"] ?? null;
		$full_email_html  = "";
		if ( is_object( $processing_email ) ) {
			$processing_email->object = $reloaded_classic_order;
			$full_email_html          = (string) $processing_email->get_content_html();
		}

		if (
			"fr_FR" !== $email_active_loc
			|| "Veste de randonnée imperméable" !== $email_item_name
			|| "en_US" !== $restored_loc
			|| false === strpos( $full_email_html, "Veste de randonnée imperméable" )
			|| false !== strpos( $full_email_html, "Waterproof Hiking Jacket" )
			|| null !== \LocalePilot\Storefront\StorefrontLocaleResolver::get_runtime_locale_override()
			|| $calls_before_storefront !== $mock_http_calls
		) {
			fwrite( STDERR, "ERROR: Order email locale switching/restoration or zero-API-call check failed.\n" );
			exit( 1 );
		}
		echo "ORDER_EMAIL_SNAPSHOT_LOCALE_SWITCH_AND_RESTORE_VERIFIED=true\n";
		echo "WC_EMAIL_CUSTOMER_PROCESSING_ORDER_HTML_VERIFIED=true\n";

		// Verify Stage 6 Action Scheduler Background Queue, Retry/Cancel, Approved Protection, Subscription Degradation & Cache Health Check.
		if ( ! function_exists( "as_enqueue_async_action" ) || ! function_exists( "as_schedule_single_action" ) ) {
			fwrite( STDERR, "ERROR: WooCommerce Action Scheduler functions not loaded.\n" );
			exit( 1 );
		}
		echo "ACTION_SCHEDULER_RUNTIME_LOADED=true\n";

		\LocalePilot\Translation\TranslationQueueManager::reset_memory_state();
		delete_option( \LocalePilot\Translation\TranslationQueueManager::OPTION_JOBS_KEY );
		delete_option( \LocalePilot\Translation\TranslationQueueManager::OPTION_RATE_LIMIT_KEY );

		$queue_mgr = new \LocalePilot\Translation\TranslationQueueManager( $repo, $mock_http, true );
		$q_nonce   = wp_create_nonce( \LocalePilot\Admin\ProviderSettingsManager::NONCE_ACTION );

		// 1) Enqueue new product_title job via AdminPage + Action Scheduler (`as_enqueue_async_action`).
		$enq_res = $admin_page->handle_enqueue_translation_submission(
			array(
				"localepilot_action"                                 => \LocalePilot\Admin\AdminPage::FORM_ACTION_ENQUEUE_TRANSLATION,
				"provider_id"                                        => "deepl",
				"content_type"                                       => "product_title",
				"object_id"                                          => 991,
				"field_key"                                          => "post_title",
				"source_locale"                                      => "en_US",
				"target_locale"                                      => "es_ES",
				"source_text"                                        => " Waterproof Trail Coat ",
				\LocalePilot\Admin\ProviderSettingsManager::NONCE_NAME => $q_nonce,
			),
			null,
			null,
			$queue_mgr
		);
		if (
			null === $enq_res
			|| ! $enq_res["ok"]
			|| "pending" !== $enq_res["status"]
			|| (int) ( $enq_res["as_action_id"] ?? 0 ) <= 0
		) {
			fwrite( STDERR, "ERROR: Failed to enqueue job into real WooCommerce Action Scheduler.\n" );
			exit( 1 );
		}
		$es_job_id = (string) $enq_res["job_id"];

		// 2) Duplicate enqueue while pending -> deduplicated.
		$enq_dup = $admin_page->handle_enqueue_translation_submission(
			array(
				"localepilot_action"                                 => \LocalePilot\Admin\AdminPage::FORM_ACTION_ENQUEUE_TRANSLATION,
				"provider_id"                                        => "deepl",
				"content_type"                                       => "product_title",
				"object_id"                                          => 991,
				"field_key"                                          => "post_title",
				"source_locale"                                      => "en_US",
				"target_locale"                                      => "es_ES",
				"source_text"                                        => "Waterproof Trail Coat",
				\LocalePilot\Admin\ProviderSettingsManager::NONCE_NAME => $q_nonce,
			),
			null,
			null,
			$queue_mgr
		);
		if ( null === $enq_dup || ! $enq_dup["deduplicated"] || "deduplicated_queue" !== $enq_dup["code"] ) {
			fwrite( STDERR, "ERROR: Action Scheduler queue deduplication check failed.\n" );
			exit( 1 );
		}

		// 3) Process queued job -> succeeded and persisted as pending_review in MariaDB.
		$proc_res = $queue_mgr->process_job( $es_job_id );
		if ( "succeeded" !== ( $proc_res["status"] ?? "" ) ) {
			fwrite( STDERR, "ERROR: Processing Action Scheduler job failed.\n" );
			exit( 1 );
		}

		// 4) Enqueue and cancel a job -> cancelled and unscheduled in Action Scheduler.
		$enq_cancel = $admin_page->handle_enqueue_translation_submission(
			array(
				"localepilot_action"                                 => \LocalePilot\Admin\AdminPage::FORM_ACTION_ENQUEUE_TRANSLATION,
				"provider_id"                                        => "deepl",
				"content_type"                                       => "product_title",
				"object_id"                                          => 992,
				"field_key"                                          => "post_title",
				"source_locale"                                      => "en_US",
				"target_locale"                                      => "de_DE",
				"source_text"                                        => "Cancelable Jacket",
				\LocalePilot\Admin\ProviderSettingsManager::NONCE_NAME => $q_nonce,
			),
			null,
			null,
			$queue_mgr
		);
		$cancel_job_id = (string) ( $enq_cancel["job_id"] ?? "" );
		$cancelled_ok  = $admin_page->handle_cancel_job_submission(
			array(
				"localepilot_action"                                 => \LocalePilot\Admin\AdminPage::FORM_ACTION_CANCEL_JOB,
				"job_id"                                             => $cancel_job_id,
				\LocalePilot\Admin\ProviderSettingsManager::NONCE_NAME => $q_nonce,
			),
			null,
			null,
			$queue_mgr
		);
		$after_cancel_job = $queue_mgr->process_job( $cancel_job_id );
		if ( ! $cancelled_ok || "cancelled" !== ( $after_cancel_job["status"] ?? "" ) ) {
			fwrite( STDERR, "ERROR: Action Scheduler job cancellation failed.\n" );
			exit( 1 );
		}

		// 5) Approved translation protection: enqueueing against already-approved $product_id (`fr_FR`) never overwrites approved text.
		$enq_prot = $queue_mgr->enqueue_job(
			array(
				"provider_id"   => "deepl",
				"content_type"  => "product_title",
				"object_id"     => $product_id,
				"field_key"     => "post_title",
				"source_locale" => "en_US",
				"target_locale" => "fr_FR",
				"source_text"   => "Waterproof Hiking Jacket",
			)
		);
		if (
			! $enq_prot["ok"]
			|| ! $enq_prot["deduplicated"]
			|| "already_approved" !== $enq_prot["code"]
			|| "Veste de randonnée imperméable" !== $enq_prot["translated_text"]
		) {
			fwrite( STDERR, "ERROR: Protection of existing approved translation in queue failed.\n" );
			exit( 1 );
		}
		echo "ACTION_SCHEDULER_QUEUE_DEDUP_CANCEL_AND_APPROVED_PROTECTION_VERIFIED=true\n";

		// 6) Subscription expired graceful degradation (RB-05): blocks new queue tasks while storefront keeps serving approved translations.
		\LocalePilot\Admin\SubscriptionStateManager::update_state( array( "status" => "expired" ) );
		$exp_enq = $queue_mgr->enqueue_job(
			array(
				"provider_id"   => "deepl",
				"content_type"  => "product_title",
				"object_id"     => 999,
				"field_key"     => "post_title",
				"source_locale" => "en_US",
				"target_locale" => "fr_FR",
				"source_text"   => "Blocked When Expired",
			)
		);
		$_COOKIE["lp_lang"] = "fr_FR";
		$prod_when_expired  = wc_get_product( $product_id );
		\LocalePilot\Admin\SubscriptionStateManager::update_state( array( "status" => "active" ) );
		if (
			$exp_enq["ok"]
			|| "subscription_expired" !== $exp_enq["code"]
			|| "Veste de randonnée imperméable" !== $prod_when_expired->get_name()
		) {
			fwrite( STDERR, "ERROR: Subscription expired progressive degradation check failed.\n" );
			exit( 1 );
		}
		echo "SUBSCRIPTION_EXPIRED_PROGRESSIVE_DEGRADATION_VERIFIED=true\n";

		// 7) Cache Health Check & Uncertified External Cache Matrix verification.
		$health_report = $admin_page->handle_cache_health_check_submission(
			array(
				"localepilot_action"                                 => \LocalePilot\Admin\AdminPage::FORM_ACTION_RUN_CACHE_HEALTH_CHECK,
				"canonical_url"                                      => "/product/waterproof-hiking-jacket/",
				"locale_a"                                           => "en_US",
				"locale_b"                                           => "fr_FR",
				\LocalePilot\Admin\ProviderSettingsManager::NONCE_NAME => $q_nonce,
			)
		);
		$admin_health_html = $admin_page->get_page_html( $admin_u1 );
		if (
			null === $health_report
			|| ! $health_report["overall_pass"]
			|| $health_report["cross_contamination_detected"]
			|| "lp_lang" !== $health_report["cookie_name"]
			|| ! $health_report["wp_rocket_cookie_registered"]
			|| "uncertified" !== ( $health_report["integrations"]["wp_rocket"]["certification_status"] ?? "" )
			|| "uncertified" !== ( $health_report["integrations"]["litespeed_cache"]["certification_status"] ?? "" )
			|| "uncertified" !== ( $health_report["integrations"]["cloudflare_apo_edge"]["certification_status"] ?? "" )
			|| false === strpos( $admin_health_html, "data-lp-cert-status=\"uncertified\"" )
		) {
			fwrite( STDERR, "ERROR: Cache Health Check or Uncertified cache matrix verification failed.\n" );
			exit( 1 );
		}
		echo "CACHE_HEALTH_CHECK_AND_UNCERTIFIED_MATRIX_VERIFIED=true\n";

		deactivate_plugins( "localepilot/localepilot.php" );
		if ( is_plugin_active( "localepilot/localepilot.php" ) ) {
			fwrite( STDERR, "ERROR: Failed to deactivate localepilot/localepilot.php.\n" );
			exit( 1 );
		}
		echo "PLUGIN_DEACTIVATED=true\n";

		activate_plugin( "localepilot/localepilot.php" );
		if ( ! is_plugin_active( "localepilot/localepilot.php" ) ) {
			fwrite( STDERR, "ERROR: Failed to re-activate localepilot/localepilot.php.\n" );
			exit( 1 );
		}
		echo "PLUGIN_REACTIVATED=true\n";
	';
	$boot_proc_cmd = sprintf( '%s -r %s', escapeshellarg( PHP_BINARY ), escapeshellarg( $boot_check_code ) );
	if ( 0 !== lp_run_cmd( $boot_proc_cmd ) ) {
		fwrite( STDERR, "ERROR: Boot / HPOS / deactivation check failed.\n" );
		exit( 1 );
	}

	echo "SUCCESS: Isolated WordPress + WooCommerce + HPOS integration verification passed.\n";
} finally {
	if ( $started_by_script && file_exists( $db_socket ) ) {
		echo "Stopping isolated MariaDB instance started by this script ...\n";
		$stop_cmd = sprintf( 'mariadb-admin --socket=%s -u root shutdown > /dev/null 2>&1', escapeshellarg( $db_socket ) );
		exec( $stop_cmd );
		for ( $w = 0; $w < 10; $w++ ) {
			if ( ! file_exists( $db_socket ) ) {
				break;
			}
			usleep( 300000 );
		}
	}
}

<?php
/**
 * Uninstall handler for LocalePilot.
 *
 * By product design (see PRODUCT-PLAN.md), uninstalling or deactivating the
 * plugin preserves merchant translation records and store data by default.
 *
 * @package LocalePilot
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

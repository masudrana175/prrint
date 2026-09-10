<?php
/**
 * Prrint uninstall: remove options. Customer photos and order print files in
 * wp-content/uploads/prrint/ are intentionally KEPT — they belong to orders.
 *
 * @package prrint
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'prrint_settings' );
delete_option( 'prrint_sample_product' );

wp_clear_scheduled_hook( 'prrint_daily_cleanup' );

<?php
/**
 * Removes everything SiteWatch Connector stored on this site.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

foreach (array('sitewatch_connector', 'sitewatch_connector_state', 'sitewatch_connector_queue', 'sitewatch_connector_logins', 'sitewatch_connector_errors', 'sitewatch_connector_files') as $option) {
    delete_option($option);
}
delete_metadata('user', 0, 'sitewatch_last_login', '', true);
wp_clear_scheduled_hook('sitewatch_connector_heartbeat');

if (defined('WPMU_PLUGIN_DIR') && is_file(WPMU_PLUGIN_DIR . '/sitewatch-connector-loader.php')) {
    @unlink(WPMU_PLUGIN_DIR . '/sitewatch-connector-loader.php');
}

$dir = WP_CONTENT_DIR . '/sitewatch-connector';
if (is_dir($dir)) {
    foreach (array('errors.json', 'index.php', '.htaccess') as $file) {
        if (is_file($dir . '/' . $file)) {
            @unlink($dir . '/' . $file);
        }
    }
    @rmdir($dir);
}

<?php
/**
 * Plugin Name: SiteWatch Connector (early error capture)
 * Description: Starts SiteWatch Connector's fatal error capture before other plugins load. Added and removed automatically by the SiteWatch Connector plugin; delete it only if you have removed that plugin.
 * Version:     1.3.0
 * Author:      SiteWatch
 */

defined('ABSPATH') || exit;

call_user_func(function () {
    $dir = WP_PLUGIN_DIR . '/sitewatch-connector';
    $basename = 'sitewatch-connector/sitewatch-connector.php';
    if (!is_readable($dir . '/includes/class-sitewatch-connector-errors.php')) {
        return;
    }
    // Only while the plugin itself is active (a deactivated plugin must not keep reporting).
    $active = in_array($basename, (array) get_option('active_plugins', array()), true);
    if (!$active && is_multisite()) {
        $active = array_key_exists($basename, (array) get_site_option('active_sitewide_plugins', array()));
    }
    if (!$active) {
        return;
    }
    require_once $dir . '/includes/class-sitewatch-connector-client.php';
    require_once $dir . '/includes/class-sitewatch-connector-errors.php';
    SiteWatch_Connector_Errors::init();
    if (is_readable($dir . '/includes/class-sitewatch-connector-pulse.php')) {
        require_once $dir . '/includes/class-sitewatch-connector-pulse.php';
        SiteWatch_Connector_Pulse::init();
    }
    // PHP warnings must be counted from the start; page timing is sampled at the end of the request.
    if (is_readable($dir . '/includes/class-sitewatch-connector-insights.php')) {
        require_once $dir . '/includes/class-sitewatch-connector-insights.php';
        SiteWatch_Connector_Insights::init();
    }
});

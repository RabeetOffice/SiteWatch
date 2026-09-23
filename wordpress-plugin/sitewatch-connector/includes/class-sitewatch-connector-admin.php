<?php
/**
 * Settings → SiteWatch: connect with a key, see the connection status and what is being reported.
 * Only administrators (manage_options) can see or change anything here.
 */

defined('ABSPATH') || exit;

final class SiteWatch_Connector_Admin
{
    const PAGE = 'sitewatch-connector';

    public static function init()
    {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_sitewatch_connector_connect', array(__CLASS__, 'handle_connect'));
        add_action('admin_post_sitewatch_connector_send', array(__CLASS__, 'handle_send'));
        add_action('admin_post_sitewatch_connector_disconnect', array(__CLASS__, 'handle_disconnect'));
        add_action('admin_post_sitewatch_connector_clear_errors', array(__CLASS__, 'handle_clear_errors'));
        add_action('admin_notices', array(__CLASS__, 'notice'));
        add_filter('plugin_action_links_' . SITEWATCH_CONNECTOR_BASENAME, array(__CLASS__, 'action_links'));
    }

    public static function url(array $args = array())
    {
        return add_query_arg(array_merge(array('page' => self::PAGE), $args), admin_url('options-general.php'));
    }

    public static function menu()
    {
        add_options_page('SiteWatch Connector', 'SiteWatch', 'manage_options', self::PAGE, array(__CLASS__, 'render'));
    }

    public static function action_links($links)
    {
        array_unshift($links, '<a href="' . esc_url(self::url()) . '">' . esc_html__('Settings', 'sitewatch-connector') . '</a>');
        return $links;
    }

    /** Remind administrators to finish the setup. */
    public static function notice()
    {
        if (!current_user_can('manage_options') || SiteWatch_Connector_Client::config() !== null) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && in_array($screen->id, array('settings_page_' . self::PAGE), true)) {
            return;
        }
        echo '<div class="notice notice-info"><p><strong>SiteWatch Connector</strong> is installed but not connected yet. '
            . '<a href="' . esc_url(self::url()) . '">Paste your connection key</a> to start reporting to SiteWatch.</p></div>';
    }

    private static function check_request($action)
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'sitewatch-connector'), 403);
        }
        check_admin_referer($action);
    }

    private static function back($type, $message)
    {
        set_transient('sitewatch_connector_notice_' . get_current_user_id(), array('type' => $type, 'message' => $message), 60);
        wp_safe_redirect(self::url());
        exit;
    }

    public static function handle_connect()
    {
        self::check_request('sitewatch_connector_connect');
        $key = isset($_POST['connection_key']) ? sanitize_text_field(wp_unslash($_POST['connection_key'])) : '';
        $config = SiteWatch_Connector_Client::parse_key($key);
        if (is_wp_error($config)) {
            self::back('error', $config->get_error_message());
        }

        // Prove the key works before saving it: SiteWatch verifies the signature and answers.
        $result = SiteWatch_Connector_Client::send('hello', array('snapshot' => SiteWatch_Connector_Health::snapshot()), 30, $config);
        if (!$result['ok']) {
            self::back('error', 'SiteWatch did not accept the connection: ' . $result['error']);
        }

        $config['connected_at'] = time();
        update_option(SiteWatch_Connector_Client::OPTION, $config, false);
        SiteWatch_Connector_Client::update_state(array('last_snapshot' => time(), 'want_snapshot' => false, 'last_ok' => time(), 'last_error' => ''));
        SiteWatch_Connector_Errors::ensure_dir();
        SiteWatch_Connector::install_loader();
        wp_clear_scheduled_hook(SiteWatch_Connector::CRON_HOOK);
        wp_schedule_event(time() + 300, SiteWatch_Connector::CRON_SCHEDULE, SiteWatch_Connector::CRON_HOOK);

        $name = !empty($result['data']['website']) ? ' as "' . $result['data']['website'] . '"' : '';
        self::back('success', 'Connected to SiteWatch' . $name . '. This site now reports every 5 minutes.');
    }

    public static function handle_send()
    {
        self::check_request('sitewatch_connector_send');
        $result = SiteWatch_Connector::heartbeat(true);
        if ($result['ok']) {
            self::back('success', 'Report sent to SiteWatch, including a fresh health snapshot.');
        }
        self::back('error', 'The report was not accepted: ' . $result['error']);
    }

    public static function handle_disconnect()
    {
        self::check_request('sitewatch_connector_disconnect');
        SiteWatch_Connector_Client::send('disconnect', array(), 8);
        delete_option(SiteWatch_Connector_Client::OPTION);
        delete_option(SiteWatch_Connector_Client::STATE_OPTION);
        delete_option(SiteWatch_Connector_Activity::QUEUE_OPTION);
        wp_clear_scheduled_hook(SiteWatch_Connector::CRON_HOOK);
        self::back('success', 'Disconnected. Nothing is sent to SiteWatch until you connect again.');
    }

    public static function handle_clear_errors()
    {
        self::check_request('sitewatch_connector_clear_errors');
        SiteWatch_Connector_Errors::clear();
        self::back('success', 'The local error list was cleared.');
    }

    private static function time_ago($timestamp)
    {
        return $timestamp ? sprintf('%s ago', human_time_diff((int) $timestamp, time())) : 'never';
    }

    public static function render()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $config = SiteWatch_Connector_Client::config();
        $state = SiteWatch_Connector_Client::state();
        $notice = get_transient('sitewatch_connector_notice_' . get_current_user_id());
        if ($notice) {
            delete_transient('sitewatch_connector_notice_' . get_current_user_id());
        }
        $errors = SiteWatch_Connector_Errors::recent();
        $queued = count(SiteWatch_Connector_Activity::queued(SiteWatch_Connector_Activity::MAX_QUEUE));
        $next = wp_next_scheduled(SiteWatch_Connector::CRON_HOOK);
        ?>
        <div class="wrap">
            <h1>SiteWatch Connector</h1>
            <?php if ($notice): ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible"><p><?php echo esc_html($notice['message']); ?></p></div>
            <?php endif; ?>

            <?php if ($config === null): ?>
                <div class="card" style="max-width:720px">
                    <h2>Connect this site</h2>
                    <ol>
                        <li>In SiteWatch, open this website and go to the <strong>WordPress</strong> section.</li>
                        <li>Click <strong>Create connection key</strong> and copy the key.</li>
                        <li>Paste it below and click <strong>Connect</strong>.</li>
                    </ol>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="sitewatch_connector_connect">
                        <?php wp_nonce_field('sitewatch_connector_connect'); ?>
                        <p><label for="sw-key"><strong>Connection key</strong></label></p>
                        <textarea id="sw-key" name="connection_key" rows="4" class="large-text code" required placeholder="swc1_…" autocomplete="off" spellcheck="false"></textarea>
                        <p><?php submit_button('Connect', 'primary', 'submit', false); ?></p>
                    </form>
                </div>
            <?php else: ?>
                <table class="widefat striped" style="max-width:720px;margin-top:16px">
                    <tbody>
                        <tr><th style="width:200px">Status</th><td>
                            <?php if (!empty($state['last_error'])): ?>
                                <span style="color:#b32d2e">&#9679;</span> Last report failed: <?php echo esc_html($state['last_error']); ?>
                            <?php else: ?>
                                <span style="color:#008a20">&#9679;</span> Connected
                            <?php endif; ?>
                        </td></tr>
                        <tr><th>SiteWatch</th><td><a href="<?php echo esc_url($config['sitewatch_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($config['sitewatch_url']); ?></a> · website #<?php echo (int) $config['site_id']; ?></td></tr>
                        <tr><th>Connected</th><td><?php echo esc_html(self::time_ago(isset($config['connected_at']) ? $config['connected_at'] : 0)); ?></td></tr>
                        <tr><th>Last successful report</th><td><?php echo esc_html(self::time_ago(isset($state['last_ok']) ? $state['last_ok'] : 0)); ?></td></tr>
                        <tr><th>Last health snapshot</th><td><?php echo esc_html(self::time_ago(isset($state['last_snapshot']) ? $state['last_snapshot'] : 0)); ?></td></tr>
                        <tr><th>Next report</th><td><?php echo $next ? esc_html(sprintf('in %s', human_time_diff(time(), $next))) : 'not scheduled'; ?>
                            <?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON): ?><br><em>WP-Cron is disabled on this site; reports go out when your server cron calls wp-cron.php.</em><?php endif; ?></td></tr>
                        <tr><th>Waiting to send</th><td><?php echo (int) $queued; ?> activity event(s)</td></tr>
                        <tr><th>Early error capture</th><td><?php echo SiteWatch_Connector::loader_installed()
                            ? 'Active (must-use loader installed)'
                            : 'Limited: the loader could not be written to ' . esc_html(defined('WPMU_PLUGIN_DIR') ? str_replace(ABSPATH, '', WPMU_PLUGIN_DIR) : 'wp-content/mu-plugins') . '. Errors in plugins that load before this one may be missed.'; ?></td></tr>
                    </tbody>
                </table>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin:16px 0">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                        <input type="hidden" name="action" value="sitewatch_connector_send">
                        <?php wp_nonce_field('sitewatch_connector_send'); ?>
                        <?php submit_button('Send report now', 'primary', 'submit', false); ?>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline" onsubmit="return confirm('Disconnect this site from SiteWatch?');">
                        <input type="hidden" name="action" value="sitewatch_connector_disconnect">
                        <?php wp_nonce_field('sitewatch_connector_disconnect'); ?>
                        <?php submit_button('Disconnect', 'secondary', 'submit', false); ?>
                    </form>
                </div>
            <?php endif; ?>

            <h2 style="margin-top:32px">Captured errors</h2>
            <p>Fatal errors are recorded here and sent to SiteWatch. Visitors still see only the standard WordPress error page.</p>
            <?php if ($errors === array()): ?>
                <p><em>No fatal errors recorded.</em></p>
            <?php else: ?>
                <table class="widefat striped" style="max-width:1000px">
                    <thead><tr><th>Error</th><th>Source</th><th>Times</th><th>Last seen</th></tr></thead>
                    <tbody>
                    <?php foreach ($errors as $entry): $d = $entry['event']['data']; ?>
                        <tr>
                            <td><strong><?php echo esc_html($d['error_type']); ?>:</strong> <?php echo esc_html($d['message']); ?><br><code><?php echo esc_html($d['file'] . ':' . $d['line']); ?></code></td>
                            <td><?php echo esc_html($d['component']['name'] !== '' ? $d['component']['name'] . ($d['component']['version'] !== '' ? ' ' . $d['component']['version'] : '') : '—'); ?></td>
                            <td><?php echo (int) $entry['count']; ?></td>
                            <td><?php echo esc_html(self::time_ago($entry['last_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="sitewatch_connector_clear_errors">
                    <?php wp_nonce_field('sitewatch_connector_clear_errors'); ?>
                    <?php submit_button('Clear list', 'secondary small', 'submit', false); ?>
                </form>
            <?php endif; ?>

            <h2 style="margin-top:32px">What is sent to SiteWatch</h2>
            <ul style="list-style:disc;padding-left:20px;max-width:720px">
                <li>Fatal PHP errors: message, file, line and the plugin or theme involved.</li>
                <li>A daily health report: WordPress, PHP and database versions, pending updates, installed plugins and themes, scheduled tasks and security checks.</li>
                <li>Changes: plugins and themes installed, updated or switched, WordPress updates, administrator sign-ins, new administrators and changes to the site address or registration settings.</li>
                <li>The number of failed sign-ins and the IP addresses they came from.</li>
            </ul>
            <p>Never sent: passwords, content, orders, customer or visitor data.</p>
        </div>
        <?php
    }
}

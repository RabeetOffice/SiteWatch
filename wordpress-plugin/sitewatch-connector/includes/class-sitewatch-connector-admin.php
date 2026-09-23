<?php
/**
 * The SiteWatch page (its own entry in the admin menu): connect with a key, see the connection status, choose remote
 * actions and auto-fix, and see what is reported. Only administrators (manage_options) can see or change anything.
 * Styles: assets/admin.css, loaded on this page only.
 */

defined('ABSPATH') || exit;

final class SiteWatch_Connector_Admin
{
    const PAGE = 'sitewatch-connector';
    /** Dashicons for the remote actions list. */
    const ACTION_ICONS = array(
        'clear_cache' => 'performance', 'deactivate_plugin' => 'dismiss', 'activate_plugin' => 'yes-alt', 'update_plugins' => 'update',
        'maintenance' => 'hammer', 'rollback_plugin' => 'backup', 'update_themes' => 'admin-appearance', 'update_core' => 'wordpress',
        'backup' => 'cloud-upload',
    );

    public static function init()
    {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_init', array(__CLASS__, 'redirect_old_url'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('admin_post_sitewatch_connector_connect', array(__CLASS__, 'handle_connect'));
        add_action('admin_post_sitewatch_connector_send', array(__CLASS__, 'handle_send'));
        add_action('admin_post_sitewatch_connector_disconnect', array(__CLASS__, 'handle_disconnect'));
        add_action('admin_post_sitewatch_connector_clear_errors', array(__CLASS__, 'handle_clear_errors'));
        add_action('admin_post_sitewatch_connector_remote', array(__CLASS__, 'handle_remote'));
        add_action('admin_post_sitewatch_connector_maintenance_off', array(__CLASS__, 'handle_maintenance_off'));
        add_action('admin_post_sitewatch_connector_autofix', array(__CLASS__, 'handle_autofix'));
        add_action('admin_notices', array(__CLASS__, 'notice'));
        add_filter('plugin_action_links_' . SITEWATCH_CONNECTOR_BASENAME, array(__CLASS__, 'action_links'));
    }

    public static function url(array $args = array())
    {
        return add_query_arg(array_merge(array('page' => self::PAGE), $args), admin_url('admin.php'));
    }

    /** Its own top-level menu entry with the SiteWatch mark, right below Dashboard. */
    public static function menu()
    {
        add_menu_page('SiteWatch Connector', 'SiteWatch', 'manage_options', self::PAGE, array(__CLASS__, 'render'), self::menu_icon(), 3);
    }

    /**
     * The SiteWatch mark for the admin menu, drawn with filled shapes only: WordPress repaints the `fill` attributes of
     * menu icons in its own colours (grey, white when selected, following the admin colour scheme); strokes and shapes
     * without a fill attribute would not be repainted.
     */
    private static function menu_icon()
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48">'
            . '<path fill="#a7aaad" d="M43.08 18.89A19.75 19.75 0 1 1 29.11 4.92A2.75 2.75 0 0 1 27.69 10.24A14.25 14.25 0 1 0 37.76 20.31A2.75 2.75 0 0 1 43.08 18.89Z"/>'
            . '<circle fill="#a7aaad" cx="36.8" cy="11.2" r="5.2"/>'
            . '<path fill="#a7aaad" d="M10 30L17.5 30L17.5 25L10 25Z"/><path fill="#a7aaad" d="M19.83 28.41L24.33 16.91L19.67 15.09L15.17 26.59Z"/>'
            . '<path fill="#a7aaad" d="M19.59 16.65L24.59 35.15L29.41 33.85L24.41 15.35Z"/><path fill="#a7aaad" d="M29.24 35.62L32.74 28.62L28.26 26.38L24.76 33.38Z"/>'
            . '<path fill="#a7aaad" d="M30.5 30L38 30L38 25L30.5 25Z"/>'
            . '<circle fill="#a7aaad" cx="17.5" cy="27.5" r="2.5"/><circle fill="#a7aaad" cx="22" cy="16" r="2.5"/><circle fill="#a7aaad" cx="27" cy="34.5" r="2.5"/><circle fill="#a7aaad" cx="30.5" cy="27.5" r="2.5"/></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /** The page used to live under Settings; send old links and bookmarks to the new place. */
    public static function redirect_old_url()
    {
        global $pagenow;
        if ($pagenow === 'options-general.php' && isset($_GET['page']) && $_GET['page'] === self::PAGE && current_user_can('manage_options')) {
            wp_safe_redirect(self::url());
            exit;
        }
    }

    public static function assets($hook)
    {
        if ($hook !== 'toplevel_page_' . self::PAGE) {
            return;
        }
        $file = SITEWATCH_CONNECTOR_DIR . '/assets/admin.css';
        wp_enqueue_style('sitewatch-connector-admin', plugins_url('assets/admin.css', SITEWATCH_CONNECTOR_FILE), array(), SITEWATCH_CONNECTOR_VERSION . '.' . (int) @filemtime($file));
    }

    public static function action_links($links)
    {
        array_unshift($links, '<a href="' . esc_url(self::url()) . '">' . esc_html__('Settings', 'sitewatch-connector') . '</a>');
        return $links;
    }

    /** Remind administrators to finish the setup, and show when the SiteWatch maintenance page is on. */
    public static function notice()
    {
        $until = SiteWatch_Connector_Remote::maintenance_until();
        if ($until !== null && current_user_can('manage_options')) {
            echo '<div class="notice notice-warning"><p><strong>Maintenance page on</strong> (switched on from SiteWatch): visitors see "Briefly unavailable for scheduled maintenance" until '
                . esc_html(wp_date(get_option('time_format'), $until)) . '. Signed-in editors see the site normally. '
                . '<a href="' . esc_url(self::url()) . '#sitewatch-maintenance">End it now</a></p></div>';
        }
        if (!current_user_can('manage_options') || SiteWatch_Connector_Client::config() !== null) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->id === 'toplevel_page_' . self::PAGE) {
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

    public static function handle_remote()
    {
        self::check_request('sitewatch_connector_remote');
        $actions = isset($_POST['remote_actions']) && is_array($_POST['remote_actions']) ? array_map('sanitize_key', wp_unslash($_POST['remote_actions'])) : array();
        $enabled = !empty($_POST['remote_enabled']);
        SiteWatch_Connector_Remote::save_settings($enabled, $actions);
        // Tell SiteWatch straight away which actions it may offer.
        SiteWatch_Connector_Client::send('heartbeat', array(), 10);
        self::back('success', $enabled && $actions !== array() ? 'Remote actions saved. SiteWatch can now request: ' . implode(', ', array_intersect_key(SiteWatch_Connector_Remote::ACTIONS, array_flip(SiteWatch_Connector_Remote::settings()['actions']))) . '.' : 'Remote actions are off. SiteWatch cannot change anything on this site.');
    }

    public static function handle_autofix()
    {
        self::check_request('sitewatch_connector_autofix');
        $protected = isset($_POST['autofix_protected']) && is_array($_POST['autofix_protected']) ? array_map('sanitize_text_field', wp_unslash($_POST['autofix_protected'])) : array();
        $enabled = !empty($_POST['autofix_enabled']);
        SiteWatch_Connector_Autofix::save_settings($enabled, $protected);
        SiteWatch_Connector_Client::send('heartbeat', array(), 10);
        self::back('success', $enabled ? 'Auto-fix is on. A plugin that crashes the site 3 times in 10 minutes is deactivated and SiteWatch alerts you.' : 'Auto-fix is off.');
    }

    public static function handle_maintenance_off()
    {
        self::check_request('sitewatch_connector_maintenance_off');
        delete_option(SiteWatch_Connector_Remote::MAINTENANCE_OPTION);
        SiteWatch_Connector_Client::send('heartbeat', array(), 10);
        self::back('success', 'The maintenance page is off.');
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
        $failing = !empty($state['last_error']);
        $post = esc_url(admin_url('admin-post.php'));
        ?>
        <div class="wrap swc">
            <h1 class="screen-reader-text">SiteWatch Connector</h1>

            <header class="swc-header">
                <div class="swc-brand">
                    <span class="swc-logo" aria-hidden="true"><?php echo self::logo(); // static SVG ?></span>
                    <div>
                        <div class="swc-title">SiteWatch Connector</div>
                        <div class="swc-subtitle">Monitoring from inside WordPress · version <?php echo esc_html(SITEWATCH_CONNECTOR_VERSION); ?></div>
                    </div>
                </div>
                <div class="swc-header-actions">
                    <?php if ($config === null): ?>
                        <span class="swc-pill swc-pill-neutral"><span class="swc-dot"></span> Not connected</span>
                    <?php elseif ($failing): ?>
                        <span class="swc-pill swc-pill-danger"><span class="swc-dot"></span> Report failing</span>
                    <?php else: ?>
                        <span class="swc-pill swc-pill-success"><span class="swc-dot"></span> Connected</span>
                    <?php endif; ?>
                    <?php if ($config !== null): ?>
                        <a class="swc-btn swc-btn-light" href="<?php echo esc_url($config['sitewatch_url'] . '/admin/website-details.php?id=' . (int) $config['site_id']); ?>" target="_blank" rel="noopener">
                            <span class="dashicons dashicons-external" aria-hidden="true"></span> Open SiteWatch</a>
                        <form method="post" action="<?php echo $post; ?>">
                            <input type="hidden" name="action" value="sitewatch_connector_send">
                            <?php wp_nonce_field('sitewatch_connector_send'); ?>
                            <button type="submit" class="swc-btn swc-btn-primary"><span class="dashicons dashicons-update" aria-hidden="true"></span> Send report now</button>
                        </form>
                    <?php endif; ?>
                </div>
            </header>

            <?php if ($notice): ?>
                <div class="swc-alert swc-alert-<?php echo $notice['type'] === 'error' ? 'danger' : 'success'; ?>" role="status">
                    <span class="dashicons dashicons-<?php echo $notice['type'] === 'error' ? 'warning' : 'yes-alt'; ?>" aria-hidden="true"></span>
                    <div><?php echo esc_html($notice['message']); ?></div>
                </div>
            <?php endif; ?>

            <?php if ($config === null): ?>
                <section class="swc-card swc-connect">
                    <span class="swc-connect-logo" aria-hidden="true"><?php echo self::logo(); ?></span>
                    <h2>Connect this site to SiteWatch</h2>
                    <p class="swc-muted">See why errors happen, not just that they happened: fatal errors with their cause, a daily health and security report, and a change log.</p>
                    <ol class="swc-steps">
                        <li><span>1</span><div>In SiteWatch, open this website and go to the <strong>WordPress</strong> section.</div></li>
                        <li><span>2</span><div>Click <strong>Create connection key</strong> and copy the key.</div></li>
                        <li><span>3</span><div>Paste it below and click <strong>Connect</strong>.</div></li>
                    </ol>
                    <form method="post" action="<?php echo $post; ?>">
                        <input type="hidden" name="action" value="sitewatch_connector_connect">
                        <?php wp_nonce_field('sitewatch_connector_connect'); ?>
                        <label class="swc-label" for="sw-key">Connection key</label>
                        <textarea id="sw-key" name="connection_key" rows="3" class="swc-input swc-mono" required placeholder="swc1_…" autocomplete="off" spellcheck="false"></textarea>
                        <button type="submit" class="swc-btn swc-btn-primary swc-btn-lg"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span> Connect</button>
                    </form>
                </section>
            <?php else:
                $remote = SiteWatch_Connector_Remote::settings();
                $recent = SiteWatch_Connector_Remote::recent(10);
                $until = SiteWatch_Connector_Remote::maintenance_until();
                $autofix = SiteWatch_Connector_Autofix::settings();
                $fixed = SiteWatch_Connector_Autofix::recent();
                if (!function_exists('get_plugins')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                $all_plugins = get_plugins();
                $loader = SiteWatch_Connector::loader_installed();
                ?>

                <?php if ($failing): ?>
                    <div class="swc-alert swc-alert-danger"><span class="dashicons dashicons-warning" aria-hidden="true"></span>
                        <div><strong>The last report failed:</strong> <?php echo esc_html($state['last_error']); ?></div></div>
                <?php endif; ?>
                <?php if ($until !== null): ?>
                    <div class="swc-alert swc-alert-warning" id="sitewatch-maintenance">
                        <span class="dashicons dashicons-hammer" aria-hidden="true"></span>
                        <div><strong>Maintenance page on</strong> until <?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $until)); ?>. Visitors see a maintenance message; signed-in editors see the site.</div>
                        <form method="post" action="<?php echo $post; ?>">
                            <input type="hidden" name="action" value="sitewatch_connector_maintenance_off">
                            <?php wp_nonce_field('sitewatch_connector_maintenance_off'); ?>
                            <button type="submit" class="swc-btn swc-btn-light swc-btn-sm">End maintenance now</button>
                        </form>
                    </div>
                <?php endif; ?>

                <div class="swc-tiles">
                    <?php
                    self::tile('clock', 'Last report', self::time_ago(isset($state['last_ok']) ? $state['last_ok'] : 0), $failing ? 'danger' : 'success');
                    self::tile('calendar-alt', 'Next report', $next ? sprintf('in %s', human_time_diff(time(), $next)) : 'Not scheduled', $next ? '' : 'warning',
                        defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'WP-Cron is off: your server cron sends the reports.' : 'Every 5 minutes through WP-Cron.');
                    self::tile('heart', 'Health report', self::time_ago(isset($state['last_snapshot']) ? $state['last_snapshot'] : 0), '', 'Sent daily, or when SiteWatch asks.');
                    self::tile('shield', 'Early error capture', $loader ? 'Active' : 'Limited', $loader ? 'success' : 'warning',
                        $loader ? 'Must-use loader installed.' : 'The loader could not be written to wp-content/mu-plugins.');
                    ?>
                </div>

                <div class="swc-grid">
                    <div class="swc-main">

                        <section class="swc-card" id="sitewatch-remote">
                            <form method="post" action="<?php echo $post; ?>" data-swc-group>
                                <input type="hidden" name="action" value="sitewatch_connector_remote">
                                <?php wp_nonce_field('sitewatch_connector_remote'); ?>
                                <div class="swc-card-head">
                                    <div>
                                        <h2><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span> Remote actions</h2>
                                        <p class="swc-muted">Let SiteWatch users ask this site to do the things you switch on. Nothing else can be requested: no code, file or user changes.
                                            Requests are checked against this site's secret and arrive with the next report.</p>
                                    </div>
                                    <label class="swc-switch"><input type="checkbox" name="remote_enabled" value="1" data-swc-master<?php checked($remote['enabled']); ?>><span></span><b>Allow</b></label>
                                </div>
                                <ul class="swc-options" data-swc-options>
                                    <?php foreach (SiteWatch_Connector_Remote::ACTIONS as $key => $label): ?>
                                        <li>
                                            <span class="swc-option-icon dashicons dashicons-<?php echo esc_attr(array_key_exists($key, self::ACTION_ICONS) ? self::ACTION_ICONS[$key] : 'admin-tools'); ?>" aria-hidden="true"></span>
                                            <span class="swc-option-text"><?php echo esc_html($label); ?></span>
                                            <label class="swc-switch swc-switch-sm"><input type="checkbox" name="remote_actions[]" value="<?php echo esc_attr($key); ?>"<?php checked(in_array($key, $remote['actions'], true)); ?>><span></span><span class="screen-reader-text"><?php echo esc_html($label); ?></span></label>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <div class="swc-card-foot"><button type="submit" class="swc-btn swc-btn-primary">Save remote actions</button></div>
                            </form>
                            <?php if ($recent !== array()): ?>
                                <h3 class="swc-subhead">Recent requests</h3>
                                <ul class="swc-list">
                                    <?php foreach ($recent as $id => $r): $ok = !empty($r['ok']); ?>
                                        <li>
                                            <span class="swc-pill swc-pill-<?php echo $ok ? 'success' : 'danger'; ?>"><?php echo $ok ? 'Done' : 'Failed'; ?></span>
                                            <div class="swc-list-body">
                                                <div><?php echo esc_html(isset($r['message']) ? $r['message'] : ''); ?></div>
                                                <div class="swc-muted swc-small"><?php echo esc_html(self::action_label(isset($r['action']) ? $r['action'] : '')); ?> · #<?php echo (int) $id; ?> · <?php echo esc_html(self::time_ago(isset($r['at']) ? $r['at'] : 0)); ?></div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </section>

                        <section class="swc-card" id="sitewatch-autofix">
                            <form method="post" action="<?php echo $post; ?>" data-swc-group>
                                <input type="hidden" name="action" value="sitewatch_connector_autofix">
                                <?php wp_nonce_field('sitewatch_connector_autofix'); ?>
                                <div class="swc-card-head">
                                    <div>
                                        <h2><span class="dashicons dashicons-sos" aria-hidden="true"></span> Auto-fix</h2>
                                        <p class="swc-muted">When the same fatal error from one plugin happens 3 times within 10 minutes, switch that plugin off so visitors get a working site,
                                            and alert SiteWatch. At most once a day per plugin; its settings and data are kept.</p>
                                    </div>
                                    <label class="swc-switch"><input type="checkbox" name="autofix_enabled" value="1" data-swc-master<?php checked($autofix['enabled']); ?>><span></span><b>On</b></label>
                                </div>
                                <div data-swc-options>
                                    <div class="swc-label">Never switch off automatically</div>
                                    <div class="swc-chips">
                                        <?php foreach ($all_plugins as $file => $p): if ($file === SITEWATCH_CONNECTOR_BASENAME || !is_plugin_active($file)) { continue; } ?>
                                            <label class="swc-chip"><input type="checkbox" name="autofix_protected[]" value="<?php echo esc_attr($file); ?>"<?php checked(in_array($file, $autofix['protected'], true)); ?>><span><?php echo esc_html($p['Name']); ?></span></label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php if ($fixed !== array()): ?>
                                    <p class="swc-small swc-muted"><strong>Switched off in the last 7 days:</strong>
                                        <?php echo esc_html(implode(', ', array_map(function ($file) use ($fixed, $all_plugins) {
                                            return (isset($all_plugins[$file]['Name']) ? $all_plugins[$file]['Name'] : $file) . ' (' . human_time_diff((int) $fixed[$file], time()) . ' ago)';
                                        }, array_keys($fixed)))); ?></p>
                                <?php endif; ?>
                                <div class="swc-card-foot"><button type="submit" class="swc-btn swc-btn-primary">Save auto-fix</button></div>
                            </form>
                        </section>

                        <section class="swc-card" id="sitewatch-errors">
                            <div class="swc-card-head">
                                <div>
                                    <h2><span class="dashicons dashicons-warning" aria-hidden="true"></span> Captured errors</h2>
                                    <p class="swc-muted">Fatal errors are recorded here and sent to SiteWatch. Visitors only see the standard WordPress error page.</p>
                                </div>
                                <?php if ($errors !== array()): ?>
                                    <form method="post" action="<?php echo $post; ?>">
                                        <input type="hidden" name="action" value="sitewatch_connector_clear_errors">
                                        <?php wp_nonce_field('sitewatch_connector_clear_errors'); ?>
                                        <button type="submit" class="swc-btn swc-btn-light swc-btn-sm">Clear list</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <?php if ($errors === array()): ?>
                                <div class="swc-empty"><span class="dashicons dashicons-smiley" aria-hidden="true"></span> No fatal errors recorded.</div>
                            <?php else: ?>
                                <div class="swc-errors">
                                    <?php foreach ($errors as $entry): $d = $entry['event']['data']; $c = $d['component']; ?>
                                        <article class="swc-error">
                                            <div class="swc-error-head">
                                                <strong><?php echo esc_html($c['name'] !== '' ? $c['name'] . ($c['version'] !== '' ? ' ' . $c['version'] : '') : 'Unknown source'); ?></strong>
                                                <span class="swc-pill swc-pill-danger"><?php echo esc_html($d['error_type']); ?></span>
                                                <?php if ((int) $entry['count'] > 1): ?><span class="swc-pill swc-pill-neutral"><?php echo (int) $entry['count']; ?>×</span><?php endif; ?>
                                                <span class="swc-muted swc-small swc-push"><?php echo esc_html(self::time_ago($entry['last_at'])); ?></span>
                                            </div>
                                            <pre class="swc-error-msg"><?php echo esc_html($d['message']); ?></pre>
                                            <div class="swc-muted swc-small swc-mono"><?php echo esc_html($d['file'] . ':' . $d['line']); ?></div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>
                    </div>

                    <aside class="swc-side">
                        <section class="swc-card">
                            <h2><span class="dashicons dashicons-admin-links" aria-hidden="true"></span> Connection</h2>
                            <dl class="swc-kv">
                                <dt>SiteWatch</dt><dd><a href="<?php echo esc_url($config['sitewatch_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html(preg_replace('#^https?://#', '', $config['sitewatch_url'])); ?></a></dd>
                                <dt>Website</dt><dd>#<?php echo (int) $config['site_id']; ?></dd>
                                <dt>Connected</dt><dd><?php echo esc_html(self::time_ago(isset($config['connected_at']) ? $config['connected_at'] : 0)); ?></dd>
                                <dt>Waiting to send</dt><dd><?php echo (int) $queued; ?> event(s)</dd>
                            </dl>
                            <form method="post" action="<?php echo $post; ?>" onsubmit="return confirm('Disconnect this site from SiteWatch? Nothing is reported until you connect again.');">
                                <input type="hidden" name="action" value="sitewatch_connector_disconnect">
                                <?php wp_nonce_field('sitewatch_connector_disconnect'); ?>
                                <button type="submit" class="swc-btn swc-btn-danger-outline swc-btn-block"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span> Disconnect</button>
                            </form>
                        </section>

                        <section class="swc-card">
                            <h2><span class="dashicons dashicons-privacy" aria-hidden="true"></span> What is sent</h2>
                            <ul class="swc-sent">
                                <li>Fatal PHP errors: message, file, line and the plugin or theme involved.</li>
                                <li>A daily health report: versions, pending updates, plugins and themes, scheduled tasks and security checks.</li>
                                <li>Changes: plugin, theme and WordPress updates, administrator sign-ins, new administrators, site address and registration settings.</li>
                                <li>Failed sign-in counts and the IP addresses they came from.</li>
                                <li>Whether wp-config.php or .htaccess changed: size, time and a short fingerprint, never the contents.</li>
                                <li>Page generation time, query count and memory of sampled requests; slow queries with all values replaced by "?" (only with SAVEQUERIES).</li>
                                <li>If switched on in SiteWatch: PHP warnings, notices and deprecations with file, line and a count.</li>
                                <li>Your remote action and auto-fix settings, their results, and the version each plugin had before its last update.</li>
                            </ul>
                            <p class="swc-never"><span class="dashicons dashicons-lock" aria-hidden="true"></span> Never sent: passwords, content, orders, customer or visitor data.</p>
                        </section>
                    </aside>
                </div>
            <?php endif; ?>
        </div>
        <script>
            // Dim the options of a section while its main switch is off (the saved values are kept).
            document.querySelectorAll('.swc [data-swc-group]').forEach(function (form) {
                var master = form.querySelector('[data-swc-master]');
                var options = form.querySelector('[data-swc-options]');
                if (!master || !options) return;
                var sync = function () { options.classList.toggle('swc-off', !master.checked); };
                master.addEventListener('change', sync);
                sync();
            });
        </script>
        <?php
    }

    /** One status tile. */
    private static function tile($icon, $label, $value, $tone = '', $help = '')
    {
        echo '<div class="swc-tile' . ($tone !== '' ? ' swc-tone-' . esc_attr($tone) : '') . '">'
            . '<span class="swc-tile-icon dashicons dashicons-' . esc_attr($icon) . '" aria-hidden="true"></span>'
            . '<div><div class="swc-tile-label">' . esc_html($label) . '</div><div class="swc-tile-value">' . esc_html($value) . '</div>'
            . ($help !== '' ? '<div class="swc-tile-help">' . esc_html($help) . '</div>' : '') . '</div></div>';
    }

    private static function action_label($action)
    {
        $labels = array(
            'clear_cache' => 'Clear caches', 'deactivate_plugin' => 'Deactivate plugin', 'activate_plugin' => 'Activate plugin',
            'update_plugins' => 'Update plugins', 'maintenance' => 'Maintenance page', 'rollback_plugin' => 'Roll back plugin',
            'update_themes' => 'Update themes', 'update_core' => 'Update WordPress', 'backup' => 'Backup',
        );
        return isset($labels[$action]) ? $labels[$action] : (string) $action;
    }

    /** The SiteWatch mark in its brand colour (inline SVG for the page header). */
    private static function logo()
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="40" height="40" focusable="false"><path d="M40.42 19.6A17 17 0 1 1 28.4 7.58" fill="none" stroke="#EA580C" stroke-width="5.5" stroke-linecap="round"/><circle cx="36.8" cy="11.2" r="5.2" fill="#EA580C"/><path d="M10 27.5H17.5L22 16L27 34.5L30.5 27.5H38" fill="none" stroke="#EA580C" stroke-width="5" stroke-linejoin="miter" stroke-miterlimit="2"/></svg>';
    }
}

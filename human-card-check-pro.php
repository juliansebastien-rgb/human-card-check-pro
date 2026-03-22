<?php
/**
 * Plugin Name: Human Card Check Pro
 * Plugin URI: https://github.com/juliansebastien-rgb/human-card-check
 * Description: Pro trust scoring addon for Human Card Check.
 * Version: 0.2.10
 * Author: Le Labo d'Azertaf
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: human-card-check-pro
 * Update URI: https://github.com/juliansebastien-rgb/human-card-check-pro
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Human_Card_Check_Pro {
    private const VERSION = '0.2.10';
    private const TRANSIENT_PREFIX = 'human_card_check_pro_';
    private const GITHUB_REPOSITORY = 'juliansebastien-rgb/human-card-check-pro';
    private const GITHUB_API_BASE = 'https://api.github.com/repos/juliansebastien-rgb/human-card-check-pro';
    private const GITHUB_REPOSITORY_URL = 'https://github.com/juliansebastien-rgb/human-card-check-pro';
    private const UPDATE_CACHE_TTL = HOUR_IN_SECONDS;
    private const DEFAULT_PAYMENT_LINK = 'https://buy.stripe.com/cNidR29Lz7OV8cN2Hj8k800';
    private const LOG_TABLE_SUFFIX = 'hcc_pro_logs';
    private const SERVICE_URL_OPTION = 'human_card_check_pro_service_url';
    private const MIN_SCORE_OPTION = 'human_card_check_pro_min_score';
    private const REVIEW_SCORE_OPTION = 'human_card_check_pro_review_score';
    private const REQUEST_TIMEOUT_OPTION = 'human_card_check_pro_timeout';
    private const LICENSE_TRANSIENT = 'human_card_check_pro_license_status';
    private const INSTALL_SYNC_TRANSIENT = 'human_card_check_pro_install_sync';
    private const INSTALL_SYNC_TTL = 6 * HOUR_IN_SECONDS;

    public function boot(): void {
        register_activation_hook(__FILE__, [$this, 'activate']);

        add_action('plugins_loaded', [$this, 'register_hooks']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'maybe_sync_installation_with_trust']);
        add_action('admin_menu', [$this, 'register_admin_pages']);
        add_action('admin_notices', [$this, 'maybe_show_dependency_notice']);
        add_action('update_option_human_card_check_pro_token', [$this, 'clear_license_status_cache'], 10, 3);
        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_github_update']);
        add_filter('plugins_api', [$this, 'filter_plugin_information'], 20, 3);
        add_filter('upgrader_source_selection', [$this, 'normalize_github_update_source'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'clear_update_cache'], 10, 2);
    }

    public function activate(): void {
        $this->create_log_table();
        $this->clear_license_status_cache();
        delete_transient(self::INSTALL_SYNC_TRANSIENT);
    }

    public function register_hooks(): void {
        if (!$this->is_free_plugin_active()) {
            return;
        }

        add_filter('human_card_check_pro_registration_decision', [$this, 'filter_registration_decision'], 10, 2);
    }

    public function clear_license_status_cache($old_value = null, $value = null, $option = null): void {
        delete_transient(self::LICENSE_TRANSIENT);
        delete_transient(self::INSTALL_SYNC_TRANSIENT);
    }

    public function maybe_sync_installation_with_trust(): void {
        if (!$this->is_free_plugin_active()) {
            return;
        }

        if (get_transient(self::INSTALL_SYNC_TRANSIENT)) {
            return;
        }

        if ($this->get_free_plugin_token() === '') {
            return;
        }

        $status = $this->get_license_status(true);
        $ttl = !empty($status['valid']) ? self::INSTALL_SYNC_TTL : 15 * MINUTE_IN_SECONDS;
        set_transient(self::INSTALL_SYNC_TRANSIENT, '1', $ttl);
    }

    public function register_settings(): void {
        register_setting(
            'human_card_check_pro_settings',
            self::SERVICE_URL_OPTION,
            [
                'type' => 'string',
                'sanitize_callback' => 'esc_url_raw',
                'default' => 'https://trust.mapage-wp.online',
            ]
        );

        register_setting(
            'human_card_check_pro_settings',
            self::MIN_SCORE_OPTION,
            [
                'type' => 'integer',
                'sanitize_callback' => [$this, 'sanitize_score'],
                'default' => 45,
            ]
        );

        register_setting(
            'human_card_check_pro_settings',
            self::REVIEW_SCORE_OPTION,
            [
                'type' => 'integer',
                'sanitize_callback' => [$this, 'sanitize_score'],
                'default' => 65,
            ]
        );

        register_setting(
            'human_card_check_pro_settings',
            self::REQUEST_TIMEOUT_OPTION,
            [
                'type' => 'integer',
                'sanitize_callback' => [$this, 'sanitize_timeout'],
                'default' => 10,
            ]
        );
    }

    public function sanitize_score($value): int {
        $score = (int) $value;
        if ($score < 0) {
            $score = 0;
        }
        if ($score > 100) {
            $score = 100;
        }
        return $score;
    }

    public function sanitize_timeout($value): int {
        $timeout = (int) $value;
        if ($timeout < 3) {
            $timeout = 3;
        }
        if ($timeout > 60) {
            $timeout = 60;
        }
        return $timeout;
    }

    public function register_admin_pages(): void {
        add_submenu_page(
            'options-general.php',
            'Human Card Check Pro',
            'Human Card Check Pro',
            'manage_options',
            'human-card-check-pro',
            [$this, 'render_settings_page']
        );

        add_submenu_page(
            'options-general.php',
            'Human Card Check Pro Logs',
            'Human Card Check Pro Logs',
            'manage_options',
            'human-card-check-pro-logs',
            [$this, 'render_logs_page']
        );
    }

    public function maybe_show_dependency_notice(): void {
        if ($this->is_free_plugin_active()) {
            return;
        }

        echo '<div class="notice notice-error"><p>Human Card Check Pro requires the Human Card Check plugin to be active.</p></div>';
    }

    public function inject_github_update($transient) {
        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_github_release_data();
        if (!$release || empty($release['version'])) {
            return $transient;
        }

        if (version_compare(self::VERSION, $release['version'], '>=')) {
            return $transient;
        }

        $plugin_file = plugin_basename(__FILE__);
        $update = (object) [
            'slug' => 'human-card-check-pro',
            'plugin' => $plugin_file,
            'new_version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'icons' => [],
            'banners' => [],
            'banners_rtl' => [],
            'tested' => '6.9',
            'requires_php' => '7.4',
            'compatibility' => new stdClass(),
        ];

        $transient->response[$plugin_file] = $update;

        return $transient;
    }

    public function filter_plugin_information($result, string $action, $args) {
        if ($action !== 'plugin_information' || !is_object($args) || empty($args->slug) || $args->slug !== 'human-card-check-pro') {
            return $result;
        }

        $release = $this->get_github_release_data();

        if (!$release) {
            return $result;
        }

        return (object) [
            'name' => 'Human Card Check Pro',
            'slug' => 'human-card-check-pro',
            'version' => $release['version'],
            'author' => '<a href="https://github.com/juliansebastien-rgb">Le Labo d&#039;Azertaf</a>',
            'author_profile' => 'https://github.com/juliansebastien-rgb',
            'homepage' => self::GITHUB_REPOSITORY_URL,
            'requires' => '6.0',
            'requires_php' => '7.4',
            'tested' => '6.9',
            'last_updated' => $release['published_at'],
            'download_link' => $release['package'],
            'sections' => [
                'description' => 'Pro trust scoring addon for Human Card Check.',
                'installation' => 'Install and activate Human Card Check first, then install Human Card Check Pro. Enter your license token in Settings > Human Card Check and configure the Pro options in Settings > Human Card Check Pro.',
                'changelog' => sprintf("= %s =\n* GitHub release package.\n", $release['version']),
            ],
            'banners' => [],
            'icons' => [],
        ];
    }

    public function clear_update_cache($upgrader, array $hook_extra): void {
        if (($hook_extra['type'] ?? '') !== 'plugin') {
            return;
        }

        $plugins = $hook_extra['plugins'] ?? [];

        if (in_array(plugin_basename(__FILE__), $plugins, true)) {
            delete_transient(self::TRANSIENT_PREFIX . 'github_release');
        }
    }

    public function normalize_github_update_source(string $source, string $remote_source, $upgrader, array $hook_extra): string {
        if (($hook_extra['type'] ?? '') !== 'plugin') {
            return $source;
        }

        $plugins = $hook_extra['plugins'] ?? [];
        if (!in_array(plugin_basename(__FILE__), $plugins, true)) {
            return $source;
        }

        $normalized = trailingslashit($remote_source) . 'human-card-check-pro';

        if ($source === $normalized || !is_dir($source)) {
            return $source;
        }

        if (@rename($source, $normalized)) {
            return $normalized;
        }

        return $source;
    }

    private function get_github_release_data(): ?array {
        $cache_key = self::TRANSIENT_PREFIX . 'github_release';
        $cached = get_transient($cache_key);

        if (is_array($cached)) {
            return $cached;
        }

        $release = $this->request_github_release('/releases/latest');

        if (!$release) {
            $tag = $this->request_github_release('/tags');
            if (!$tag || empty($tag[0]['name'])) {
                return null;
            }

            $first_tag = $tag[0];
            $release = [
                'tag_name' => $first_tag['name'],
                'zipball_url' => self::GITHUB_API_BASE . '/zipball/' . rawurlencode($first_tag['name']),
                'html_url' => self::GITHUB_REPOSITORY_URL . '/releases/tag/' . rawurlencode($first_tag['name']),
                'published_at' => gmdate('Y-m-d H:i:s'),
                'body' => '',
            ];
        }

        if (empty($release['tag_name'])) {
            return null;
        }

        $package = '';
        if (!empty($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (!is_array($asset)) {
                    continue;
                }

                $name = isset($asset['name']) ? (string) $asset['name'] : '';
                $download = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';

                if ($name !== '' && substr($name, -4) === '.zip' && $download !== '') {
                    $package = $download;
                    break;
                }
            }
        }

        if ($package === '' && !empty($release['zipball_url'])) {
            $package = (string) $release['zipball_url'];
        }

        if ($package === '') {
            return null;
        }

        $data = [
            'version' => ltrim((string) $release['tag_name'], 'v'),
            'package' => $package,
            'url' => !empty($release['html_url']) ? (string) $release['html_url'] : self::GITHUB_REPOSITORY_URL,
            'published_at' => !empty($release['published_at']) ? gmdate('Y-m-d H:i:s', strtotime((string) $release['published_at'])) : gmdate('Y-m-d H:i:s'),
            'body' => !empty($release['body']) ? (string) $release['body'] : '',
        ];

        set_transient($cache_key, $data, self::UPDATE_CACHE_TTL);

        return $data;
    }

    private function request_github_release(string $path) {
        $response = wp_remote_get(
            self::GITHUB_API_BASE . $path,
            [
                'timeout' => 15,
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'Human Card Check Pro/' . self::VERSION . '; ' . home_url('/'),
                ],
            ]
        );

        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($data) ? $data : null;
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $license = $this->get_license_status(true);
        $payment_link = $this->get_payment_link();
        ?>
        <div class="wrap">
            <h1>Human Card Check Pro</h1>
            <?php $this->render_admin_branding(); ?>
            <form method="post" action="options.php">
                <?php settings_fields('human_card_check_pro_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="hcc_pro_service_url">Trust service URL</label></th>
                        <td>
                            <input type="text" class="regular-text" id="hcc_pro_service_url" value="<?php echo esc_attr($this->get_service_url()); ?>" readonly disabled />
                            <p class="description">This service URL is managed by the plugin.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hcc_pro_min_score">Minimum score to allow registration</label></th>
                        <td><input type="number" min="0" max="100" id="hcc_pro_min_score" name="<?php echo esc_attr(self::MIN_SCORE_OPTION); ?>" value="<?php echo esc_attr((string) $this->get_min_score()); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hcc_pro_review_score">Review threshold</label></th>
                        <td>
                            <input type="number" min="0" max="100" id="hcc_pro_review_score" name="<?php echo esc_attr(self::REVIEW_SCORE_OPTION); ?>" value="<?php echo esc_attr((string) $this->get_review_score()); ?>" />
                            <p class="description">Scores below this threshold are logged as review candidates.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hcc_pro_timeout">Request timeout</label></th>
                        <td><input type="number" min="3" max="60" id="hcc_pro_timeout" name="<?php echo esc_attr(self::REQUEST_TIMEOUT_OPTION); ?>" value="<?php echo esc_attr((string) $this->get_request_timeout()); ?>" /></td>
                    </tr>
                </table>
                <?php submit_button('Save Pro settings'); ?>
            </form>

            <h2>License status</h2>
            <p><strong>Status:</strong> <?php echo !empty($license['valid']) ? 'Valid' : 'Invalid'; ?></p>
            <?php if (!empty($license['message'])) : ?>
                <p><?php echo esc_html($license['message']); ?></p>
            <?php endif; ?>
            <p class="description">The license token is entered in Settings > Human Card Check.</p>
            <?php if ($payment_link !== '' && empty($license['valid'])) : ?>
                <p><a class="button button-primary" href="<?php echo esc_url($payment_link); ?>" target="_blank" rel="noopener noreferrer">Buy or renew Human Card Check Pro</a></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_admin_branding(): void {
        $logo_url = plugin_dir_url(__FILE__) . 'assets/images/hcc-logo.PNG';
        ?>
        <style>
            .hcc-pro-admin-brand {
                display: flex;
                align-items: center;
                gap: 18px;
                margin: 18px 0 24px;
            }
            .hcc-pro-admin-brand__logo {
                width: 78px;
                height: 78px;
                object-fit: cover;
            }
            .hcc-pro-admin-brand__eyebrow {
                margin: 0 0 6px;
                font-size: 12px;
                font-weight: 700;
                letter-spacing: .08em;
                text-transform: uppercase;
                color: #64748b;
            }
            .hcc-pro-admin-brand__title {
                margin: 0 0 6px;
                font-size: 24px;
                line-height: 1.2;
                color: #0f172a;
            }
            .hcc-pro-admin-brand__text {
                margin: 0;
                max-width: 760px;
                color: #475569;
            }
        </style>
        <div class="hcc-pro-admin-brand">
            <img class="hcc-pro-admin-brand__logo" src="<?php echo esc_url($logo_url); ?>" alt="Human Card Check logo" />
            <div>
                <p class="hcc-pro-admin-brand__eyebrow">Le Labo d'Azertaf</p>
                <h2 class="hcc-pro-admin-brand__title">Human Card Check Pro</h2>
                <p class="hcc-pro-admin-brand__text">Advanced trust scoring, email and domain risk analysis, and automatic decisions to reduce low-quality registrations.</p>
            </div>
        </div>
        <?php
    }

    public function render_logs_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        $table = $this->get_log_table_name();
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 100", ARRAY_A);
        ?>
        <div class="wrap">
            <h1>Human Card Check Pro Logs</h1>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Context</th>
                        <th>Email</th>
                        <th>Score</th>
                        <th>Action</th>
                        <th>Flags</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)) : ?>
                        <tr><td colspan="6">No trust-score logs yet.</td></tr>
                    <?php else : ?>
                        <?php foreach ($rows as $row) : ?>
                            <tr>
                                <td><?php echo esc_html((string) $row['created_at']); ?></td>
                                <td><?php echo esc_html((string) $row['context']); ?></td>
                                <td><?php echo esc_html((string) $row['user_email']); ?></td>
                                <td><?php echo esc_html((string) $row['trust_score']); ?></td>
                                <td><?php echo esc_html((string) $row['recommended_action']); ?></td>
                                <td><code><?php echo esc_html((string) $row['flags_json']); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * @param array<string,mixed> $decision
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function filter_registration_decision(array $decision, array $payload): array {
        $license = $this->get_license_status();
        if (empty($license['valid'])) {
            return $decision;
        }

        $score = $this->request_trust_score($payload);
        if (!$score['ok']) {
            return $decision;
        }

        $this->store_log($payload, $score);

        $trust_score = isset($score['trust_score']) ? (int) $score['trust_score'] : 0;
        $recommended_action = isset($score['recommended_action']) ? (string) $score['recommended_action'] : 'allow';

        if ($trust_score < $this->get_min_score() || $recommended_action === 'block') {
            return [
                'allow' => false,
                'message' => !empty($score['message']) ? (string) $score['message'] : 'Registration blocked by Human Card Check Pro.',
                'action' => 'block',
            ];
        }

        if ($trust_score < $this->get_review_score()) {
            return [
                'allow' => true,
                'message' => '',
                'action' => 'review',
            ];
        }

        return [
            'allow' => true,
            'message' => '',
            'action' => 'allow',
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function request_trust_score(array $payload): array {
        $service_url = trailingslashit($this->get_service_url()) . 'v1/score';
        $body = [
            'license_token' => $this->get_free_plugin_token(),
            'site_url' => home_url('/'),
            'site_name' => get_bloginfo('name'),
            'pro_plugin_version' => self::VERSION,
            'context' => isset($payload['context']) ? (string) $payload['context'] : '',
            'user_email' => isset($payload['user_email']) ? (string) $payload['user_email'] : '',
            'user_login' => isset($payload['user_login']) ? (string) $payload['user_login'] : '',
            'ip' => isset($payload['ip']) ? (string) $payload['ip'] : '',
            'user_agent' => isset($payload['user_agent']) ? (string) $payload['user_agent'] : '',
            'challenge_passed' => true,
            'language' => determine_locale(),
        ];

        $response = wp_remote_post(
            $service_url,
            [
                'timeout' => $this->get_request_timeout(),
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($body),
            ]
        );

        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300 || !is_array($data)) {
            return [
                'ok' => false,
                'message' => 'Invalid response from trust service.',
            ];
        }

        return $data;
    }

    /**
     * @return array{valid:bool,message:string,plan:string,checked_at:string}
     */
    private function get_license_status(bool $force_refresh = false): array {
        $cached = $force_refresh ? false : get_transient(self::LICENSE_TRANSIENT);
        if (!$force_refresh && is_array($cached)) {
            return $cached;
        }

        $token = $this->get_free_plugin_token();
        if ($token === '') {
            return [
                'valid' => false,
                'message' => 'No license token found in Human Card Check settings.',
                'plan' => '',
                'checked_at' => gmdate('Y-m-d H:i:s'),
            ];
        }

        $response = wp_remote_post(
            trailingslashit($this->get_service_url()) . 'v1/license/validate',
            [
                'timeout' => $this->get_request_timeout(),
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode([
                    'license_token' => $token,
                    'site_url' => home_url('/'),
                    'site_name' => get_bloginfo('name'),
                    'pro_plugin_version' => self::VERSION,
                ]),
            ]
        );

        if (is_wp_error($response)) {
            $status = [
                'valid' => false,
                'message' => $response->get_error_message(),
                'plan' => '',
                'checked_at' => gmdate('Y-m-d H:i:s'),
            ];
            set_transient(self::LICENSE_TRANSIENT, $status, 5 * MINUTE_IN_SECONDS);
            return $status;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        $status = [
            'valid' => $code >= 200 && $code < 300 && !empty($data['valid']),
            'message' => is_array($data) && !empty($data['message']) ? (string) $data['message'] : '',
            'plan' => is_array($data) && !empty($data['plan']) ? (string) $data['plan'] : '',
            'checked_at' => gmdate('Y-m-d H:i:s'),
        ];

        set_transient(self::LICENSE_TRANSIENT, $status, HOUR_IN_SECONDS);

        return $status;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $score
     */
    private function store_log(array $payload, array $score): void {
        global $wpdb;
        $wpdb->insert(
            $this->get_log_table_name(),
            [
                'created_at' => current_time('mysql'),
                'context' => isset($payload['context']) ? (string) $payload['context'] : '',
                'user_email' => isset($payload['user_email']) ? (string) $payload['user_email'] : '',
                'user_login' => isset($payload['user_login']) ? (string) $payload['user_login'] : '',
                'ip' => isset($payload['ip']) ? (string) $payload['ip'] : '',
                'trust_score' => isset($score['trust_score']) ? (int) $score['trust_score'] : 0,
                'recommended_action' => isset($score['recommended_action']) ? (string) $score['recommended_action'] : 'allow',
                'flags_json' => wp_json_encode($score['flags'] ?? []),
                'raw_payload_json' => wp_json_encode([
                    'request' => $payload,
                    'response' => $score,
                ]),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
        );
    }

    private function create_log_table(): void {
        global $wpdb;
        $table = $this->get_log_table_name();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            context VARCHAR(64) NOT NULL,
            user_email VARCHAR(190) NOT NULL,
            user_login VARCHAR(190) NOT NULL,
            ip VARCHAR(100) NOT NULL,
            trust_score INT NOT NULL,
            recommended_action VARCHAR(64) NOT NULL,
            flags_json LONGTEXT NOT NULL,
            raw_payload_json LONGTEXT NOT NULL,
            PRIMARY KEY (id),
            KEY created_at (created_at),
            KEY user_email (user_email),
            KEY trust_score (trust_score)
        ) {$charset};";
        dbDelta($sql);
    }

    private function get_log_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::LOG_TABLE_SUFFIX;
    }

    private function is_free_plugin_active(): bool {
        return class_exists('Human_Card_Check');
    }

    private function get_service_url(): string {
        $value = get_option(self::SERVICE_URL_OPTION, 'https://trust.mapage-wp.online');
        return is_string($value) && $value !== '' ? untrailingslashit($value) : 'https://trust.mapage-wp.online';
    }

    private function get_min_score(): int {
        return (int) get_option(self::MIN_SCORE_OPTION, 45);
    }

    private function get_review_score(): int {
        return (int) get_option(self::REVIEW_SCORE_OPTION, 65);
    }

    private function get_request_timeout(): int {
        return (int) get_option(self::REQUEST_TIMEOUT_OPTION, 10);
    }

    private function get_free_plugin_token(): string {
        $value = get_option('human_card_check_pro_token', '');
        return is_string($value) ? trim($value) : '';
    }

    private function get_payment_link(): string {
        $value = get_option('human_card_check_pro_payment_link', self::DEFAULT_PAYMENT_LINK);
        $value = is_string($value) ? trim($value) : '';
        return $value !== '' ? $value : self::DEFAULT_PAYMENT_LINK;
    }
}

(new Human_Card_Check_Pro())->boot();

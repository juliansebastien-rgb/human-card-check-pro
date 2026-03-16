<?php
/**
 * Plugin Name: Human Card Check Pro
 * Plugin URI: https://github.com/juliansebastien-rgb/human-card-check
 * Description: Pro trust scoring addon for Human Card Check.
 * Version: 0.1.2
 * Author: Le Labo d'Azertaf
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: human-card-check-pro
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Human_Card_Check_Pro {
    private const VERSION = '0.1.2';
    private const DEFAULT_PAYMENT_LINK = 'https://buy.stripe.com/cNidR29Lz7OV8cN2Hj8k800';
    private const LOG_TABLE_SUFFIX = 'hcc_pro_logs';
    private const SERVICE_URL_OPTION = 'human_card_check_pro_service_url';
    private const SERVICE_KEY_OPTION = 'human_card_check_pro_service_key';
    private const MIN_SCORE_OPTION = 'human_card_check_pro_min_score';
    private const REVIEW_SCORE_OPTION = 'human_card_check_pro_review_score';
    private const REQUEST_TIMEOUT_OPTION = 'human_card_check_pro_timeout';
    private const LICENSE_TRANSIENT = 'human_card_check_pro_license_status';

    public function boot(): void {
        register_activation_hook(__FILE__, [$this, 'activate']);

        add_action('plugins_loaded', [$this, 'register_hooks']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'register_admin_pages']);
        add_action('admin_notices', [$this, 'maybe_show_dependency_notice']);
    }

    public function activate(): void {
        $this->create_log_table();
    }

    public function register_hooks(): void {
        if (!$this->is_free_plugin_active()) {
            return;
        }

        add_filter('human_card_check_pro_registration_decision', [$this, 'filter_registration_decision'], 10, 2);
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
            self::SERVICE_KEY_OPTION,
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_service_key'],
                'default' => '',
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

    public function sanitize_service_key($value): string {
        return is_string($value) ? trim($value) : '';
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

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $license = $this->get_license_status();
        $payment_link = $this->get_payment_link();
        ?>
        <div class="wrap">
            <h1>Human Card Check Pro</h1>
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
                        <th scope="row"><label for="hcc_pro_service_key">Trust service API key</label></th>
                        <td>
                            <input type="password" class="regular-text" id="hcc_pro_service_key" name="<?php echo esc_attr(self::SERVICE_KEY_OPTION); ?>" value="<?php echo esc_attr($this->get_service_key()); ?>" autocomplete="off" />
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
            <p class="description">The Pro token is entered in Settings > Human Card Check.</p>
            <?php if ($payment_link !== '' && empty($license['valid'])) : ?>
                <p><a class="button button-primary" href="<?php echo esc_url($payment_link); ?>" target="_blank" rel="noopener noreferrer">Buy or renew Human Card Check Pro</a></p>
            <?php endif; ?>
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
                    'X-HCC-Service-Key' => $this->get_service_key(),
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
    private function get_license_status(): array {
        $cached = get_transient(self::LICENSE_TRANSIENT);
        if (is_array($cached)) {
            return $cached;
        }

        $token = $this->get_free_plugin_token();
        if ($token === '') {
            return [
                'valid' => false,
                'message' => 'No Pro token found in Human Card Check settings.',
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
                    'X-HCC-Service-Key' => $this->get_service_key(),
                ],
                'body' => wp_json_encode([
                    'license_token' => $token,
                    'site_url' => home_url('/'),
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

    private function get_service_key(): string {
        $value = get_option(self::SERVICE_KEY_OPTION, '');
        return is_string($value) ? $value : '';
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

<?php
/**
 * Plugin Name: Frequent Updates Checker
 * Description: Sprawdza aktualizacje wtyczek co minutę
 * Version: 1.0
 * Author: CraneCode
 */

if (!defined('ABSPATH')) {
    exit;
}

class FrequentUpdatesChecker {
    public function __construct() {
        // Change update frequency
        add_filter('wp_update_plugins_frequency', array($this, 'change_update_frequency'));

        // Add custom cron schedule
        add_filter('cron_schedules', array($this, 'add_one_minute_schedule'));

        // Plugin activation
        register_activation_hook(__FILE__, array($this, 'activate_plugin'));

        // Plugin deactivation
        register_deactivation_hook(__FILE__, array($this, 'deactivate_plugin'));

        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Add action to log checks
        add_action('wp_update_plugins', array($this, 'log_last_check'), 999);

        // Add AJAX endpoint to refresh data
        add_action('wp_ajax_get_update_status', array($this, 'get_update_status'));

        // Add custom hook to check updates
        add_action('fuc_check_updates', array($this, 'force_update_check'));

        // Add AJAX endpoint to force update check
        add_action('wp_ajax_force_update_check', array($this, 'handle_force_update_check'));

        // Add AJAX endpoint to check single plugin update
        add_action('wp_ajax_check_single_plugin_update', array($this, 'handle_single_plugin_check'));

        // Add AJAX endpoint to save settings
        add_action('wp_ajax_save_fuc_settings', array($this, 'save_settings'));
    }

    public function change_update_frequency() {
        return 60;
    }

    public function add_one_minute_schedule($schedules) {
        $interval = $this->get_check_interval();
        $schedules['fuc_custom_interval'] = array(
            'interval' => $interval,
            'display' => sprintf('Co %d sekund', $interval)
        );
        return $schedules;
    }

    public function activate_plugin() {
        wp_clear_scheduled_hook('fuc_check_updates');
        if (!wp_next_scheduled('fuc_check_updates')) {
            wp_schedule_event(time(), 'fuc_custom_interval', 'fuc_check_updates');
        }
    }

    public function deactivate_plugin() {
        // Clear schedule when deactivating
        wp_clear_scheduled_hook('fuc_check_updates');
    }

    public function add_admin_menu() {
        add_menu_page(
            'Sprawdzanie aktualizacji',
            'Sprawdzanie aktualizacji',
            'manage_options',
            'frequent-updates-checker',
            array($this, 'display_admin_page'),
            'dashicons-update',
            60
        );
    }

    public function log_last_check() {
        update_option('fuc_last_check_time', time());

        if (!wp_next_scheduled('fuc_check_updates')) {
            wp_schedule_event(time() + 60, 'every_minute', 'fuc_check_updates');
        }
    }

    public function get_update_status() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access');
        }

        check_ajax_referer('fuc_update_check', 'nonce');

        $response = array(
            'last_check' => get_option('fuc_last_check_time', 0),
            'next_check' => wp_next_scheduled('wp_update_plugins')
        );
        wp_send_json($response);
    }

    public function display_admin_page() {
        $last_check = get_option('fuc_last_check_time', 0);
        $next_check = wp_next_scheduled('fuc_check_updates');
        $all_plugins = get_plugins();
        $update_plugins = get_site_transient('update_plugins');
        ?>
        <div class="wrap fuc-wrapper">
            <h1 class="fuc-title">Status sprawdzania aktualizacji</h1>
            <div class="fuc-container">
                <div class="fuc-column fuc-main-column">
                    <div class="fuc-main-card">
                        <h2>Szczegóły sprawdzanych wtyczek</h2>
                        <table class="fuc-plugins-table widefat">
                            <thead>
                                <tr>
                                    <th class="plugin-name">Nazwa wtyczki</th>
                                    <th class="plugin-version">Aktualna wersja</th>
                                    <th class="plugin-update">Dostępna aktualizacja</th>
                                    <th class="plugin-source">Źródło aktualizacji</th>
                                    <th class="plugin-actions">Akcje</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_plugins as $plugin_file => $plugin_data):
                                    $has_update = isset($update_plugins->response[$plugin_file]);
                                    $update_source = $this->get_update_source($plugin_file, $update_plugins);
                                ?>
                                    <tr>
                                        <td class="plugin-name"><?php echo esc_html($plugin_data['Name']); ?></td>
                                        <td class="plugin-version"><?php echo esc_html($plugin_data['Version']); ?></td>
                                        <td class="plugin-update">
                                            <span class="plugin-version-<?php echo esc_attr(preg_replace('/[^\w\s-]/', '_', $plugin_file)); ?>">
                                                <?php if ($has_update): ?>
                                                    <span style="color: green;">
                                                        <?php echo esc_html($update_plugins->response[$plugin_file]->new_version); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color: #666;">Aktualna</span>
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td class="plugin-source"><?php echo wp_kses_post($update_source); ?></td>
                                        <td class="plugin-actions">
                                            <div class="plugin-actions-container">
                                                <button type="button"
                                                        class="button check-single-plugin"
                                                        data-plugin="<?php echo esc_attr($plugin_file); ?>"
                                                        data-nonce="<?php echo wp_create_nonce('check_plugin_' . $plugin_file); ?>">
                                                    Sprawdź aktualizacje
                                                </button>
                                                <span class="fuc-spinner"></span>
                                                <div class="check-result"></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="fuc-column fuc-side-column">
                    <div class="fuc-side-card">
                        <h2>Informacje o sprawdzaniu aktualizacji</h2>
                        <p>
                            <strong>Aktualny czas:</strong><br>
                            <span id="current-time">...</span>
                        </p>
                        <p>
                            <strong>Ostatnie sprawdzenie:</strong><br>
                            <span id="last-check"><?php echo $last_check ? date('Y-m-d H:i:s', $last_check) : 'Brak danych'; ?></span>
                        </p>
                        <p>
                            <strong>Następne zaplanowane sprawdzenie:</strong><br>
                            <span id="next-check"><?php echo $next_check ? date('Y-m-d H:i:s', $next_check) : 'Nie zaplanowano'; ?></span>
                        </p>
                        <p>
                            <strong>Pozostało do następnego sprawdzenia:</strong><br>
                            <span id="countdown">...</span>
                        </p>
                        <p>
                            <strong>Status WP-Cron:</strong><br>
                            <?php echo defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'Wyłączony' : 'Włączony'; ?>
                        </p>
                        <p>
                            <strong>Interwał sprawdzania:</strong><br>
                            <?php
                            $interval = $this->get_check_interval();
                            if ($interval < 60) {
                                echo sprintf('Co %d sekund', $interval);
                            } elseif ($interval == 60) {
                                echo 'Co minutę';
                            } elseif ($interval < 3600) {
                                $minutes = $interval / 60;
                                echo sprintf('Co %d %s',
                                    $minutes,
                                    $minutes == 1 ? 'minutę' : ($minutes < 5 ? 'minuty' : 'minut')
                                );
                            } else {
                                echo 'Co godzinę';
                            }
                            ?>
                        </p>
                        <p>
                            <strong>Status automatycznego sprawdzania:</strong><br>
                            <?php echo $this->is_auto_check_enabled() ? 'Włączone' : 'Wyłączone'; ?>
                        </p>
                    </div>
                    <div class="fuc-side-card">
                        <h2>Wymuś sprawdzenie wtyczek</h2>
                        <form method="post">
                            <?php
                            wp_nonce_field('force_update_check', 'force_check_nonce');

                            if (isset($_POST['force_check']) &&
                                isset($_POST['force_check_nonce']) &&
                                wp_verify_nonce($_POST['force_check_nonce'], 'force_update_check')) {

                                // Force update check
                                delete_site_transient('update_plugins');
                                wp_update_plugins();

                                // Update last check time
                                update_option('fuc_last_check_time', time());

                                echo '<div class="notice notice-success"><p>Wymuszono sprawdzenie aktualizacji!</p></div>';
                            }
                            ?>
                            <input type="submit" name="force_check" class="button button-primary" value="Sprawdź teraz">
                        </form>
                    </div>
                    <div class="fuc-side-card">
                        <h2>Ustawienia sprawdzania</h2>
                        <form id="fuc-settings-form" method="post">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="auto-check">Automatyczne sprawdzanie</label>
                                    </th>
                                    <td>
                                        <label class="fuc-switch">
                                            <input type="checkbox"
                                                   id="auto-check"
                                                   name="auto_check"
                                                   <?php echo $this->is_auto_check_enabled() ? 'checked' : ''; ?>>
                                            <span class="fuc-slider round"></span>
                                        </label>
                                        <p class="description">
                                            Włącz/wyłącz automatyczne sprawdzanie aktualizacji
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="check-interval">Interwał sprawdzania (w sekundach)</label>
                                    </th>
                                    <td>
                                        <input type="number"
                                               id="check-interval"
                                               name="interval"
                                               min="30"
                                               max="3600"
                                               value="<?php echo esc_attr($this->get_check_interval()); ?>"
                                               class="small-text">
                                        <p class="description">
                                            Minimum: 30 sekund, Maximum: 3600 sekund (1 godzina)
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            <?php wp_nonce_field('fuc_save_settings', 'settings_nonce'); ?>
                            <button type="submit" class="button button-primary">Zapisz ustawienia</button>
                            <span class="fuc-spinner settings-spinner"></span>
                            <div class="settings-message"></div>
                        </form>
                    </div>
                </div>
            </div>

            <style>
                .fuc-container {
                    display: flex;
                    gap: 20px;
                }

                .fuc-column.main-column {
                    flex: 5;
                    padding: 20px 30px;
                }

                .fuc-column.side-column {
                    flex: 2;
                    min-width: 300px;
                    max-width: 400px;
                    border-right: 20px;
                }

                /* Style dla kart */
                .fuc-main-card {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    border-radius: 4px;
                    padding: 20px 30px;
                    margin-bottom: 20px;
                    box-shadow: 0 1px 1px rgba(0,0,0,.04);
                    width: 100%;
                    min-width: 800px;
                }

                .fuc-side-card h2 {
                    margin-top: 0;
                    margin-bottom: 20px;
                    font-size: 16px;
                    font-weight: 600;
                }

                /* Style dla kart */
                .fuc-side-card {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    border-radius: 4px;
                    padding: 20px 30px;
                    margin-bottom: 20px;
                    box-shadow: 0 1px 1px rgba(0,0,0,.04);
                    width: 80%;
                    min-width: 300px;

                }

                .fuc-main-card h2 {
                    margin-top: 0;
                    margin-bottom: 20px;
                    font-size: 16px;
                    font-weight: 600;
                    padding-right: 100px;
                }

                .fuc-plugins-table {
                    table-layout: fixed;
                    width: 100%;
                    min-width: 800px;
                    padding: 0px 10px;

                }

                .fuc-plugins-table th,
                .fuc-plugins-table td {
                    padding: 12px 5px;
                    vertical-align: middle;

                }

                .fuc-plugins-table th.plugin-name,
                .fuc-plugins-table td.plugin-name {
                    width: 15%;
                }

                .fuc-plugins-table th.plugin-version,
                .fuc-plugins-table td.plugin-version {
                    width: 8%;
                }

                .fuc-plugins-table th.plugin-update,
                .fuc-plugins-table td.plugin-update {
                    width: 12%;
                }

                .fuc-plugins-table th.plugin-source,
                .fuc-plugins-table td.plugin-source {
                    width: 20%;
                }

                .fuc-plugins-table th.plugin-actions,
                .fuc-plugins-table td.plugin-actions {
                    width: 12%;
                    padding-right: 10px;
                }

                .plugin-actions-container {
                    display: flex;
                    flex-direction: column;
                    gap: 8px;
                    min-width: 150px;
                }

                .fuc-spinner {
                    float: none;
                    margin: 4px 0;
                    visibility: hidden;
                }

                .fuc-spinner.is-active {
                    visibility: visible;
                }

                .check-result {
                    font-size: 13px;
                    padding: 5px 0;
                    margin-top: 5px;
                    border-left: 4px solid transparent;
                    padding-left: 8px;
                }

                .check-result.success {
                    color: green;
                    border-left-color: green;
                    background: #f0fff0;
                }

                .check-result.error {
                    color: #dc3232;
                    border-left-color: #dc3232;
                    background: #fff0f0;
                }

                .check-result.info {
                    color: #666;
                    border-left-color: #666;
                    background: #f7f7f7;
                }

                /* Button styles */
                .check-single-plugin {
                    padding: 4px 12px;
                    min-width: 150px;
                }

                /* Settings form styles */
                .fuc-spinner {
                    float: none;
                    margin-left: 10px;
                    vertical-align: middle;
                }

                .settings-message {
                    margin-top: 10px;
                    padding: 10px;
                    display: none;
                }

                .settings-message.success {
                    background-color: #f0fff0;
                    border-left: 4px solid green;
                    color: green;
                }

                .settings-message.error {
                    background-color: #fff0f0;
                    border-left: 4px solid #dc3232;
                    color: #dc3232;
                }

                /* Switch styles */
                .fuc-switch {
                    position: relative;
                    display: inline-block;
                    width: 60px;
                    height: 34px;
                }

                .fuc-switch input {
                    opacity: 0;
                    width: 0;
                    height: 0;
                }

                .fuc-slider {
                    position: absolute;
                    cursor: pointer;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background-color: #ccc;
                    transition: .4s;
                }

                .fuc-slider:before {
                    position: absolute;
                    content: "";
                    height: 26px;
                    width: 26px;
                    left: 4px;
                    bottom: 4px;
                    background-color: white;
                    transition: .4s;
                }

                input:checked + .fuc-slider {
                    background-color: #2196F3;
                }

                input:focus + .fuc-slider {
                    box-shadow: 0 0 1px #2196F3;
                }

                input:checked + .fuc-slider:before {
                    transform: translateX(26px);
                }

                .fuc-slider.round {
                    border-radius: 34px;
                }

                .fuc-slider.round:before {
                    border-radius: 50%;
                }

                @media screen and (max-width: 782px) {
                    .fuc-container {
                        flex-direction: column;
                    }

                    .fuc-column.main-column {
                        padding: 0 20px 10px 10px;
                        min-width: auto;
                    }

                    .fuc-plugins-table {
                        min-width: auto;
                    }

                    /* Adjust column widths for small screens */
                    .fuc-plugins-table th.plugin-name,
                    .fuc-plugins-table td.plugin-name {
                        width: 50%;
                    }

                    .fuc-plugins-table th.plugin-version,
                    .fuc-plugins-table td.plugin-version {
                        width: 25%;
                    }
                }

                .fuc-wrapper .fuc-container {
                    display: flex;
                    gap: 20px;
                }

                .fuc-wrapper .fuc-main-card {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                }
            </style>
            </div>


        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const FUC = {
                init: function() {
                    this.updateTime();
                    this.updateStatus();
                    this.bindEvents();
                },

                updateTime: function() {
                    const now = new Date();
                    document.getElementById('current-time').textContent =
                        now.toLocaleString('pl-PL', {
                            year: 'numeric',
                            month: '2-digit',
                            day: '2-digit',
                            hour: '2-digit',
                            minute: '2-digit',
                            second: '2-digit'
                        });
                },

                updateStatus: function() {
                    fetch(ajaxurl + '?action=get_update_status&nonce=<?php echo wp_create_nonce("fuc_update_check"); ?>', {
                        credentials: 'same-origin'
                    })
                        .then(response => response.json())
                        .then(data => {
                            const now = new Date().getTime();
                            const nextCheck = data.next_check * 1000;

                            // Update last check
                            if (data.last_check) {
                                const lastCheckDate = new Date(data.last_check * 1000);
                                document.getElementById('last-check').textContent =
                                    lastCheckDate.toLocaleString('pl-PL');
                            }

                            // Update next check
                            if (data.next_check) {
                                const nextCheckDate = new Date(nextCheck);
                                document.getElementById('next-check').textContent =
                                    nextCheckDate.toLocaleString('pl-PL');
                            }

                            // Update countdown
                            if (nextCheck > now) {
                                const timeLeft = nextCheck - now;
                                const seconds = Math.floor((timeLeft / 1000) % 60);
                                const minutes = Math.floor((timeLeft / (1000 * 60)) % 60);
                                const hours = Math.floor((timeLeft / (1000 * 60 * 60)) % 24);

                                document.getElementById('countdown').textContent =
                                    `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                            } else {
                                document.getElementById('countdown').textContent = 'Aktualizacja w toku...';
                            }
                        })
                        .catch(error => console.error('Error updating status:', error));
                },

                bindEvents: function() {
                }
            };

            FUC.init();
        });
        </script>
        <script>
        jQuery(document).ready(function($) {
            $('form[name="force_check"]').on('submit', function(e) {
                e.preventDefault();

                const $button = $(this).find('input[type="submit"]');
                $button.prop('disabled', true);

                $.post(ajaxurl, {
                    action: 'force_update_check',
                    nonce: '<?php echo wp_create_nonce('force_update_check'); ?>'
                })
                .done(function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Wystąpił błąd podczas sprawdzania aktualizacji.');
                    }
                })
                .fail(function() {
                    alert('Wystąpił błąd podczas sprawdzania aktualizacji.');
                })
                .always(function() {
                    $button.prop('disabled', false);
                });
            });
        });
        </script>
        <script>
        jQuery(document).ready(function($) {
            $('.check-single-plugin').on('click', function() {
                const $button = $(this);
                const $container = $button.closest('.plugin-actions-container');
                const $spinner = $container.find('.fuc-spinner');
                const $result = $container.find('.check-result');

                // Improved selector encoding
                const pluginFile = $button.data('plugin');
                const safePluginId = pluginFile.replace(/[^\w\s-]/g, '_');
                const $versionCell = $('.plugin-version-' + safePluginId);

                console.log('Container:', $container);
                console.log('Spinner:', $spinner);
                console.log('Result div:', $result);

                // Reset previous result
                $result.removeClass('success error info').empty();

                // Disable button and show spinner
                $button.prop('disabled', true);
                $spinner.addClass('is-active');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'check_single_plugin_update',
                        plugin: pluginFile,
                        nonce: $button.data('nonce')
                    },
                    success: function(response) {
                        console.log('Response:', response);
                        if (response.success) {
                            if (response.data.has_update) {
                                $versionCell.html('<span style="color: green;">' +
                                    response.data.new_version + '</span>');
                                $result
                                    .addClass('success')
                                    .html('Znaleziono nową wersję: ' + response.data.new_version);
                            } else {
                                $versionCell.html('<span style="color: #666;">Aktualna</span>');
                                $result
                                    .addClass('info')
                                    .html('Posiadasz najnowszą wersję wtyczki');
                            }

                            // Add check time information
                            $result.append('<br><small>Sprawdzono: ' +
                                new Date().toLocaleString('pl-PL') + '</small>');
                        } else {
                            $result
                                .addClass('error')
                                .html('Wystąpił błąd podczas sprawdzania aktualizacji');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Ajax error:', error);
                        $result
                            .addClass('error')
                            .html('Wystąpił błąd podczas komunikacji z serwerem');
                    },
                    complete: function() {
                        $button.prop('disabled', false);
                        $spinner.removeClass('is-active');
                    }
                });
            });
        });
        </script>
        <script>
        jQuery(document).ready(function($) {
            $('#fuc-settings-form').on('submit', function(e) {
                e.preventDefault();

                const $form = $(this);
                const $spinner = $form.find('.fuc-spinner');
                const $message = $form.find('.settings-message');
                const $submit = $form.find('button[type="submit"]');

                $submit.prop('disabled', true);
                $spinner.addClass('is-active');
                $message.hide();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'save_fuc_settings',
                        interval: $('#check-interval').val(),
                        auto_check: $('#auto-check').is(':checked'),
                        nonce: $('#settings_nonce').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            $message
                                .removeClass('error')
                                .addClass('success')
                                .html(response.data.message)
                                .show();

                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            $message
                                .removeClass('success')
                                .addClass('error')
                                .html('Wystąpił błąd podczas zapisywania ustawień')
                                .show();
                        }
                    },
                    error: function() {
                        $message
                            .removeClass('success')
                            .addClass('error')
                            .html('Wystąpił błąd podczas komunikacji z serwerem')
                            .show();
                    },
                    complete: function() {
                        $submit.prop('disabled', false);
                        $spinner.removeClass('is-active');
                    }
                });
            });
        });
        </script>
        <?php
    }

    private function get_update_source($plugin_file, $update_plugins) {
        // Add caching results
        static $cache = array();

        if (isset($cache[$plugin_file])) {
            return $cache[$plugin_file];
        }

        $update_data = isset($update_plugins->response[$plugin_file]) ?
            $update_plugins->response[$plugin_file] :
            ($update_plugins->no_update[$plugin_file] ?? null);

        if (!$update_data) {
            return 'Brak informacji o źródle';
        }

        // Get endpoint information
        $endpoints = array();

        // Check package URL
        if (!empty($update_data->package)) {
            $endpoints[] = "Package URL: " . esc_url($update_data->package);
        }

        // Check update URL
        if (!empty($update_data->url)) {
            $endpoints[] = "Update URL: " . esc_url($update_data->url);
        }

        // Check update URI
        if (!empty($update_data->update_uri)) {
            $endpoints[] = "Update URI: " . esc_url($update_data->update_uri);
        }

        // Check update source
        if (strpos($update_data->package ?? '', 'wordpress.org') !== false) {
            $source = 'WordPress.org';
        } elseif (isset($update_data->package) && empty($update_data->package)) {
            $source = 'Wymaga licencji';
        } elseif (isset($update_data->external) && $update_data->external) {
            $source = 'Zewnętrzne API';
        } else {
            $source = 'Niestandardowe API';
        }

        // Add endpoint information to source
        if (!empty($endpoints)) {
            $source .= "<br><small>" . implode("<br>", $endpoints) . "</small>";
        }

        $cache[$plugin_file] = $source;
        return $source;
    }

    public function force_update_check() {
        delete_site_transient('update_plugins');
        wp_update_plugins();
    }

    public function handle_force_update_check() {
        // Check permissions and nonce
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Brak uprawnień');
        }

        check_ajax_referer('force_update_check', 'nonce');

        // Force update check
        delete_site_transient('update_plugins');
        wp_update_plugins();

        // Update last check time
        update_option('fuc_last_check_time', time());

        wp_send_json_success();
    }

    public function handle_single_plugin_check() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Brak uprawnień');
        }

        $plugin_file = isset($_POST['plugin']) ? sanitize_text_field($_POST['plugin']) : '';
        check_ajax_referer('check_plugin_' . $plugin_file, 'nonce');

        if (empty($plugin_file) || !file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
            wp_send_json_error('Nieprawidłowa wtyczka');
        }

        try {
            // Remove cache only for this specific plugin
            $update_plugins = get_site_transient('update_plugins');
            if ($update_plugins && isset($update_plugins->checked[$plugin_file])) {
                unset($update_plugins->checked[$plugin_file]);
                if (isset($update_plugins->response[$plugin_file])) {
                    unset($update_plugins->response[$plugin_file]);
                }
                set_site_transient('update_plugins', $update_plugins);
            }

            // Check updates
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
            wp_update_plugins();

            // Get updated information
            $update_plugins = get_site_transient('update_plugins');
            $has_update = isset($update_plugins->response[$plugin_file]);

            $response = array(
                'has_update' => $has_update,
                'new_version' => $has_update ? $update_plugins->response[$plugin_file]->new_version : null
            );

            // Save last check time
            update_option('fuc_last_check_time_' . $plugin_file, time());

            wp_send_json_success($response);
        } catch (Exception $e) {
            wp_send_json_error('Wystąpił błąd: ' . $e->getMessage());
        }
    }

    public function get_check_interval() {
        return get_option('fuc_check_interval', 60); // domyślnie 60 sekund
    }

    public function save_settings() {
        try {
            if (!current_user_can('manage_options')) {
                wp_send_json_error('Brak uprawnień');
            }

            check_ajax_referer('fuc_save_settings', 'nonce');

            $interval = isset($_POST['interval']) ? intval($_POST['interval']) : 60;
            $interval = max(30, min(3600, $interval));

            $auto_check = isset($_POST['auto_check']) ? $_POST['auto_check'] === 'true' : false;

            update_option('fuc_check_interval', $interval);
            update_option('fuc_auto_check_enabled', $auto_check);

            // Update schedule depending on settings
            wp_clear_scheduled_hook('fuc_check_updates');
            if ($auto_check && !wp_next_scheduled('fuc_check_updates')) {
                wp_schedule_event(time(), 'fuc_custom_interval', 'fuc_check_updates');
            }

            wp_send_json_success(['message' => 'Ustawienia zostały zapisane']);
        } catch (Exception $e) {
            wp_send_json_error('Wystąpił błąd: ' . $e->getMessage());
        }
    }

    public function is_auto_check_enabled() {
        return get_option('fuc_auto_check_enabled', true);
    }
}


new FrequentUpdatesChecker();
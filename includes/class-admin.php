<?php

if (!defined('ABSPATH')) exit;

/**
 * MP_Admin
 *
 * Responsabilité unique : interface d'administration WordPress.
 * Gère les menus, les pages, les settings et les formulaires admin.
 *
 * Extrait de MP_Creator_Notifier_Pro — aucune modification de comportement.
 */
class MP_Admin
{
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu',  [$this, 'add_pages']);
        add_action('admin_init',  [$this, 'on_admin_init']);
    }

    // =========================================================
    // INITIALISATION
    // =========================================================

    public function on_admin_init()
    {
        $this->register_settings();

        // Soumission formulaire créateur
        if (isset($_POST['mp_submit_creator']) && check_admin_referer('mp_create_creator', 'mp_creator_nonce')) {
            $this->handle_creator_submission();
        }

        // Sync manuelle via formulaire POST
        if (isset($_POST['mp_manual_sync']) && check_admin_referer('mp_manual_sync', 'mp_sync_nonce')) {
            $this->handle_manual_sync_form();
        }

        // Régénération token
        if (isset($_POST['mp_generate_token']) && check_admin_referer('mp_generate_token')) {
            $new_token = MP_Creator_API_Token::create_and_store();
            add_settings_error('mp_creator_messages', 'token_generated', sprintf(__('New token generated. Save this: %s', 'mp-creator-notifier'), $new_token), 'success');
        }

        // Forcer recréation tables
        if (isset($_GET['mp_force_create_table']) && current_user_can('manage_options')) {
            $db = MP_Creator_DB::get_instance();
            $db->create_tables();
            $db->add_missing_columns();
            wp_redirect(admin_url('admin.php?page=mp-creators&table_created=1'));
            exit;
        }
    }

    // =========================================================
    // MENUS
    // =========================================================

    public function add_pages()
    {
        add_menu_page('MP Creators', 'MP Creators', 'manage_woocommerce', 'mp-creators',         [$this, 'page_creators'],  'dashicons-groups', 56);
        add_submenu_page('mp-creators', 'Settings',          'Settings', 'manage_options', 'mp-creator-settings', [$this, 'page_settings']);
        add_submenu_page('mp-creators', 'Synchronisation',   'Sync',     'manage_options', 'mp-creator-sync',     [$this, 'page_sync']);
        add_submenu_page('mp-creators', 'PAPS Logistics',    'PAPS',     'manage_woocommerce', 'mp-paps-settings',  [$this, 'page_paps_settings']);
        add_submenu_page('mp-creators', 'Logs',              'Logs',     'manage_options', 'mp-creator-logs',     [$this, 'page_logs']);
        add_submenu_page('mp-creators', 'API Documentation', 'API Docs', 'manage_options', 'mp-creator-api',      [$this, 'page_api_docs']);
    }

    // =========================================================
    // PAGE CRÉATEURS
    // =========================================================

    public function page_creators()
    {
        $this->display_transient_notices();
?>
        <div class="wrap">
            <h1><?php _e('MP Creator Management', 'mp-creator-notifier'); ?></h1>
            <div class="mp-admin-header">
                <div class="mp-stats-cards">
                    <?php $this->render_stat_card(__('Total Creators', 'mp-creator-notifier'), MP_Creator_DB::get_instance()->get_creators_count()); ?>
                    <?php $this->render_stat_card(__('Active Brands', 'mp-creator-notifier'),  MP_Creator_DB::get_instance()->get_active_brands_count()); ?>
                    <?php $this->render_stat_card(__('Products Synced', 'mp-creator-notifier'), MP_Creator_DB::get_instance()->get_synced_products_count()); ?>
                    <?php $this->render_stat_card(__('Sync Health', 'mp-creator-notifier'),    MP_Creator_DB::get_instance()->get_sync_health_percentage() . '%'); ?>
                </div>
            </div>
            <div class="mp-admin-content">
                <div class="mp-tab-content active">
                    <?php $this->render_creators_list(); ?>
                    <?php $this->render_creator_form(); ?>
                </div>
            </div>
            <?php $this->render_admin_styles(); ?>
            <?php $this->render_delete_script(); ?>
        </div>
<?php
    }

    private function render_stat_card($label, $value)
    {
        echo '<div class="mp-card"><h3>' . esc_html($label) . '</h3><p class="mp-stat">' . esc_html($value) . '</p></div>';
    }

    // =========================================================
    // LISTE DES CRÉATEURS
    // =========================================================

    public function render_creators_list()
    {
        $db                = MP_Creator_DB::get_instance();
        $has_wp_creator_id = $db->column_exists('creators', 'wp_creator_id');
        $creators          = $db->get_all_creators();

        if (empty($creators)) {
            echo '<p>' . __('No creators found. Click "Add New Creator" to get started.', 'mp-creator-notifier') . '</p>';
            return;
        }

        echo '<button type="button" class="button button-primary button-large mp-add-creator-btn" style="margin-bottom:20px;">';
        echo '<span class="dashicons dashicons-plus-alt"></span> ' . __('Add New Creator', 'mp-creator-notifier');
        echo '</button>';

        echo '<table class="mp-creator-table"><thead><tr>';
        echo '<th>ID</th>';
        if ($has_wp_creator_id) echo '<th>ID Laravel</th>';
        echo '<th>' . __('Name') . '</th><th>' . __('Email') . '</th><th>' . __('Brand') . '</th><th>' . __('Status') . '</th><th>' . __('Actions') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($creators as $creator) {
            echo '<tr>';
            echo '<td>' . esc_html($creator->id) . '</td>';
            if ($has_wp_creator_id) echo '<td>' . esc_html($creator->wp_creator_id ?: '-') . '</td>';
            echo '<td>' . esc_html($creator->name) . '</td>';
            echo '<td>' . esc_html($creator->email) . '</td>';
            echo '<td>' . esc_html($creator->brand_slug) . '</td>';
            echo '<td>' . ($creator->status === 'active' ? '<span style="color:green;">✓ Active</span>' : '<span style="color:red;">✗ Inactive</span>') . '</td>';
            echo '<td>';
            echo '<a href="#" class="mp-delete-creator mp-delete-btn" data-id="' . esc_attr($creator->id) . '" data-name="' . esc_attr($creator->name) . '">';
            echo '<span class="dashicons dashicons-trash"></span> ' . __('Delete', 'mp-creator-notifier');
            echo '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // =========================================================
    // FORMULAIRE AJOUT CRÉATEUR
    // =========================================================

    public function render_creator_form()
    {
        $brands = $this->get_available_brands();
?>
        <div id="mp-creator-form-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:999999;">
            <div style="max-width:600px;margin:50px auto;background:#fff;border-radius:8px;padding:30px;">
                <h2><?php _e('Add New Creator', 'mp-creator-notifier'); ?></h2>
                <form method="post" action="">
                    <?php wp_nonce_field('mp_create_creator', 'mp_creator_nonce'); ?>
                    <table class="form-table">
                        <tr><th><label for="creator_name"><?php _e('Name', 'mp-creator-notifier'); ?> *</label></th><td><input type="text" id="creator_name" name="creator_name" class="regular-text" required></td></tr>
                        <tr><th><label for="creator_email"><?php _e('Email', 'mp-creator-notifier'); ?> *</label></th><td><input type="email" id="creator_email" name="creator_email" class="regular-text" required></td></tr>
                        <tr><th><label for="creator_phone"><?php _e('Phone', 'mp-creator-notifier'); ?></label></th><td><input type="tel" id="creator_phone" name="creator_phone" class="regular-text"></td></tr>
                        <tr>
                            <th><label for="creator_brand">Brand *</label></th>
                            <td>
                                <select id="creator_brand" name="creator_brand" class="regular-text" required>
                                    <option value="">-- <?php _e('Select a Brand', 'mp-creator-notifier'); ?> --</option>
                                    <?php foreach ($brands as $brand): ?>
                                        <option value="<?php echo esc_attr($brand['slug']); ?>"><?php echo esc_html($brand['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description" style="color:#666;font-style:italic;"> Créez d'abord une marque dans <strong>Produits → Marques</strong></p>
                            </td>
                        </tr>
                        <tr><th><label for="creator_address"><?php _e('Address', 'mp-creator-notifier'); ?></label></th><td><textarea id="creator_address" name="creator_address" rows="3" class="large-text"></textarea></td></tr>
                    </table>
                    <p class="submit">
                        <button type="submit" name="mp_submit_creator" class="button button-primary"><?php _e('Create Creator', 'mp-creator-notifier'); ?></button>
                        <button type="button" class="button button-secondary mp-close-modal"><?php _e('Cancel', 'mp-creator-notifier'); ?></button>
                    </p>
                </form>
            </div>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('.mp-add-creator-btn').on('click', function() { $('#mp-creator-form-modal').fadeIn(); });
            $('.mp-close-modal').on('click',     function() { $('#mp-creator-form-modal').fadeOut(); });
        });
        </script>
<?php
    }

    // =========================================================
    // PAGE SETTINGS
    // =========================================================

    public function page_settings()
    {
?>
        <div class="wrap">
            <h1><?php _e('MP Creator Settings', 'mp-creator-notifier'); ?></h1>
            <?php settings_errors('mp_creator_messages'); ?>
            <form method="post" action="options.php">
                <?php settings_fields('mp_creator_settings'); ?>

                <div style="background:#fff;padding:20px;margin-bottom:20px;border-radius:8px;">
                    <h2><?php _e('Laravel Integration', 'mp-creator-notifier'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="mp_laravel_webhook_url"><?php _e('Laravel Webhook URL', 'mp-creator-notifier'); ?></label></th>
                            <td>
                                <input type="url" id="mp_laravel_webhook_url" name="mp_laravel_webhook_url" value="<?php echo esc_url(get_option('mp_laravel_webhook_url')); ?>" class="regular-text" placeholder="https://votre-site.com/api/webhooks/wordpress">
                                <p class="description"><?php _e('Base URL for Laravel webhooks', 'mp-creator-notifier'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="mp_laravel_webhook_secret"><?php _e('Webhook Secret', 'mp-creator-notifier'); ?></label></th>
                            <td>
                                <input type="password" id="mp_laravel_webhook_secret" name="mp_laravel_webhook_secret" value="<?php echo esc_attr(get_option('mp_laravel_webhook_secret')); ?>" class="regular-text">
                                <p class="description"><?php _e('Same as WORDPRESS_WEBHOOK_SECRET in your Laravel .env', 'mp-creator-notifier'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th></th>
                            <td>
                                <button type="button" id="mp-test-connection" class="button button-secondary"><?php _e('Test Connection', 'mp-creator-notifier'); ?></button>
                                <span id="mp-connection-result" style="margin-left:10px;"></span>
                            </td>
                        </tr>
                    </table>
                </div>

                <div style="background:#fff;padding:20px;margin-bottom:20px;border-radius:8px;">
                    <h2><?php _e('Synchronization', 'mp-creator-notifier'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><?php _e('Auto Sync Interval', 'mp-creator-notifier'); ?></th>
                            <td>
                                <select name="mp_auto_sync_interval">
                                    <?php foreach (['hourly' => __('Hourly'), 'daily' => __('Daily'), 'weekly' => __('Weekly'), 'disabled' => __('Disabled')] as $val => $label): ?>
                                        <option value="<?php echo $val; ?>" <?php selected(get_option('mp_auto_sync_interval'), $val); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Sync on Order Update', 'mp-creator-notifier'); ?></th>
                            <td><label><input type="checkbox" name="mp_sync_on_order_update" value="1" <?php checked(get_option('mp_sync_on_order_update', true)); ?>><?php _e('Send webhook when order is updated', 'mp-creator-notifier'); ?></label></td>
                        </tr>
                        <tr>
                            <th><?php _e('Sync on Product Update', 'mp-creator-notifier'); ?></th>
                            <td><label><input type="checkbox" name="mp_sync_on_product_update" value="1" <?php checked(get_option('mp_sync_on_product_update', true)); ?>><?php _e('Send webhook when product is updated', 'mp-creator-notifier'); ?></label></td>
                        </tr>
                    </table>
                </div>

                <div style="background:#fff;padding:20px;margin-bottom:20px;border-radius:8px;">
                    <h2><?php _e('Notifications', 'mp-creator-notifier'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><?php _e('Notify on Order Status', 'mp-creator-notifier'); ?></th>
                            <td>
                                <?php
                                $statuses  = wc_get_order_statuses();
                                $selected  = get_option('mp_notify_on_status', ['processing', 'completed']);
                                foreach ($statuses as $key => $label):
                                    $clean_key = str_replace('wc-', '', $key);
                                ?>
                                    <label style="display:block;margin-bottom:5px;">
                                        <input type="checkbox" name="mp_notify_on_status[]" value="<?php echo esc_attr($clean_key); ?>" <?php checked(in_array($clean_key, $selected)); ?>>
                                        <?php echo esc_html($label); ?>
                                    </label>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Notify Creators on Order', 'mp-creator-notifier'); ?></th>
                            <td><label><input type="checkbox" name="mp_notify_creators_on_order" value="1" <?php checked(get_option('mp_notify_creators_on_order', true)); ?>><?php _e('Send email notifications to creators when their products are ordered', 'mp-creator-notifier'); ?></label></td>
                        </tr>
                        <tr>
                            <th><label for="mp_email_template"><?php _e('Email Template', 'mp-creator-notifier'); ?></label></th>
                            <td>
                                <textarea id="mp_email_template" name="mp_email_template" rows="8" class="large-text code"><?php echo esc_textarea(get_option('mp_email_template', $this->default_email_template())); ?></textarea>
                                <p class="description"><?php _e('Variables:', 'mp-creator-notifier'); ?> <code>{creator_name}</code>, <code>{order_id}</code>, <code>{order_date}</code>, <code>{order_total}</code>, <code>{products_list}</code></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div style="background:#fff;padding:20px;margin-bottom:20px;border-radius:8px;">
                    <h2><?php _e('API Token', 'mp-creator-notifier'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><?php _e('Current Token', 'mp-creator-notifier'); ?></th>
                            <td>
                                <?php if (get_option('mp_api_token_hash')): ?>
                                    <span style="color:green;">✓ <?php _e('Configured', 'mp-creator-notifier'); ?></span>
                                    <p class="description"><?php _e('Created:', 'mp-creator-notifier'); ?> <?php echo esc_html(get_option('mp_api_token_created_at')); ?></p>
                                <?php else: ?>
                                    <span style="color:red;">✗ <?php _e('Not configured', 'mp-creator-notifier'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                    <form method="post" action="">
                        <?php wp_nonce_field('mp_generate_token'); ?>
                        <button type="submit" name="mp_generate_token" class="button button-primary"><?php _e('Generate New Token', 'mp-creator-notifier'); ?></button>
                    </form>
                </div>

                <?php submit_button(__('Save Settings', 'mp-creator-notifier')); ?>
            </form>

            <script>
            jQuery(document).ready(function($) {
                $('#mp-test-connection').on('click', function() {
                    var btn = $(this), result = $('#mp-connection-result');
                    var url = $('#mp_laravel_webhook_url').val(), secret = $('#mp_laravel_webhook_secret').val();
                    if (!url || !secret) { result.html('<span style="color:red;">Please fill URL and Secret first</span>'); return; }
                    btn.prop('disabled', true).text('Testing...');
                    $.ajax({
                        url: ajaxurl, method: 'POST',
                        data: { action: 'mp_test_laravel_connection', nonce: '<?php echo wp_create_nonce("mp_test_connection"); ?>', url: url, secret: secret },
                        success: function(r) { result.html(r.success ? '<span style="color:green;">✓ '+r.data.message+'</span>' : '<span style="color:red;">✗ '+r.data.message+'</span>'); },
                        error: function() { result.html('<span style="color:red;">✗ Connection failed</span>'); },
                        complete: function() { btn.prop('disabled', false).text('<?php _e('Test Connection', 'mp-creator-notifier'); ?>'); }
                    });
                });
            });
            </script>
        </div>
<?php
    }

    // =========================================================
    // PAGE SYNC
    // =========================================================

    public function page_sync()
    {
?>
        <div class="wrap">
            <h1><?php _e('Manual Synchronization', 'mp-creator-notifier'); ?></h1>
            <div style="background:#fff;padding:20px;border-radius:8px;margin-bottom:20px;">
                <form method="post" action="">
                    <?php wp_nonce_field('mp_manual_sync', 'mp_sync_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th><?php _e('Sync Type', 'mp-creator-notifier'); ?></th>
                            <td>
                                <select name="sync_type">
                                    <option value="all"><?php _e('Full Synchronization (All)', 'mp-creator-notifier'); ?></option>
                                    <option value="creators"><?php _e('Creators Only', 'mp-creator-notifier'); ?></option>
                                    <option value="products"><?php _e('Products Only', 'mp-creator-notifier'); ?></option>
                                    <option value="orders"><?php _e('Orders Only', 'mp-creator-notifier'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Date Range', 'mp-creator-notifier'); ?></th>
                            <td>
                                <select name="date_range">
                                    <option value="today"><?php _e('Today', 'mp-creator-notifier'); ?></option>
                                    <option value="week"><?php _e('Last 7 days', 'mp-creator-notifier'); ?></option>
                                    <option value="month"><?php _e('Last 30 days', 'mp-creator-notifier'); ?></option>
                                    <option value="all"><?php _e('All time', 'mp-creator-notifier'); ?></option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" name="mp_manual_sync" class="button button-primary button-large"><?php _e('Start Synchronization', 'mp-creator-notifier'); ?></button>
                    </p>
                </form>
            </div>
            <div style="background:#fff;padding:20px;border-radius:8px;">
                <h2><?php _e('Recent Sync Logs', 'mp-creator-notifier'); ?></h2>
                <?php $this->render_sync_status(); ?>
            </div>
        </div>
<?php
    }

    // =========================================================
    // PAGE PAPS SETTINGS
    // =========================================================

    public function page_paps_settings()
    {
        MP_Paps_Settings::get_instance()->render_settings_page();
    }

    // =========================================================
    // PAGE LOGS
    // =========================================================

    public function page_logs()
    {
        global $wpdb;
        $db                  = MP_Creator_DB::get_instance();
        $webhooks_table      = $db->get_table_name('webhooks');
        $notifications_table = $db->get_table_name('notifications');
        $creators_table      = $db->get_table_name('creators');

        $webhook_logs      = $wpdb->get_results("SELECT * FROM {$webhooks_table} ORDER BY created_at DESC LIMIT 200");
        $notification_logs = $wpdb->get_results("SELECT n.*, c.name as creator_name FROM {$notifications_table} n LEFT JOIN {$creators_table} c ON n.creator_id = c.id ORDER BY n.sent_at DESC LIMIT 100");
?>
        <div class="wrap">
            <h1><?php _e('Synchronization Logs', 'mp-creator-notifier'); ?></h1>
            <h2><?php _e('Webhook Logs', 'mp-creator-notifier'); ?></h2>
            <table class="widefat striped">
                <thead><tr><th>ID</th><th><?php _e('Date'); ?></th><th><?php _e('Event'); ?></th><th><?php _e('Order ID'); ?></th><th><?php _e('Status'); ?></th><th>HTTP</th><th><?php _e('Error'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($webhook_logs as $log): ?>
                    <tr>
                        <td><?php echo (int) $log->id; ?></td>
                        <td><?php echo esc_html($log->created_at); ?></td>
                        <td><?php echo esc_html($log->event_type); ?></td>
                        <td><?php echo $log->order_id ? (int) $log->order_id : '-'; ?></td>
                        <td><span class="sync-badge <?php echo esc_attr($log->status); ?>"><?php echo esc_html($log->status); ?></span></td>
                        <td><?php echo $log->response_code ? (int) $log->response_code : '-'; ?></td>
                        <td><?php echo esc_html($log->error_message ?? '-'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <h2><?php _e('Email Notifications', 'mp-creator-notifier'); ?></h2>
            <table class="widefat striped">
                <thead><tr><th>ID</th><th><?php _e('Date'); ?></th><th><?php _e('Creator'); ?></th><th><?php _e('Order ID'); ?></th><th><?php _e('Subject'); ?></th><th><?php _e('Status'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($notification_logs as $log): ?>
                    <tr>
                        <td><?php echo (int) $log->id; ?></td>
                        <td><?php echo esc_html($log->sent_at); ?></td>
                        <td><?php echo esc_html($log->creator_name ?? 'N/A'); ?></td>
                        <td>#<?php echo (int) $log->order_id; ?></td>
                        <td><?php echo esc_html($log->subject); ?></td>
                        <td><span class="sync-badge <?php echo esc_attr($log->status); ?>"><?php echo esc_html($log->status); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
<?php
    }

    // =========================================================
    // PAGE API DOCS
    // =========================================================

    public function page_api_docs()
    {
?>
        <div class="wrap">
            <h1><?php _e('API Documentation', 'mp-creator-notifier'); ?></h1>
            <div class="notice notice-info">
                <p><strong>Base URL:</strong> <code><?php echo esc_url(rest_url('mp/v2')); ?></code></p>
                <p><strong>Authentication:</strong> <code>X-MP-Token: YOUR_TOKEN</code></p>
            </div>
            <table class="widefat striped">
                <thead><tr><th>Endpoint</th><th>Method</th><th>Description</th></tr></thead>
                <tbody>
                    <tr><td><code>/creators</code></td><td>GET / POST</td><td>List / Create creators</td></tr>
                    <tr><td><code>/creators/{id}</code></td><td>GET / PUT / DELETE</td><td>Single creator CRUD</td></tr>
                    <tr><td><code>/products/brands-bulk</code></td><td>POST</td><td>Brands for multiple products</td></tr>
                    <tr><td><code>/products/creators</code></td><td>POST</td><td>Creators for multiple products</td></tr>
                    <tr><td><code>/orders</code></td><td>GET</td><td>List orders</td></tr>
                    <tr><td><code>/orders/{id}</code></td><td>GET</td><td>Single order</td></tr>
                    <tr><td><code>/system/test</code></td><td>GET</td><td>Test API (no auth)</td></tr>
                    <tr><td><code>/system/stats</code></td><td>GET</td><td>System statistics</td></tr>
                </tbody>
            </table>
            <h3>cURL example</h3>
            <pre><code>curl -H "X-MP-Token: YOUR_TOKEN" <?php echo esc_url(rest_url('mp/v2/creators')); ?></code></pre>
        </div>
<?php
    }

    // =========================================================
    // WIDGET SYNC STATUS (partagé entre pages)
    // =========================================================

    public function render_sync_status()
    {
        global $wpdb;
        $table  = MP_Creator_DB::get_instance()->get_table_name('webhooks');
        $logs   = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 50");
        $stats  = $wpdb->get_row("SELECT COUNT(*) as total, SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) as success, SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed FROM {$table} WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $rate   = ($stats && $stats->total > 0) ? round(($stats->success / $stats->total) * 100) : 100;
?>
        <div class="mp-stats-cards" style="grid-template-columns:repeat(4,1fr);margin-bottom:16px;">
            <div class="mp-card"><h3>Total</h3><p class="mp-stat"><?php echo (int) ($stats->total ?? 0); ?></p></div>
            <div class="mp-card"><h3>Success</h3><p class="mp-stat" style="color:#4caf50;"><?php echo (int) ($stats->success ?? 0); ?></p></div>
            <div class="mp-card"><h3>Failed</h3><p class="mp-stat" style="color:#f44336;"><?php echo (int) ($stats->failed ?? 0); ?></p></div>
            <div class="mp-card"><h3>Rate</h3><p class="mp-stat"><?php echo $rate; ?>%</p></div>
        </div>
        <?php if (empty($logs)): ?>
            <p><?php _e('No synchronization logs yet.', 'mp-creator-notifier'); ?></p>
        <?php else: ?>
        <table class="mp-creator-table">
            <thead><tr><th>Date</th><th>Event</th><th>Order ID</th><th>Status</th><th>HTTP</th><th>Error</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?php echo date_i18n(get_option('date_format') . ' H:i:s', strtotime($log->created_at)); ?></td>
                    <td><?php echo esc_html($log->event_type); ?></td>
                    <td><?php echo $log->order_id ? (int) $log->order_id : '-'; ?></td>
                    <td><span class="sync-badge <?php echo esc_attr($log->status); ?>"><?php echo esc_html(ucfirst($log->status)); ?></span></td>
                    <td><?php echo $log->response_code ? (int) $log->response_code : '-'; ?></td>
                    <td><?php echo esc_html(substr($log->error_message ?? '-', 0, 60)); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif;
    }

    // =========================================================
    // SETTINGS
    // =========================================================

    public function register_settings()
    {
        $settings = [
            'mp_laravel_webhook_url'               => 'esc_url_raw',
            'mp_laravel_webhook_secret'            => 'sanitize_text_field',
            'mp_email_template'                    => 'wp_kses_post',
            'mp_notify_creators_on_order'          => 'rest_sanitize_boolean',
            'mp_sync_on_order_update'              => 'rest_sanitize_boolean',
            'mp_sync_on_product_update'            => 'rest_sanitize_boolean',
            'mp_auto_sync_interval'                => 'sanitize_text_field',
            'mp_sync_products_on_creator_creation' => 'rest_sanitize_boolean',
        ];
        foreach ($settings as $option => $callback) {
            register_setting('mp_creator_settings', $option, ['sanitize_callback' => $callback]);
        }
        register_setting('mp_creator_settings', 'mp_notify_on_status', ['sanitize_callback' => [$this, 'sanitize_array']]);
    }

    public function sanitize_array($input)
    {
        return is_array($input) ? array_map('sanitize_text_field', $input) : [];
    }

    // =========================================================
    // HEALTH CHECK
    // =========================================================

    public function check_health()
    {
        $db = MP_Creator_DB::get_instance();

        if (!$db->table_exists('creators')) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-warning"><p><strong>MP Creator Notifier</strong> — Database tables missing. <a href="' . admin_url('admin.php?page=mp-creators&mp_force_create_table=1') . '" class="button">Create Tables Now</a></p></div>';
            });
        }

        if (empty(get_option('mp_laravel_webhook_url'))) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-info"><p><strong>MP Creator Notifier</strong> — Please configure Laravel webhook URL. <a href="' . admin_url('admin.php?page=mp-creator-settings') . '" class="button">Configure Now</a></p></div>';
            });
        }

        global $wpdb;
        if ($db->table_exists('webhooks')) {
            $failed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$db->get_table_name('webhooks')} WHERE status = 'failed' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            if ($failed > 10) {
                add_action('admin_notices', function () use ($failed) {
                    echo '<div class="notice notice-warning"><p><strong>MP Creator Notifier</strong> — ' . $failed . ' webhooks failed in the last 24h. <a href="' . admin_url('admin.php?page=mp-creator-logs') . '">Check logs</a></p></div>';
                });
            }
        }
    }

    // =========================================================
    // UTILITAIRES PRIVÉS
    // =========================================================

    private function handle_creator_submission()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to perform this action.', 'mp-creator-notifier'));
        }

        $name       = sanitize_text_field($_POST['creator_name']);
        $email      = sanitize_email($_POST['creator_email']);
        $phone      = sanitize_text_field($_POST['creator_phone'] ?? '');
        $address    = sanitize_textarea_field($_POST['creator_address'] ?? '');
        $brand_slug = sanitize_title($_POST['creator_brand'] ?? '');

        if (empty($name) || empty($email) || empty($brand_slug)) {
            $this->add_notice(__('Name, Email, and Brand are required.', 'mp-creator-notifier'), 'error');
            wp_redirect(admin_url('admin.php?page=mp-creators'));
            exit;
        }

        if (!is_email($email)) {
            $this->add_notice(__('Please enter a valid email address.', 'mp-creator-notifier'), 'error');
            wp_redirect(admin_url('admin.php?page=mp-creators'));
            exit;
        }

        $creator_id = MP_Creator_DB::get_instance()->create_creator([
            'name'       => $name,
            'email'      => $email,
            'phone'      => $phone,
            'brand_slug' => $brand_slug,
            'address'    => $address,
        ]);

        if (is_wp_error($creator_id)) {
            $this->add_notice($creator_id->get_error_message(), 'error');
        } else {
            MP_Creator_Webhook::send_creator_created($creator_id);
            MP_Creator_Webhook::send_products_sync_by_brand($brand_slug);
            $this->add_notice(sprintf(__('Creator "%s" created successfully for brand "%s"!', 'mp-creator-notifier'), $name, $brand_slug), 'success');
        }

        wp_redirect(admin_url('admin.php?page=mp-creators'));
        exit;
    }

    private function handle_manual_sync_form()
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        $sync_type  = $_POST['sync_type']  ?? 'all';
        $date_range = $_POST['date_range'] ?? 'all';

        MP_Sync_Service::get_instance()->sync_by_type($sync_type, $date_range);

        $this->add_notice(__('Synchronization initiated.', 'mp-creator-notifier'), 'success');
        wp_redirect(admin_url('admin.php?page=mp-creator-sync'));
        exit;
    }

    private function add_notice($message, $type = 'success')
    {
        add_settings_error('mp_creator_messages', 'mp_message', $message, $type);
        set_transient('mp_settings_errors', get_settings_errors(), 30);
    }

    private function display_transient_notices()
    {
        $errors = get_transient('mp_settings_errors');
        if ($errors) {
            delete_transient('mp_settings_errors');
            foreach ($errors as $error) {
                printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($error['type']), esc_html($error['message']));
            }
        }
    }

    private function get_available_brands()
    {
        $brands = [];
        foreach (['product_brand', 'brand'] as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
                if (!is_wp_error($terms)) {
                    foreach ($terms as $term) {
                        $brands[] = ['slug' => $term->slug, 'name' => $term->name];
                    }
                }
            }
        }
        return $brands;
    }

    private function default_email_template()
    {
        return __("Hello {creator_name},\n\nYou have a new order for your products!\n\nOrder ID: {order_id}\nOrder Date: {order_date}\nTotal: {order_total}\n\nProducts:\n{products_list}\n\nThank you,\nThe Store Team", 'mp-creator-notifier');
    }

    // =========================================================
    // CSS / JS INLINE
    // =========================================================

    private function render_admin_styles()
    {
        echo '<style>
            .mp-stats-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:20px; margin-bottom:30px; }
            .mp-card { background:#fff; padding:20px; border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,.1); text-align:center; }
            .mp-stat { font-size:32px; font-weight:bold; margin:10px 0; color:#2271b1; }
            .mp-delete-btn { color:#dc3232; text-decoration:none; padding:4px 8px; border-radius:3px; }
            .mp-delete-btn:hover { background:#dc3232; color:#fff; }
            .mp-tab-content { display:none; background:#fff; padding:20px; border-radius:0 8px 8px 8px; box-shadow:0 2px 4px rgba(0,0,0,.1); }
            .mp-tab-content.active { display:block; }
            .mp-creator-table { width:100%; border-collapse:collapse; }
            .mp-creator-table th { background:#f1f1f1; padding:12px; text-align:left; }
            .mp-creator-table td { padding:12px; border-bottom:1px solid #eee; }
            .sync-badge { display:inline-block; padding:3px 8px; border-radius:12px; font-size:12px; font-weight:bold; }
            .sync-badge.success { background:#d4edda; color:#155724; }
            .sync-badge.pending { background:#fff3cd; color:#856404; }
            .sync-badge.failed  { background:#f8d7da; color:#721c24; }
        </style>';
    }

    private function render_delete_script()
    {
        $nonce = wp_create_nonce('mp_delete_creator');
        echo "<script>
        jQuery(document).ready(function($) {
            $('.mp-delete-creator').on('click', function(e) {
                e.preventDefault();
                if (!confirm(' Supprimer ce créateur ? Cette action est irréversible.')) return;
                var creatorId = $(this).data('id'), creatorName = $(this).data('name'), row = $(this).closest('tr');
                $.ajax({
                    url: ajaxurl, method: 'POST',
                    data: { action: 'mp_delete_creator', nonce: '{$nonce}', creator_id: creatorId },
                    beforeSend: function() { row.css('opacity','0.5'); },
                    success: function(r) {
                        if (r.success) { row.fadeOut(300, function() { $(this).remove(); }); alert(' Créateur \"'+creatorName+'\" supprimé.'); }
                        else { alert(' '+r.data.message); row.css('opacity','1'); }
                    },
                    error: function() { alert(' Erreur lors de la suppression.'); row.css('opacity','1'); }
                });
            });
        });
        </script>";
    }

    // =========================================================
    // GESTIONNAIRE AJAX PAPS (délégation à la classe PAPS)
    // =========================================================

    public function handle_paps_ajax_actions()
    {
        // Délégué à MP_Paps_Settings qui gère déjà ses propres actions AJAX
    }
}
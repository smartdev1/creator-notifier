<?php

/**
 * Plugin Name: MP Creator Notifier Pro
 * Description: Gestion complète des créateurs avec synchronisation bidirectionnelle WordPress ↔ Laravel
 * Version: 5.1.0
 * Author: Baana Baana Boutique
 * Text Domain: mp-creator-notifier
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * Requires WordPress: 6.0.0
 */

if (!defined('ABSPATH')) exit;

define('MP_CREATOR_NOTIFIER_VERSION', '5.0.0');
define('MP_CREATOR_NOTIFIER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MP_CREATOR_NOTIFIER_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('MP_CREATOR_NOTIFIER_TABLE_PREFIX', 'mp_');

// Vérifier WooCommerce avant l'initialisation
add_action('plugins_loaded', 'mp_creator_notifier_pro_init', 5);

function mp_creator_notifier_pro_init()
{
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'mp_creator_notifier_woocommerce_missing');
        return;
    }

    MP_Creator_Notifier_Pro::get_instance();
}

function mp_creator_notifier_woocommerce_missing()
{
?>
    <div class="notice notice-error">
        <p><strong>MP Creator Notifier Pro</strong> nécessite WooCommerce. Activez WooCommerce d'abord.</p>
    </div>
    <?php
}

// =============================================
// CLASSE DE GESTION DES TOKENS API
// =============================================
class MP_Creator_API_Token
{
    public static function generate()
    {
        return bin2hex(random_bytes(32));
    }

    public static function hash($token)
    {
        return hash('sha256', $token);
    }

    public static function verify($token, $stored_hash)
    {
        return hash_equals($stored_hash, self::hash($token));
    }

    public static function create_and_store()
    {
        $token = self::generate();
        $hash = self::hash($token);

        update_option('mp_api_token_hash', $hash);
        update_option('mp_api_token_created_at', current_time('mysql'));

        return $token;
    }
}

// =============================================
// CLASSE PRINCIPALE DU PLUGIN
// =============================================
class MP_Creator_Notifier_Pro
{
    private static $instance = null;
    private $db;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('init', [$this, 'init'], 1);
        add_action('admin_init', [$this, 'admin_init']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('admin_menu', [$this, 'add_admin_pages']);

        // =============================================
        // ACTIONS WOOCOMMERCE - COMMANDES
        // =============================================
        add_action('woocommerce_new_order', [$this, 'handle_new_order'], 10, 2);
        add_action('woocommerce_order_status_changed', [$this, 'handle_order_status_change'], 10, 4);
        add_action('woocommerce_update_order', [$this, 'handle_order_update'], 10, 1);
        add_action('woocommerce_order_refunded', [$this, 'handle_order_refund'], 10, 2);

        // =============================================
        // ACTIONS WOOCOMMERCE - PRODUITS
        // =============================================
        add_action('woocommerce_new_product', [$this, 'handle_product_save'], 10, 1);
        add_action('woocommerce_update_product', [$this, 'handle_product_save'], 10, 1);
        add_action('woocommerce_product_meta_save', [$this, 'handle_product_save'], 10, 1);
        add_action('woocommerce_before_product_object_save', [$this, 'handle_product_before_save'], 10, 1);
        add_action('woocommerce_product_set_stock', [$this, 'handle_product_stock_change'], 10, 1);
        add_action('woocommerce_variation_set_stock', [$this, 'handle_product_stock_change'], 10, 1);
        add_action('woocommerce_product_set_price', [$this, 'handle_product_price_change'], 10, 1);
        add_action('woocommerce_product_deleted', [$this, 'handle_product_deleted'], 10, 1);

        // =============================================
        // ACTIONS WOOCOMMERCE - CATÉGORIES/MARQUES
        // =============================================
        add_action('created_term', [$this, 'handle_brand_term_save'], 10, 3);
        add_action('edited_term', [$this, 'handle_brand_term_save'], 10, 3);
        add_action('delete_term', [$this, 'handle_brand_term_delete'], 10, 3);

        // =============================================
        // ACTIONS AJAX
        // =============================================
        add_action('wp_ajax_mp_test_laravel_connection', [$this, 'ajax_test_laravel_connection']);
        add_action('wp_ajax_mp_send_webhook_test', [$this, 'ajax_send_webhook_test']);
        add_action('wp_ajax_mp_manual_sync', [$this, 'ajax_manual_sync']);
        add_action('wp_ajax_mp_force_full_sync', [$this, 'ajax_force_full_sync']);
        add_action('wp_ajax_mp_delete_creator', [$this, 'ajax_delete_creator']);
        // =============================================
        // CRON POUR SYNCHRONISATION PÉRIODIQUE
        // =============================================
        add_action('mp_hourly_sync', [$this, 'run_hourly_sync']);
        add_action('mp_daily_sync', [$this, 'run_daily_sync']);
        add_action('mp_weekly_sync', [$this, 'run_weekly_sync']);

        // =============================================
        // FILTERS POUR AMÉLIORER LES DONNÉES
        // =============================================
        add_filter('woocommerce_rest_prepare_product', [$this, 'filter_product_api_response'], 10, 3);
        add_filter('woocommerce_rest_prepare_shop_order', [$this, 'filter_order_api_response'], 10, 3);
    }

    public function init()
    {
        $this->db = new MP_Creator_DB();
        load_plugin_textdomain('mp-creator-notifier', false, dirname(plugin_basename(__FILE__)) . '/languages');

        // Planifier les crons si nécessaire
        $this->schedule_crons();
    }

    private function schedule_crons()
    {
        $interval = get_option('mp_auto_sync_interval', 'hourly');

        if ($interval !== 'disabled') {
            if (!wp_next_scheduled('mp_hourly_sync')) {
                wp_schedule_event(time(), 'hourly', 'mp_hourly_sync');
            }
            if (!wp_next_scheduled('mp_daily_sync')) {
                wp_schedule_event(time(), 'daily', 'mp_daily_sync');
            }
            if (!wp_next_scheduled('mp_weekly_sync')) {
                wp_schedule_event(time(), 'weekly', 'mp_weekly_sync');
            }
        }
    }

    public function activate()
    {
        error_log('=== MP Creator Notifier Pro - Activation ===');

        $this->db = new MP_Creator_DB();
        $this->db->create_tables();
        $this->db->add_missing_columns();

        if (!get_option('mp_api_token_hash')) {
            $token = MP_Creator_API_Token::create_and_store();
            set_transient('mp_new_token_display', $token, 5 * MINUTE_IN_SECONDS);
        }

        // Créer les pages nécessaires
        $this->create_default_pages();

        // Planifier les crons
        $this->schedule_crons();

        update_option('mp_creator_notifier_version', MP_CREATOR_NOTIFIER_VERSION);
        error_log('=== MP Creator Notifier Pro - Activation terminée ===');
    }

    public function admin_init()
    {
        $this->check_plugin_health();
        $this->register_settings();
        $this->db->add_missing_columns();

        if (isset($_POST['mp_submit_creator']) && check_admin_referer('mp_create_creator', 'mp_creator_nonce')) {
            $this->handle_creator_submission();
        }

        if (isset($_POST['mp_manual_sync']) && check_admin_referer('mp_manual_sync', 'mp_sync_nonce')) {
            $this->handle_manual_sync();
        }

        // Forcer la mise à jour des tables si demandé
        if (isset($_GET['mp_force_create_table']) && current_user_can('manage_options')) {
            $this->db->create_tables();
            $this->db->add_missing_columns();
            wp_redirect(admin_url('admin.php?page=mp-creators&table_created=1'));
            exit;
        }
    }

    public function deactivate()
    {
        wp_clear_scheduled_hook('mp_hourly_sync');
        wp_clear_scheduled_hook('mp_daily_sync');
        wp_clear_scheduled_hook('mp_weekly_sync');
        flush_rewrite_rules();
    }

    private function create_default_pages()
    {
        // Créer une page pour le dashboard créateur
        $page_id = wp_insert_post([
            'post_title' => __('Creator Dashboard', 'mp-creator-notifier'),
            'post_content' => '[mp_creator_dashboard]',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => 'creator-dashboard'
        ]);

        if ($page_id) {
            update_option('mp_creator_dashboard_page', $page_id);
        }
    }

    // =============================================
    // SYNCHRONISATIONS PLANIFIÉES
    // =============================================

    public function run_hourly_sync()
    {
        error_log('MP Creator: Running hourly sync');

        // Synchroniser les commandes des dernières 24h
        $this->sync_recent_orders(24);

        // Synchroniser les produits modifiés récemment
        $this->sync_recent_products(24);

        // Vérifier les webhooks en échec
        $this->retry_failed_webhooks();
    }

    public function run_daily_sync()
    {
        error_log('MP Creator: Running daily sync');

        // Synchronisation complète des créateurs
        MP_Creator_Webhook::send_full_sync_request('creators');

        // Synchronisation des produits récents (7 jours)
        $this->sync_recent_products(168);

        // Nettoyer les vieux logs
        $this->clean_old_logs();
    }

    public function run_weekly_sync()
    {
        error_log('MP Creator: Running weekly full sync');

        // Synchronisation complète
        MP_Creator_Webhook::send_full_sync_request('all');

        // Générer un rapport
        $this->generate_sync_report();
    }

    private function sync_recent_orders($hours = 24)
    {
        global $wpdb;

        $date_from = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));

        $args = [
            'limit' => -1,
            'date_created' => '>' . $date_from,
            'return' => 'ids'
        ];

        $order_ids = wc_get_orders($args);

        foreach ($order_ids as $order_id) {
            MP_Creator_Webhook::send_order_sync($order_id, 'periodic_sync');

            // Envoyer aussi avec les créateurs
            MP_Creator_Webhook::send_order_with_creators($order_id);
        }

        error_log("MP Creator: Synced " . count($order_ids) . " recent orders");
    }

    private function sync_recent_products($hours = 24)
    {
        global $wpdb;

        $date_from = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));

        $products = $wpdb->get_results($wpdb->prepare("
            SELECT ID, post_modified
            FROM {$wpdb->posts}
            WHERE post_type = 'product'
            AND post_modified > %s
            LIMIT 200
        ", $date_from));

        foreach ($products as $product) {
            MP_Creator_Webhook::send_product_sync($product->ID, 'periodic_sync');
        }

        error_log("MP Creator: Synced " . count($products) . " recent products");
    }

    private function retry_failed_webhooks()
    {
        global $wpdb;

        $webhooks_table = $this->db->get_table_name('webhooks');

        $failed_webhooks = $wpdb->get_results("
            SELECT * FROM {$webhooks_table}
            WHERE status = 'failed'
            AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            LIMIT 20
        ");

        foreach ($failed_webhooks as $webhook) {
            if ($webhook->event_type === 'order.created' || $webhook->event_type === 'order.status_changed') {
                MP_Creator_Webhook::send_order_sync($webhook->order_id, 'retry_' . $webhook->event_type);
            } elseif ($webhook->event_type === 'product.saved') {
                MP_Creator_Webhook::send_product_sync($webhook->product_id, 'retry');
            }
        }
    }

    private function clean_old_logs()
    {
        global $wpdb;

        $webhooks_table = $this->db->get_table_name('webhooks');
        $notifications_table = $this->db->get_table_name('notifications');

        // Supprimer les logs de plus de 30 jours
        $wpdb->query("DELETE FROM {$webhooks_table} WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $wpdb->query("DELETE FROM {$notifications_table} WHERE sent_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    }

    private function generate_sync_report()
    {
        global $wpdb;

        $webhooks_table = $this->db->get_table_name('webhooks');

        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
            FROM {$webhooks_table}
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");

        error_log('MP Creator: Weekly sync report - ' . json_encode($stats));

        // Envoyer le rapport par email à l'admin
        if ($stats->failed > 10) {
            $admin_email = get_option('admin_email');
            wp_mail(
                $admin_email,
                'MP Creator - Sync Report - Attention required',
                "Weekly sync report:\n" .
                    "Total: {$stats->total}\n" .
                    "Success: {$stats->success}\n" .
                    "Failed: {$stats->failed}\n" .
                    "Pending: {$stats->pending}\n\n" .
                    "Check the logs for more details."
            );
        }
    }

    // =============================================
    // GESTION DES CRÉATEURS
    // =============================================

    private function handle_creator_submission()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to perform this action.', 'mp-creator-notifier'));
        }

        $name = sanitize_text_field($_POST['creator_name']);
        $email = sanitize_email($_POST['creator_email']);
        $phone = sanitize_text_field($_POST['creator_phone'] ?? '');
        $address = sanitize_textarea_field($_POST['creator_address'] ?? '');
        $brand_slug = sanitize_title($_POST['creator_brand'] ?? '');

        if (empty($name) || empty($email) || empty($brand_slug)) {
            $this->add_admin_notice(__('Name, Email, and Brand are required.', 'mp-creator-notifier'), 'error');
            wp_redirect(admin_url('admin.php?page=mp-creators'));
            exit;
        }

        if (!is_email($email)) {
            $this->add_admin_notice(__('Please enter a valid email address.', 'mp-creator-notifier'), 'error');
            wp_redirect(admin_url('admin.php?page=mp-creators'));
            exit;
        }

        $creator_id = $this->db->create_creator([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'brand_slug' => $brand_slug,
            'address' => $address
        ]);

        if (is_wp_error($creator_id)) {
            $this->add_admin_notice($creator_id->get_error_message(), 'error');
        } else {
            // Envoyer le webhook vers Laravel
            MP_Creator_Webhook::send_creator_created($creator_id);

            // Lancer la synchronisation des produits de ce créateur
            MP_Creator_Webhook::send_products_sync_by_brand($brand_slug);

            $this->add_admin_notice(
                sprintf(__('Creator "%s" created successfully for brand "%s"!', 'mp-creator-notifier'), $name, $brand_slug),
                'success'
            );
        }

        wp_redirect(admin_url('admin.php?page=mp-creators'));
        exit;
    }

    private function add_admin_notice($message, $type = 'success')
    {
        add_settings_error('mp_creator_messages', 'mp_message', $message, $type);
        set_transient('mp_settings_errors', get_settings_errors(), 30);
    }

    // =============================================
    // GESTION DES COMMANDES - SYNCHRO VERS LARAVEL
    // =============================================

    public function handle_new_order($order_id, $order)
    {
        error_log("MP Creator: 🆕 Nouvelle commande #{$order_id} - Déclenchement synchronisations");

        // 1. Notification email aux créateurs (optionnel)
        if (get_option('mp_notify_creators_on_order', true)) {
            $this->notify_creators_for_order($order_id);
        }

        // 2. Envoi vers Laravel avec TOUTES les données
        MP_Creator_Webhook::send_order_sync($order_id, 'order_created');

        // 3. Envoi des données enrichies avec créateurs
        MP_Creator_Webhook::send_order_with_creators($order_id);

        // 4. Mise à jour des statistiques locales
        $this->update_order_stats($order_id);

        // 5. Déclencher une sync des produits concernés
        $this->trigger_products_sync_from_order($order_id);
    }

    public function handle_order_status_change($order_id, $old_status, $new_status, $order)
    {
        error_log("MP Creator: 🔄 Statut commande #{$order_id} changé: {$old_status} → {$new_status}");

        // Notification email si nécessaire
        $notify_statuses = get_option('mp_notify_on_status', ['processing', 'completed']);
        if (in_array($new_status, $notify_statuses)) {
            $this->notify_creators_for_order($order_id);
        }

        // Envoi vers Laravel (mise à jour statut)
        MP_Creator_Webhook::send_order_sync($order_id, 'order_status_changed', $new_status);

        // Si commande terminée, mettre à jour les stats
        if ($new_status === 'completed') {
            $this->update_creators_stats_from_order($order_id);

            // Déclencher une sync des produits pour mettre à jour les stocks
            $this->trigger_products_sync_from_order($order_id);
        }

        // Si commande annulée, notifier Laravel
        if ($new_status === 'cancelled' || $new_status === 'refunded') {
            MP_Creator_Webhook::send_order_cancelled($order_id, $new_status);
        }
    }

    public function handle_order_update($order_id)
    {
        // Synchronisation sur mise à jour (optionnel)
        if (get_option('mp_sync_on_order_update', true)) {
            MP_Creator_Webhook::send_order_sync($order_id, 'order_updated');
        }
    }

    public function handle_order_refund($order_id, $refund_id)
    {
        error_log("MP Creator: 💰 Remboursement commande #{$order_id}");

        $refund = wc_get_order($refund_id);

        MP_Creator_Webhook::send_order_refund($order_id, $refund_id, $refund->get_total());
    }

    private function trigger_products_sync_from_order($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $product_ids = [];
        foreach ($order->get_items() as $item) {
            $product_ids[] = $item->get_product_id();
        }

        $product_ids = array_unique($product_ids);

        foreach ($product_ids as $product_id) {
            // Sync chaque produit pour mettre à jour le stock
            MP_Creator_Webhook::send_product_sync($product_id, 'order_triggered');
        }
    }

    private function notify_creators_for_order($order_id)
    {
        $creators = $this->db->get_creators_for_order($order_id);

        foreach ($creators as $creator) {
            $this->send_creator_notification($creator, $order_id);
        }
    }

    private function send_creator_notification($creator, $order_id)
    {
        $order = wc_get_order($order_id);
        $products = $this->db->get_creator_products_in_order($creator->id, $order_id);
        $creator_total = array_sum(array_column($products, 'total'));

        $template = get_option('mp_email_template', $this->get_default_email_template());

        $replacements = [
            '{creator_name}' => $creator->name,
            '{order_id}' => $order_id,
            '{order_date}' => $order->get_date_created()->date('d/m/Y H:i'),
            '{order_total}' => wc_price($creator_total),
            '{products_list}' => $this->format_products_list($products)
        ];

        $message = str_replace(array_keys($replacements), array_values($replacements), $template);
        $subject = sprintf(__('New Order #%d for your products', 'mp-creator-notifier'), $order_id);

        $sent = wp_mail($creator->email, $subject, nl2br($message), ['Content-Type: text/html; charset=UTF-8']);

        $this->db->log_notification($creator->id, $order_id, $subject, $message, $sent ? 'sent' : 'failed');

        return $sent;
    }

    private function update_order_stats($order_id)
    {
        global $wpdb;

        $creators = $this->db->get_creators_for_order($order_id);

        foreach ($creators as $creator) {
            $creator_total = $this->db->get_creator_order_total($creator->id, $order_id);

            $wpdb->query($wpdb->prepare("
                UPDATE {$this->db->get_table_name('creators')}
                SET total_orders = total_orders + 1,
                    total_sales = total_sales + %f,
                    last_order_date = NOW()
                WHERE id = %d
            ", $creator_total, $creator->id));
        }
    }

    private function update_creators_stats_from_order($order_id)
    {
        $creators = $this->db->get_creators_for_order($order_id);

        foreach ($creators as $creator) {
            do_action('mp_creator_order_completed', $creator->id, $order_id);
        }
    }

    private function format_products_list($products)
    {
        $list = '';
        foreach ($products as $product) {
            $list .= sprintf("- %s x%d: %s\n", $product['name'], $product['quantity'], wc_price($product['total']));
        }
        return $list;
    }

    private function get_default_email_template()
    {
        return __("Hello {creator_name},\n\nYou have a new order for your products!\n\nOrder ID: {order_id}\nOrder Date: {order_date}\nTotal: {order_total}\n\nProducts:\n{products_list}\n\nThank you,\nThe Store Team", 'mp-creator-notifier');
    }

    // =============================================
    // GESTION DES PRODUITS - SYNCHRO VERS LARAVEL
    // =============================================

    public function handle_product_save($product_id)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($product_id)) return;

        $product = wc_get_product($product_id);
        if (!$product) return;

        error_log("MP Creator: 📦 Produit #{$product_id} sauvegardé - Déclenchement synchronisation");

        // Récupérer la marque du produit
        $brand_slug = get_post_meta($product_id, 'brand_slug', true);

        if (empty($brand_slug)) {
            // Essayer de détecter la marque depuis d'autres sources
            $brand_slug = $this->detect_product_brand($product);

            // Sauvegarder la marque si trouvée
            if (!empty($brand_slug)) {
                update_post_meta($product_id, 'brand_slug', $brand_slug);
            }
        }

        // Préparer les données complètes du produit
        $product_data = [
            'id' => $product_id,
            'name' => $product->get_name(),
            'slug' => $product->get_slug(),
            'sku' => $product->get_sku(),
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'stock_quantity' => $product->get_stock_quantity(),
            'stock_status' => $product->get_stock_status(),
            'manage_stock' => $product->get_manage_stock(),
            'brand_slug' => $brand_slug,
            'categories' => wp_get_post_terms($product_id, 'product_cat', ['fields' => 'names']),
            'image' => wp_get_attachment_url($product->get_image_id()),
            'permalink' => get_permalink($product_id),
            'date_modified' => $product->get_date_modified() ? $product->get_date_modified()->date('Y-m-d H:i:s') : current_time('mysql')
        ];

        // Envoyer vers Laravel
        MP_Creator_Webhook::send_product_sync($product_id, 'product_saved', $product_data);

        // Si le produit a une marque, vérifier le créateur associé
        if (!empty($brand_slug)) {
            $creator = $this->db->get_creator_by_brand($brand_slug);
            if ($creator) {
                update_post_meta($product_id, 'creator_id', $creator->id);
            }
        }

        // Marquer comme synchronisé
        update_post_meta($product_id, '_mp_last_sync', current_time('mysql'));
        update_post_meta($product_id, '_mp_sync_status', 'pending');
    }

    public function handle_product_before_save($product)
    {
        // Actions avant sauvegarde si nécessaire
    }

    public function handle_product_stock_change($product)
    {
        $product_id = $product->get_id();
        error_log("MP Creator: 📊 Stock changé pour produit #{$product_id}");

        $stock_data = [
            'stock_quantity' => $product->get_stock_quantity(),
            'stock_status' => $product->get_stock_status(),
            'manage_stock' => $product->get_manage_stock()
        ];

        // Envoyer mise à jour stock vers Laravel
        MP_Creator_Webhook::send_product_sync($product_id, 'stock_updated', $stock_data);
    }

    public function handle_product_price_change($product)
    {
        $product_id = $product->get_id();
        error_log("MP Creator: 💰 Prix changé pour produit #{$product_id}");

        $price_data = [
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price()
        ];

        MP_Creator_Webhook::send_product_sync($product_id, 'price_updated', $price_data);
    }

    public function handle_product_deleted($product_id)
    {
        error_log("MP Creator: 🗑️ Produit #{$product_id} supprimé");

        MP_Creator_Webhook::send_product_deleted($product_id);
    }

    private function detect_product_brand($product)
    {
        $product_id = $product->get_id();

        // 1. Vérifier les meta existantes
        $brand_meta = get_post_meta($product_id, 'brand_slug', true);
        if (!empty($brand_meta)) return $brand_meta;

        $brand_meta = get_post_meta($product_id, '_brand', true);
        if (!empty($brand_meta)) return sanitize_title($brand_meta);

        // 2. Vérifier la taxonomie product_brand
        if (taxonomy_exists('product_brand')) {
            $terms = wp_get_post_terms($product_id, 'product_brand');
            if (!empty($terms) && !is_wp_error($terms)) {
                return $terms[0]->slug;
            }
        }

        // 3. Vérifier la taxonomie brand
        if (taxonomy_exists('brand')) {
            $terms = wp_get_post_terms($product_id, 'brand');
            if (!empty($terms) && !is_wp_error($terms)) {
                return $terms[0]->slug;
            }
        }

        // 4. Vérifier les attributs
        $attributes = $product->get_attributes();
        foreach ($attributes as $attribute) {
            $attr_name = $attribute->get_name();
            if (strpos($attr_name, 'brand') !== false || strpos($attr_name, 'marque') !== false) {
                $options = $attribute->get_options();
                if (!empty($options)) {
                    return sanitize_title($options[0]);
                }
            }
        }

        return null;
    }

    // =============================================
    // GESTION DES MARQUES/TERMES
    // =============================================

    public function handle_brand_term_save($term_id, $tt_id, $taxonomy)
    {
        if (!in_array($taxonomy, ['product_brand', 'brand', 'pa_brand'])) {
            return;
        }

        $term = get_term($term_id, $taxonomy);
        if (!$term || is_wp_error($term)) return;

        error_log("MP Creator: 🏷️ Marque sauvegardée: {$term->name}");

        $brand_data = [
            'term_id' => $term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'description' => $term->description,
            'taxonomy' => $taxonomy
        ];

        MP_Creator_Webhook::send_brand_sync($brand_data, 'brand_saved');
    }

    public function handle_brand_term_delete($term_id, $tt_id, $taxonomy)
    {
        if (!in_array($taxonomy, ['product_brand', 'brand', 'pa_brand'])) {
            return;
        }

        error_log("MP Creator: 🗑️ Marque supprimée ID: {$term_id}");

        MP_Creator_Webhook::send_brand_deleted($term_id, $taxonomy);
    }

    // =============================================
    // FILTERS API
    // =============================================

    public function filter_product_api_response($response, $product, $request)
    {
        if (!isset($response->data)) {
            return $response;
        }

        $brand_slug = get_post_meta($product->get_id(), 'brand_slug', true);
        $creator = $brand_slug ? $this->db->get_creator_by_brand($brand_slug) : null;

        $response->data['brand_slug'] = $brand_slug;
        $response->data['creator_id'] = $creator ? $creator->id : null;
        $response->data['creator_name'] = $creator ? $creator->name : null;
        $response->data['last_sync'] = get_post_meta($product->get_id(), '_mp_last_sync', true);

        return $response;
    }

    public function filter_order_api_response($response, $order, $request)
    {
        if (!isset($response->data)) {
            return $response;
        }

        $creators = $this->db->get_creators_for_order($order->get_id());
        $creators_data = [];

        foreach ($creators as $creator) {
            $creator_total = $this->db->get_creator_order_total($creator->id, $order->get_id());
            $creators_data[] = [
                'id' => $creator->id,
                'name' => $creator->name,
                'email' => $creator->email,
                'brand_slug' => $creator->brand_slug,
                'total' => $creator_total
            ];
        }

        $response->data['creators'] = $creators_data;
        $response->data['creators_count'] = count($creators_data);

        return $response;
    }

    // =============================================
    // PAGES ADMIN
    // =============================================

    public function add_admin_pages()
    {
        add_menu_page(
            'MP Creators',
            'MP Creators',
            'manage_woocommerce',
            'mp-creators',
            [$this, 'render_admin_page'],
            'dashicons-groups',
            56
        );

        add_submenu_page(
            'mp-creators',
            'Settings',
            'Settings',
            'manage_options',
            'mp-creator-settings',
            [$this, 'render_settings_page']
        );

        add_submenu_page(
            'mp-creators',
            'Synchronisation',
            'Sync',
            'manage_options',
            'mp-creator-sync',
            [$this, 'render_sync_page']
        );

        add_submenu_page(
            'mp-creators',
            'Logs',
            'Logs',
            'manage_options',
            'mp-creator-logs',
            [$this, 'render_logs_page']
        );

        add_submenu_page(
            'mp-creators',
            'API Documentation',
            'API Docs',
            'manage_options',
            'mp-creator-api',
            [$this, 'render_api_docs']
        );
    }

    public function render_admin_page()
    {
        $errors = get_transient('mp_settings_errors');
        if ($errors) {
            delete_transient('mp_settings_errors');
            foreach ($errors as $error) {
                printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $error['type'], $error['message']);
            }
        }
    ?>
        <div class="wrap">
            <h1><?php _e('MP Creator Management', 'mp-creator-notifier'); ?></h1>

            <div class="mp-admin-header">
                <div class="mp-stats-cards">
                    <div class="mp-card">
                        <h3><?php _e('Total Creators', 'mp-creator-notifier'); ?></h3>
                        <p class="mp-stat"><?php echo $this->db->get_creators_count(); ?></p>
                    </div>
                    <div class="mp-card">
                        <h3><?php _e('Active Brands', 'mp-creator-notifier'); ?></h3>
                        <p class="mp-stat"><?php echo $this->db->get_active_brands_count(); ?></p>
                    </div>
                    <div class="mp-card">
                        <h3><?php _e('Products Synced', 'mp-creator-notifier'); ?></h3>
                        <p class="mp-stat"><?php echo $this->db->get_synced_products_count(); ?></p>
                    </div>
                    <div class="mp-card">
                        <h3><?php _e('Sync Health', 'mp-creator-notifier'); ?></h3>
                        <p class="mp-stat"><?php echo $this->db->get_sync_health_percentage(); ?>%</p>
                    </div>
                </div>
            </div>

            <div class="mp-admin-content">
                <div class="mp-tabs">
                    <!-- <nav class="nav-tab-wrapper">
                        <a href="#creators" class="nav-tab nav-tab-active"><?php _e('Creators', 'mp-creator-notifier'); ?></a>
                        <a href="#products" class="nav-tab"><?php _e('Products', 'mp-creator-notifier'); ?></a>
                        <a href="#orders" class="nav-tab"><?php _e('Orders', 'mp-creator-notifier'); ?></a>
                        <a href="#sync" class="nav-tab"><?php _e('Sync Status', 'mp-creator-notifier'); ?></a>
                    </nav> -->

                    <div id="creators" class="mp-tab-content active">
                        <?php $this->render_creators_list(); ?>
                        <?php $this->render_creator_form(); ?>
                    </div>

                    <!-- <div id="products" class="mp-tab-content">
                        <?php $this->render_products_list(); ?>
                    </div>

                    <div id="orders" class="mp-tab-content">
                        <?php $this->render_orders_list(); ?>
                    </div>

                    <div id="sync" class="mp-tab-content">
                        <?php $this->render_sync_status(); ?>
                    </div> -->
                </div>
            </div>

            <style>
                .mp-stats-cards {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                    gap: 20px;
                    margin-bottom: 30px;
                }

                .mp-card {
                    background: #fff;
                    padding: 20px;
                    border-radius: 8px;
                    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                    text-align: center;
                }

                .mp-delete-btn {
                    color: #dc3232;
                    text-decoration: none;
                    padding: 4px 8px;
                    border-radius: 3px;
                }

                .mp-delete-btn:hover {
                    background: #dc3232;
                    color: #fff;
                }

                .mp-stat {
                    font-size: 32px;
                    font-weight: bold;
                    margin: 10px 0;
                    color: #2271b1;
                }

                .mp-tab-content {
                    display: none;
                    background: #fff;
                    padding: 20px;
                    border-radius: 0 8px 8px 8px;
                    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                }

                .mp-tab-content.active {
                    display: block;
                }

                .mp-creator-table {
                    width: 100%;
                    border-collapse: collapse;
                }

                .mp-creator-table th {
                    background: #f1f1f1;
                    padding: 12px;
                    text-align: left;
                }

                .mp-creator-table td {
                    padding: 12px;
                    border-bottom: 1px solid #eee;
                }

                .sync-badge {
                    display: inline-block;
                    padding: 3px 8px;
                    border-radius: 12px;
                    font-size: 12px;
                    font-weight: bold;
                }

                .sync-badge.success {
                    background: #d4edda;
                    color: #155724;
                }

                .sync-badge.pending {
                    background: #fff3cd;
                    color: #856404;
                }

                .sync-badge.failed {
                    background: #f8d7da;
                    color: #721c24;
                }

                .mp-progress-bar {
                    height: 20px;
                    background: #f0f0f1;
                    border-radius: 10px;
                    overflow: hidden;
                    margin: 10px 0;
                }

                .mp-progress-bar-fill {
                    height: 100%;
                    background: #2271b1;
                    color: white;
                    text-align: center;
                    line-height: 20px;
                    font-size: 11px;
                }
            </style>

            <script>
                jQuery(document).ready(function($) {
                    $('.nav-tab').on('click', function(e) {
                        e.preventDefault();
                        $('.nav-tab').removeClass('nav-tab-active');
                        $(this).addClass('nav-tab-active');
                        $('.mp-tab-content').removeClass('active');
                        $($(this).attr('href')).addClass('active');
                    });

                    // Auto-refresh des stats
                    setInterval(function() {
                        $.ajax({
                            url: ajaxurl,
                            method: 'POST',
                            data: {
                                action: 'mp_get_stats',
                                nonce: '<?php echo wp_create_nonce("mp_get_stats"); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    $('.mp-stat').each(function(index, el) {
                                        // Mettre à jour les stats
                                    });
                                }
                            }
                        });
                    }, 30000); // Toutes les 30 secondes
                });
                jQuery(document).ready(function($) {
                    // Gestion de la suppression de créateur
                    $('.mp-delete-creator').on('click', function(e) {
                        e.preventDefault();

                        if (!confirm('⚠️ Êtes-vous sûr de vouloir supprimer ce créateur ? Cette action est irréversible.')) {
                            return;
                        }

                        var creatorId = $(this).data('id');
                        var creatorName = $(this).data('name');
                        var row = $(this).closest('tr');

                        $.ajax({
                            url: ajaxurl,
                            method: 'POST',
                            data: {
                                action: 'mp_delete_creator',
                                nonce: '<?php echo wp_create_nonce("mp_delete_creator"); ?>',
                                creator_id: creatorId
                            },
                            beforeSend: function() {
                                row.css('opacity', '0.5');
                            },
                            success: function(response) {
                                if (response.success) {
                                    row.fadeOut(300, function() {
                                        $(this).remove();
                                    });
                                    alert('✅ Créateur "' + creatorName + '" supprimé avec succès.');
                                } else {
                                    alert('❌ Erreur: ' + response.data.message);
                                    row.css('opacity', '1');
                                }
                            },
                            error: function() {
                                alert('❌ Erreur lors de la suppression du créateur.');
                                row.css('opacity', '1');
                            }
                        });
                    });
                });
            </script>
        </div>
    <?php
    }

    public function render_creators_list()
    {
        global $wpdb;

        $has_wp_creator_id = $this->db->column_exists('creators', 'wp_creator_id');
        $creators = $wpdb->get_results("SELECT * FROM {$this->db->get_table_name('creators')} ORDER BY created_at DESC");

        if (empty($creators)) {
            echo '<p>' . __('No creators found. Click "Add New Creator" to get started.', 'mp-creator-notifier') . '</p>';
            return;
        }

        echo '<button type="button" class="button button-primary button-large mp-add-creator-btn" style="margin-bottom: 20px;">';
        echo '<span class="dashicons dashicons-plus-alt"></span> ' . __('Add New Creator', 'mp-creator-notifier');
        echo '</button>';

        echo '<table class="mp-creator-table">';
        echo '<thead><tr>';
        echo '<th>ID</th>';
        if ($has_wp_creator_id) {
            echo '<th>ID Laravel</th>';
        }
        echo '<th>Name</th>';
        echo '<th>Email</th>';
        echo '<th>Brand</th>';
        echo '<th>Status</th>';
        echo '<th>Actions</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($creators as $creator) {
            $product_count = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$wpdb->postmeta} 
                WHERE meta_key = 'brand_slug' AND meta_value = %s
            ", $creator->brand_slug));

            echo '<tr>';
            echo '<td>' . esc_html($creator->id) . '</td>';

            if ($has_wp_creator_id) {
                $wp_creator_id = isset($creator->wp_creator_id) ? $creator->wp_creator_id : '';
                echo '<td>' . esc_html($wp_creator_id ?: '-') . '</td>';
            }

            echo '<td>' . esc_html($creator->name) . '</td>';
            echo '<td>' . esc_html($creator->email) . '</td>';
            echo '<td><span class="mp-badge">' . esc_html($creator->brand_slug) . '</span></td>';
            echo '<td>';
            if ($creator->status === 'active') {
                echo '<span style="color: green;">✓ Active</span>';
            } else {
                echo '<span style="color: red;">✗ Inactive</span>';
            }
            echo '</td>';
            echo '<td>';
            echo '<a href="#" class="mp-delete-creator mp-delete-btn" data-id="' . esc_attr($creator->id) . '" data-name="' . esc_attr($creator->name) . '" title="Supprimer">';
            echo '<span class="dashicons dashicons-trash"></span> Supprimer';
            echo '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    public function render_products_list()
    {
        global $wpdb;

        $products = $wpdb->get_results("
            SELECT p.ID, p.post_title, pm.meta_value as brand_slug, p.post_modified
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'brand_slug'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            ORDER BY p.post_modified DESC
            LIMIT 100
        ");

        if (empty($products)) {
            echo '<p>' . __('No products found.', 'mp-creator-notifier') . '</p>';
            return;
        }

        echo '<table class="mp-creator-table">';
        echo '<thead><tr><th>ID</th><th>Product</th><th>Brand</th><th>Creator</th><th>Price</th><th>Stock</th><th>Last Sync</th><th>Actions</th></tr></thead>';
        echo '<tbody>';

        foreach ($products as $product) {
            $creator = $this->db->get_creator_by_brand($product->brand_slug);
            $sync_status = get_post_meta($product->ID, '_mp_sync_status', true);
            $sync_time = get_post_meta($product->ID, '_mp_last_sync', true);
            $wc_product = wc_get_product($product->ID);

            $status_class = 'pending';
            $status_text = 'Pending';

            if ($sync_status === 'success') {
                $status_class = 'success';
                $status_text = 'Synced';
            } elseif ($sync_status === 'failed') {
                $status_class = 'failed';
                $status_text = 'Failed';
            }

            echo '<tr>';
            echo '<td>' . $product->ID . '</td>';
            echo '<td>' . esc_html($product->post_title) . '</td>';
            echo '<td>' . esc_html($product->brand_slug ?: '-') . '</td>';
            echo '<td>' . ($creator ? esc_html($creator->name) : '-') . '</td>';
            echo '<td>' . ($wc_product ? wc_price($wc_product->get_price()) : '-') . '</td>';
            echo '<td>' . ($wc_product ? $wc_product->get_stock_quantity() : '-') . '</td>';
            echo '<td><span class="sync-badge ' . $status_class . '">' . $status_text . '</span> ' . ($sync_time ? '<br><small>' . date_i18n(get_option('date_format') . ' H:i', strtotime($sync_time)) . '</small>' : '') . '</td>';
            echo '<td><button class="button button-small mp-sync-product" data-id="' . $product->ID . '">Sync Now</button></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    public function render_orders_list()
    {
        $args = [
            'limit' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
            'return' => 'objects'
        ];

        $orders = wc_get_orders($args);

        if (empty($orders)) {
            echo '<p>' . __('No orders found.', 'mp-creator-notifier') . '</p>';
            return;
        }

        echo '<table class="mp-creator-table">';
        echo '<thead><tr><th>Order ID</th><th>Date</th><th>Customer</th><th>Total</th><th>Status</th><th>Creators</th><th>Actions</th></tr></thead>';
        echo '<tbody>';

        foreach ($orders as $order) {
            $creators = $this->db->get_creators_for_order($order->get_id());
            $creators_count = count($creators);

            echo '<tr>';
            echo '<td>#' . $order->get_id() . '</td>';
            echo '<td>' . $order->get_date_created()->date('d/m/Y H:i') . '</td>';
            echo '<td>' . $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() . '</td>';
            echo '<td>' . wc_price($order->get_total()) . '</td>';
            echo '<td>' . $order->get_status() . '</td>';
            echo '<td>' . $creators_count . ' créateur(s)</td>';
            echo '<td><button class="button button-small mp-sync-order" data-id="' . $order->get_id() . '">Sync</button></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    public function render_creator_form()
    {
        $brands = $this->get_available_brands();
    ?>
        <div id="mp-creator-form-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999999;">
            <div style="max-width: 600px; margin: 50px auto; background: #fff; border-radius: 8px; padding: 30px;">
                <h2><?php _e('Add New Creator', 'mp-creator-notifier'); ?></h2>

                <form method="post" action="">
                    <?php wp_nonce_field('mp_create_creator', 'mp_creator_nonce'); ?>

                    <table class="form-table">
                        <tr>
                            <th><label for="creator_name"><?php _e('Name', 'mp-creator-notifier'); ?> *</label></th>
                            <td><input type="text" id="creator_name" name="creator_name" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="creator_email"><?php _e('Email', 'mp-creator-notifier'); ?> *</label></th>
                            <td><input type="email" id="creator_email" name="creator_email" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="creator_phone"><?php _e('Phone', 'mp-creator-notifier'); ?></label></th>
                            <td><input type="tel" id="creator_phone" name="creator_phone" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="creator_brand">Brand *</label></th>
                            <td>
                                <select id="creator_brand" name="creator_brand" class="regular-text" required>
                                    <option value="">-- Select a Brand --</option>
                                    <?php foreach ($brands as $brand): ?>
                                        <option value="<?php echo esc_attr($brand['slug']); ?>">
                                            <?php echo esc_html($brand['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description" style="margin-top: 8px; color: #666; font-style: italic;">
                                    ℹ️ Veuillez créer une marque dans <strong>Produits → Marques</strong> avant de créer un créateur
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="creator_address"><?php _e('Address', 'mp-creator-notifier'); ?></label></th>
                            <td><textarea id="creator_address" name="creator_address" rows="3" class="large-text"></textarea></td>
                        </tr>
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
                $('.mp-add-creator-btn').on('click', function() {
                    $('#mp-creator-form-modal').fadeIn();
                });
                $('.mp-close-modal').on('click', function() {
                    $('#mp-creator-form-modal').fadeOut();
                });

                $('#mp-create-new-brand').on('change', function() {
                    if ($(this).is(':checked')) {
                        $('#mp-manual-brand-input').slideDown();
                        $('#creator_brand').prop('required', false);
                    } else {
                        $('#mp-manual-brand-input').slideUp();
                        $('#creator_brand').prop('required', true);
                    }
                });

                $('.mp-sync-product').on('click', function() {
                    var product_id = $(this).data('id');
                    var button = $(this);

                    button.prop('disabled', true).text('Syncing...');

                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'mp_manual_sync',
                            nonce: '<?php echo wp_create_nonce("mp_manual_sync"); ?>',
                            type: 'product',
                            id: product_id
                        },
                        success: function(response) {
                            if (response.success) {
                                button.text('✓ Synced').css('background', '#d4edda');
                                setTimeout(function() {
                                    location.reload();
                                }, 1000);
                            } else {
                                button.text('✗ Failed').css('background', '#f8d7da');
                                setTimeout(function() {
                                    button.prop('disabled', false).text('Sync Now').css('background', '');
                                }, 2000);
                            }
                        },
                        error: function() {
                            button.text('✗ Error').css('background', '#f8d7da');
                            setTimeout(function() {
                                button.prop('disabled', false).text('Sync Now').css('background', '');
                            }, 2000);
                        }
                    });
                });

                $('.mp-sync-order').on('click', function() {
                    var order_id = $(this).data('id');
                    var button = $(this);

                    button.prop('disabled', true).text('Syncing...');

                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'mp_manual_sync',
                            nonce: '<?php echo wp_create_nonce("mp_manual_sync"); ?>',
                            type: 'order',
                            id: order_id
                        },
                        success: function(response) {
                            if (response.success) {
                                button.text('✓ Synced').css('background', '#d4edda');
                                setTimeout(function() {
                                    button.prop('disabled', false).text('Sync');
                                }, 2000);
                            } else {
                                button.text('✗ Failed').css('background', '#f8d7da');
                                setTimeout(function() {
                                    button.prop('disabled', false).text('Sync');
                                }, 2000);
                            }
                        }
                    });
                });
            });
        </script>
    <?php
    }

    public function render_sync_status()
    {
        global $wpdb;

        $webhook_logs = $wpdb->get_results("
            SELECT * FROM {$this->db->get_table_name('webhooks')}
            ORDER BY created_at DESC
            LIMIT 50
        ");

        // Calculer les stats de sync
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
            FROM {$this->db->get_table_name('webhooks')}
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");

        $success_rate = $stats->total > 0 ? round(($stats->success / $stats->total) * 100) : 100;

    ?>
        <h2><?php _e('Sync Health (Last 24h)', 'mp-creator-notifier'); ?></h2>

        <div class="mp-stats-cards" style="grid-template-columns: repeat(4, 1fr);">
            <div class="mp-card">
                <h3>Total</h3>
                <p class="mp-stat"><?php echo intval($stats->total); ?></p>
            </div>
            <div class="mp-card">
                <h3>Success</h3>
                <p class="mp-stat" style="color: #4caf50;"><?php echo intval($stats->success); ?></p>
            </div>
            <div class="mp-card">
                <h3>Failed</h3>
                <p class="mp-stat" style="color: #f44336;"><?php echo intval($stats->failed); ?></p>
            </div>
            <div class="mp-card">
                <h3>Success Rate</h3>
                <p class="mp-stat"><?php echo $success_rate; ?>%</p>
            </div>
        </div>

        <div class="mp-progress-bar">
            <div class="mp-progress-bar-fill" style="width: <?php echo $success_rate; ?>%;">
                <?php echo $success_rate; ?>%
            </div>
        </div>

        <h2><?php _e('Recent Synchronizations', 'mp-creator-notifier'); ?></h2>

        <?php if (empty($webhook_logs)): ?>
            <p><?php _e('No synchronization logs yet.', 'mp-creator-notifier'); ?></p>
        <?php else: ?>
            <table class="mp-creator-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Event Type</th>
                        <th>Order ID</th>
                        <th>Creator ID</th>
                        <th>Status</th>
                        <th>Response Code</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($webhook_logs as $log): ?>
                        <tr>
                            <td><?php echo date_i18n(get_option('date_format') . ' H:i:s', strtotime($log->created_at)); ?></td>
                            <td><?php echo esc_html($log->event_type); ?></td>
                            <td><?php echo $log->order_id ?: '-'; ?></td>
                            <td><?php echo $log->creator_id ?: '-'; ?></td>
                            <td>
                                <span class="sync-badge <?php echo $log->status === 'success' ? 'success' : ($log->status === 'pending' ? 'pending' : 'failed'); ?>">
                                    <?php echo ucfirst($log->status); ?>
                                </span>
                            </td>
                            <td><?php echo $log->response_code ?: '-'; ?></td>
                            <td><?php echo esc_html(substr($log->error_message ?: '-', 0, 50)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2><?php _e('Manual Synchronization', 'mp-creator-notifier'); ?></h2>

        <form method="post" action="">
            <?php wp_nonce_field('mp_manual_sync', 'mp_sync_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Sync Type', 'mp-creator-notifier'); ?></th>
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
                    <th scope="row"><?php _e('Date Range', 'mp-creator-notifier'); ?></th>
                    <td>
                        <select name="date_range">
                            <option value="today"><?php _e('Today', 'mp-creator-notifier'); ?></option>
                            <option value="week"><?php _e('Last 7 days', 'mp-creator-notifier'); ?></option>
                            <option value="month"><?php _e('Last 30 days', 'mp-creator-notifier'); ?></option>
                            <option value="all"><?php _e('All time', 'mp-creator-notifier'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Force Sync', 'mp-creator-notifier'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="force_sync" value="1">
                            <?php _e('Force synchronization even if already synced', 'mp-creator-notifier'); ?>
                        </label>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" name="mp_manual_sync" class="button button-primary">
                    <?php _e('Start Synchronization', 'mp-creator-notifier'); ?>
                </button>
                <button type="button" id="mp-force-full-sync" class="button button-secondary">
                    <?php _e('Force Full Sync', 'mp-creator-notifier'); ?>
                </button>
            </p>
        </form>

        <script>
            jQuery(document).ready(function($) {
                $('#mp-force-full-sync').on('click', function() {
                    if (confirm('⚠️ This will force a full synchronization of all data. Continue?')) {
                        var button = $(this);
                        button.prop('disabled', true).text('Syncing...');

                        $.ajax({
                            url: ajaxurl,
                            method: 'POST',
                            data: {
                                action: 'mp_force_full_sync',
                                nonce: '<?php echo wp_create_nonce("mp_force_full_sync"); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    alert('Full sync initiated successfully!');
                                    location.reload();
                                } else {
                                    alert('Error: ' + response.data.message);
                                    button.prop('disabled', false).text('Force Full Sync');
                                }
                            },
                            error: function() {
                                alert('Connection error');
                                button.prop('disabled', false).text('Force Full Sync');
                            }
                        });
                    }
                });
            });
        </script>
    <?php
    }

    public function render_logs_page()
    {
        global $wpdb;

        $webhook_logs = $wpdb->get_results("
            SELECT * FROM {$this->db->get_table_name('webhooks')}
            ORDER BY created_at DESC
            LIMIT 200
        ");

        $notification_logs = $wpdb->get_results("
            SELECT n.*, c.name as creator_name
            FROM {$this->db->get_table_name('notifications')} n
            LEFT JOIN {$this->db->get_table_name('creators')} c ON n.creator_id = c.id
            ORDER BY n.sent_at DESC
            LIMIT 100
        ");

    ?>
        <div class="wrap">
            <h1><?php _e('Synchronization Logs', 'mp-creator-notifier'); ?></h1>

            <h2><?php _e('Webhook Logs', 'mp-creator-notifier'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Date</th>
                        <th>Event</th>
                        <th>Order ID</th>
                        <th>Creator ID</th>
                        <th>Status</th>
                        <th>Response</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($webhook_logs as $log): ?>
                        <tr>
                            <td><?php echo $log->id; ?></td>
                            <td><?php echo $log->created_at; ?></td>
                            <td><?php echo $log->event_type; ?></td>
                            <td><?php echo $log->order_id ?: '-'; ?></td>
                            <td><?php echo $log->creator_id ?: '-'; ?></td>
                            <td>
                                <span class="sync-badge <?php echo $log->status; ?>">
                                    <?php echo $log->status; ?>
                                </span>
                            </td>
                            <td><?php echo $log->response_code ?: '-'; ?></td>
                            <td><?php echo esc_html($log->error_message ?: '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2><?php _e('Email Notifications', 'mp-creator-notifier'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Date</th>
                        <th>Creator</th>
                        <th>Order ID</th>
                        <th>Subject</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($notification_logs as $log): ?>
                        <tr>
                            <td><?php echo $log->id; ?></td>
                            <td><?php echo $log->sent_at; ?></td>
                            <td><?php echo $log->creator_name ?: 'N/A'; ?></td>
                            <td>#<?php echo $log->order_id; ?></td>
                            <td><?php echo esc_html($log->subject); ?></td>
                            <td>
                                <span class="sync-badge <?php echo $log->status; ?>">
                                    <?php echo $log->status; ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php
    }

    private function get_available_brands()
    {
        $brands = [];

        if (taxonomy_exists('product_brand')) {
            $terms = get_terms(['taxonomy' => 'product_brand', 'hide_empty' => false]);
            foreach ($terms as $term) {
                $brands[] = ['slug' => $term->slug, 'name' => $term->name];
            }
        }

        if (taxonomy_exists('brand')) {
            $terms = get_terms(['taxonomy' => 'brand', 'hide_empty' => false]);
            foreach ($terms as $term) {
                $brands[] = ['slug' => $term->slug, 'name' => $term->name];
            }
        }

        return $brands;
    }

    private function handle_manual_sync()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $sync_type = $_POST['sync_type'] ?? 'all';
        $date_range = $_POST['date_range'] ?? 'all';
        $force_sync = isset($_POST['force_sync']);

        $message = '';

        switch ($sync_type) {
            case 'creators':
                MP_Creator_Webhook::send_full_sync_request('creators');
                $message = __('Creators sync initiated', 'mp-creator-notifier');
                break;
            case 'products':
                if ($date_range === 'all') {
                    MP_Creator_Webhook::send_full_sync_request('products');
                } else {
                    $this->sync_recent_products($this->get_hours_from_range($date_range));
                }
                $message = __('Products sync initiated', 'mp-creator-notifier');
                break;
            case 'orders':
                if ($date_range === 'all') {
                    MP_Creator_Webhook::send_full_sync_request('orders');
                } else {
                    $this->sync_recent_orders($this->get_hours_from_range($date_range));
                }
                $message = __('Orders sync initiated', 'mp-creator-notifier');
                break;
            default:
                MP_Creator_Webhook::send_full_sync_request('all');
                $message = __('Full sync initiated', 'mp-creator-notifier');
        }

        $this->add_admin_notice($message, 'success');

        wp_redirect(admin_url('admin.php?page=mp-creator-sync'));
        exit;
    }

    private function get_hours_from_range($range)
    {
        switch ($range) {
            case 'today':
                return 24;
            case 'week':
                return 168;
            case 'month':
                return 720;
            default:
                return 24;
        }
    }

    // =============================================
    // SETTINGS
    // =============================================

    public function register_settings()
    {
        register_setting('mp_creator_settings', 'mp_laravel_webhook_url', ['sanitize_callback' => 'esc_url_raw']);
        register_setting('mp_creator_settings', 'mp_laravel_webhook_secret', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('mp_creator_settings', 'mp_notify_on_status', ['sanitize_callback' => [$this, 'sanitize_array']]);
        register_setting('mp_creator_settings', 'mp_email_template', ['sanitize_callback' => 'wp_kses_post']);
        register_setting('mp_creator_settings', 'mp_notify_creators_on_order', ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('mp_creator_settings', 'mp_sync_on_order_update', ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('mp_creator_settings', 'mp_sync_on_product_update', ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('mp_creator_settings', 'mp_auto_sync_interval', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('mp_creator_settings', 'mp_sync_products_on_creator_creation', ['sanitize_callback' => 'rest_sanitize_boolean']);
    }

    public function sanitize_array($input)
    {
        return is_array($input) ? array_map('sanitize_text_field', $input) : [];
    }

    public function render_settings_page()
    {
        if (isset($_POST['mp_generate_token']) && check_admin_referer('mp_generate_token')) {
            $new_token = MP_Creator_API_Token::create_and_store();
            add_settings_error('mp_creator_messages', 'token_generated', sprintf(__('New token generated. Save this: %s', 'mp-creator-notifier'), $new_token), 'success');
        }
    ?>
        <div class="wrap">
            <h1><?php _e('MP Creator Settings', 'mp-creator-notifier'); ?></h1>
            <?php settings_errors('mp_creator_messages'); ?>

            <form method="post" action="options.php">
                <?php settings_fields('mp_creator_settings'); ?>

                <div class="mp-settings-section" style="background: #fff; padding: 20px; margin-bottom: 20px; border-radius: 8px;">
                    <h2><?php _e('Laravel Integration', 'mp-creator-notifier'); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th><label for="mp_laravel_webhook_url"><?php _e('Laravel Webhook URL', 'mp-creator-notifier'); ?></label></th>
                            <td>
                                <input type="url" id="mp_laravel_webhook_url" name="mp_laravel_webhook_url"
                                    value="<?php echo esc_url(get_option('mp_laravel_webhook_url', '')); ?>" class="regular-text" placeholder="https://votre-site.com/api/webhooks/wordpress">
                                <p class="description">URL de base pour les webhooks Laravel</p>
                            </td>
                        </tr>

                        <tr>
                            <th><label for="mp_laravel_webhook_secret"><?php _e('Webhook Secret', 'mp-creator-notifier'); ?></label></th>
                            <td>
                                <input type="password" id="mp_laravel_webhook_secret" name="mp_laravel_webhook_secret"
                                    value="<?php echo esc_attr(get_option('mp_laravel_webhook_secret', '')); ?>" class="regular-text">
                                <p class="description"><?php _e('Same as WORDPRESS_WEBHOOK_SECRET in your Laravel .env', 'mp-creator-notifier'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th></th>
                            <td>
                                <button type="button" id="mp-test-connection" class="button button-secondary">
                                    <?php _e('Test Connection', 'mp-creator-notifier'); ?>
                                </button>
                                <span id="mp-connection-result" style="margin-left: 10px;"></span>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="mp-settings-section" style="background: #fff; padding: 20px; margin-bottom: 20px; border-radius: 8px;">
                    <h2><?php _e('Synchronization Settings', 'mp-creator-notifier'); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Auto Sync Interval', 'mp-creator-notifier'); ?></th>
                            <td>
                                <select name="mp_auto_sync_interval">
                                    <option value="hourly" <?php selected(get_option('mp_auto_sync_interval'), 'hourly'); ?>><?php _e('Hourly', 'mp-creator-notifier'); ?></option>
                                    <option value="daily" <?php selected(get_option('mp_auto_sync_interval'), 'daily'); ?>><?php _e('Daily', 'mp-creator-notifier'); ?></option>
                                    <option value="weekly" <?php selected(get_option('mp_auto_sync_interval'), 'weekly'); ?>><?php _e('Weekly', 'mp-creator-notifier'); ?></option>
                                    <option value="disabled" <?php selected(get_option('mp_auto_sync_interval'), 'disabled'); ?>><?php _e('Disabled', 'mp-creator-notifier'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Sync on Order Update', 'mp-creator-notifier'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="mp_sync_on_order_update" value="1" <?php checked(get_option('mp_sync_on_order_update', true)); ?>>
                                    <?php _e('Send webhook when order is updated', 'mp-creator-notifier'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Sync on Product Update', 'mp-creator-notifier'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="mp_sync_on_product_update" value="1" <?php checked(get_option('mp_sync_on_product_update', true)); ?>>
                                    <?php _e('Send webhook when product is updated', 'mp-creator-notifier'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Sync Products on Creator Creation', 'mp-creator-notifier'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="mp_sync_products_on_creator_creation" value="1" <?php checked(get_option('mp_sync_products_on_creator_creation', true)); ?>>
                                    <?php _e('Automatically sync products when a creator is created', 'mp-creator-notifier'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="mp-settings-section" style="background: #fff; padding: 20px; margin-bottom: 20px; border-radius: 8px;">
                    <h2><?php _e('Notification Settings', 'mp-creator-notifier'); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Notify on Order Status', 'mp-creator-notifier'); ?></th>
                            <td>
                                <?php
                                $statuses = wc_get_order_statuses();
                                $selected = get_option('mp_notify_on_status', ['processing', 'completed']);
                                foreach ($statuses as $key => $label):
                                    $clean_key = str_replace('wc-', '', $key);
                                ?>
                                    <label style="display: block; margin-bottom: 5px;">
                                        <input type="checkbox" name="mp_notify_on_status[]" value="<?php echo esc_attr($clean_key); ?>" <?php checked(in_array($clean_key, $selected)); ?>>
                                        <?php echo esc_html($label); ?>
                                    </label>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Notify Creators on Order', 'mp-creator-notifier'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="mp_notify_creators_on_order" value="1" <?php checked(get_option('mp_notify_creators_on_order', true)); ?>>
                                    <?php _e('Send email notifications to creators when their products are ordered', 'mp-creator-notifier'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mp_email_template"><?php _e('Email Template', 'mp-creator-notifier'); ?></label></th>
                            <td>
                                <textarea id="mp_email_template" name="mp_email_template" rows="8" class="large-text code"><?php echo esc_textarea(get_option('mp_email_template', $this->get_default_email_template())); ?></textarea>
                                <p class="description">
                                    <?php _e('Available variables:', 'mp-creator-notifier'); ?>
                                    <code>{creator_name}</code>, <code>{order_id}</code>, <code>{order_date}</code>,
                                    <code>{order_total}</code>, <code>{products_list}</code>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="mp-settings-section" style="background: #fff; padding: 20px; margin-bottom: 20px; border-radius: 8px;">
                    <h2><?php _e('API Token', 'mp-creator-notifier'); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th><label><?php _e('Current Token', 'mp-creator-notifier'); ?></label></th>
                            <td>
                                <?php if (get_option('mp_api_token_hash')): ?>
                                    <span style="color: green;">✓ <?php _e('Configured', 'mp-creator-notifier'); ?></span>
                                    <p class="description">Token created: <?php echo get_option('mp_api_token_created_at'); ?></p>
                                <?php else: ?>
                                    <span style="color: red;">✗ <?php _e('Not configured', 'mp-creator-notifier'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>

                    <form method="post" action="">
                        <?php wp_nonce_field('mp_generate_token'); ?>
                        <button type="submit" name="mp_generate_token" class="button button-primary">
                            <?php _e('Generate New Token', 'mp-creator-notifier'); ?>
                        </button>
                    </form>
                </div>

                <?php submit_button(__('Save Settings', 'mp-creator-notifier')); ?>
            </form>

            <script>
                jQuery(document).ready(function($) {
                    $('#mp-test-connection').on('click', function() {
                        var button = $(this);
                        var result = $('#mp-connection-result');
                        var url = $('#mp_laravel_webhook_url').val();
                        var secret = $('#mp_laravel_webhook_secret').val();

                        if (!url || !secret) {
                            result.html('<span style="color: red;">Please fill URL and Secret first</span>');
                            return;
                        }

                        button.prop('disabled', true).text('Testing...');
                        result.html('');

                        $.ajax({
                            url: ajaxurl,
                            method: 'POST',
                            data: {
                                action: 'mp_test_laravel_connection',
                                nonce: '<?php echo wp_create_nonce("mp_test_connection"); ?>',
                                url: url,
                                secret: secret
                            },
                            success: function(response) {
                                if (response.success) {
                                    result.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                                } else {
                                    result.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                                }
                            },
                            error: function() {
                                result.html('<span style="color: red;">✗ Connection failed</span>');
                            },
                            complete: function() {
                                button.prop('disabled', false).text('Test Connection');
                            }
                        });
                    });
                });
            </script>
        </div>
    <?php
    }

    public function render_sync_page()
    {
    ?>
        <div class="wrap">
            <h1><?php _e('Manual Synchronization', 'mp-creator-notifier'); ?></h1>

            <div class="mp-sync-options" style="background: #fff; padding: 20px; border-radius: 8px;">
                <h2><?php _e('Sync Options', 'mp-creator-notifier'); ?></h2>

                <form method="post" action="">
                    <?php wp_nonce_field('mp_manual_sync', 'mp_sync_nonce'); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Sync Type', 'mp-creator-notifier'); ?></th>
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
                            <th scope="row"><?php _e('Date Range', 'mp-creator-notifier'); ?></th>
                            <td>
                                <select name="date_range">
                                    <option value="today"><?php _e('Today', 'mp-creator-notifier'); ?></option>
                                    <option value="week"><?php _e('Last 7 days', 'mp-creator-notifier'); ?></option>
                                    <option value="month"><?php _e('Last 30 days', 'mp-creator-notifier'); ?></option>
                                    <option value="all"><?php _e('All time', 'mp-creator-notifier'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Force Sync', 'mp-creator-notifier'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="force_sync" value="1">
                                    <?php _e('Force synchronization even if already synced', 'mp-creator-notifier'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" name="mp_manual_sync" class="button button-primary button-large">
                            <?php _e('Start Synchronization', 'mp-creator-notifier'); ?>
                        </button>
                    </p>
                </form>
            </div>

            <div class="mp-sync-progress" style="background: #fff; padding: 20px; margin-top: 20px; border-radius: 8px; display: none;">
                <h2><?php _e('Progress', 'mp-creator-notifier'); ?></h2>
                <div class="mp-progress-bar">
                    <div class="mp-progress-bar-fill" style="width: 0%;">0%</div>
                </div>
                <p id="mp-sync-status">Initializing...</p>
            </div>

            <div class="mp-sync-logs" style="background: #fff; padding: 20px; margin-top: 20px; border-radius: 8px;">
                <h2><?php _e('Recent Sync Logs', 'mp-creator-notifier'); ?></h2>
                <?php $this->render_sync_status(); ?>
            </div>
        </div>
    <?php
    }

    public function render_api_docs()
    {
    ?>
        <div class="wrap">
            <h1><?php _e('API Documentation', 'mp-creator-notifier'); ?></h1>

            <div class="notice notice-info">
                <p><strong>Base URL:</strong> <code><?php echo esc_url(rest_url('mp/v2')); ?></code></p>
                <p><strong>Authentication:</strong> <code>X-MP-Token: YOUR_TOKEN</code></p>
            </div>

            <h2><?php _e('Available Endpoints', 'mp-creator-notifier'); ?></h2>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Endpoint</th>
                        <th>Method</th>
                        <th>Description</th>
                        <th>Parameters</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>/creators</code></td>
                        <td>GET</td>
                        <td>List all creators</td>
                        <td>page, per_page, search</td>
                    </tr>
                    <tr>
                        <td><code>/creators/{id}</code></td>
                        <td>GET</td>
                        <td>Get single creator</td>
                        <td>-</td>
                    </tr>
                    <tr>
                        <td><code>/creators</code></td>
                        <td>POST</td>
                        <td>Create creator</td>
                        <td>name, email, brand_slug, phone, address</td>
                    </tr>
                    <tr>
                        <td><code>/creators/{id}</code></td>
                        <td>PUT</td>
                        <td>Update creator</td>
                        <td>name, email, brand_slug, etc.</td>
                    </tr>
                    <tr>
                        <td><code>/creators/{id}</code></td>
                        <td>DELETE</td>
                        <td>Delete creator</td>
                        <td>-</td>
                    </tr>
                    <tr>
                        <td><code>/products/brands-bulk</code></td>
                        <td>POST</td>
                        <td>Get brands for multiple products</td>
                        <td>product_ids array</td>
                    </tr>
                    <tr>
                        <td><code>/products/creators</code></td>
                        <td>POST</td>
                        <td>Get creators for multiple products</td>
                        <td>product_ids array</td>
                    </tr>
                    <tr>
                        <td><code>/orders</code></td>
                        <td>GET</td>
                        <td>List orders</td>
                        <td>page, per_page, status, after</td>
                    </tr>
                    <tr>
                        <td><code>/orders/{id}</code></td>
                        <td>GET</td>
                        <td>Get single order</td>
                        <td>-</td>
                    </tr>
                    <tr>
                        <td><code>/system/test</code></td>
                        <td>GET</td>
                        <td>Test API connection</td>
                        <td>-</td>
                    </tr>
                    <tr>
                        <td><code>/system/stats</code></td>
                        <td>GET</td>
                        <td>Get system statistics</td>
                        <td>-</td>
                    </tr>
                </tbody>
            </table>

            <h2><?php _e('Example Requests', 'mp-creator-notifier'); ?></h2>

            <h3>cURL</h3>
            <pre><code>curl -H "X-MP-Token: YOUR_TOKEN" <?php echo esc_url(rest_url('mp/v2/creators')); ?></code></pre>

            <h3>PHP</h3>
            <pre><code>$response = wp_remote_get('<?php echo esc_url(rest_url('mp/v2/creators')); ?>', [
    'headers' => [
        'X-MP-Token' => 'YOUR_TOKEN'
    ]
]);</code></pre>

            <h3>JavaScript</h3>
            <pre><code>fetch('<?php echo esc_url(rest_url('mp/v2/creators')); ?>', {
    headers: {
        'X-MP-Token': 'YOUR_TOKEN'
    }
})
.then(response => response.json())
.then(data => console.log(data));</code></pre>
        </div>
        <?php
    }

    // =============================================
    // API REST
    // =============================================

    public function register_rest_routes()
    {
        // Creators endpoints
        register_rest_route('mp/v2', '/creators', [
            'methods' => 'GET',
            'callback' => [$this, 'api_get_creators'],
            'permission_callback' => [$this, 'verify_api_auth']
        ]);

        register_rest_route('mp/v2', '/creators', [
            'methods' => 'POST',
            'callback' => [$this, 'api_create_creator'],
            'permission_callback' => [$this, 'verify_api_auth']
        ]);

        register_rest_route('mp/v2', '/creators/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'api_get_creator'],
            'permission_callback' => [$this, 'verify_api_auth']
        ]);

        register_rest_route('mp/v2', '/creators/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [$this, 'api_update_creator'],
            'permission_callback' => [$this, 'verify_api_auth']
        ]);

        register_rest_route('mp/v2', '/creators/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'api_delete_creator'],
            'permission_callback' => [$this, 'verify_api_auth']
        ]);

        // Products endpoints
        register_rest_route('mp/v2', '/products/brands-bulk', [
            'methods' => 'POST',
            'callback' => [$this, 'api_get_products_brands'],
            'permission_callback' => [$this, 'verify_api_auth']
        ]);

        register_rest_route('mp/v2', '/products/creators', [
            'methods' => 'POST',
            'callback' => [$this, 'api_get_products_creators'],
            'permission_callback' => [$this, 'verify_api_auth']
        ]);

        // Orders endpoints
        register_rest_route('mp/v2', '/orders', [
            'methods' => 'GET',
            'callback' => [$this, 'api_get_orders'],
            'permission_callback' => [$this, 'verify_api_auth']
        ]);

        register_rest_route('mp/v2', '/orders/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'api_get_order'],
            'permission_callback' => [$this, 'verify_api_auth']
        ]);

        // System endpoints
        register_rest_route('mp/v2', '/system/test', [
            'methods' => 'GET',
            'callback' => [$this, 'api_test'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('mp/v2', '/system/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'api_stats'],
            'permission_callback' => [$this, 'verify_api_auth']
        ]);

        // Sync endpoints (pour Laravel)
        register_rest_route('mp/v2', '/sync/creators', [
            'methods' => 'POST',
            'callback' => [$this, 'api_sync_creators'],
            'permission_callback' => [$this, 'verify_api_auth']
        ]);

        register_rest_route('mp/v2', '/sync/products', [
            'methods' => 'POST',
            'callback' => [$this, 'api_sync_products'],
            'permission_callback' => [$this, 'verify_api_auth']
        ]);

        register_rest_route('mp/v2', '/sync/orders', [
            'methods' => 'POST',
            'callback' => [$this, 'api_sync_orders'],
            'permission_callback' => [$this, 'verify_api_auth']
        ]);
    }

    public function verify_api_auth($request)
    {
        $token = $request->get_header('X-MP-Token');
        $stored_hash = get_option('mp_api_token_hash');

        return $stored_hash && MP_Creator_API_Token::verify($token, $stored_hash);
    }

    public function api_get_creators($request)
    {
        global $wpdb;
        $per_page = $request->get_param('per_page') ?? 20;
        $page = $request->get_param('page') ?? 1;
        $search = $request->get_param('search');

        $offset = ($page - 1) * $per_page;
        $where = '';

        if (!empty($search)) {
            $where = $wpdb->prepare(
                "WHERE name LIKE %s OR email LIKE %s OR brand_slug LIKE %s",
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
        }

        $creators = $wpdb->get_results("
            SELECT * FROM {$this->db->get_table_name('creators')}
            $where
            ORDER BY created_at DESC
            LIMIT $offset, $per_page
        ");

        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$this->db->get_table_name('creators')} $where");

        return rest_ensure_response([
            'data' => $creators,
            'total' => intval($total),
            'page' => intval($page),
            'per_page' => intval($per_page),
            'total_pages' => ceil($total / $per_page)
        ]);
    }

    public function api_get_creator($request)
    {
        global $wpdb;
        $id = $request->get_param('id');
        $creator = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->db->get_table_name('creators')} WHERE id = %d", $id));

        if (!$creator) {
            return new WP_Error('not_found', 'Creator not found', ['status' => 404]);
        }

        return rest_ensure_response(['data' => $creator]);
    }

    public function api_create_creator($request)
    {
        $data = $request->get_json_params();

        $creator_id = $this->db->create_creator([
            'name' => sanitize_text_field($data['name']),
            'email' => sanitize_email($data['email']),
            'brand_slug' => sanitize_title($data['brand_slug']),
            'phone' => sanitize_text_field($data['phone'] ?? ''),
            'address' => sanitize_textarea_field($data['address'] ?? '')
        ]);

        if (is_wp_error($creator_id)) {
            return $creator_id;
        }

        // Envoyer webhook vers Laravel
        MP_Creator_Webhook::send_creator_created($creator_id);

        return rest_ensure_response([
            'success' => true,
            'creator_id' => $creator_id
        ], 201);
    }

    public function api_update_creator($request)
    {
        global $wpdb;
        $id = $request->get_param('id');
        $data = $request->get_json_params();

        $update_data = [];
        if (isset($data['name'])) $update_data['name'] = sanitize_text_field($data['name']);
        if (isset($data['email'])) $update_data['email'] = sanitize_email($data['email']);
        if (isset($data['phone'])) $update_data['phone'] = sanitize_text_field($data['phone']);
        if (isset($data['address'])) $update_data['address'] = sanitize_textarea_field($data['address']);
        if (isset($data['brand_slug'])) $update_data['brand_slug'] = sanitize_title($data['brand_slug']);
        if (isset($data['status'])) $update_data['status'] = $data['status'];

        $update_data['updated_at'] = current_time('mysql');

        $result = $wpdb->update(
            $this->db->get_table_name('creators'),
            $update_data,
            ['id' => $id]
        );

        if ($result === false) {
            return new WP_Error('update_failed', 'Failed to update creator', ['status' => 500]);
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Creator updated successfully'
        ]);
    }

    public function api_delete_creator($request)
    {
        global $wpdb;
        $id = $request->get_param('id');

        $result = $wpdb->delete(
            $this->db->get_table_name('creators'),
            ['id' => $id]
        );

        if ($result === false) {
            return new WP_Error('delete_failed', 'Failed to delete creator', ['status' => 500]);
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Creator deleted successfully'
        ]);
    }

    public function api_get_products_brands($request)
    {
        global $wpdb;
        $product_ids = $request->get_json_params()['product_ids'] ?? [];

        if (empty($product_ids) || !is_array($product_ids)) {
            return new WP_Error('invalid_data', 'product_ids required', ['status' => 400]);
        }

        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT post_id, meta_value as brand_slug
            FROM {$wpdb->postmeta}
            WHERE post_id IN ($placeholders) AND meta_key = 'brand_slug'
        ", $product_ids));

        $brands = [];
        foreach ($results as $row) {
            $brands[$row->post_id] = $row->brand_slug;
        }

        return rest_ensure_response(['data' => $brands]);
    }

    public function api_get_products_creators($request)
    {
        global $wpdb;
        $product_ids = $request->get_json_params()['product_ids'] ?? [];

        if (empty($product_ids) || !is_array($product_ids)) {
            return new WP_Error('invalid_data', 'product_ids required', ['status' => 400]);
        }

        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT pm.post_id, c.id as creator_id
            FROM {$wpdb->postmeta} pm
            JOIN {$this->db->get_table_name('creators')} c ON pm.meta_value = c.brand_slug
            WHERE pm.post_id IN ($placeholders) AND pm.meta_key = 'brand_slug'
        ", $product_ids));

        $creators = [];
        foreach ($results as $row) {
            $creators[$row->post_id] = $row->creator_id;
        }

        return rest_ensure_response(['data' => $creators]);
    }

    public function api_get_orders($request)
    {
        $per_page = $request->get_param('per_page') ?? 20;
        $page = $request->get_param('page') ?? 1;
        $status = $request->get_param('status');
        $after = $request->get_param('after');

        $args = [
            'limit' => $per_page,
            'page' => $page,
            'return' => 'objects'
        ];

        if ($status) {
            $args['status'] = $status;
        }

        if ($after) {
            $args['date_created'] = '>' . $after;
        }

        $orders = wc_get_orders($args);
        $formatted_orders = [];

        foreach ($orders as $order) {
            $formatted_orders[] = $this->format_order_for_api($order);
        }

        return rest_ensure_response([
            'data' => $formatted_orders,
            'total' => count($orders)
        ]);
    }

    public function api_get_order($request)
    {
        $id = $request->get_param('id');
        $order = wc_get_order($id);

        if (!$order) {
            return new WP_Error('not_found', 'Order not found', ['status' => 404]);
        }

        return rest_ensure_response([
            'data' => $this->format_order_for_api($order)
        ]);
    }

    private function format_order_for_api($order)
    {
        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $items[] = [
                'id' => $item->get_id(),
                'product_id' => $item->get_product_id(),
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'total' => $item->get_total(),
                'brand_slug' => get_post_meta($item->get_product_id(), 'brand_slug', true)
            ];
        }

        return [
            'id' => $order->get_id(),
            'number' => $order->get_order_number(),
            'status' => $order->get_status(),
            'date_created' => $order->get_date_created()->date('Y-m-d H:i:s'),
            'date_modified' => $order->get_date_modified()->date('Y-m-d H:i:s'),
            'total' => $order->get_total(),
            'subtotal' => $order->get_subtotal(),
            'currency' => $order->get_currency(),
            'customer_id' => $order->get_customer_id(),
            'customer_email' => $order->get_billing_email(),
            'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'billing' => $order->get_address('billing'),
            'shipping' => $order->get_address('shipping'),
            'line_items' => $items
        ];
    }

    public function api_test($request)
    {
        return rest_ensure_response([
            'success' => true,
            'message' => 'MP Creator API is working',
            'version' => MP_CREATOR_NOTIFIER_VERSION,
            'timestamp' => current_time('mysql'),
            'site_url' => site_url(),
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'wc_version' => defined('WC_VERSION') ? WC_VERSION : 'N/A'
        ]);
    }

    public function api_stats($request)
    {
        global $wpdb;

        return rest_ensure_response([
            'creators' => [
                'total' => $this->db->get_creators_count(),
                'active' => $this->db->get_active_brands_count()
            ],
            'products' => [
                'total' => wp_count_posts('product')->publish,
                'with_brand' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'brand_slug'")
            ],
            'orders' => [
                'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order'"),
                'today' => $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(*) FROM {$wpdb->posts} 
                    WHERE post_type = 'shop_order' 
                    AND post_date > %s
                ", date('Y-m-d 00:00:00')))
            ],
            'sync' => [
                'last_hour' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->db->get_table_name('webhooks')} WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"),
                'success_rate' => $this->db->get_sync_health_percentage()
            ]
        ]);
    }

    public function api_sync_creators($request)
    {
        $data = $request->get_json_params();

        // Logique pour synchroniser depuis Laravel
        // Par exemple, mettre à jour les créateurs avec les données de Laravel

        return rest_ensure_response([
            'success' => true,
            'message' => 'Creators sync initiated'
        ]);
    }

    public function api_sync_products($request)
    {
        $data = $request->get_json_params();

        // Logique pour synchroniser depuis Laravel
        // Par exemple, mettre à jour les produits avec les données de Laravel

        return rest_ensure_response([
            'success' => true,
            'message' => 'Products sync initiated'
        ]);
    }

    public function api_sync_orders($request)
    {
        $data = $request->get_json_params();

        // Logique pour synchroniser depuis Laravel
        // Par exemple, mettre à jour les commandes avec les données de Laravel

        return rest_ensure_response([
            'success' => true,
            'message' => 'Orders sync initiated'
        ]);
    }

    // =============================================
    // AJAX HANDLERS
    // =============================================

    public function ajax_test_laravel_connection()
    {
        check_ajax_referer('mp_test_connection', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $url = sanitize_text_field($_POST['url']);
        $secret = sanitize_text_field($_POST['secret']);

        $response = MP_Creator_Webhook::test_connection($url, $secret);

        if ($response['success']) {
            wp_send_json_success(['message' => $response['message']]);
        } else {
            wp_send_json_error(['message' => $response['message']]);
        }
    }

    public function ajax_send_webhook_test()
    {
        check_ajax_referer('mp_test_webhook', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $creator_id = intval($_POST['creator_id']);
        $result = MP_Creator_Webhook::send_creator_created($creator_id, true);

        if ($result) {
            wp_send_json_success(['message' => 'Webhook sent successfully']);
        } else {
            wp_send_json_error(['message' => 'Failed to send webhook']);
        }
    }

    public function ajax_manual_sync()
    {
        check_ajax_referer('mp_manual_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $type = $_POST['type'] ?? 'product';
        $id = intval($_POST['id'] ?? 0);

        if ($type === 'product' && $id > 0) {
            $result = MP_Creator_Webhook::send_product_sync($id, 'manual_sync');

            if ($result) {
                update_post_meta($id, '_mp_sync_status', 'success');
                update_post_meta($id, '_mp_last_sync', current_time('mysql'));
                wp_send_json_success(['message' => 'Product synced successfully']);
            } else {
                update_post_meta($id, '_mp_sync_status', 'failed');
                wp_send_json_error(['message' => 'Failed to sync product']);
            }
        } elseif ($type === 'order' && $id > 0) {
            $result = MP_Creator_Webhook::send_order_sync($id, 'manual_sync');

            if ($result) {
                wp_send_json_success(['message' => 'Order sync initiated']);
            } else {
                wp_send_json_error(['message' => 'Failed to sync order']);
            }
        } else {
            wp_send_json_error(['message' => 'Invalid parameters']);
        }
    }

    public function ajax_force_full_sync()
    {
        check_ajax_referer('mp_force_full_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        MP_Creator_Webhook::send_full_sync_request('all');

        wp_send_json_success(['message' => 'Full sync initiated']);
    }

    public function ajax_delete_creator()
    {
        check_ajax_referer('mp_delete_creator', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $creator_id = intval($_POST['creator_id']);

        if ($creator_id <= 0) {
            wp_send_json_error(['message' => 'Invalid creator ID']);
        }

        global $wpdb;
        $creator = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->db->get_table_name('creators')} WHERE id = %d",
            $creator_id
        ));

        if (!$creator) {
            wp_send_json_error(['message' => 'Creator not found']);
        }

        // ✅ ÉTAPE 1: Notifier Laravel AVANT la suppression
        $webhook_sent = MP_Creator_Webhook::send_creator_deleted($creator_id, $creator);

        if (!$webhook_sent) {
            error_log("MP Creator: ⚠️ Failed to notify Laravel about creator deletion #{$creator_id}");
            // Vous pouvez choisir de continuer ou d'arrêter ici
            // wp_send_json_error(['message' => 'Failed to sync deletion with CRM']);
            // return;
        }

        // ✅ ÉTAPE 2: Supprimer le créateur de WordPress
        $result = $this->db->delete_creator($creator_id);

        if ($result !== false) {
            error_log("MP Creator: ✅ Creator #{$creator_id} deleted and synced with Laravel");

            wp_send_json_success([
                'message' => sprintf('Creator "%s" deleted successfully and synced with CRM', $creator->name)
            ]);
        } else {
            wp_send_json_error(['message' => 'Failed to delete creator from database']);
        }
    }

    // =============================================
    // HEALTH CHECK
    // =============================================

    public function check_plugin_health()
    {
        if (!is_admin()) return;

        if (!$this->db->table_exists('creators')) {
            add_action('admin_notices', function () {
        ?>
                <div class="notice notice-warning">
                    <p><strong>MP Creator Notifier</strong> - Database tables missing.</p>
                    <p><a href="<?php echo admin_url('admin.php?page=mp-creators&mp_force_create_table=1'); ?>" class="button">Create Tables Now</a></p>
                </div>
            <?php
            });
        }

        // Vérifier si le webhook est configuré
        if (empty(get_option('mp_laravel_webhook_url'))) {
            add_action('admin_notices', function () {
            ?>
                <div class="notice notice-info">
                    <p><strong>MP Creator Notifier</strong> - Please configure Laravel webhook URL in settings.</p>
                    <p><a href="<?php echo admin_url('admin.php?page=mp-creator-settings'); ?>" class="button">Configure Now</a></p>
                </div>
            <?php
            });
        }

        // Vérifier si des webhooks ont échoué récemment
        global $wpdb;
        $webhooks_table = $this->db->get_table_name('webhooks');
        $failed_count = $wpdb->get_var("SELECT COUNT(*) FROM {$webhooks_table} WHERE status = 'failed' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");

        if ($failed_count > 10) {
            add_action('admin_notices', function () use ($failed_count) {
            ?>
                <div class="notice notice-warning">
                    <p><strong>MP Creator Notifier</strong> - <?php echo $failed_count; ?> webhooks failed in the last 24 hours. <a href="<?php echo admin_url('admin.php?page=mp-creator-logs'); ?>">Check logs</a></p>
                </div>
    <?php
            });
        }
    }
}

// =============================================
// CLASSE DE GESTION DE LA BASE DE DONNÉES (COMPLÈTE)
// =============================================
class MP_Creator_DB
{
    private $tables = [];

    public function __construct()
    {
        global $wpdb;
        $prefix = MP_CREATOR_NOTIFIER_TABLE_PREFIX;
        $this->tables = [
            'creators' => $wpdb->prefix . $prefix . 'creators',
            'notifications' => $wpdb->prefix . $prefix . 'notifications',
            'webhooks' => $wpdb->prefix . $prefix . 'webhooks'
        ];
    }

    /**
     * Récupérer le nom complet d'une table
     */
    public function get_table_name($table)
    {
        return $this->tables[$table] ?? null;
    }

    /**
     * Vérifier si une table existe
     */
    public function table_exists($table)
    {
        global $wpdb;
        $table_name = $this->tables[$table] ?? $table;
        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;
    }

    /**
     * Vérifier si une colonne existe dans une table
     */
    public function column_exists($table, $column)
    {
        global $wpdb;
        $table_name = $this->get_table_name($table);
        $result = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE '{$column}'");
        return !empty($result);
    }

    /**
     * Ajouter les colonnes manquantes à toutes les tables
     */
    public function add_missing_columns()
    {
        global $wpdb;

        // =========================================
        // Table creators - Ajouter les colonnes manquantes
        // =========================================
        $creators_table = $this->get_table_name('creators');
        $creators_columns = [
            'total_orders' => 'INT DEFAULT 0',
            'total_sales' => 'DECIMAL(15,2) DEFAULT 0',
            'last_order_date' => 'DATETIME DEFAULT NULL',
            'last_synced_at' => 'DATETIME DEFAULT NULL',
            'wp_creator_id' => 'BIGINT UNSIGNED DEFAULT NULL'
        ];

        foreach ($creators_columns as $column => $definition) {
            if (!$this->column_exists('creators', $column)) {
                $after = $column === 'wp_creator_id' ? 'AFTER id' : '';
                $sql = "ALTER TABLE {$creators_table} ADD COLUMN {$column} {$definition} {$after}";
                $result = $wpdb->query($sql);

                if ($result !== false) {
                    error_log("MP Creator: Column {$column} added to creators table");

                    if ($column === 'wp_creator_id') {
                        $wpdb->query("ALTER TABLE {$creators_table} ADD INDEX (wp_creator_id)");
                    }
                    if ($column === 'brand_slug') {
                        $wpdb->query("ALTER TABLE {$creators_table} ADD INDEX (brand_slug)");
                    }
                }
            }
        }

        // =========================================
        // Table webhooks - Ajouter les colonnes manquantes
        // =========================================
        $webhooks_table = $this->get_table_name('webhooks');
        $webhooks_columns = [
            'creator_id' => 'BIGINT UNSIGNED DEFAULT NULL AFTER order_id',
            'product_id' => 'BIGINT UNSIGNED DEFAULT NULL AFTER creator_id',
            'response_code' => 'INT DEFAULT NULL',
            'error_message' => 'TEXT DEFAULT NULL'
        ];

        foreach ($webhooks_columns as $column => $definition) {
            if (!$this->column_exists('webhooks', $column)) {
                $sql = "ALTER TABLE {$webhooks_table} ADD COLUMN {$column} {$definition}";
                $result = $wpdb->query($sql);
                if ($result !== false) {
                    error_log("MP Creator: Column {$column} added to webhooks table");

                    if (in_array($column, ['creator_id', 'product_id', 'order_id'])) {
                        $wpdb->query("ALTER TABLE {$webhooks_table} ADD INDEX ({$column})");
                    }
                }
            }
        }

        // =========================================
        // Table notifications - Ajouter les colonnes manquantes
        // =========================================
        $notifications_table = $this->get_table_name('notifications');
        $notifications_columns = [
            'error_message' => 'TEXT DEFAULT NULL'
        ];

        foreach ($notifications_columns as $column => $definition) {
            if (!$this->column_exists('notifications', $column)) {
                $sql = "ALTER TABLE {$notifications_table} ADD COLUMN {$column} {$definition}";
                $wpdb->query($sql);
            }
        }
    }

    /**
     * Créer toutes les tables du plugin
     */
    public function create_tables()
    {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $charset_collate = $wpdb->get_charset_collate();

        // Table des créateurs
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tables['creators']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_creator_id BIGINT UNSIGNED DEFAULT NULL,
            name VARCHAR(200) NOT NULL,
            email VARCHAR(100) NOT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            address TEXT DEFAULT NULL,
            brand_slug VARCHAR(100) NOT NULL,
            status ENUM('active','inactive') DEFAULT 'active',
            total_orders INT DEFAULT 0,
            total_sales DECIMAL(15,2) DEFAULT 0,
            last_order_date DATETIME DEFAULT NULL,
            last_synced_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY email (email),
            UNIQUE KEY brand_slug (brand_slug),
            KEY wp_creator_id (wp_creator_id),
            KEY status (status)
        ) $charset_collate;";
        dbDelta($sql);

        // Table des notifications
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tables['notifications']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            creator_id BIGINT UNSIGNED DEFAULT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            subject VARCHAR(255) NOT NULL,
            message LONGTEXT NOT NULL,
            sent_at DATETIME NOT NULL,
            status ENUM('sent','failed') DEFAULT 'sent',
            error_message TEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY creator_id (creator_id),
            KEY order_id (order_id),
            KEY sent_at (sent_at)
        ) $charset_collate;";
        dbDelta($sql);

        // Table des webhooks
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tables['webhooks']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(100) NOT NULL,
            order_id BIGINT UNSIGNED DEFAULT NULL,
            creator_id BIGINT UNSIGNED DEFAULT NULL,
            product_id BIGINT UNSIGNED DEFAULT NULL,
            status ENUM('success','failed','pending') DEFAULT 'pending',
            response_code INT DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY event_type (event_type),
            KEY order_id (order_id),
            KEY creator_id (creator_id),
            KEY product_id (product_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        dbDelta($sql);

        error_log('MP Creator: Tables created successfully');
    }

    // =============================================
    // MÉTHODES POUR LES CRÉATEURS
    // =============================================

    /**
     * Créer un nouveau créateur
     */
    public function create_creator($data)
    {
        global $wpdb;

        $defaults = [
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
            'status' => 'active',
            'total_orders' => 0,
            'total_sales' => 0
        ];

        $data = wp_parse_args($data, $defaults);

        // Vérifier si l'email existe déjà
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->tables['creators']} WHERE email = %s",
            $data['email']
        ));

        if ($exists) {
            return new WP_Error('email_exists', __('A creator with this email already exists.'));
        }

        // Vérifier si le brand_slug existe déjà
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->tables['creators']} WHERE brand_slug = %s",
            $data['brand_slug']
        ));

        if ($exists) {
            return new WP_Error('brand_exists', __('A creator with this brand already exists.'));
        }

        $result = $wpdb->insert($this->tables['creators'], $data);

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to create creator.'));
        }

        return $wpdb->insert_id;
    }

    /**
     * Récupérer un créateur par son ID
     */
    public function get_creator($creator_id)
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$this->tables['creators']}
            WHERE id = %d
        ", $creator_id));
    }

    /**
     * Récupérer un créateur par son brand_slug
     */
    public function get_creator_by_brand($brand_slug)
    {
        global $wpdb;

        if (empty($brand_slug)) {
            return null;
        }

        return $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$this->tables['creators']}
            WHERE brand_slug = %s
            AND status = 'active'
            LIMIT 1
        ", $brand_slug));
    }

    /**
     * Récupérer un créateur par son email
     */
    public function get_creator_by_email($email)
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$this->tables['creators']}
            WHERE email = %s
        ", $email));
    }

    /**
     * Récupérer tous les créateurs
     */
    public function get_creators($limit = 100, $offset = 0, $status = null)
    {
        global $wpdb;

        $sql = "SELECT * FROM {$this->tables['creators']}";
        $params = [];

        if ($status) {
            $sql .= " WHERE status = %s";
            $params[] = $status;
        }

        $sql .= " ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    /**
     * Compter le nombre total de créateurs
     */
    public function get_creators_count($status = null)
    {
        global $wpdb;

        if ($status) {
            return $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$this->tables['creators']}
                WHERE status = %s
            ", $status));
        }

        return $wpdb->get_var("SELECT COUNT(*) FROM {$this->tables['creators']}");
    }

    /**
     * Compter le nombre de marques actives
     */
    public function get_active_brands_count()
    {
        global $wpdb;
        return $wpdb->get_var("
            SELECT COUNT(DISTINCT brand_slug) 
            FROM {$this->tables['creators']} 
            WHERE status = 'active'
        ");
    }

    /**
     * Mettre à jour un créateur
     */
    public function update_creator($creator_id, $data)
    {
        global $wpdb;

        $data['updated_at'] = current_time('mysql');

        return $wpdb->update(
            $this->tables['creators'],
            $data,
            ['id' => $creator_id]
        );
    }

    /**
     * Supprimer un créateur
     */
    public function delete_creator($creator_id)
    {
        global $wpdb;

        return $wpdb->delete(
            $this->tables['creators'],
            ['id' => $creator_id]
        );
    }

    /**
     * Récupérer les créateurs associés à une commande
     */
    public function get_creators_for_order($order_id)
    {
        global $wpdb;

        $order = wc_get_order($order_id);
        if (!$order) return [];

        $product_ids = [];
        foreach ($order->get_items() as $item) {
            $product_ids[] = $item->get_product_id();
        }

        if (empty($product_ids)) return [];

        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));

        return $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT c.* 
            FROM {$this->tables['creators']} c
            JOIN {$wpdb->postmeta} pm ON c.brand_slug = pm.meta_value
            WHERE pm.post_id IN ($placeholders)
            AND pm.meta_key = 'brand_slug'
        ", $product_ids));
    }

    /**
     * Récupérer les produits d'un créateur dans une commande
     */
    public function get_creator_products_in_order($creator_id, $order_id)
    {
        global $wpdb;

        $creator = $this->get_creator($creator_id);
        if (!$creator) return [];

        $order = wc_get_order($order_id);
        $products = [];

        foreach ($order->get_items() as $item) {
            $brand_slug = get_post_meta($item->get_product_id(), 'brand_slug', true);

            if ($brand_slug === $creator->brand_slug) {
                $products[] = [
                    'id' => $item->get_product_id(),
                    'name' => $item->get_name(),
                    'quantity' => $item->get_quantity(),
                    'total' => $item->get_total()
                ];
            }
        }

        return $products;
    }

    /**
     * Calculer le total d'un créateur pour une commande
     */
    public function get_creator_order_total($creator_id, $order_id)
    {
        $products = $this->get_creator_products_in_order($creator_id, $order_id);
        return array_sum(array_column($products, 'total'));
    }

    /**
     * Mettre à jour les statistiques d'un créateur
     */
    public function update_creator_stats($creator_id, $order_total)
    {
        global $wpdb;

        return $wpdb->query($wpdb->prepare("
            UPDATE {$this->tables['creators']} 
            SET 
                total_orders = total_orders + 1,
                total_sales = total_sales + %f,
                last_order_date = NOW(),
                updated_at = NOW()
            WHERE id = %d
        ", $order_total, $creator_id));
    }

    // =============================================
    // MÉTHODES POUR LES NOTIFICATIONS
    // =============================================

    /**
     * Enregistrer une notification
     */
    public function log_notification($creator_id, $order_id, $subject, $message, $status, $error_message = null)
    {
        global $wpdb;

        return $wpdb->insert($this->tables['notifications'], [
            'creator_id' => $creator_id,
            'order_id' => $order_id,
            'subject' => $subject,
            'message' => $message,
            'sent_at' => current_time('mysql'),
            'status' => $status,
            'error_message' => $error_message
        ]);
    }

    /**
     * Récupérer les notifications d'un créateur
     */
    public function get_creator_notifications($creator_id, $limit = 50)
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$this->tables['notifications']}
            WHERE creator_id = %d
            ORDER BY sent_at DESC
            LIMIT %d
        ", $creator_id, $limit));
    }

    /**
     * Récupérer les notifications d'une commande
     */
    public function get_order_notifications($order_id)
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare("
            SELECT n.*, c.name as creator_name
            FROM {$this->tables['notifications']} n
            LEFT JOIN {$this->tables['creators']} c ON n.creator_id = c.id
            WHERE n.order_id = %d
            ORDER BY n.sent_at DESC
        ", $order_id));
    }

    // =============================================
    // MÉTHODES POUR LES WEBHOOKS
    // =============================================

    /**
     * Enregistrer un webhook
     */
    public function log_webhook($event_type, $order_id = null, $creator_id = null, $product_id = null, $status = 'pending', $response_code = null, $error_message = null)
    {
        global $wpdb;

        // S'assurer que les colonnes existent
        $this->add_missing_columns();

        $data = [
            'event_type' => $event_type,
            'order_id' => $order_id,
            'status' => $status,
            'created_at' => current_time('mysql')
        ];

        // Ajouter conditionnellement les colonnes
        if ($this->column_exists('webhooks', 'creator_id')) {
            $data['creator_id'] = $creator_id;
        }

        if ($this->column_exists('webhooks', 'product_id')) {
            $data['product_id'] = $product_id;
        }

        if ($this->column_exists('webhooks', 'response_code')) {
            $data['response_code'] = $response_code;
        }

        if ($this->column_exists('webhooks', 'error_message')) {
            $data['error_message'] = $error_message;
        }

        return $wpdb->insert($this->tables['webhooks'], $data);
    }

    /**
     * Récupérer les webhooks récents
     */
    public function get_recent_webhooks($limit = 100, $status = null)
    {
        global $wpdb;

        $sql = "SELECT * FROM {$this->tables['webhooks']}";
        $params = [];

        if ($status) {
            $sql .= " WHERE status = %s";
            $params[] = $status;
        }

        $sql .= " ORDER BY created_at DESC LIMIT %d";
        $params[] = $limit;

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    /**
     * Récupérer les webhooks en échec
     */
    public function get_failed_webhooks($hours = 24)
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$this->tables['webhooks']}
            WHERE status = 'failed'
            AND created_at > DATE_SUB(NOW(), INTERVAL %d HOUR)
            ORDER BY created_at DESC
        ", $hours));
    }

    /**
     * Mettre à jour le statut d'un webhook
     */
    public function update_webhook_status($webhook_id, $status, $response_code = null, $error_message = null)
    {
        global $wpdb;

        $data = ['status' => $status];

        if ($response_code !== null) {
            $data['response_code'] = $response_code;
        }

        if ($error_message !== null) {
            $data['error_message'] = $error_message;
        }

        return $wpdb->update(
            $this->tables['webhooks'],
            $data,
            ['id' => $webhook_id]
        );
    }

    /**
     * Nettoyer les vieux webhooks
     */
    public function clean_old_webhooks($days = 30)
    {
        global $wpdb;

        return $wpdb->query($wpdb->prepare("
            DELETE FROM {$this->tables['webhooks']}
            WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
        ", $days));
    }

    // =============================================
    // MÉTHODES DE STATISTIQUES
    // =============================================

    /**
     * Compter le nombre de produits synchronisés
     */
    public function get_synced_products_count()
    {
        global $wpdb;
        return $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->postmeta} 
            WHERE meta_key = '_mp_sync_status' AND meta_value = 'success'
        ");
    }

    /**
     * Calculer le pourcentage de santé de la synchronisation
     */
    public function get_sync_health_percentage()
    {
        global $wpdb;

        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success
            FROM {$this->tables['webhooks']}
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");

        if ($stats->total == 0) {
            return 100;
        }

        return round(($stats->success / $stats->total) * 100);
    }

    /**
     * Récupérer les statistiques globales
     */
    public function get_global_stats()
    {
        global $wpdb;

        $creators_count = $this->get_creators_count();
        $active_brands = $this->get_active_brands_count();
        $synced_products = $this->get_synced_products_count();
        $sync_health = $this->get_sync_health_percentage();

        // Commandes récentes
        $recent_orders = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_type = 'shop_order'
            AND post_date > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");

        // Webhooks récents
        $recent_webhooks = $wpdb->get_var("
            SELECT COUNT(*) FROM {$this->tables['webhooks']}
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");

        return [
            'creators' => [
                'total' => $creators_count,
                'active_brands' => $active_brands
            ],
            'products' => [
                'synced' => $synced_products
            ],
            'orders' => [
                'last_24h' => $recent_orders
            ],
            'sync' => [
                'webhooks_24h' => $recent_webhooks,
                'health_percentage' => $sync_health
            ]
        ];
    }

    /**
     * Nettoyer les vieilles données
     */
    public function clean_old_data($days = 30)
    {
        $webhooks_deleted = $this->clean_old_webhooks($days);

        global $wpdb;
        $notifications_deleted = $wpdb->query($wpdb->prepare("
            DELETE FROM {$this->tables['notifications']}
            WHERE sent_at < DATE_SUB(NOW(), INTERVAL %d DAY)
        ", $days));

        return [
            'webhooks_deleted' => $webhooks_deleted,
            'notifications_deleted' => $notifications_deleted
        ];
    }
}

// =============================================
// CLASSE DE GESTION DES WEBHOOKS (VERSION AMÉLIORÉE)
// =============================================
class MP_Creator_Webhook
{
    /**
     * Configuration centralisée
     */
    private static function get_config()
    {
        return [
            'url' => get_option('mp_laravel_webhook_url'),
            'secret' => get_option('mp_laravel_webhook_secret'),
            'timeout' => 15,
            'debug' => defined('WP_DEBUG') && WP_DEBUG
        ];
    }

    /**
     * Construire l'URL complète pour un endpoint
     */
    private static function build_url($base_url, $endpoint)
    {
        // Nettoyer l'URL de base
        $base_url = rtrim($base_url, '/');

        // Nettoyer l'endpoint
        $endpoint = ltrim($endpoint, '/');

        // Si l'URL de base se termine déjà par un endpoint spécifique (erreur de config)
        // On enlève tout ce qui vient après /api/webhooks
        if (strpos($base_url, '/api/webhooks/') !== false) {
            $parts = explode('/api/webhooks/', $base_url);
            $base_url = $parts[0] . '/api/webhooks';
        }

        // Si l'URL contient déjà /api/webhooks, on ajoute juste l'endpoint
        if (strpos($base_url, '/api/webhooks') !== false) {
            return $base_url . '/' . $endpoint;
        }

        // Sinon on construit l'URL complète
        return $base_url . '/api/webhooks/' . $endpoint;
    }

    /**
     * Envoyer un webhook avec gestion d'erreurs améliorée
     */
    private static function send_webhook($endpoint, $data, $log_data = [])
    {
        $config = self::get_config();

        if (empty($config['url']) || empty($config['secret'])) {
            error_log('MP Creator Webhook: ⚠️ Configuration manquante (URL ou Secret)');
            return false;
        }

        $url = self::build_url($config['url'], $endpoint);

        if ($config['debug']) {
            error_log("MP Creator Webhook: 📤 Envoi vers $url");
            error_log("MP Creator Webhook: 📋 Data = " . json_encode($data, JSON_PRETTY_PRINT));
        }

        $response = wp_remote_post($url, [
            'method' => 'POST',
            'timeout' => $config['timeout'],
            'blocking' => true, // IMPORTANT: bloquer pour vérifier la réponse
            'headers' => [
                'Content-Type' => 'application/json',
                'X-MP-Webhook-Token' => $config['secret'],
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . ' MP-Creator/' . MP_CREATOR_NOTIFIER_VERSION
            ],
            'body' => json_encode($data),
            'sslverify' => !$config['debug'] // Désactiver vérification SSL en debug local
        ]);

        $success = false;
        $status_code = 0;
        $error_message = null;

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log("MP Creator Webhook: ❌ Erreur - $error_message");
        } else {
            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);

            if ($status_code >= 200 && $status_code < 300) {
                $success = true;
                if ($config['debug']) {
                    error_log("MP Creator Webhook: ✅ Succès (HTTP $status_code)");
                    error_log("MP Creator Webhook: 📥 Response = $body");
                }
            } else {
                $error_message = "HTTP $status_code: $body";
                error_log("MP Creator Webhook: ❌ Échec (HTTP $status_code)");
                error_log("MP Creator Webhook: 📥 Response = $body");
            }
        }

        // Logger dans la base de données
        $db = new MP_Creator_DB();
        $db->log_webhook(
            $log_data['event_type'] ?? 'unknown',
            $log_data['order_id'] ?? null,
            $log_data['creator_id'] ?? null,
            $log_data['product_id'] ?? null,
            $success ? 'success' : 'failed',
            $status_code,
            $error_message
        );

        return $success;
    }

    /**
     * Envoyer la suppression d'un créateur vers Laravel
     */
    public static function send_creator_deleted($creator_id, $creator_data)
    {
        error_log("MP Creator: 📤 Envoi suppression créateur #{$creator_id} vers Laravel");

        $data = [
            'event' => 'creator.deleted',
            'creator' => [
                'wp_creator_id' => $creator_id,
                'wp_laravel_id' => $creator_data->wp_creator_id ?? null, // ID Laravel si disponible
                'name' => $creator_data->name,
                'email' => $creator_data->email,
                'brand_slug' => $creator_data->brand_slug,
            ],
            'timestamp' => current_time('mysql'),
            'site_url' => site_url()
        ];

        $success = self::send_webhook('creator-deleted', $data, [
            'event_type' => 'creator.deleted',
            'creator_id' => $creator_id
        ]);

        if ($success) {
            error_log("MP Creator: ✅ Suppression créateur #{$creator_id} synchronisée avec Laravel");
        } else {
            error_log("MP Creator: ❌ Échec synchronisation suppression créateur #{$creator_id}");
        }

        return $success;
    }

    /**
     * Envoyer la création d'un créateur vers Laravel
     */
    public static function send_creator_created($creator_id, $force_sync = false)
    {
        global $wpdb;

        $creator_table = (new MP_Creator_DB())->get_table_name('creators');
        $creator = $wpdb->get_row($wpdb->prepare("SELECT * FROM $creator_table WHERE id = %d", $creator_id));

        if (!$creator) {
            error_log("MP Creator: ❌ Creator #$creator_id not found");
            return false;
        }

        error_log("MP Creator: 📤 Envoi création créateur #$creator_id vers Laravel");

        $data = [
            'event' => 'creator.created',
            'creator' => [
                'wp_creator_id' => $creator->id,
                'name' => $creator->name,
                'email' => $creator->email,
                'phone' => $creator->phone,
                'brand_slug' => $creator->brand_slug,
                'address' => $creator->address,
                'status' => $creator->status ?? 'active'
            ],
            'timestamp' => current_time('mysql'),
            'site_url' => site_url()
        ];

        $success = self::send_webhook('creator-created', $data, [
            'event_type' => 'creator.created',
            'creator_id' => $creator_id
        ]);

        if ($success) {
            error_log("MP Creator: ✅ Créateur #$creator_id synchronisé avec succès");
            $wpdb->update(
                $creator_table,
                ['last_synced_at' => current_time('mysql')],
                ['id' => $creator_id]
            );
        }

        return $success;
    }

    /**
     * Envoyer une commande pour synchronisation
     */
    public static function send_order_sync($order_id, $event_type, $new_status = null)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            error_log("MP Creator: ❌ Order #$order_id not found");
            return false;
        }

        error_log("MP Creator: 📤 Envoi commande #$order_id vers Laravel (event: $event_type)");

        $data = [
            'event' => 'order.' . $event_type,
            'order_id' => (int) $order_id,
            'timestamp' => current_time('mysql'),
            'site_url' => site_url()
        ];

        if ($new_status) {
            $data['new_status'] = $new_status;
        }

        return self::send_webhook('wordpress/sync-orders', $data, [
            'event_type' => 'order.' . $event_type,
            'order_id' => $order_id
        ]);
    }

    /**
     * Envoyer une commande avec les créateurs associés
     */
    public static function send_order_with_creators($order_id)
    {
        error_log("MP Creator: 📤 Envoi commande #$order_id avec créateurs vers Laravel");

        $db = new MP_Creator_DB();
        $creators = $db->get_creators_for_order($order_id);

        $creators_data = [];
        foreach ($creators as $creator) {
            $creator_total = $db->get_creator_order_total($creator->id, $order_id);
            $products = $db->get_creator_products_in_order($creator->id, $order_id);

            $creators_data[] = [
                'creator_id' => $creator->id,
                'wp_creator_id' => $creator->wp_creator_id ?? null,
                'name' => $creator->name,
                'email' => $creator->email,
                'brand_slug' => $creator->brand_slug,
                'total' => $creator_total,
                'products' => $products
            ];
        }

        $data = [
            'event' => 'order.with_creators',
            'order_id' => (int) $order_id,
            'creators' => $creators_data,
            'creators_count' => count($creators_data),
            'timestamp' => current_time('mysql'),
            'site_url' => site_url()
        ];

        return self::send_webhook('sync-orders-with-creators', $data, [
            'event_type' => 'order.with_creators',
            'order_id' => $order_id
        ]);
    }

    /**
     * Envoyer une commande annulée/remboursée
     */
    public static function send_order_cancelled($order_id, $status)
    {
        error_log("MP Creator: 📤 Envoi annulation commande #$order_id vers Laravel");

        $order = wc_get_order($order_id);
        if (!$order) return false;

        $data = [
            'event' => 'order.cancelled',
            'order_id' => (int) $order_id,
            'status' => $status,
            'timestamp' => current_time('mysql'),
            'site_url' => site_url()
        ];

        return self::send_webhook('wordpress/sync-orders', $data, [
            'event_type' => 'order.cancelled',
            'order_id' => $order_id
        ]);
    }

    /**
     * Envoyer un remboursement
     */
    public static function send_order_refund($order_id, $refund_id, $amount)
    {
        error_log("MP Creator: 📤 Envoi remboursement commande #$order_id vers Laravel");

        $data = [
            'event' => 'order.refunded',
            'order_id' => (int) $order_id,
            'refund_id' => (int) $refund_id,
            'amount' => (float) $amount,
            'timestamp' => current_time('mysql'),
            'site_url' => site_url()
        ];

        return self::send_webhook('wordpress/sync-orders', $data, [
            'event_type' => 'order.refunded',
            'order_id' => $order_id
        ]);
    }

    /**
     * Envoyer un produit pour synchronisation
     */
    public static function send_product_sync($product_id, $event_type, $extra_data = [])
    {
        $product = wc_get_product($product_id);
        if (!$product) {
            error_log("MP Creator: ❌ Product #$product_id not found");
            return false;
        }

        error_log("MP Creator: 📤 Envoi produit #$product_id vers Laravel (event: $event_type)");

        $brand_slug = get_post_meta($product_id, 'brand_slug', true);

        $data = array_merge([
            'event' => 'product.' . $event_type,
            'product_id' => $product_id,
            'brand_slug' => $brand_slug,
            'timestamp' => current_time('mysql'),
            'site_url' => site_url()
        ], $extra_data);

        $success = self::send_webhook('wordpress/sync-products', $data, [
            'event_type' => 'product.' . $event_type,
            'product_id' => $product_id
        ]);

        if ($success) {
            update_post_meta($product_id, '_mp_last_sync', current_time('mysql'));
            update_post_meta($product_id, '_mp_sync_status', 'success');
        } else {
            update_post_meta($product_id, '_mp_sync_status', 'failed');
        }

        return $success;
    }

    /**
     * Envoyer un produit supprimé
     */
    public static function send_product_deleted($product_id)
    {
        error_log("MP Creator: 📤 Envoi suppression produit #$product_id vers Laravel");

        $data = [
            'event' => 'product.deleted',
            'product_id' => $product_id,
            'timestamp' => current_time('mysql'),
            'site_url' => site_url()
        ];

        return self::send_webhook('wordpress/sync-products', $data, [
            'event_type' => 'product.deleted',
            'product_id' => $product_id
        ]);
    }

    /**
     * Envoyer synchronisation des produits par marque
     */
    public static function send_products_sync_by_brand($brand_slug)
    {
        if (empty($brand_slug)) {
            error_log("MP Creator: ⚠️ Brand slug vide, synchronisation ignorée");
            return false;
        }

        error_log("MP Creator: 📤 Demande de synchronisation des produits pour la marque: $brand_slug");

        $data = [
            'event' => 'products.by_brand',
            'brand_slug' => $brand_slug,
            'timestamp' => current_time('mysql'),
            'site_url' => site_url()
        ];

        return self::send_webhook('sync-products-by-brand', $data, [
            'event_type' => 'products.by_brand'
        ]);
    }

    /**
     * Envoyer une marque pour synchronisation
     */
    public static function send_brand_sync($brand_data, $event_type)
    {
        error_log("MP Creator: 📤 Envoi marque vers Laravel (event: $event_type)");

        $data = [
            'event' => 'brand.' . $event_type,
            'brand' => $brand_data,
            'timestamp' => current_time('mysql'),
            'site_url' => site_url()
        ];

        return self::send_webhook('sync-brands', $data, [
            'event_type' => 'brand.' . $event_type
        ]);
    }

    /**
     * Envoyer une marque supprimée
     */
    public static function send_brand_deleted($term_id, $taxonomy)
    {
        error_log("MP Creator: 📤 Envoi suppression marque #$term_id vers Laravel");

        $data = [
            'event' => 'brand.deleted',
            'term_id' => $term_id,
            'taxonomy' => $taxonomy,
            'timestamp' => current_time('mysql'),
            'site_url' => site_url()
        ];

        return self::send_webhook('sync-brands', $data, [
            'event_type' => 'brand.deleted'
        ]);
    }

    /**
     * Envoyer une demande de synchronisation complète
     */
    public static function send_full_sync_request($type)
    {
        error_log("MP Creator: 📤 Demande de synchronisation complète ($type) vers Laravel");

        $data = [
            'event' => 'full_sync',
            'sync_type' => $type,
            'timestamp' => current_time('mysql'),
            'site_url' => site_url(),
            'version' => MP_CREATOR_NOTIFIER_VERSION
        ];

        return self::send_webhook('full-sync', $data, [
            'event_type' => 'full_sync'
        ]);
    }

    /**
     * Tester la connexion avec Laravel
     */
    public static function test_connection($url = null, $secret = null)
    {
        $config = self::get_config();
        $test_url = $url ?? $config['url'];
        $test_secret = $secret ?? $config['secret'];

        if (empty($test_url) || empty($test_secret)) {
            return [
                'success' => false,
                'message' => 'URL ou Secret manquant dans la configuration'
            ];
        }

        $webhook_url = self::build_url($test_url, 'wordpress/test');

        error_log("MP Creator: 🧪 Test de connexion vers $webhook_url");

        $response = wp_remote_post($webhook_url, [
            'timeout' => 10,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-MP-Webhook-Token' => $test_secret
            ],
            'body' => json_encode([
                'test' => true,
                'timestamp' => current_time('mysql'),
                'wp_version' => get_bloginfo('version'),
                'plugin_version' => MP_CREATOR_NOTIFIER_VERSION
            ]),
            'sslverify' => false // Pour tests locaux
        ]);

        if (is_wp_error($response)) {
            $error = $response->get_error_message();
            error_log("MP Creator: ❌ Test échoué - $error");
            return [
                'success' => false,
                'message' => "Erreur de connexion: $error"
            ];
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        error_log("MP Creator: 📥 Test response HTTP $status: $body");

        if ($status === 200) {
            $data = json_decode($body, true);
            if (isset($data['success']) && $data['success']) {
                error_log("MP Creator: ✅ Test de connexion réussi");
                return [
                    'success' => true,
                    'message' => 'Connexion réussie! Laravel version: ' . ($data['laravel_version'] ?? 'N/A')
                ];
            }
        }

        return [
            'success' => false,
            'message' => "Laravel a retourné HTTP $status: $body"
        ];
    }
}

// Shortcode pour le dashboard créateur
add_shortcode('mp_creator_dashboard', 'mp_creator_dashboard_shortcode');
function mp_creator_dashboard_shortcode($atts)
{
    if (!is_user_logged_in()) {
        return '<p>Please log in to view your dashboard.</p>';
    }

    $current_user = wp_get_current_user();
    $db = new MP_Creator_DB();

    // Chercher le créateur associé à cet utilisateur (par email)
    global $wpdb;
    $creator = $wpdb->get_row($wpdb->prepare("
        SELECT * FROM {$db->get_table_name('creators')}
        WHERE email = %s
    ", $current_user->user_email));

    if (!$creator) {
        return '<p>No creator profile found for your account.</p>';
    }

    ob_start();
    ?>
    <div class="mp-creator-dashboard">
        <h2>Welcome, <?php echo esc_html($creator->name); ?>!</h2>

        <div class="mp-stats-cards">
            <div class="mp-card">
                <h3>Total Sales</h3>
                <p class="mp-stat"><?php echo wc_price($creator->total_sales); ?></p>
            </div>
            <div class="mp-card">
                <h3>Total Orders</h3>
                <p class="mp-stat"><?php echo $creator->total_orders; ?></p>
            </div>
            <div class="mp-card">
                <h3>Brand</h3>
                <p class="mp-stat"><?php echo esc_html($creator->brand_slug); ?></p>
            </div>
        </div>

        <h3>Recent Orders</h3>
        <?php
        // Afficher les commandes récentes de ce créateur
        $args = [
            'limit' => 10,
            'return' => 'objects'
        ];
        $orders = wc_get_orders($args);
        ?>
        <table class="mp-creator-table">
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Your Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order):
                    $creator_total = $db->get_creator_order_total($creator->id, $order->get_id());
                    if ($creator_total > 0):
                ?>
                        <tr>
                            <td>#<?php echo $order->get_id(); ?></td>
                            <td><?php echo $order->get_date_created()->date('d/m/Y'); ?></td>
                            <td><?php echo $order->get_status(); ?></td>
                            <td><?php echo wc_price($creator_total); ?></td>
                        </tr>
                <?php endif;
                endforeach; ?>
            </tbody>
        </table>
    </div>

    <style>
        .mp-creator-dashboard {
            max-width: 1200px;
            margin: 0 auto;
        }

        .mp-stats-cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin: 20px 0;
        }

        .mp-card {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .mp-stat {
            font-size: 24px;
            font-weight: bold;
            margin: 10px 0;
            color: #2271b1;
        }
    </style>
<?php
    return ob_get_clean();
}

// Initialisation du plugin
MP_Creator_Notifier_Pro::get_instance();

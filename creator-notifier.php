<?php

/**
 * Plugin Name: MP Creator Notifier Pro
 * Description: Gestion complète des créateurs avec synchronisation bidirectionnelle WordPress ↔ Laravel
 * Version: 5.3.0
 * Author: Baana Baana Boutique
 * Text Domain: mp-creator-notifier
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * Requires WordPress: 6.0.0
 */

if (!defined('ABSPATH')) exit;

define('MP_CREATOR_NOTIFIER_VERSION',      '5.3.0');
define('MP_CREATOR_NOTIFIER_PLUGIN_URL',   plugin_dir_url(__FILE__));
define('MP_CREATOR_NOTIFIER_PLUGIN_PATH',  plugin_dir_path(__FILE__));
define('MP_CREATOR_NOTIFIER_TABLE_PREFIX', 'mp_');

add_action('plugins_loaded', 'mp_creator_notifier_boot', 5);

function mp_creator_notifier_boot()
{
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>MP Creator Notifier Pro</strong> nécessite WooCommerce.</p></div>';
        });
        return;
    }

    mp_creator_notifier_load_modules();
    MP_Creator_Notifier_Pro::get_instance();
}

/**
 * Charge tous les modules dans l'ordre de dépendance.
 *
 *  1. Infrastructure  : DB, Token, Webhook
 *  2. Domaine         : OwnershipResolver, Email, OrderHandler
 *  3. WooCommerce     : ProductHandler, BrandHandler
 *  4. Interface       : RestApi, Ajax, Sync, Admin
 *  5. PAPS Logistics  : API, Settings, Checkout, Shipping
 *  6. Frontend/Debug  : Shortcodes, Debug
 */
function mp_creator_notifier_load_modules()
{
    $p = MP_CREATOR_NOTIFIER_PLUGIN_PATH . 'includes/';

    // Infrastructure
    require_once $p . 'class-api-token.php';
    require_once $p . 'class-db.php';
    require_once $p . 'class-webhook.php';

    // Domaine métier
    require_once $p . 'class-ownership-resolver.php';
    require_once $p . 'class-email.php';
    require_once $p . 'class-order-handler.php';

    // Intégration WooCommerce
    require_once $p . 'class-product-handler.php';
    require_once $p . 'class-brand-handler.php';

    // Interface & API
    require_once $p . 'class-rest-api.php';
    require_once $p . 'class-ajax.php';
    require_once $p . 'class-sync.php';
    require_once $p . 'class-admin.php';

    // PAPS Logistics (livraison)
    require_once $p . 'class-paps-api.php';
    require_once $p . 'class-paps-settings.php';
    require_once $p . 'class-paps-checkout.php';
    require_once $p . 'class-paps-shipping.php';
    require_once $p . 'class-paps-shipping-method.php';

    // Frontend & Debug
    require_once $p . 'class-shortcodes.php';
    require_once $p . 'class-debug.php';
}

// =============================================
// ORCHESTRATEUR PRINCIPAL — bootstrap seulement
// Chaque responsabilité métier est dans son module.
// =============================================

class MP_Creator_Notifier_Pro
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
        register_activation_hook(__FILE__,   [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('init',       [$this, 'init'], 1);
        add_action('admin_init', [$this, 'on_admin_init']);
        // Hooks WooCommerce commandes (webhooks Laravel + stats locales)
        // Les emails créateurs sont dans MP_Order_Handler
        add_action('woocommerce_new_order',            [$this, 'on_new_order'],            10, 2);
        add_action('woocommerce_order_status_changed', [$this, 'on_order_status_changed'], 10, 4);
        add_action('woocommerce_update_order',         [$this, 'on_order_updated'],        10, 1);
        add_action('woocommerce_order_refunded',       [$this, 'on_order_refunded'],       10, 2);
        add_action('woocommerce_new_order',            [$this, 'on_new_order'],            10, 2);

        // Instantier les modules
        MP_Order_Handler::get_instance();
        MP_Product_Handler::get_instance();
        MP_Brand_Handler::get_instance();
        MP_Rest_Api::get_instance();
        MP_Ajax_Handler::get_instance();
        MP_Sync_Service::get_instance();
        MP_Admin::get_instance();

        MP_Paps_API::get_instance();
        MP_Paps_Settings::get_instance();
    }

    // =========================================================
    // CYCLE DE VIE
    // =========================================================

    public function init()
    {
        load_plugin_textdomain('mp-creator-notifier', false, dirname(plugin_basename(__FILE__)) . '/languages');
        MP_Sync_Service::get_instance()->schedule();
    }

    public function on_admin_init()
    {
        MP_Creator_DB::get_instance()->add_missing_columns();
        MP_Admin::get_instance()->check_health();
    }

    public function activate()
    {
        error_log('=== MP Creator Notifier Pro — Activation v' . MP_CREATOR_NOTIFIER_VERSION . ' ===');

        $db = MP_Creator_DB::get_instance();
        $db->create_tables();
        $db->add_missing_columns();

        if (!get_option('mp_api_token_hash')) {
            $token = MP_Creator_API_Token::create_and_store();
            set_transient('mp_new_token_display', $token, 5 * MINUTE_IN_SECONDS);
        }

        $this->create_dashboard_page();
        MP_Sync_Service::get_instance()->schedule();
        update_option('mp_creator_notifier_version', MP_CREATOR_NOTIFIER_VERSION);

        error_log('=== MP Creator Notifier Pro — Activation terminée ===');
    }

    public function deactivate()
    {
        MP_Sync_Service::get_instance()->unschedule();
        flush_rewrite_rules();
    }

    private function create_dashboard_page()
    {
        if (get_option('mp_creator_dashboard_page')) return;

        $page_id = wp_insert_post([
            'post_title'   => __('Creator Dashboard', 'mp-creator-notifier'),
            'post_content' => '[mp_creator_dashboard]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_name'    => 'creator-dashboard',
        ]);

        if ($page_id) update_option('mp_creator_dashboard_page', $page_id);
    }

    // =========================================================
    // HOOKS COMMANDES
    // =========================================================

    public function on_new_order($order_id, $order = null)
    {
        error_log("MP Creator: Nouvelle commande #{$order_id}");

        MP_Creator_Webhook::send_order_sync($order_id, 'order_created');
        MP_Creator_Webhook::send_order_with_creators($order_id);
        $this->update_order_stats($order_id);
        MP_Sync_Service::get_instance()->sync_products_from_order($order_id);
    }

    public function on_order_status_changed($order_id, $old_status, $new_status, $order)
    {
        error_log("MP Creator: Commande #{$order_id} — {$old_status} → {$new_status}");

        MP_Creator_Webhook::send_order_sync($order_id, 'order_status_changed', $new_status);

        if ($new_status === 'completed') {
            $this->update_creators_stats($order_id);
            MP_Sync_Service::get_instance()->sync_products_from_order($order_id);
        }

        if (in_array($new_status, ['cancelled', 'refunded'], true)) {
            MP_Creator_Webhook::send_order_cancelled($order_id, $new_status);
        }
    }

    public function on_order_updated($order_id)
    {
        if (get_option('mp_sync_on_order_update', true)) {
            MP_Creator_Webhook::send_order_sync($order_id, 'order_updated');
        }
    }

    public function on_order_refunded($order_id, $refund_id)
    {
        $refund = wc_get_order($refund_id);
        if ($refund) {
            MP_Creator_Webhook::send_order_refund($order_id, $refund_id, $refund->get_total());
        }
    }

    // =========================================================
    // STATS LOCALES
    // =========================================================

    private function update_order_stats($order_id)
    {
        global $wpdb;
        $db       = MP_Creator_DB::get_instance();
        $creators = $db->get_creators_for_order($order_id);
        $table    = $db->get_table_name('creators');

        foreach ($creators as $creator) {
            $total = $db->get_creator_order_total($creator->id, $order_id);
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET total_orders = total_orders + 1,
                     total_sales  = total_sales + %f,
                     last_order_date = NOW()
                 WHERE id = %d",
                $total,
                $creator->id
            ));
        }
    }

    private function update_creators_stats($order_id)
    {
        $creators = MP_Creator_DB::get_instance()->get_creators_for_order($order_id);
        foreach ($creators as $creator) {
            do_action('mp_creator_order_completed', $creator->id, $order_id);
        }
    }
}

// =============================================
// SHORTCODE FRONTEND — Dashboard créateur
// =============================================
add_shortcode('mp_creator_dashboard', function ($atts) {
    if (!is_user_logged_in()) {
        return '<p>' . esc_html__('Please log in to view your dashboard.', 'mp-creator-notifier') . '</p>';
    }

    $creator = MP_Creator_DB::get_instance()->get_creator_by_email(wp_get_current_user()->user_email);
    if (!$creator) {
        return '<p>' . esc_html__('No creator profile found for your account.', 'mp-creator-notifier') . '</p>';
    }

    ob_start();
?>
    <div class="mp-creator-dashboard">
        <h2><?php echo esc_html__('Welcome', 'mp-creator-notifier') . ', ' . esc_html($creator->name); ?>!</h2>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin:20px 0;">
            <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,.1);text-align:center;">
                <h3><?php _e('Total Sales', 'mp-creator-notifier'); ?></h3>
                <p style="font-size:24px;font-weight:bold;color:#2271b1;"><?php echo wc_price($creator->total_sales ?? 0); ?></p>
            </div>
            <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,.1);text-align:center;">
                <h3><?php _e('Total Orders', 'mp-creator-notifier'); ?></h3>
                <p style="font-size:24px;font-weight:bold;color:#2271b1;"><?php echo (int) ($creator->total_orders ?? 0); ?></p>
            </div>
            <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,.1);text-align:center;">
                <h3><?php _e('Brand', 'mp-creator-notifier'); ?></h3>
                <p style="font-size:24px;font-weight:bold;color:#2271b1;"><?php echo esc_html($creator->brand_slug); ?></p>
            </div>
        </div>
    </div>
<?php
    return ob_get_clean();
});


add_action('woocommerce_shipping_init', function () {
    if (class_exists('MP_Paps_Shipping_Method')) {
        error_log('[DEBUG] Classe MP_Paps_Shipping_Method OK après shipping_init.');
    } else {
        error_log('[DEBUG] Classe toujours absente après shipping_init.');
    }
}); 
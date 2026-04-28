<?php

if (!defined('ABSPATH')) exit;

/**
 * MP_Sync_Service
 *
 * Responsabilité unique : synchronisation périodique via WP-Cron.
 * Gère les syncs horaires, quotidiennes et hebdomadaires.
 *
 * Extrait de MP_Creator_Notifier_Pro — aucune modification de comportement.
 */
class MP_Sync_Service
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
        add_action('mp_hourly_sync', [$this, 'run_hourly_sync']);
        add_action('mp_daily_sync',  [$this, 'run_daily_sync']);
        add_action('mp_weekly_sync', [$this, 'run_weekly_sync']);
    }

    // =========================================================
    // PLANIFICATION
    // =========================================================

    public function schedule()
    {
        $interval = get_option('mp_auto_sync_interval', 'hourly');
        if ($interval === 'disabled') return;

        if (!wp_next_scheduled('mp_hourly_sync')) wp_schedule_event(time(), 'hourly',  'mp_hourly_sync');
        if (!wp_next_scheduled('mp_daily_sync'))  wp_schedule_event(time(), 'daily',   'mp_daily_sync');
        if (!wp_next_scheduled('mp_weekly_sync')) wp_schedule_event(time(), 'weekly',  'mp_weekly_sync');
    }

    public function unschedule()
    {
        wp_clear_scheduled_hook('mp_hourly_sync');
        wp_clear_scheduled_hook('mp_daily_sync');
        wp_clear_scheduled_hook('mp_weekly_sync');
    }

    // =========================================================
    // CRON HANDLERS
    // =========================================================

    public function run_hourly_sync()
    {
        error_log('MP Sync Service: Running hourly sync');
        $this->sync_recent_orders(24);
        $this->sync_recent_products(24);
        $this->retry_failed_webhooks();
    }

    public function run_daily_sync()
    {
        error_log('MP Sync Service: Running daily sync');
        MP_Creator_Webhook::send_full_sync_request('creators');
        $this->sync_recent_products(168);
        $this->clean_old_logs();
    }

    public function run_weekly_sync()
    {
        error_log('MP Sync Service: Running weekly full sync');
        MP_Creator_Webhook::send_full_sync_request('all');
        $this->generate_sync_report();
    }

    // =========================================================
    // SYNCS MANUELLES (accessibles depuis l'admin)
    // =========================================================

    public function sync_by_type($sync_type, $date_range = 'all')
    {
        switch ($sync_type) {
            case 'creators':
                MP_Creator_Webhook::send_full_sync_request('creators');
                break;
            case 'products':
                $date_range === 'all'
                    ? MP_Creator_Webhook::send_full_sync_request('products')
                    : $this->sync_recent_products($this->hours_from_range($date_range));
                break;
            case 'orders':
                $date_range === 'all'
                    ? MP_Creator_Webhook::send_full_sync_request('orders')
                    : $this->sync_recent_orders($this->hours_from_range($date_range));
                break;
            default:
                MP_Creator_Webhook::send_full_sync_request('all');
        }
    }

    public function hours_from_range($range)
    {
        return ['today' => 24, 'week' => 168, 'month' => 720][$range] ?? 24;
    }

    // =========================================================
    // SYNCS INTERNES
    // =========================================================

    public function sync_recent_orders($hours = 24)
    {
        $order_ids = wc_get_orders([
            'limit'        => -1,
            'date_created' => '>' . date('Y-m-d H:i:s', strtotime("-{$hours} hours")),
            'return'       => 'ids',
        ]);

        foreach ($order_ids as $order_id) {
            MP_Creator_Webhook::send_order_sync($order_id, 'periodic_sync');
            MP_Creator_Webhook::send_order_with_creators($order_id);
        }

        error_log('MP Sync Service: Synced ' . count($order_ids) . " orders ({$hours}h)");
    }

    public function sync_recent_products($hours = 24)
    {
        global $wpdb;

        $products = $wpdb->get_results($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'product'
             AND post_modified > %s
             LIMIT 200",
            date('Y-m-d H:i:s', strtotime("-{$hours} hours"))
        ));

        foreach ($products as $product) {
            MP_Creator_Webhook::send_product_sync($product->ID, 'periodic_sync');
        }

        error_log('MP Sync Service: Synced ' . count($products) . " products ({$hours}h)");
    }

    public function sync_products_from_order($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $product_ids = [];
        foreach ($order->get_items() as $item) {
            $product_ids[] = $item->get_product_id();
        }

        foreach (array_unique($product_ids) as $product_id) {
            MP_Creator_Webhook::send_product_sync($product_id, 'order_triggered');
        }
    }

    // =========================================================
    // UTILITAIRES
    // =========================================================

    private function retry_failed_webhooks()
    {
        $failed = MP_Creator_DB::get_instance()->get_failed_webhooks(24);

        foreach ($failed as $webhook) {
            if (in_array($webhook->event_type, ['order.created', 'order.status_changed'], true)) {
                MP_Creator_Webhook::send_order_sync($webhook->order_id, 'retry_' . $webhook->event_type);
            } elseif ($webhook->event_type === 'product.saved' && !empty($webhook->product_id)) {
                MP_Creator_Webhook::send_product_sync($webhook->product_id, 'retry');
            }
        }
    }

    private function clean_old_logs()
    {
        MP_Creator_DB::get_instance()->clean_old_data(30);
    }

    private function generate_sync_report()
    {
        global $wpdb;

        $db    = MP_Creator_DB::get_instance();
        $table = $db->get_table_name('webhooks');

        $stats = $wpdb->get_row(
            "SELECT COUNT(*) as total,
                    SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) as success,
                    SUM(CASE WHEN status='failed'  THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending
             FROM {$table}
             WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        error_log('MP Sync Service: Weekly report — ' . json_encode($stats));

        if ($stats && $stats->failed > 10) {
            wp_mail(
                get_option('admin_email'),
                'MP Creator — Sync Report — Attention required',
                "Weekly sync report:\nTotal: {$stats->total}\nSuccess: {$stats->success}\nFailed: {$stats->failed}\nPending: {$stats->pending}"
            );
        }
    }
}
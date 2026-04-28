<?php

if (!defined('ABSPATH')) exit;

/**
 * MP_Ajax_Handler
 *
 * Responsabilité unique : traiter les 5 requêtes AJAX admin.
 * Extrait de MP_Creator_Notifier_Pro — aucune modification de comportement.
 */
class MP_Ajax_Handler
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
        add_action('wp_ajax_mp_test_laravel_connection', [$this, 'test_laravel_connection']);
        add_action('wp_ajax_mp_send_webhook_test',       [$this, 'send_webhook_test']);
        add_action('wp_ajax_mp_manual_sync',             [$this, 'manual_sync']);
        add_action('wp_ajax_mp_force_full_sync',         [$this, 'force_full_sync']);
        add_action('wp_ajax_mp_delete_creator',          [$this, 'delete_creator']);
    }

    // =========================================================
    // HANDLERS
    // =========================================================

    public function test_laravel_connection()
    {
        check_ajax_referer('mp_test_connection', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Unauthorized'], 403); }

        $response = MP_Creator_Webhook::test_connection(
            sanitize_text_field($_POST['url']),
            sanitize_text_field($_POST['secret'])
        );

        $response['success']
            ? wp_send_json_success(['message' => $response['message']])
            : wp_send_json_error(['message' => $response['message']]);
    }

    public function send_webhook_test()
    {
        check_ajax_referer('mp_test_webhook', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Unauthorized'], 403); }

        $result = MP_Creator_Webhook::send_creator_created(intval($_POST['creator_id']), true);

        $result
            ? wp_send_json_success(['message' => 'Webhook sent successfully'])
            : wp_send_json_error(['message' => 'Failed to send webhook']);
    }

    public function manual_sync()
    {
        check_ajax_referer('mp_manual_sync', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Unauthorized'], 403); }

        $type = $_POST['type'] ?? 'product';
        $id   = intval($_POST['id'] ?? 0);

        if ($type === 'product' && $id > 0) {
            $result = MP_Creator_Webhook::send_product_sync($id, 'manual_sync');
            if ($result) {
                update_post_meta($id, '_mp_sync_status', 'success');
                update_post_meta($id, '_mp_last_sync',   current_time('mysql'));
                wp_send_json_success(['message' => 'Product synced successfully']);
            } else {
                update_post_meta($id, '_mp_sync_status', 'failed');
                wp_send_json_error(['message' => 'Failed to sync product']);
            }
        } elseif ($type === 'order' && $id > 0) {
            $result = MP_Creator_Webhook::send_order_sync($id, 'manual_sync');
            $result
                ? wp_send_json_success(['message' => 'Order sync initiated'])
                : wp_send_json_error(['message' => 'Failed to sync order']);
        } else {
            wp_send_json_error(['message' => 'Invalid parameters']);
        }
    }

    public function force_full_sync()
    {
        check_ajax_referer('mp_force_full_sync', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Unauthorized'], 403); }

        MP_Creator_Webhook::send_full_sync_request('all');
        wp_send_json_success(['message' => 'Full sync initiated']);
    }

    public function delete_creator()
    {
        check_ajax_referer('mp_delete_creator', 'nonce');
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['message' => 'Unauthorized'], 403); }

        $creator_id = intval($_POST['creator_id'] ?? 0);
        if ($creator_id <= 0) { wp_send_json_error(['message' => 'Invalid creator ID']); return; }

        $db      = MP_Creator_DB::get_instance();
        $creator = $db->get_creator($creator_id);
        if (!$creator) { wp_send_json_error(['message' => 'Creator not found']); return; }

        // Notifier Laravel AVANT la suppression locale
        MP_Creator_Webhook::send_creator_deleted($creator_id, $creator);

        $result = $db->delete_creator($creator_id);

        $result
            ? wp_send_json_success(['message' => sprintf('Creator "%s" deleted successfully.', $creator->name)])
            : wp_send_json_error(['message' => 'Failed to delete creator from database']);
    }
}
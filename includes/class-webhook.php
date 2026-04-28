<?php

if (!defined('ABSPATH')) exit;

/**
 * MP_Creator_Webhook
 *
 * Toute la communication sortante vers Laravel.
 * Extrait de creator-notifier.php — aucune modification de comportement.
 */
class MP_Creator_Webhook
{
    // =========================================================
    // CONFIGURATION
    // =========================================================

    private static function get_config()
    {
        return [
            'url'     => get_option('mp_laravel_webhook_url'),
            'secret'  => get_option('mp_laravel_webhook_secret'),
            'timeout' => 15,
            'debug'   => defined('WP_DEBUG') && WP_DEBUG,
        ];
    }

    private static function build_url($base_url, $endpoint)
    {
        $base_url = rtrim($base_url, '/');
        $endpoint = ltrim($endpoint, '/');

        if (strpos($base_url, '/api/webhooks/') !== false) {
            $parts    = explode('/api/webhooks/', $base_url);
            $base_url = $parts[0] . '/api/webhooks';
        }

        if (strpos($base_url, '/api/webhooks') !== false) {
            return $base_url . '/' . $endpoint;
        }

        return $base_url . '/api/webhooks/' . $endpoint;
    }

    // =========================================================
    // TRANSPORT
    // =========================================================

    private static function send_webhook($endpoint, $data, $log_data = [])
    {
        $config = self::get_config();

        if (empty($config['url']) || empty($config['secret'])) {
            error_log('MP Creator Webhook: Configuration manquante (URL ou Secret)');
            return false;
        }

        $url      = self::build_url($config['url'], $endpoint);
        $response = wp_remote_post($url, [
            'method'    => 'POST',
            'timeout'   => $config['timeout'],
            'blocking'  => true,
            'headers'   => [
                'Content-Type'       => 'application/json',
                'X-MP-Webhook-Token' => $config['secret'],
                'User-Agent'         => 'WordPress/' . get_bloginfo('version') . ' MP-Creator/' . MP_CREATOR_NOTIFIER_VERSION,
            ],
            'body'      => json_encode($data),
            'sslverify' => !$config['debug'],
        ]);

        $success       = false;
        $status_code   = 0;
        $error_message = null;

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log("MP Creator Webhook: Erreur — {$error_message}");
        } else {
            $status_code = wp_remote_retrieve_response_code($response);
            $body        = wp_remote_retrieve_body($response);

            if ($status_code >= 200 && $status_code < 300) {
                $success = true;
                if ($config['debug']) {
                    error_log("MP Creator Webhook: Succès HTTP {$status_code}");
                }
            } else {
                $error_message = "HTTP {$status_code}: {$body}";
                error_log("MP Creator Webhook: Échec HTTP {$status_code} — {$body}");
            }
        }

        MP_Creator_DB::get_instance()->log_webhook(
            $log_data['event_type'] ?? 'unknown',
            $log_data['order_id']   ?? null,
            $log_data['creator_id'] ?? null,
            $log_data['product_id'] ?? null,
            $success ? 'success' : 'failed',
            $status_code,
            $error_message
        );

        return $success;
    }

    // =========================================================
    // CRÉATEURS
    // =========================================================

    public static function send_creator_created($creator_id, $force_sync = false)
    {
        $creator = MP_Creator_DB::get_instance()->get_creator($creator_id);
        if (!$creator) {
            error_log("MP Creator Webhook: Creator #{$creator_id} not found");
            return false;
        }

        $success = self::send_webhook('creator-created', [
            'event'     => 'creator.created',
            'creator'   => [
                'wp_creator_id' => $creator->id,
                'name'          => $creator->name,
                'email'         => $creator->email,
                'phone'         => $creator->phone,
                'brand_slug'    => $creator->brand_slug,
                'address'       => $creator->address,
                'status'        => $creator->status ?? 'active',
            ],
            'timestamp' => current_time('mysql'),
            'site_url'  => site_url(),
        ], ['event_type' => 'creator.created', 'creator_id' => $creator_id]);

        if ($success) {
            MP_Creator_DB::get_instance()->update_creator($creator_id, ['last_synced_at' => current_time('mysql')]);
        }

        return $success;
    }

    public static function send_creator_deleted($creator_id, $creator_data)
    {
        return self::send_webhook('creator-deleted', [
            'event'     => 'creator.deleted',
            'creator'   => [
                'wp_creator_id' => $creator_id,
                'wp_laravel_id' => $creator_data->wp_creator_id ?? null,
                'name'          => $creator_data->name,
                'email'         => $creator_data->email,
                'brand_slug'    => $creator_data->brand_slug,
            ],
            'timestamp' => current_time('mysql'),
            'site_url'  => site_url(),
        ], ['event_type' => 'creator.deleted', 'creator_id' => $creator_id]);
    }

    // =========================================================
    // COMMANDES
    // =========================================================

    public static function send_order_sync($order_id, $event_type, $new_status = null)
    {
        $order = wc_get_order($order_id);
        if (!$order) return false;

        $data = [
            'event'     => 'order.' . $event_type,
            'order_id'  => (int) $order_id,
            'timestamp' => current_time('mysql'),
            'site_url'  => site_url(),
        ];
        if ($new_status) $data['new_status'] = $new_status;

        return self::send_webhook('wordpress/sync-orders', $data, [
            'event_type' => 'order.' . $event_type,
            'order_id'   => $order_id,
        ]);
    }

    public static function send_order_with_creators($order_id)
    {
        $resolver      = MP_Creator_Ownership_Resolver::get_instance();
        $grouped       = $resolver->group_products_by_creator($order_id);
        $creators_data = [];

        foreach ($grouped as $group) {
            $creators_data[] = [
                'creator_id'    => $group['creator']->id,
                'wp_creator_id' => $group['creator']->wp_creator_id ?? null,
                'name'          => $group['creator']->name,
                'email'         => $group['creator']->email,
                'brand_slug'    => $group['creator']->brand_slug,
                'total'         => $group['total'],
                'products'      => $group['products'],
            ];
        }

        return self::send_webhook('sync-orders-with-creators', [
            'event'          => 'order.with_creators',
            'order_id'       => (int) $order_id,
            'creators'       => $creators_data,
            'creators_count' => count($creators_data),
            'timestamp'      => current_time('mysql'),
            'site_url'       => site_url(),
        ], ['event_type' => 'order.with_creators', 'order_id' => $order_id]);
    }

    public static function send_order_cancelled($order_id, $status)
    {
        return self::send_webhook('wordpress/sync-orders', [
            'event'     => 'order.cancelled',
            'order_id'  => (int) $order_id,
            'status'    => $status,
            'timestamp' => current_time('mysql'),
            'site_url'  => site_url(),
        ], ['event_type' => 'order.cancelled', 'order_id' => $order_id]);
    }

    public static function send_order_refund($order_id, $refund_id, $amount)
    {
        return self::send_webhook('wordpress/sync-orders', [
            'event'     => 'order.refunded',
            'order_id'  => (int) $order_id,
            'refund_id' => (int) $refund_id,
            'amount'    => (float) $amount,
            'timestamp' => current_time('mysql'),
            'site_url'  => site_url(),
        ], ['event_type' => 'order.refunded', 'order_id' => $order_id]);
    }

    // =========================================================
    // PRODUITS
    // =========================================================

    public static function send_product_sync($product_id, $event_type, $extra_data = [])
    {
        $product = wc_get_product($product_id);
        if (!$product) return false;

        $data    = array_merge([
            'event'      => 'product.' . $event_type,
            'product_id' => $product_id,
            'brand_slug' => get_post_meta($product_id, 'brand_slug', true),
            'timestamp'  => current_time('mysql'),
            'site_url'   => site_url(),
        ], $extra_data);

        $success = self::send_webhook('wordpress/sync-products', $data, [
            'event_type' => 'product.' . $event_type,
            'product_id' => $product_id,
        ]);

        update_post_meta($product_id, '_mp_last_sync',   current_time('mysql'));
        update_post_meta($product_id, '_mp_sync_status', $success ? 'success' : 'failed');

        return $success;
    }

    public static function send_product_deleted($product_id)
    {
        return self::send_webhook('wordpress/sync-products', [
            'event'      => 'product.deleted',
            'product_id' => $product_id,
            'timestamp'  => current_time('mysql'),
            'site_url'   => site_url(),
        ], ['event_type' => 'product.deleted', 'product_id' => $product_id]);
    }

    public static function send_products_sync_by_brand($brand_slug)
    {
        if (empty($brand_slug)) return false;

        return self::send_webhook('sync-products-by-brand', [
            'event'      => 'products.by_brand',
            'brand_slug' => $brand_slug,
            'timestamp'  => current_time('mysql'),
            'site_url'   => site_url(),
        ], ['event_type' => 'products.by_brand']);
    }

    // =========================================================
    // MARQUES
    // =========================================================

    public static function send_brand_sync($brand_data, $event_type)
    {
        return self::send_webhook('sync-brands', [
            'event'     => 'brand.' . $event_type,
            'brand'     => $brand_data,
            'timestamp' => current_time('mysql'),
            'site_url'  => site_url(),
        ], ['event_type' => 'brand.' . $event_type]);
    }

    public static function send_brand_deleted($term_id, $taxonomy)
    {
        return self::send_webhook('sync-brands', [
            'event'     => 'brand.deleted',
            'term_id'   => $term_id,
            'taxonomy'  => $taxonomy,
            'timestamp' => current_time('mysql'),
            'site_url'  => site_url(),
        ], ['event_type' => 'brand.deleted']);
    }

    // =========================================================
    // SYNC GLOBALE
    // =========================================================

    public static function send_full_sync_request($type)
    {
        return self::send_webhook('full-sync', [
            'event'     => 'full_sync',
            'sync_type' => $type,
            'timestamp' => current_time('mysql'),
            'site_url'  => site_url(),
            'version'   => MP_CREATOR_NOTIFIER_VERSION,
        ], ['event_type' => 'full_sync']);
    }

    // =========================================================
    // TEST DE CONNEXION
    // =========================================================

    public static function test_connection($url = null, $secret = null)
    {
        $config      = self::get_config();
        $test_url    = $url    ?? $config['url'];
        $test_secret = $secret ?? $config['secret'];

        if (empty($test_url) || empty($test_secret)) {
            return ['success' => false, 'message' => 'URL ou Secret manquant dans la configuration'];
        }

        $webhook_url = self::build_url($test_url, 'wordpress/test');
        $response    = wp_remote_post($webhook_url, [
            'timeout'   => 10,
            'headers'   => [
                'Content-Type'       => 'application/json',
                'X-MP-Webhook-Token' => $test_secret,
            ],
            'body'      => json_encode([
                'test'           => true,
                'timestamp'      => current_time('mysql'),
                'plugin_version' => MP_CREATOR_NOTIFIER_VERSION,
            ]),
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => 'Erreur de connexion: ' . $response->get_error_message()];
        }

        $status = wp_remote_retrieve_response_code($response);
        $body   = wp_remote_retrieve_body($response);

        if ($status === 200) {
            $data = json_decode($body, true);
            if (!empty($data['success'])) {
                return ['success' => true, 'message' => 'Connexion réussie! Laravel version: ' . ($data['laravel_version'] ?? 'N/A')];
            }
        }

        return ['success' => false, 'message' => "Laravel a retourné HTTP {$status}: {$body}"];
    }
}
<?php

if (!defined('ABSPATH')) exit;

/**
 * MP_Rest_Api
 *
 * Responsabilité unique : déclarer et traiter les 12 routes REST API.
 * Extrait de MP_Creator_Notifier_Pro — aucune modification de comportement.
 */
class MP_Rest_Api
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
        add_action('rest_api_init', [$this, 'register_routes']);

        // Filtres pour enrichir les réponses REST WooCommerce natives
        add_filter('woocommerce_rest_prepare_product',   [$this, 'filter_product_response'], 10, 3);
        add_filter('woocommerce_rest_prepare_shop_order', [$this, 'filter_order_response'],  10, 3);
    }

    // =========================================================
    // ENREGISTREMENT DES ROUTES
    // =========================================================

    public function register_routes()
    {
        $auth = [$this, 'verify_auth'];
        $ns   = 'mp/v2';

        // Créateurs
        register_rest_route($ns, '/creators',             ['methods' => 'GET',    'callback' => [$this, 'get_creators'],        'permission_callback' => $auth]);
        register_rest_route($ns, '/creators',             ['methods' => 'POST',   'callback' => [$this, 'create_creator'],       'permission_callback' => $auth]);
        register_rest_route($ns, '/creators/(?P<id>\d+)', ['methods' => 'GET',    'callback' => [$this, 'get_creator'],          'permission_callback' => $auth]);
        register_rest_route($ns, '/creators/(?P<id>\d+)', ['methods' => 'PUT',    'callback' => [$this, 'update_creator'],       'permission_callback' => $auth]);
        register_rest_route($ns, '/creators/(?P<id>\d+)', ['methods' => 'DELETE', 'callback' => [$this, 'delete_creator'],       'permission_callback' => $auth]);

        // Produits
        register_rest_route($ns, '/products/brands-bulk', ['methods' => 'POST',   'callback' => [$this, 'get_products_brands'],  'permission_callback' => $auth]);
        register_rest_route($ns, '/products/creators',    ['methods' => 'POST',   'callback' => [$this, 'get_products_creators'], 'permission_callback' => $auth]);

        // Commandes
        register_rest_route($ns, '/orders',               ['methods' => 'GET',    'callback' => [$this, 'get_orders'],           'permission_callback' => $auth]);
        register_rest_route($ns, '/orders/(?P<id>\d+)',   ['methods' => 'GET',    'callback' => [$this, 'get_order'],            'permission_callback' => $auth]);

        // Système
        register_rest_route($ns, '/system/test',          ['methods' => 'GET',    'callback' => [$this, 'system_test'],          'permission_callback' => '__return_true']);
        register_rest_route($ns, '/system/stats',         ['methods' => 'GET',    'callback' => [$this, 'system_stats'],         'permission_callback' => $auth]);

        // Sync (entrants depuis Laravel)
        register_rest_route($ns, '/sync/creators',        ['methods' => 'POST',   'callback' => [$this, 'sync_creators'],        'permission_callback' => $auth]);
        register_rest_route($ns, '/sync/products',        ['methods' => 'POST',   'callback' => [$this, 'sync_products'],        'permission_callback' => $auth]);
        register_rest_route($ns, '/sync/orders',          ['methods' => 'POST',   'callback' => [$this, 'sync_orders'],          'permission_callback' => $auth]);
    }

    // =========================================================
    // AUTHENTIFICATION
    // =========================================================

    public function verify_auth($request)
    {
        $token       = $request->get_header('X-MP-Token');
        $stored_hash = get_option('mp_api_token_hash');
        return $stored_hash && MP_Creator_API_Token::verify($token, $stored_hash);
    }

    // =========================================================
    // HANDLERS CRÉATEURS
    // =========================================================

    public function get_creators($request)
    {
        global $wpdb;

        $per_page = (int) ($request->get_param('per_page') ?? 20);
        $page     = (int) ($request->get_param('page')     ?? 1);
        $search   = $request->get_param('search');
        $offset   = ($page - 1) * $per_page;
        $table    = MP_Creator_DB::get_instance()->get_table_name('creators');
        $where    = '';

        if (!empty($search)) {
            $where = $wpdb->prepare(
                "WHERE name LIKE %s OR email LIKE %s OR brand_slug LIKE %s",
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
        }

        $creators = $wpdb->get_results("SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT {$offset}, {$per_page}");
        $total    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} {$where}");

        return rest_ensure_response([
            'data'        => $creators,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil($total / $per_page),
        ]);
    }

    public function get_creator($request)
    {
        $creator = MP_Creator_DB::get_instance()->get_creator($request->get_param('id'));
        if (!$creator) return new WP_Error('not_found', 'Creator not found', ['status' => 404]);
        return rest_ensure_response(['data' => $creator]);
    }

    public function create_creator($request)
    {
        $data       = $request->get_json_params();
        $creator_id = MP_Creator_DB::get_instance()->create_creator([
            'name'       => sanitize_text_field($data['name']),
            'email'      => sanitize_email($data['email']),
            'brand_slug' => sanitize_title($data['brand_slug']),
            'phone'      => sanitize_text_field($data['phone']    ?? ''),
            'address'    => sanitize_textarea_field($data['address'] ?? ''),
        ]);

        if (is_wp_error($creator_id)) return $creator_id;

        MP_Creator_Webhook::send_creator_created($creator_id);
        return rest_ensure_response(['success' => true, 'creator_id' => $creator_id], 201);
    }

    public function update_creator($request)
    {
        $id     = $request->get_param('id');
        $data   = $request->get_json_params();
        $update = ['updated_at' => current_time('mysql')];

        foreach (['name' => 'sanitize_text_field', 'phone' => 'sanitize_text_field'] as $field => $fn) {
            if (isset($data[$field])) $update[$field] = $fn($data[$field]);
        }
        if (isset($data['email']))      $update['email']      = sanitize_email($data['email']);
        if (isset($data['address']))    $update['address']    = sanitize_textarea_field($data['address']);
        if (isset($data['brand_slug'])) $update['brand_slug'] = sanitize_title($data['brand_slug']);
        if (isset($data['status']))     $update['status']     = $data['status'];

        $result = MP_Creator_DB::get_instance()->update_creator($id, $update);
        if (!$result) return new WP_Error('update_failed', 'Failed to update creator', ['status' => 500]);

        return rest_ensure_response(['success' => true, 'message' => 'Creator updated successfully']);
    }

    public function delete_creator($request)
    {
        $result = MP_Creator_DB::get_instance()->delete_creator($request->get_param('id'));
        if (!$result) return new WP_Error('delete_failed', 'Failed to delete creator', ['status' => 500]);
        return rest_ensure_response(['success' => true, 'message' => 'Creator deleted successfully']);
    }

    // =========================================================
    // HANDLERS PRODUITS
    // =========================================================

    public function get_products_brands($request)
    {
        global $wpdb;

        $product_ids = $request->get_json_params()['product_ids'] ?? [];
        if (empty($product_ids) || !is_array($product_ids)) {
            return new WP_Error('invalid_data', 'product_ids required', ['status' => 400]);
        }

        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $results      = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value as brand_slug
             FROM {$wpdb->postmeta}
             WHERE post_id IN ({$placeholders}) AND meta_key = 'brand_slug'",
            ...$product_ids
        ));

        $brands = [];
        foreach ($results as $row) { $brands[$row->post_id] = $row->brand_slug; }

        return rest_ensure_response(['data' => $brands]);
    }

    public function get_products_creators($request)
    {
        global $wpdb;

        $product_ids = $request->get_json_params()['product_ids'] ?? [];
        if (empty($product_ids) || !is_array($product_ids)) {
            return new WP_Error('invalid_data', 'product_ids required', ['status' => 400]);
        }

        $table        = MP_Creator_DB::get_instance()->get_table_name('creators');
        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $results      = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.post_id, c.id as creator_id
             FROM {$wpdb->postmeta} pm
             JOIN {$table} c ON pm.meta_value = c.brand_slug
             WHERE pm.post_id IN ({$placeholders}) AND pm.meta_key = 'brand_slug'",
            ...$product_ids
        ));

        $map = [];
        foreach ($results as $row) { $map[$row->post_id] = $row->creator_id; }

        return rest_ensure_response(['data' => $map]);
    }

    // =========================================================
    // HANDLERS COMMANDES
    // =========================================================

    public function get_orders($request)
    {
        $args = ['limit' => $request->get_param('per_page') ?? 20, 'page' => $request->get_param('page') ?? 1, 'return' => 'objects'];
        $status = $request->get_param('status');
        $after  = $request->get_param('after');
        if ($status) $args['status']       = $status;
        if ($after)  $args['date_created'] = '>' . $after;

        $orders = wc_get_orders($args);
        return rest_ensure_response([
            'data'  => array_map([$this, 'format_order'], $orders),
            'total' => count($orders),
        ]);
    }

    public function get_order($request)
    {
        $order = wc_get_order($request->get_param('id'));
        if (!$order) return new WP_Error('not_found', 'Order not found', ['status' => 404]);
        return rest_ensure_response(['data' => $this->format_order($order)]);
    }

    private function format_order($order)
    {
        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = [
                'id'         => $item->get_id(),
                'product_id' => $item->get_product_id(),
                'name'       => $item->get_name(),
                'quantity'   => $item->get_quantity(),
                'total'      => $item->get_total(),
                'brand_slug' => get_post_meta($item->get_product_id(), 'brand_slug', true),
            ];
        }
        return [
            'id'            => $order->get_id(),
            'number'        => $order->get_order_number(),
            'status'        => $order->get_status(),
            'date_created'  => $order->get_date_created()->date('Y-m-d H:i:s'),
            'total'         => $order->get_total(),
            'currency'      => $order->get_currency(),
            'customer_email' => $order->get_billing_email(),
            'customer_name'  => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'billing'       => $order->get_address('billing'),
            'shipping'      => $order->get_address('shipping'),
            'line_items'    => $items,
        ];
    }

    // =========================================================
    // HANDLERS SYSTÈME
    // =========================================================

    public function system_test($request)
    {
        return rest_ensure_response([
            'success'    => true,
            'message'    => 'MP Creator API is working',
            'version'    => MP_CREATOR_NOTIFIER_VERSION,
            'timestamp'  => current_time('mysql'),
            'site_url'   => site_url(),
            'wp_version' => get_bloginfo('version'),
            'wc_version' => defined('WC_VERSION') ? WC_VERSION : 'N/A',
        ]);
    }

    public function system_stats($request)
    {
        global $wpdb;
        $db = MP_Creator_DB::get_instance();
        return rest_ensure_response([
            'creators' => ['total' => $db->get_creators_count(), 'active' => $db->get_active_brands_count()],
            'products' => [
                'total'      => (int) wp_count_posts('product')->publish,
                'with_brand' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'brand_slug'"),
            ],
            'sync' => ['success_rate' => $db->get_sync_health_percentage()],
        ]);
    }

    // =========================================================
    // HANDLERS SYNC ENTRANTS (depuis Laravel)
    // =========================================================

    public function sync_creators($request) { return rest_ensure_response(['success' => true, 'message' => 'Creators sync initiated']); }
    public function sync_products($request) { return rest_ensure_response(['success' => true, 'message' => 'Products sync initiated']); }
    public function sync_orders($request)   { return rest_ensure_response(['success' => true, 'message' => 'Orders sync initiated']); }

    // =========================================================
    // FILTRES REST WOOCOMMERCE
    // =========================================================

    public function filter_product_response($response, $product, $request)
    {
        if (!isset($response->data)) return $response;

        $resolver   = MP_Creator_Ownership_Resolver::get_instance();
        $brand_slug = $resolver->resolve_brand_slug($product->get_id());
        $creator    = $brand_slug ? MP_Creator_DB::get_instance()->get_creator_by_brand($brand_slug) : null;

        $response->data['brand_slug']   = $brand_slug;
        $response->data['creator_id']   = $creator ? $creator->id   : null;
        $response->data['creator_name'] = $creator ? $creator->name : null;
        $response->data['last_sync']    = get_post_meta($product->get_id(), '_mp_last_sync', true);

        return $response;
    }

    public function filter_order_response($response, $order, $request)
    {
        if (!isset($response->data)) return $response;

        $resolver      = MP_Creator_Ownership_Resolver::get_instance();
        $grouped       = $resolver->group_products_by_creator($order->get_id());
        $creators_data = [];

        foreach ($grouped as $group) {
            $creators_data[] = [
                'id'         => $group['creator']->id,
                'name'       => $group['creator']->name,
                'email'      => $group['creator']->email,
                'brand_slug' => $group['creator']->brand_slug,
                'total'      => $group['total'],
            ];
        }

        $response->data['creators']       = $creators_data;
        $response->data['creators_count'] = count($creators_data);

        return $response;
    }
}
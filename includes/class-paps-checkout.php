<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Intégration PAPS dans le checkout WooCommerce
 * Affiche les frais de livraison calculés dynamiquement
 */
class MP_Paps_Checkout
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
        // Enregistrer la méthode de livraison PAPS
        add_filter('woocommerce_shipping_methods', array($this, 'register_shipping_method'));

        // Afficher un résumé des frais PAPS dans le checkout
        add_action('woocommerce_review_order_before_shipping', array($this, 'display_paps_fee_info'));

        // Ajouter les frais PAPS comme frais de livraison si la méthode est activée
        add_action('woocommerce_cart_calculate_fees', array($this, 'add_paps_fee_to_cart'));

        // AJAX pour recalcul côté client
        add_action('wp_ajax_mp_get_paps_shipping_fee', array($this, 'ajax_get_fee'));
        add_action('wp_ajax_nopriv_mp_get_paps_shipping_fee', array($this, 'ajax_get_fee'));

        // Scripts/styles frontend
        add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_scripts'));
    }

    /**
     * Enregistre la méthode de livraison PAPS dans WooCommerce
     */
    public function register_shipping_method($methods)
    {
        $methods['paps_shipping'] = 'WC_Paps_Shipping_Method';
        return $methods;
    }

    /**
     * Ajoute les frais PAPS au panier (mode "fee" alternatif si méthode de zone non configurée)
     */
    public function add_paps_fee_to_cart($cart)
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        if (!get_option('mp_paps_enabled', false)) {
            return;
        }

        // Ce hook sert de fallback si on n'utilise pas les shipping zones
        // La logique principale est dans WC_Paps_Shipping_Method::calculate_shipping()
    }

    /**
     * Affiche des infos PAPS dans la section livraison du checkout
     */
    public function display_paps_fee_info()
    {
        if (!get_option('mp_paps_enabled', false)) {
            return;
        }

        // Vérifier si la méthode PAPS est sélectionnée
        $chosen_methods = WC()->session ? WC()->session->get('chosen_shipping_methods') : array();
        $paps_chosen = false;
        if (is_array($chosen_methods)) {
            foreach ($chosen_methods as $method) {
                if (strpos($method, 'paps_shipping') !== false) {
                    $paps_chosen = true;
                    break;
                }
            }
        }

        if (!$paps_chosen) {
            return;
        }

        echo '<tr class="paps-shipping-info"><td colspan="2" style="padding:4px 0;">';
        echo '<small style="color:#777;">🚚 Livraison calculée via <strong>PAPS Logistics</strong> — Estimation en fonction de votre adresse de livraison.</small>';
        echo '</td></tr>';
    }

    /**
     * Scripts pour mise à jour dynamique des frais au checkout
     */
    public function enqueue_checkout_scripts()
    {
        if (!is_checkout() && !is_cart()) {
            return;
        }

        if (!get_option('mp_paps_enabled', false)) {
            return;
        }

        wp_enqueue_script(
            'mp-paps-checkout',
            MP_CREATOR_NOTIFIER_PLUGIN_URL . 'assets/paps-checkout.js',
            array('jquery', 'wc-checkout'),
            MP_CREATOR_NOTIFIER_VERSION,
            true
        );

        wp_localize_script('mp-paps-checkout', 'mp_paps_params', array(
            'ajax_url'      => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('mp_paps_fee'),
            'currency'      => get_woocommerce_currency_symbol(),
            'loading_text'  => 'Calcul des frais de livraison...',
        ));
    }

    /**
     * AJAX — Calcul des frais pour une destination donnée
     */
    public function ajax_get_fee()
    {
        check_ajax_referer('mp_paps_fee', 'nonce');

        if (!get_option('mp_paps_enabled', false)) {
            wp_send_json_error(array('message' => 'PAPS non activé.'));
        }

        $city    = sanitize_text_field($_POST['city'] ?? '');
        $state   = sanitize_text_field($_POST['state'] ?? '');
        $country = sanitize_text_field($_POST['country'] ?? '');

        $destination = implode(', ', array_filter(array($city, $state, $country)));

        if (empty($destination)) {
            wp_send_json_error(array('message' => 'Adresse de destination manquante.'));
        }

        $origin = get_option('mp_paps_default_origin', '');
        if (empty($origin)) {
            wp_send_json_error(array('message' => 'Adresse d\'origine non configurée.'));
        }

        // Construire les size_details depuis le panier
        $cart = WC()->cart;
        if (!$cart || $cart->is_empty()) {
            wp_send_json_error(array('message' => 'Panier vide.'));
        }

        $size_details = MP_Paps_API::build_size_details_from_cart($cart);

        // Cache
        $cache_key = 'paps_ajax_' . md5($origin . $destination . serialize($size_details));
        $cached    = get_transient($cache_key);

        if ($cached !== false) {
            wp_send_json_success($cached);
        }

        $delivery_type = get_option('mp_paps_delivery_type', 'STANDARD');
        $paps          = MP_Paps_API::get_instance();
        $result        = $paps->get_delivery_fee($origin, $destination, $size_details, $delivery_type);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        $currency        = get_woocommerce_currency_symbol();
        $price_formatted = number_format($result['price'], 0, ',', ' ') . ' ' . $currency;

        $data = array(
            'price'           => $result['price'],
            'price_formatted' => $price_formatted,
            'package_size'    => $result['packageSize'],
            'distance'        => $result['distance'],
        );

        set_transient($cache_key, $data, 600);

        wp_send_json_success($data);
    }
}
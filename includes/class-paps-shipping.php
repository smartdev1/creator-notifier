<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Méthode de livraison WooCommerce via PAPS
 */
class WC_Paps_Shipping_Method extends WC_Shipping_Method
{
    public function __construct($instance_id = 0)
    {
        $this->id                 = 'paps_shipping';
        $this->instance_id        = absint($instance_id);
        $this->method_title       = __('PAPS Livraison', 'mp-creator-notifier');
        $this->method_description = __('Calcul automatique des frais de livraison via l\'API PAPS Logistics.', 'mp-creator-notifier');
        $this->supports           = array('shipping-zones', 'instance-settings');

        $this->init();
    }

    public function init()
    {
        $this->init_form_fields();
        $this->init_settings();

        $this->title        = $this->get_option('title', 'Livraison PAPS');
        $this->origin       = $this->get_option('origin', get_option('mp_paps_default_origin', ''));
        $this->delivery_type = $this->get_option('delivery_type', 'STANDARD');

        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }

    public function init_form_fields()
    {
        $this->instance_form_fields = array(
            'title' => array(
                'title'   => 'Titre',
                'type'    => 'text',
                'default' => 'Livraison PAPS',
            ),
            'origin' => array(
                'title'       => 'Adresse d\'origine',
                'type'        => 'text',
                'description' => 'Adresse de départ pour le calcul (ex: Dakar, Sénégal). Laissez vide pour utiliser l\'adresse par défaut des paramètres PAPS.',
                'default'     => '',
            ),
            'delivery_type' => array(
                'title'   => 'Type de livraison',
                'type'    => 'select',
                'options' => array(
                    'STANDARD' => 'Standard',
                    'EXPRESS'  => 'Express',
                ),
                'default' => 'STANDARD',
            ),
        );
    }

    /**
     * Calcule et retourne le tarif de livraison
     */
    public function calculate_shipping($package = array())
    {
        // Vérifier que PAPS est activé et configuré
        if (!get_option('mp_paps_enabled', false)) {
            return;
        }

        $client_id = get_option('mp_paps_client_id', '');
        $client_secret = get_option('mp_paps_client_secret', '');

        if (empty($client_id) || empty($client_secret)) {
            return;
        }

        // Adresse de destination depuis le panier
        $destination = $this->build_destination_string($package['destination']);
        if (empty($destination)) {
            return;
        }

        // Adresse d'origine
        $origin = !empty($this->origin) ? $this->origin : get_option('mp_paps_default_origin', '');
        if (empty($origin)) {
            return;
        }

        // Construire les détails des colis
        $size_details = $this->build_size_details($package['contents']);
        if (empty($size_details)) {
            return;
        }

        // Clé de cache pour éviter les appels répétés
        $cache_key = 'paps_fee_' . md5($origin . $destination . $this->delivery_type . serialize($size_details));
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            $fee_data = $cached;
        } else {
            $paps = MP_Paps_API::get_instance();
            $fee_data = $paps->get_delivery_fee($origin, $destination, $size_details, $this->delivery_type);

            if (is_wp_error($fee_data)) {
                error_log('MP PAPS Shipping - ' . $fee_data->get_error_message());
                return;
            }

            // Cache 10 minutes
            set_transient($cache_key, $fee_data, 600);
        }

        $price = isset($fee_data['price']) ? intval($fee_data['price']) : 0;

        if ($price <= 0) {
            return;
        }

        $label = $this->title;
        if (!empty($fee_data['distance']) && $fee_data['distance'] > 0) {
            $label .= ' (' . number_format($fee_data['distance'], 0, ',', ' ') . ' km)';
        }

        $this->add_rate(array(
            'id'    => $this->get_rate_id(),
            'label' => $label,
            'cost'  => $price,
            'meta_data' => array(
                'paps_package_size' => $fee_data['packageSize'] ?? '',
                'paps_distance'     => $fee_data['distance'] ?? 0,
            ),
        ));
    }

    /**
     * Construit la chaîne de destination depuis les données WooCommerce
     */
    private function build_destination_string($destination)
    {
        $parts = array_filter(array(
            $destination['city']     ?? '',
            $destination['state']    ?? '',
            $destination['country']  ?? '',
        ));

        return implode(', ', $parts);
    }

    /**
     * Construit les size_details depuis le contenu du panier
     */
    private function build_size_details($cart_contents)
    {
        $size_details = array();

        foreach ($cart_contents as $item) {
            $product  = $item['data'];
            $quantity = $item['quantity'];

            $weight = $product->get_weight() ? floatval($product->get_weight()) : 1;
            $height = $product->get_height() ? floatval($product->get_height()) : 10;
            $width  = $product->get_width()  ? floatval($product->get_width())  : 10;
            $length = $product->get_length() ? floatval($product->get_length()) : 10;

            $size_details[] = array(
                'quantity' => intval($quantity),
                'weight'   => max(0.1, $weight),
                'height'   => max(1, $height),
                'width'    => max(1, $width),
                'length'   => max(1, $length),
            );
        }

        return $size_details;
    }
}
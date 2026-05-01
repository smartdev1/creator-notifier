<?php

/**
 * MP PAPS Shipping Method
 * Intègre PAPS Logistics comme méthode d'expédition native WooCommerce
 */

if (!defined('ABSPATH')) exit;

/**
 * Vérifie que WooCommerce est actif avant de continuer
 */
function mp_paps_shipping_method_init()
{
    if (!class_exists('MP_Paps_Shipping_Method')) {

        class MP_Paps_Shipping_Method extends WC_Shipping_Method
        {
            /**
             * Constructeur : Configuration de la méthode
             */
            public function __construct($instance_id = 0)
            {
                $this->id                 = 'mp_paps_shipping'; // ID unique interne
                $this->instance_id        = absint($instance_id);
                $this->method_title       = __('PAPS Logistics', 'mp-creator-notifier');
                $this->method_description = __('Calcule les frais de livraison en temps réel via l\'API PAPS Logistics en fonction du poids, des dimensions et de la destination.', 'mp-creator-notifier');

                // Fonctionnalités supportées
                $this->supports = array(
                    'shipping-zones',
                    'instance-settings',
                    'instance-settings-modal',
                );

                // Chargement des paramètres
                $this->init();

                // Si on est dans une zone d'expédition, on charge les settings spécifiques à l'instance
                // Sinon, on utilise les settings globaux (si définis plus tard)
                if ($this->instance_id > 0) {
                    $this->title = $this->get_option('title', 'Livraison PAPS');
                } else {
                    $this->title = 'Livraison PAPS';
                }
            }

            /**
             * Initialisation des paramètres
             */
            function init()
            {
                // Charge les settings de l'instance (si dans une zone)
                $this->init_form_fields();
                $this->init_settings();

                // Hooks pour sauvegarder les settings dans l'admin
                add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
            }

            /**
             * Champs de configuration dans l'admin WooCommerce
             */
            function init_form_fields()
            {
                $this->form_fields = array(
                    'title' => array(
                        'title'       => __('Titre', 'mp-creator-notifier'),
                        'type'        => 'text',
                        'description' => __('Le nom affiché au client lors du checkout.', 'mp-creator-notifier'),
                        'default'     => __('Livraison PAPS', 'mp-creator-notifier'),
                        'desc_tip'    => true,
                    ),
                    'tax_status' => array(
                        'title'   => __('Statut taxe', 'mp-creator-notifier'),
                        'type'    => 'select',
                        'options' => array(
                            'taxable' => __('Taxable', 'mp-creator-notifier'),
                            'none'    => __('Aucun', 'mp-creator-notifier'),
                        ),
                        'default' => 'taxable',
                    ),
                );
            }

            /**
             * CŒUR DU SYSTÈME : Calcul des frais
             * Appelé par WooCommerce quand le client entre son adresse
             */
            public function calculate_shipping($package = array())
            {
                // 1. Vérifier si PAPS est activé globalement
                if (!get_option('mp_paps_enabled', false)) {
                    return;
                }

                // 2. Récupérer les infos de destination
                $destination_country = $package['destination']['country'] ?? '';
                $destination_city    = $package['destination']['city'] ?? '';
                $destination_state   = $package['destination']['state'] ?? '';

                $destination_address = trim($destination_city . ', ' . $destination_state . ' ' . $destination_country);

                if (empty($destination_address)) {
                    return;
                }

                $origin_address = get_option('mp_paps_default_origin', 'Dakar, Sénégal');
                $delivery_type  = get_option('mp_paps_delivery_type', 'STANDARD'); // STANDARD ou RELAY

                // 3. Préparer les détails des colis avec calcul du Poids Volumétrique
                $size_details = array();
                $has_packages = false;

                // Constante diviseur PAPS
                $volumetric_divisor = 5000;

                foreach ($package['contents'] as $item_id => $values) {
                    $product = $values['data'];
                    $qty     = $values['quantity'];

                    if ($qty > 0 && $product->needs_shipping()) {
                        // Récupération dimensions (avec valeurs par défaut si vides)
                        $weight = $product->get_weight() ? floatval($product->get_weight()) : floatval(get_option('mp_paps_default_weight', 1));
                        $height = $product->get_height() ? floatval($product->get_height()) : floatval(get_option('mp_paps_default_height', 10));
                        $width  = $product->get_width()  ? floatval($product->get_width())  : floatval(get_option('mp_paps_default_width', 10));
                        $length = $product->get_length() ? floatval($product->get_length()) : floatval(get_option('mp_paps_default_length', 10));

                        // --- CALCUL POIDS VOLUMÉTRIQUE ---
                        // Formule : (L x l x h) / 5000
                        $volumetric_weight = ($length * $width * $height) / $volumetric_divisor;

                        // On retient le poids le plus élevé entre le poids réel et le poids volumétrique
                        $billable_weight = max($weight, $volumetric_weight);

                        error_log("[PAPS SHIPPING] Produit: " . $product->get_name() . " | Réel: {$weight}kg | Vol: " . round($volumetric_weight, 2) . "kg | Facturé: " . round($billable_weight, 2) . "kg");

                        $size_details[] = array(
                            'quantity' => intval($qty),
                            'weight'   => max(0.1, round($billable_weight, 2)), // Arrondi à 2 décimales
                            'height'   => max(1, $height),
                            'width'    => max(1, $width),
                            'length'   => max(1, $length),
                        );
                        $has_packages = true;
                    }
                }

                if (!$has_packages || empty($size_details)) {
                    return;
                }

                // 4. Appel à l'API PAPS
                $paps_api = MP_Paps_API::get_instance();

                error_log('[PAPS SHIPPING] Envoi requête API pour: ' . $destination_address);
                error_log('[PAPS SHIPPING] Payload SizeDetails: ' . json_encode($size_details));

                $result = $paps_api->get_delivery_fee($origin_address, $destination_address, $size_details, $delivery_type);

                // 5. Gestion de la réponse
                if (is_wp_error($result)) {
                    error_log('[PAPS SHIPPING] Erreur API: ' . $result->get_error_message());
                    return;
                }

                if (isset($result['price']) && $result['price'] > 0) {
                    $rate = array(
                        'id'        => $this->id . ($this->instance_id > 0 ? ':' . $this->instance_id : ''),
                        'label'     => $this->title,
                        'cost'      => $result['price'],
                        'calc_tax'  => 'per_order',
                        'meta_data' => array(
                            __('Distance', 'mp-creator-notifier') => $result['distance'] . ' km',
                            __('Type colis', 'mp-creator-notifier') => $result['packageSize'], // S, M, L, XL...
                        ),
                    );

                    $this->add_rate($rate);

                    error_log('[PAPS SHIPPING] Taux ajouté: ' . $result['price'] . ' (' . $result['packageSize'] . ')');
                } else {
                    error_log('[PAPS SHIPPING] Prix retourné nul ou invalide.');
                }
            }
        }
    }
}
// Hook critique : Enregistrement dans WooCommerce AVANT l'affichage des settings
add_action('woocommerce_shipping_init', 'mp_paps_shipping_method_init');

/**
 * Filtre pour déclarer la méthode à WooCommerce
 * Sans ceci, la méthode n'apparaît pas dans la liste "Ajouter une méthode"
 */
function mp_paps_add_shipping_method($methods)
{
    // On vérifie que la classe existe (grâce au hook précédent)
    if (class_exists('MP_Paps_Shipping_Method')) {
        $methods['mp_paps_shipping'] = 'MP_Paps_Shipping_Method';
    }
    return $methods;
}
add_filter('woocommerce_shipping_methods', 'mp_paps_add_shipping_method');

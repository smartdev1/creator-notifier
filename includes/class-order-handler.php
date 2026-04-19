<?php

if (!defined('ABSPATH')) {
    exit;
}

class MP_Order_Handler
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
        error_log('MP Order Handler - Constructeur appelé');

        // Hooks avec priorité 20 pour s'exécuter après les emails WooCommerce
        add_action('woocommerce_order_status_processing', array($this, 'handle_new_order'), 20);
        add_action('woocommerce_order_status_completed', array($this, 'handle_new_order'), 20);

        // Hook supplémentaire pour les nouvelles commandes
        add_action('woocommerce_thankyou', array($this, 'handle_new_order'), 20);

        error_log('MP Order Handler - Hooks enregistrés');
    }

    public function handle_new_order($order_id)
    {
        error_log('==============================================');
        error_log('MP Order Handler - handle_new_order DÉCLENCHÉ');
        error_log('Order ID reçu: ' . $order_id);
        error_log('==============================================');

        // Éviter les doublons
        $already_notified = get_post_meta($order_id, '_mp_creator_notified', true);
        if ($already_notified) {
            error_log('MP Order Handler - Créateurs déjà notifiés pour cette commande');
            return;
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            error_log('MP Order Handler - Commande introuvable');
            return;
        }

        error_log('MP Order Handler - Commande trouvée: #' . $order->get_order_number());
        error_log('MP Order Handler - Statut: ' . $order->get_status());

        // Récupérer les produits groupés par marque
        $products_by_brand = $this->get_products_by_brand($order);

        error_log('MP Order Handler - Marques détectées: ' . count($products_by_brand));
        error_log('MP Order Handler - Liste des marques: ' . print_r(array_keys($products_by_brand), true));

        // Envoyer les emails aux créateurs concernés
        $emails_sent = $this->notify_creators($products_by_brand, $order);

        if ($emails_sent > 0) {
            // Marquer comme notifié
            update_post_meta($order_id, '_mp_creator_notified', current_time('mysql'));
            error_log('MP Order Handler - ' . $emails_sent . ' email(s) envoyé(s)');
        } else {
            error_log('MP Order Handler - Aucun email envoyé');
        }

        error_log('==============================================');
    }

    private function get_products_by_brand($order)
    {
        error_log('MP Order Handler - get_products_by_brand appelée');

        $products_by_brand = array();

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();

            if (!$product) {
                error_log('MP Order Handler - Produit introuvable dans item');
                continue;
            }

            // NOUVEAU : Récupérer l'ID parent si c'est une variation
            $product_id = $product->get_id();
            $parent_id = $product->get_parent_id();

            // Si c'est une variation, utiliser l'ID du parent pour les taxonomies
            $taxonomy_product_id = $parent_id ? $parent_id : $product_id;

            error_log('MP Order Handler - Traitement produit ID: ' . $product_id . ' - ' . $product->get_name());
            if ($parent_id) {
                error_log('MP Order Handler - Variation détectée, parent ID: ' . $parent_id);
            }

            // Récupérer les marques du produit (ou parent si variation)
            $brands = wp_get_post_terms($taxonomy_product_id, 'product_brand');

            if (is_wp_error($brands)) {
                error_log('MP Order Handler - Erreur récupération marques: ' . $brands->get_error_message());
                continue;
            }

            error_log('MP Order Handler - Marques trouvées pour ce produit: ' . count($brands));

            if (!empty($brands)) {
                foreach ($brands as $brand) {
                    error_log('MP Order Handler - Marque: ' . $brand->name . ' (slug: ' . $brand->slug . ')');

                    $brand_slug = $brand->slug;

                    if (!isset($products_by_brand[$brand_slug])) {
                        $products_by_brand[$brand_slug] = array(
                            'brand_name' => $brand->name,
                            'brand_slug' => $brand->slug,
                            'brand_id' => $brand->term_id,
                            'products' => array()
                        );
                    }

                    $products_by_brand[$brand_slug]['products'][] = array(
                        'name' => $product->get_name(),
                        'quantity' => $item->get_quantity(),
                        'price' => $product->get_price(),
                        'sku' => $product->get_sku(),
                        'product_id' => $product_id
                    );
                }
            } else {
                error_log('MP Order Handler - Produit sans marque: ' . $product->get_name());
            }
        }

        error_log('MP Order Handler - Total marques avec produits: ' . count($products_by_brand));
        return $products_by_brand;
    }

    private function notify_creators($products_by_brand, $order)
    {
        error_log('MP Order Handler - notify_creators appelée');

        $db = MP_Creator_DB::get_instance();
        $email_handler = MP_Creator_Email::get_instance();
        $emails_sent = 0;

        $total_creators = count($products_by_brand);

        foreach ($products_by_brand as $brand_slug => $brand_data) {

            error_log('MP Order Handler - Recherche créateur pour marque: ' . $brand_slug);

            $creator = $db->get_creator_by_brand($brand_slug);

            if (!$creator) {
                error_log('MP Order Handler - ❌ Aucun créateur trouvé pour: ' . $brand_slug);
                continue;
            }

            error_log('MP Order Handler - Créateur trouvé: ' . $creator->name . ' (' . $creator->email . ')');

            // 🔥 FUSION : Réinitialisation PHPMailer + délai léger
            global $phpmailer;
            if (isset($phpmailer) && is_object($phpmailer)) {
                $phpmailer->clearAllRecipients();
                $phpmailer->clearAttachments();
                $phpmailer->clearCustomHeaders();
                $phpmailer->clearReplyTos();
                error_log('MP Creator Email - PHPMailer réinitialisé');
            }

            //  Délai contrôlé et plus léger (5 secondes)
            if ($emails_sent > 0) {
                error_log('MP Order Handler - Pause de 5 secondes...');
                sleep(5);
            }

            // Envoi de l'email
            $sent = $email_handler->send_order_notification($creator, $brand_data, $order);

            if ($sent) {
                error_log('MP Order Handler -  Email ENVOYÉ à: ' . $creator->email);
                $emails_sent++;
            } else {
                error_log('MP Order Handler - ❌ ÉCHEC envoi à: ' . $creator->email);

                if (isset($phpmailer) && !empty($phpmailer->ErrorInfo)) {
                    error_log('MP Order Handler - Erreur PHPMailer: ' . $phpmailer->ErrorInfo);
                }
            }
        }

        error_log('MP Order Handler - Total: ' . $emails_sent . '/' . $total_creators . ' email(s) envoyé(s)');

        return $emails_sent;
    }


    public function get_order_creators($order_id)
    {
        $order = wc_get_order($order_id);
        $creators = array();

        if (!$order) {
            return $creators;
        }

        $products_by_brand = $this->get_products_by_brand($order);
        $db = MP_Creator_DB::get_instance();

        foreach ($products_by_brand as $brand_slug => $brand_data) {
            $creator = $db->get_creator_by_brand($brand_slug);
            if ($creator) {
                $creators[] = array(
                    'creator' => $creator,
                    'brand' => $brand_data['brand_name'],
                    'products' => $brand_data['products']
                );
            }
        }

        return $creators;
    }
}

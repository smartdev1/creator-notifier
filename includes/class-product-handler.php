<?php

if (!defined('ABSPATH')) exit;

/**
 * MP_Product_Handler
 *
 * Responsabilité unique : réagir aux événements WooCommerce sur les produits
 * et déclencher les synchronisations Laravel correspondantes.
 *
 * Extrait de MP_Creator_Notifier_Pro — aucune modification de comportement.
 */
class MP_Product_Handler
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
        add_action('woocommerce_new_product',                [$this, 'handle_product_save'],          10, 1);
        add_action('woocommerce_update_product',             [$this, 'handle_product_save'],          10, 1);
        add_action('woocommerce_product_meta_save',          [$this, 'handle_product_save'],          10, 1);
        add_action('woocommerce_before_product_object_save', [$this, 'handle_product_before_save'],   10, 1);
        add_action('woocommerce_product_set_stock',          [$this, 'handle_product_stock_change'],  10, 1);
        add_action('woocommerce_variation_set_stock',        [$this, 'handle_product_stock_change'],  10, 1);
        add_action('woocommerce_product_set_price',          [$this, 'handle_product_price_change'],  10, 1);
        add_action('woocommerce_product_deleted',            [$this, 'handle_product_deleted'],       10, 1);
    }

    // =========================================================
    // HANDLERS
    // =========================================================

    public function handle_product_save($product_id)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($product_id)) return;

        $product = wc_get_product($product_id);
        if (!$product) return;

        error_log("MP Product Handler: Produit #{$product_id} sauvegardé");

        $resolver   = MP_Creator_Ownership_Resolver::get_instance();
        $brand_slug = $resolver->resolve_brand_slug($product_id);

        $product_data = [
            'id'             => $product_id,
            'name'           => $product->get_name(),
            'slug'           => $product->get_slug(),
            'sku'            => $product->get_sku(),
            'price'          => $product->get_price(),
            'regular_price'  => $product->get_regular_price(),
            'sale_price'     => $product->get_sale_price(),
            'stock_quantity' => $product->get_stock_quantity(),
            'stock_status'   => $product->get_stock_status(),
            'manage_stock'   => $product->get_manage_stock(),
            'brand_slug'     => $brand_slug,
            'categories'     => wp_get_post_terms($product_id, 'product_cat', ['fields' => 'names']),
            'image'          => wp_get_attachment_url($product->get_image_id()),
            'permalink'      => get_permalink($product_id),
            'date_modified'  => $product->get_date_modified()
                ? $product->get_date_modified()->date('Y-m-d H:i:s')
                : current_time('mysql'),
        ];

        MP_Creator_Webhook::send_product_sync($product_id, 'product_saved', $product_data);

        if (!empty($brand_slug)) {
            $creator = MP_Creator_DB::get_instance()->get_creator_by_brand($brand_slug);
            if ($creator) {
                update_post_meta($product_id, 'creator_id', $creator->id);
            }
        }

        update_post_meta($product_id, '_mp_last_sync',   current_time('mysql'));
        update_post_meta($product_id, '_mp_sync_status', 'pending');
    }

    public function handle_product_before_save($product)
    {
        // Hook disponible pour extensions futures — ne rien faire pour l'instant.
    }

    public function handle_product_stock_change($product)
    {
        $product_id = $product->get_id();
        error_log("MP Product Handler: Stock changé pour produit #{$product_id}");

        MP_Creator_Webhook::send_product_sync($product_id, 'stock_updated', [
            'stock_quantity' => $product->get_stock_quantity(),
            'stock_status'   => $product->get_stock_status(),
            'manage_stock'   => $product->get_manage_stock(),
        ]);
    }

    public function handle_product_price_change($product)
    {
        $product_id = $product->get_id();
        error_log("MP Product Handler: Prix changé pour produit #{$product_id}");

        MP_Creator_Webhook::send_product_sync($product_id, 'price_updated', [
            'price'         => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price'    => $product->get_sale_price(),
        ]);
    }

    public function handle_product_deleted($product_id)
    {
        error_log("MP Product Handler: Produit #{$product_id} supprimé");
        MP_Creator_Webhook::send_product_deleted($product_id);
    }
}
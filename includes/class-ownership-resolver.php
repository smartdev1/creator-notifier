<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CreatorOwnershipResolver
 *
 * Résout la relation produit → créateur de façon fiable et déterministe.
 *
 * Priorité de résolution (notifer1.md §Refactor product ownership logic) :
 *  1. Postmeta `brand_slug` (source explicite WooCommerce)
 *  2. Taxonomie `product_brand` (WooCommerce Brands)
 *  3. Taxonomie `brand` (plugins tiers)
 *  4. Attribut produit contenant "brand" ou "marque"
 *
 * Ce service ENCAPSULE la logique existante sans la remplacer (notifier2.md).
 * Toutes les méthodes retournent null plutôt que de lever une exception.
 */
class MP_Creator_Ownership_Resolver
{
    private static $instance = null;

    /** Cache en mémoire pour éviter les requêtes DB répétées dans une même requête. */
    private $cache = [];

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    // =========================================================
    // API PUBLIQUE
    // =========================================================

    /**
     * Retourne le créateur propriétaire d'un produit.
     *
     * @param int $product_id
     * @return object|null  Ligne de la table mp_creators, ou null.
     */
    public function get_creator_by_product($product_id)
    {
        if (isset($this->cache['product_' . $product_id])) {
            return $this->cache['product_' . $product_id];
        }

        $brand_slug = $this->resolve_brand_slug($product_id);

        if (empty($brand_slug)) {
            error_log("MP CreatorOwnershipResolver: Aucune marque trouvée pour produit #{$product_id}");
            $this->cache['product_' . $product_id] = null;
            return null;
        }

        $db      = MP_Creator_DB::get_instance();
        $creator = $db->get_creator_by_brand($brand_slug);

        if (!$creator) {
            error_log("MP CreatorOwnershipResolver: Aucun créateur actif pour brand_slug '{$brand_slug}' (produit #{$product_id})");
        }

        $this->cache['product_' . $product_id] = $creator;
        return $creator;
    }

    /**
     * Retourne tous les créateurs distincts impliqués dans une commande.
     *
     * @param int $order_id
     * @return object[]  Tableau de lignes mp_creators (peut être vide).
     */
    public function get_creators_from_order($order_id)
    {
        $grouped = $this->group_products_by_creator($order_id);
        return array_values(array_column($grouped, 'creator'));
    }

    /**
     * Regroupe les produits d'une commande par créateur.
     *
     * @param int $order_id
     * @return array  [
     *   creator_id => [
     *     'creator'  => object,   // ligne mp_creators
     *     'products' => [         // produits de ce créateur dans la commande
     *       ['id', 'name', 'quantity', 'total', 'sku', 'brand_slug']
     *     ],
     *     'total'    => float,    // total pour ce créateur
     *   ]
     * ]
     */
    public function group_products_by_creator($order_id)
    {
        $cache_key = 'order_' . $order_id;
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            error_log("MP CreatorOwnershipResolver: Commande #{$order_id} introuvable.");
            return [];
        }

        $grouped = [];

        foreach ($order->get_items() as $item) {
            $product_id = (int) $item->get_product_id();

            // Utiliser l'ID parent pour les variations
            $product = $item->get_product();
            if ($product && $product->is_type('variation')) {
                $product_id = (int) $product->get_parent_id();
            }

            $creator = $this->get_creator_by_product($product_id);
            if (!$creator) {
                continue;
            }

            $creator_id = (int) $creator->id;

            if (!isset($grouped[$creator_id])) {
                $grouped[$creator_id] = [
                    'creator'  => $creator,
                    'products' => [],
                    'total'    => 0.0,
                ];
            }

            $line_total = (float) $item->get_total();

            $grouped[$creator_id]['products'][] = [
                'id'         => $item->get_product_id(),
                'name'       => $item->get_name(),
                'quantity'   => $item->get_quantity(),
                'total'      => $line_total,
                'sku'        => $product ? $product->get_sku() : '',
                'brand_slug' => $creator->brand_slug,
            ];

            $grouped[$creator_id]['total'] += $line_total;
        }

        $this->cache[$cache_key] = $grouped;
        return $grouped;
    }

    /**
     * Vide le cache pour une commande donnée (utile après modification).
     *
     * @param int $order_id
     */
    public function invalidate_order_cache($order_id)
    {
        unset($this->cache['order_' . $order_id]);
    }

    // =========================================================
    // RÉSOLUTION DE MARQUE — LOGIQUE INTERNE
    // =========================================================

    /**
     * Résout le brand_slug d'un produit en testant les sources dans l'ordre
     * de priorité défini par notifer1.md.
     *
     * @param int $product_id
     * @return string|null
     */
    public function resolve_brand_slug($product_id)
    {
        // Pour les variations, utiliser le parent
        $resolved_id = $this->get_canonical_product_id($product_id);

        // --- Priorité 1 : postmeta `brand_slug` ---
        $brand_slug = get_post_meta($resolved_id, 'brand_slug', true);
        if (!empty($brand_slug)) {
            return sanitize_title($brand_slug);
        }

        // --- Priorité 2 : postmeta `_brand` (plugins tiers) ---
        $brand_meta = get_post_meta($resolved_id, '_brand', true);
        if (!empty($brand_meta)) {
            $slug = sanitize_title($brand_meta);
            // Persister pour les prochains appels
            update_post_meta($resolved_id, 'brand_slug', $slug);
            return $slug;
        }

        // --- Priorité 3 : taxonomie `product_brand` ---
        $slug = $this->resolve_from_taxonomy($resolved_id, 'product_brand');
        if ($slug) {
            update_post_meta($resolved_id, 'brand_slug', $slug);
            return $slug;
        }

        // --- Priorité 4 : taxonomie `brand` ---
        $slug = $this->resolve_from_taxonomy($resolved_id, 'brand');
        if ($slug) {
            update_post_meta($resolved_id, 'brand_slug', $slug);
            return $slug;
        }

        // --- Priorité 5 : attribut produit ---
        $slug = $this->resolve_from_attribute($resolved_id);
        if ($slug) {
            update_post_meta($resolved_id, 'brand_slug', $slug);
            return $slug;
        }

        return null;
    }

    /**
     * Retourne l'ID canonique du produit (parent si variation).
     */
    private function get_canonical_product_id($product_id)
    {
        $product = wc_get_product($product_id);
        if ($product && $product->is_type('variation')) {
            return (int) $product->get_parent_id();
        }
        return (int) $product_id;
    }

    /**
     * Résout le brand_slug depuis une taxonomie WooCommerce.
     */
    private function resolve_from_taxonomy($product_id, $taxonomy)
    {
        if (!taxonomy_exists($taxonomy)) {
            return null;
        }
        $terms = wp_get_post_terms($product_id, $taxonomy);
        if (is_wp_error($terms) || empty($terms)) {
            return null;
        }
        return $terms[0]->slug;
    }

    /**
     * Résout le brand_slug depuis les attributs du produit.
     */
    private function resolve_from_attribute($product_id)
    {
        $product = wc_get_product($product_id);
        if (!$product) {
            return null;
        }

        foreach ($product->get_attributes() as $attribute) {
            $attr_name = strtolower($attribute->get_name());
            if (strpos($attr_name, 'brand') !== false || strpos($attr_name, 'marque') !== false) {
                $options = $attribute->get_options();
                if (!empty($options)) {
                    return sanitize_title($options[0]);
                }
            }
        }
        return null;
    }
}
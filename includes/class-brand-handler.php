<?php

if (!defined('ABSPATH')) exit;

/**
 * MP_Brand_Handler
 *
 * Responsabilité unique : réagir aux événements sur les taxonomies de marques
 * (product_brand, brand, pa_brand) et notifier Laravel.
 *
 * Extrait de MP_Creator_Notifier_Pro — aucune modification de comportement.
 */
class MP_Brand_Handler
{
    private static $instance = null;

    /** Taxonomies surveillées */
    private const BRAND_TAXONOMIES = ['product_brand', 'brand', 'pa_brand'];

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('created_term', [$this, 'handle_brand_term_save'],   10, 3);
        add_action('edited_term',  [$this, 'handle_brand_term_save'],   10, 3);
        add_action('delete_term',  [$this, 'handle_brand_term_delete'], 10, 3);
    }

    // =========================================================
    // HANDLERS
    // =========================================================

    public function handle_brand_term_save($term_id, $tt_id, $taxonomy)
    {
        if (!in_array($taxonomy, self::BRAND_TAXONOMIES, true)) return;

        $term = get_term($term_id, $taxonomy);
        if (!$term || is_wp_error($term)) return;

        error_log("MP Brand Handler: Marque sauvegardée — {$term->name}");

        MP_Creator_Webhook::send_brand_sync([
            'term_id'     => $term_id,
            'name'        => $term->name,
            'slug'        => $term->slug,
            'description' => $term->description,
            'taxonomy'    => $taxonomy,
        ], 'brand_saved');
    }

    public function handle_brand_term_delete($term_id, $tt_id, $taxonomy)
    {
        if (!in_array($taxonomy, self::BRAND_TAXONOMIES, true)) return;

        error_log("MP Brand Handler: Marque supprimée ID — {$term_id}");

        MP_Creator_Webhook::send_brand_deleted($term_id, $taxonomy);
    }
}
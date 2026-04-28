<?php

if (!defined('ABSPATH')) {
    exit;
}

class MP_Creator_Shortcodes {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_shortcode('product_creator', array($this, 'product_creator_shortcode'));
    }
    
    public function product_creator_shortcode($atts) {
        $atts = shortcode_atts(array(
            'product_id' => get_the_ID(),
        ), $atts);
        
        $product_id = $atts['product_id'];
        $brands = wp_get_post_terms($product_id, 'product_brand');
        
        if (empty($brands) || is_wp_error($brands)) {
            return '';
        }
        
        $output = '';
        $db = MP_Creator_DB::get_instance();
        
        foreach ($brands as $brand) {
            $creator = $db->get_creator_by_brand($brand->slug);
            
            if ($creator) {
                $output .= '<div class="product-creator-info">';
                $output .= '<h4>Créateur : ' . esc_html($creator->name) . '</h4>';
                if ($creator->email) {
                    $output .= '<p>Email : ' . esc_html($creator->email) . '</p>';
                }
                if ($creator->phone) {
                    $output .= '<p>Téléphone : ' . esc_html($creator->phone) . '</p>';
                }
                $output .= '</div>';
            }
        }
        
        return $output;
    }
}

// Initialiser les shortcodes
MP_Creator_Shortcodes::get_instance();
<?php

if (!defined('ABSPATH')) {
    exit;
}

class MP_Creator_Manager
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
        add_action('wp_ajax_create_creator', array($this, 'handle_create_creator'));
        add_action('wp_ajax_update_creator', array($this, 'handle_update_creator'));
        add_action('wp_ajax_delete_creator', array($this, 'handle_delete_creator'));
    }

    public function create_creator($data)
    {
        // Validation des données
        $validation = $this->validate_creator_data($data);
        if (!$validation['valid']) {
            return $validation;
        }

        // Vérifier que la marque existe
        if (!$this->brand_exists($data['brand_slug'])) {
            return array(
                'valid' => false,
                'message' => 'La marque sélectionnée n\'existe pas.'
            );
        }

        // Vérifier que l'email n'existe pas déjà
        $db = MP_Creator_DB::get_instance();
        $existing_creator = $db->get_creator_by_email($data['email']);
        if ($existing_creator) {
            return array(
                'valid' => false,
                'message' => 'Un créateur avec cet email existe déjà.'
            );
        }

        // Vérifier que la marque n'est pas déjà attribuée
        $existing_brand_creator = $db->get_creator_by_brand($data['brand_slug']);
        if ($existing_brand_creator) {
            return array(
                'valid' => false,
                'message' => 'Un créateur est déjà attribué à cette marque.'
            );
        }

        // Insérer dans la base de données
        $creator_id = $db->insert_creator($data);

        if ($creator_id) {
            return array(
                'valid' => true,
                'message' => 'Créateur ajouté avec succès.',
                'creator_id' => $creator_id
            );
        }

        return array(
            'valid' => false,
            'message' => 'Erreur lors de l\'ajout du créateur.'
        );
    }

    public function update_creator($id, $data)
    {
        // Validation des données
        $validation = $this->validate_creator_data($data, $id);
        if (!$validation['valid']) {
            return $validation;
        }

        // Vérifier que la marque existe
        if (!$this->brand_exists($data['brand_slug'])) {
            return array(
                'valid' => false,
                'message' => 'La marque sélectionnée n\'existe pas.'
            );
        }

        $db = MP_Creator_DB::get_instance();

        // Vérifier que l'email n'existe pas déjà pour un autre créateur
        $existing_creator = $db->get_creator_by_email($data['email']);
        if ($existing_creator && $existing_creator->id != $id) {
            return array(
                'valid' => false,
                'message' => 'Un autre créateur avec cet email existe déjà.'
            );
        }

        // Vérifier que la marque n'est pas déjà attribuée à un autre créateur
        $existing_brand_creator = $db->get_creator_by_brand($data['brand_slug']);
        if ($existing_brand_creator && $existing_brand_creator->id != $id) {
            return array(
                'valid' => false,
                'message' => 'Cette marque est déjà attribuée à un autre créateur.'
            );
        }

        // Mettre à jour dans la base de données
        $result = $db->update_creator($id, $data);

        if ($result) {
            return array(
                'valid' => true,
                'message' => 'Créateur mis à jour avec succès.'
            );
        }

        return array(
            'valid' => false,
            'message' => 'Erreur lors de la mise à jour du créateur.'
        );
    }

    public function delete_creator($id)
    {
        $db = MP_Creator_DB::get_instance();
        $result = $db->delete_creator($id);

        if ($result) {
            return array(
                'valid' => true,
                'message' => 'Créateur supprimé avec succès.'
            );
        }

        return array(
            'valid' => false,
            'message' => 'Erreur lors de la suppression du créateur.'
        );
    }

    private function validate_creator_data($data, $creator_id = null)
    {
        $required_fields = array(
            'name' => 'Nom',
            'email' => 'Email',
            'brand_slug' => 'Marque'
        );

        foreach ($required_fields as $field => $label) {
            if (empty($data[$field])) {
                return array(
                    'valid' => false,
                    'message' => sprintf('Le champ "%s" est obligatoire.', $label)
                );
            }
        }

        // Validation email
        if (!is_email($data['email'])) {
            return array(
                'valid' => false,
                'message' => 'L\'adresse email n\'est pas valide.'
            );
        }

        return array('valid' => true);
    }

    private function brand_exists($brand_slug)
    {
        $brand = get_term_by('slug', $brand_slug, 'product_brand');
        return $brand !== false;
    }

    public function get_available_brands()
    {
        // Vérifier d'abord que la taxonomie existe
        if (!$this->verify_brand_taxonomy()) {
            return array();
        }

        $brands = get_terms(array(
            'taxonomy' => 'product_brand',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        ));

        // Vérifier les erreurs
        if (is_wp_error($brands)) {
            error_log('MP Creator Notifier - Erreur récupération marques: ' . $brands->get_error_message());
            return array();
        }

        $brands_list = array();
        foreach ($brands as $brand) {
            $brands_list[] = array(
                'slug' => $brand->slug,
                'name' => $brand->name,
                'term_id' => $brand->term_id
            );
        }

        return $brands_list;
    }

    // Handlers AJAX
    public function handle_create_creator()
    {
        check_ajax_referer('mp_creator_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Accès non autorisé');
        }

        $data = array(
            'name' => sanitize_text_field($_POST['name']),
            'email' => sanitize_email($_POST['email']),
            'phone' => sanitize_text_field($_POST['phone']),
            'address' => sanitize_textarea_field($_POST['address']),
            'brand_slug' => sanitize_text_field($_POST['brand_slug'])
        );

        $result = $this->create_creator($data);

        wp_send_json($result);
    }

    public function handle_update_creator()
    {
        check_ajax_referer('mp_creator_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Accès non autorisé');
        }

        $id = intval($_POST['id']);
        $data = array(
            'name' => sanitize_text_field($_POST['name']),
            'email' => sanitize_email($_POST['email']),
            'phone' => sanitize_text_field($_POST['phone']),
            'address' => sanitize_textarea_field($_POST['address']),
            'brand_slug' => sanitize_text_field($_POST['brand_slug'])
        );

        $result = $this->update_creator($id, $data);

        wp_send_json($result);
    }

    public function handle_delete_creator()
    {
        check_ajax_referer('mp_creator_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Accès non autorisé');
        }

        $id = intval($_POST['id']);
        $result = $this->delete_creator($id);

        wp_send_json($result);
    }

    // Ajoutez cette méthode dans la classe MP_Creator_Manager
    public function verify_brand_taxonomy()
    {
        if (!taxonomy_exists('product_brand')) {
            error_log('MP Creator Notifier - La taxonomie product_brand n\'existe pas');
            return false;
        }
        return true;
    }
}

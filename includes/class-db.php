<?php

if (!defined('ABSPATH')) {
    exit;
}

class MP_Creator_DB
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
        add_action('plugins_loaded', array($this, 'verify_table_exists'));
        add_action('admin_init', array($this, 'check_table_status'));
    }

    public function verify_table_exists()
    {
        global $wpdb;

        $table_name = self::get_table_name();
        $table_exists = $this->table_exists();

        if (!$table_exists) {
            error_log('MP Creator Notifier - Table manquante, tentative de création...');
            $result = self::create_tables();

            if (!$result) {
                error_log('MP Creator Notifier - Échec critique: Impossible de créer la table');
                $this->log_table_error('Échec de la création de la table après activation');
            }
        }
    }

    public static function create_tables()
    {
        global $wpdb;

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $table_name = self::get_table_name();
        
        // Vérifier si la table existe déjà
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

        if ($table_exists) {
            error_log('MP Creator Notifier - Table déjà existante: ' . $table_name);
            return true;
        }

        $charset_collate = $wpdb->get_charset_collate();

        // Version SIMPLIFIÉE pour dbDelta - format très spécifique requis
        $sql = "CREATE TABLE " . $table_name . " (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            email varchar(100) NOT NULL,
            phone varchar(20) NULL,
            address text NULL,
            brand_slug varchar(100) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY email (email),
            UNIQUE KEY brand_slug (brand_slug)
        ) " . $charset_collate . ";";

        error_log('MP Creator Notifier - Exécution SQL: ' . $sql);

        // Utiliser dbDelta qui est plus robuste pour les créations de table
        $result = dbDelta($sql);

        // Vérifier si la table a été créée
        $table_exists_after = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

        if (!$table_exists_after) {
            $error_message = 'Échec création table après dbDelta: ' . $wpdb->last_error;
            error_log('MP Creator Notifier - ' . $error_message);

            // Tentative avec une création directe
            return self::create_tables_direct();
        }

        error_log('MP Creator Notifier - Table créée avec succès via dbDelta: ' . $table_name);
        return true;
    }

    // Méthode de création directe si dbDelta échoue
    private static function create_tables_direct()
    {
        global $wpdb;

        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        // Version ultra-simplifiée sans contraintes UNIQUE
        $sql = "CREATE TABLE " . $table_name . " (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            email varchar(100) NOT NULL,
            phone varchar(20) NULL,
            address text NULL,
            brand_slug varchar(100) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) " . $charset_collate . ";";

        error_log('MP Creator Notifier - Tentative création directe: ' . $sql);

        // Exécution directe
        $result = $wpdb->query($sql);

        if ($result === false) {
            $error_message = 'Échec création directe: ' . $wpdb->last_error;
            error_log('MP Creator Notifier - ' . $error_message);
            
            // Dernière tentative - table minimaliste
            return self::create_tables_minimal();
        }

        error_log('MP Creator Notifier - Table créée avec succès via méthode directe: ' . $table_name);
        return true;
    }

    // Méthode minimaliste en dernier recours
    private static function create_tables_minimal()
    {
        global $wpdb;

        $table_name = self::get_table_name();

        // Table minimaliste sans timestamps
        $sql = "CREATE TABLE " . $table_name . " (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            email varchar(100) NOT NULL,
            brand_slug varchar(100) NOT NULL,
            PRIMARY KEY  (id)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        error_log('MP Creator Notifier - Tentative création minimaliste: ' . $sql);

        $result = $wpdb->query($sql);

        if ($result === false) {
            $final_error = 'Échec création même avec table minimaliste: ' . $wpdb->last_error;
            error_log('MP Creator Notifier - ' . $final_error);
            return false;
        }

        error_log('MP Creator Notifier - Table créée avec succès (version minimaliste): ' . $table_name);
        return true;
    }

    public function table_exists()
    {
        global $wpdb;
        $table_name = self::get_table_name();
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    }

    private function log_table_error($message)
    {
        error_log('MP Creator Notifier - ERREUR TABLE: ' . $message);

        add_action('admin_footer', function () use ($message) {
            echo '<script>';
            echo 'console.error("[MP Creator] ERREUR TABLE: ' . esc_js($message) . '");';
            echo '</script>';
        });
    }

    private function log_table_success($message)
    {
        error_log('MP Creator Notifier - SUCCÈS TABLE: ' . $message);

        add_action('admin_footer', function () use ($message) {
            echo '<script>';
            echo 'console.log("%c[MP Creator] SUCCÈS TABLE: ' . esc_js($message) . '", "color: #51cf66; font-weight: bold;");';
            echo '</script>';
        });
    }

    public static function get_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'mp_creators';
    }

    // MÉTHODES CRUD COMPLÈTES

    public function insert_creator($data)
    {
        global $wpdb;

        // Vérifier que la table existe avant toute opération
        if (!$this->table_exists()) {
            $this->log_table_error('Tentative d\'insertion dans une table inexistante');
            return false;
        }

        // Validation des données requises
        $required_fields = array('name', 'email', 'brand_slug');
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                error_log('MP Creator Notifier - Champ manquant: ' . $field);
                return false;
            }
        }

        // Tronquer les données si nécessaire
        $clean_data = array(
            'name' => substr(sanitize_text_field($data['name']), 0, 100),
            'email' => substr(sanitize_email($data['email']), 0, 100),
            'phone' => substr(sanitize_text_field($data['phone']), 0, 20),
            'address' => sanitize_textarea_field($data['address']),
            'brand_slug' => substr(sanitize_text_field($data['brand_slug']), 0, 100),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );

        $result = $wpdb->insert(
            self::get_table_name(),
            $clean_data,
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if ($result === false) {
            $error_message = 'Erreur insertion: ' . $wpdb->last_error;
            error_log('MP Creator Notifier - ' . $error_message);

            // Log de l'erreur d'insertion
            add_action('admin_footer', function () use ($error_message) {
                echo '<script>';
                echo 'console.error("[MP Creator] ERREUR INSERTION: ' . esc_js($error_message) . '");';
                echo '</script>';
            });

            return false;
        }

        return $wpdb->insert_id;
    }

    public function update_creator($id, $data)
    {
        global $wpdb;

        if (empty($id) || !is_numeric($id)) {
            error_log('MP Creator Notifier - ID invalide pour mise à jour: ' . $id);
            return false;
        }

        // Vérifier que la table existe
        if (!$this->table_exists()) {
            $this->log_table_error('Tentative de mise à jour dans une table inexistante');
            return false;
        }

        // Tronquer les données si nécessaire
        $clean_data = array(
            'updated_at' => current_time('mysql')
        );

        if (isset($data['name'])) $clean_data['name'] = substr(sanitize_text_field($data['name']), 0, 100);
        if (isset($data['email'])) $clean_data['email'] = substr(sanitize_email($data['email']), 0, 100);
        if (isset($data['phone'])) $clean_data['phone'] = substr(sanitize_text_field($data['phone']), 0, 20);
        if (isset($data['address'])) $clean_data['address'] = sanitize_textarea_field($data['address']);
        if (isset($data['brand_slug'])) $clean_data['brand_slug'] = substr(sanitize_text_field($data['brand_slug']), 0, 100);

        $result = $wpdb->update(
            self::get_table_name(),
            $clean_data,
            array('id' => $id),
            array('%s', '%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );

        if ($result === false) {
            error_log('MP Creator Notifier - Erreur mise à jour: ' . $wpdb->last_error);
            return false;
        }

        return $result > 0;
    }

    public function delete_creator($id)
    {
        global $wpdb;

        if (empty($id) || !is_numeric($id)) {
            error_log('MP Creator Notifier - ID invalide pour suppression: ' . $id);
            return false;
        }

        // Vérifier que la table existe
        if (!$this->table_exists()) {
            $this->log_table_error('Tentative de suppression dans une table inexistante');
            return false;
        }

        $result = $wpdb->delete(
            self::get_table_name(),
            array('id' => $id),
            array('%d')
        );

        if ($result === false) {
            error_log('MP Creator Notifier - Erreur suppression: ' . $wpdb->last_error);
            return false;
        }

        return $result > 0;
    }

    public function get_creator($id)
    {
        global $wpdb;

        if (empty($id) || !is_numeric($id)) {
            return null;
        }

        // Vérifier que la table existe
        if (!$this->table_exists()) {
            $this->log_table_error('Tentative de lecture depuis une table inexistante');
            return null;
        }

        $creator = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . self::get_table_name() . " WHERE id = %d", $id)
        );

        if ($wpdb->last_error) {
            error_log('MP Creator Notifier - Erreur récupération créateur: ' . $wpdb->last_error);
        }

        return $creator;
    }

    // MÉTHODE MANQUANTE - get_creator_by_email
    public function get_creator_by_email($email)
    {
        global $wpdb;

        if (empty($email)) {
            return null;
        }

        // Vérifier que la table existe
        if (!$this->table_exists()) {
            $this->log_table_error('Tentative de recherche par email dans une table inexistante');
            return null;
        }

        $clean_email = substr(sanitize_email($email), 0, 100);

        $creator = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . self::get_table_name() . " WHERE email = %s", $clean_email)
        );

        return $creator;
    }

    // MÉTHODE MANQUANTE - get_creator_by_brand
    public function get_creator_by_brand($brand_slug)
    {
        global $wpdb;

        if (empty($brand_slug)) {
            return null;
        }

        // Vérifier que la table existe
        if (!$this->table_exists()) {
            $this->log_table_error('Tentative de recherche par marque dans une table inexistante');
            return null;
        }

        $clean_brand_slug = substr(sanitize_text_field($brand_slug), 0, 100);

        $creator = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . self::get_table_name() . " WHERE brand_slug = %s", $clean_brand_slug)
        );

        return $creator;
    }

    public function get_all_creators()
    {
        global $wpdb;

        $table_name = self::get_table_name();

        // Vérifier d'abord si la table existe
        if (!$this->table_exists()) {
            $this->log_table_error('Tentative de lecture depuis une table inexistante: ' . $table_name);
            return array();
        }

        $creators = $wpdb->get_results("SELECT * FROM $table_name ORDER BY name ASC");

        if ($wpdb->last_error) {
            $error_message = 'Erreur récupération créateurs: ' . $wpdb->last_error;
            error_log('MP Creator Notifier - ' . $error_message);

            add_action('admin_footer', function () use ($error_message) {
                echo '<script>';
                echo 'console.error("[MP Creator] ERREUR LECTURE: ' . esc_js($error_message) . '");';
                echo '</script>';
            });

            return array();
        }

        return $creators;
    }

    public function creator_exists($id)
    {
        global $wpdb;

        // Vérifier que la table existe
        if (!$this->table_exists()) {
            return false;
        }

        $count = $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM " . self::get_table_name() . " WHERE id = %d", $id)
        );

        return $count > 0;
    }

    public function get_creators_count()
    {
        global $wpdb;

        $table_name = self::get_table_name();
        
        // Vérifier que la table existe
        if (!$this->table_exists()) {
            return 0;
        }

        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

        return intval($count);
    }

    // Méthode pour diagnostiquer les problèmes de table
    public function diagnose_table_issues()
    {
        global $wpdb;

        $table_name = self::get_table_name();
        $diagnosis = array();

        // 1. Vérifier l'existence de la table
        $table_exists = $this->table_exists();
        $diagnosis['table_exists'] = $table_exists;

        if (!$table_exists) {
            $diagnosis['error'] = 'La table n\'existe pas';
            return $diagnosis;
        }

        // 2. Vérifier la structure
        $structure = $wpdb->get_results("DESCRIBE $table_name");
        $diagnosis['structure'] = $structure;

        // 3. Vérifier les privilèges
        $diagnosis['privileges'] = $wpdb->get_var("SELECT COUNT(*) FROM $table_name") !== null;

        // 4. Vérifier le charset
        $charset = $wpdb->get_row("SHOW TABLE STATUS LIKE '$table_name'");
        $diagnosis['charset'] = $charset->Collation ?? 'Inconnu';

        return $diagnosis;
    }

    public function check_table_status()
    {
        // Vérifier la table seulement dans l'admin et si on est sur la page des créateurs
        if (!is_admin() || !isset($_GET['page']) || $_GET['page'] !== 'mp-creators') {
            return;
        }

        $table_exists = $this->table_exists();

        if (!$table_exists) {
            $this->log_table_error('Table mp_creators non trouvée lors du chargement de la page admin');
        } else {
            $this->log_table_success('Table mp_creators trouvée avec succès');
            $this->log_table_structure();
        }
    }

    private function log_table_structure()
    {
        global $wpdb;
        $table_name = self::get_table_name();

        // Vérifier que la table existe avant de lire sa structure
        if (!$this->table_exists()) {
            return;
        }

        $structure = $wpdb->get_results("DESCRIBE $table_name");
        $columns_info = [];

        foreach ($structure as $column) {
            $columns_info[] = $column->Field . ' (' . $column->Type . ')';
        }

        add_action('admin_footer', function () use ($columns_info) {
            echo '<script>';
            echo 'console.log("%c[MP Creator] Structure de la table:", "color: #74c0fc; font-weight: bold;");';
            foreach ($columns_info as $column) {
                echo 'console.log("  - ' . esc_js($column) . '");';
            }
            echo '</script>';
        });
    }
}
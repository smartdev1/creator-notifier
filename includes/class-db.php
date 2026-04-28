<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MP_Creator_DB — Classe unifiée de gestion de la base de données.
 *
 * Fusionne l'ancienne version inline (creator-notifier.php) et la version
 * modulaire (includes/class-db.php) en une seule source de vérité.
 *
 * Principes appliqués (notifier2.md) :
 *  - Aucune fonctionnalité supprimée
 *  - Pattern Singleton ajouté sans casser les appels via `new MP_Creator_DB()`
 *  - Meilleure gestion d'erreurs intégrée
 *  - Schéma de table complet et cohérent
 */
class MP_Creator_DB
{
    // =========================================================
    // SINGLETON — rétrocompatible avec `new MP_Creator_DB()`
    // =========================================================

    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // =========================================================
    // TABLES
    // =========================================================

    private $tables = [];

    public function __construct()
    {
        global $wpdb;
        $prefix = MP_CREATOR_NOTIFIER_TABLE_PREFIX; // 'mp_'
        $this->tables = [
            'creators'      => $wpdb->prefix . $prefix . 'creators',
            'notifications' => $wpdb->prefix . $prefix . 'notifications',
            'webhooks'      => $wpdb->prefix . $prefix . 'webhooks',
        ];
    }

    /**
     * Retourne le nom complet d'une table.
     * Accepte les appels statiques (compatibilité includes/class-db.php).
     */
    public function get_table_name($table = 'creators')
    {
        if (isset($this->tables[$table])) {
            return $this->tables[$table];
        }
        // Fallback : compatibilité appel statique implicite via get_instance
        global $wpdb;
        return $wpdb->prefix . MP_CREATOR_NOTIFIER_TABLE_PREFIX . $table;
    }

    // =========================================================
    // VÉRIFICATIONS STRUCTURE
    // =========================================================

    public function table_exists($table = 'creators')
    {
        global $wpdb;
        $table_name = $this->get_table_name($table);
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name;
    }

    public function column_exists($table, $column)
    {
        global $wpdb;
        $table_name = $this->get_table_name($table);
        if (!$this->table_exists($table)) {
            return false;
        }
        $result = $wpdb->get_results("SHOW COLUMNS FROM `{$table_name}` LIKE '{$column}'");
        return !empty($result);
    }

    // =========================================================
    // CRÉATION DES TABLES
    // =========================================================

    public function create_tables()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        // ----- Créateurs -----
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tables['creators']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_creator_id BIGINT UNSIGNED DEFAULT NULL,
            name VARCHAR(200) NOT NULL,
            email VARCHAR(100) NOT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            address TEXT DEFAULT NULL,
            brand_slug VARCHAR(100) NOT NULL,
            status ENUM('active','inactive') DEFAULT 'active',
            total_orders INT DEFAULT 0,
            total_sales DECIMAL(15,2) DEFAULT 0,
            last_order_date DATETIME DEFAULT NULL,
            last_synced_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY email (email),
            UNIQUE KEY brand_slug (brand_slug),
            KEY wp_creator_id (wp_creator_id),
            KEY status (status)
        ) {$charset_collate};";
        dbDelta($sql);

        // ----- Notifications -----
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tables['notifications']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            creator_id BIGINT UNSIGNED DEFAULT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            subject VARCHAR(255) NOT NULL,
            message LONGTEXT NOT NULL,
            sent_at DATETIME NOT NULL,
            status ENUM('sent','failed') DEFAULT 'sent',
            error_message TEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY creator_id (creator_id),
            KEY order_id (order_id),
            KEY sent_at (sent_at)
        ) {$charset_collate};";
        dbDelta($sql);

        // ----- Webhooks -----
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tables['webhooks']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(100) NOT NULL,
            order_id BIGINT UNSIGNED DEFAULT NULL,
            creator_id BIGINT UNSIGNED DEFAULT NULL,
            product_id BIGINT UNSIGNED DEFAULT NULL,
            status ENUM('success','failed','pending') DEFAULT 'pending',
            response_code INT DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY event_type (event_type),
            KEY order_id (order_id),
            KEY creator_id (creator_id),
            KEY product_id (product_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql);

        error_log('MP Creator DB: Tables créées/vérifiées avec succès.');
    }

    /**
     * Ajoute les colonnes manquantes sans recréer les tables.
     * Appelée à chaque admin_init pour assurer la cohérence après mise à jour.
     */
    public function add_missing_columns()
    {
        global $wpdb;

        if (!$this->table_exists('creators')) {
            return;
        }

        $creators_columns = [
            'total_orders'    => 'INT DEFAULT 0',
            'total_sales'     => 'DECIMAL(15,2) DEFAULT 0',
            'last_order_date' => 'DATETIME DEFAULT NULL',
            'last_synced_at'  => 'DATETIME DEFAULT NULL',
            'wp_creator_id'   => 'BIGINT UNSIGNED DEFAULT NULL',
        ];

        foreach ($creators_columns as $column => $definition) {
            if (!$this->column_exists('creators', $column)) {
                $after = ($column === 'wp_creator_id') ? 'AFTER `id`' : '';
                $wpdb->query("ALTER TABLE `{$this->tables['creators']}` ADD COLUMN `{$column}` {$definition} {$after}");
                error_log("MP Creator DB: Colonne {$column} ajoutée à la table creators.");
            }
        }

        if (!$this->table_exists('webhooks')) {
            return;
        }

        $webhooks_columns = [
            'creator_id'     => 'BIGINT UNSIGNED DEFAULT NULL',
            'product_id'     => 'BIGINT UNSIGNED DEFAULT NULL',
            'response_code'  => 'INT DEFAULT NULL',
            'error_message'  => 'TEXT DEFAULT NULL',
        ];

        foreach ($webhooks_columns as $column => $definition) {
            if (!$this->column_exists('webhooks', $column)) {
                $wpdb->query("ALTER TABLE `{$this->tables['webhooks']}` ADD COLUMN `{$column}` {$definition}");
                error_log("MP Creator DB: Colonne {$column} ajoutée à la table webhooks.");
            }
        }

        if (!$this->table_exists('notifications')) {
            return;
        }

        if (!$this->column_exists('notifications', 'error_message')) {
            $wpdb->query("ALTER TABLE `{$this->tables['notifications']}` ADD COLUMN `error_message` TEXT DEFAULT NULL");
        }
    }

    // =========================================================
    // CRÉATEURS — CRUD
    // =========================================================

    public function create_creator($data)
    {
        global $wpdb;

        if (!$this->table_exists('creators')) {
            error_log('MP Creator DB: Tentative d\'insertion dans une table inexistante.');
            return new WP_Error('db_error', __('Database table missing.', 'mp-creator-notifier'));
        }

        $defaults = [
            'created_at'   => current_time('mysql'),
            'updated_at'   => current_time('mysql'),
            'status'       => 'active',
            'total_orders' => 0,
            'total_sales'  => 0,
        ];
        $data = wp_parse_args($data, $defaults);

        // Unicité email
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->tables['creators']} WHERE email = %s",
            $data['email']
        ));
        if ($exists) {
            return new WP_Error('email_exists', __('A creator with this email already exists.', 'mp-creator-notifier'));
        }

        // Unicité brand_slug
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->tables['creators']} WHERE brand_slug = %s",
            $data['brand_slug']
        ));
        if ($exists) {
            return new WP_Error('brand_exists', __('A creator with this brand already exists.', 'mp-creator-notifier'));
        }

        $result = $wpdb->insert($this->tables['creators'], $data);

        if ($result === false) {
            error_log('MP Creator DB: Erreur insertion créateur — ' . $wpdb->last_error);
            return new WP_Error('db_error', __('Failed to create creator.', 'mp-creator-notifier'));
        }

        return $wpdb->insert_id;
    }

    /** Alias pour compatibilité avec includes/class-db.php */
    public function insert_creator($data)
    {
        $result = $this->create_creator($data);
        if (is_wp_error($result)) {
            error_log('MP Creator DB: insert_creator — ' . $result->get_error_message());
            return false;
        }
        return $result;
    }

    public function get_creator($creator_id)
    {
        global $wpdb;
        if (empty($creator_id) || !is_numeric($creator_id)) {
            return null;
        }
        if (!$this->table_exists('creators')) {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->tables['creators']} WHERE id = %d",
            $creator_id
        ));
    }

    public function get_creator_by_brand($brand_slug)
    {
        global $wpdb;
        if (empty($brand_slug)) {
            return null;
        }
        if (!$this->table_exists('creators')) {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->tables['creators']}
             WHERE brand_slug = %s AND status = 'active'
             LIMIT 1",
            sanitize_title($brand_slug)
        ));
    }

    public function get_creator_by_email($email)
    {
        global $wpdb;
        if (empty($email)) {
            return null;
        }
        if (!$this->table_exists('creators')) {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->tables['creators']} WHERE email = %s",
            sanitize_email($email)
        ));
    }

    public function get_creators($limit = 100, $offset = 0, $status = null)
    {
        global $wpdb;
        if (!$this->table_exists('creators')) {
            return [];
        }

        if ($status) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->tables['creators']}
                 WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $status, $limit, $offset
            ));
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->tables['creators']}
             ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $limit, $offset
        ));
    }

    /** Alias pour compatibilité avec includes/class-db.php */
    public function get_all_creators()
    {
        global $wpdb;
        if (!$this->table_exists('creators')) {
            return [];
        }
        $results = $wpdb->get_results("SELECT * FROM {$this->tables['creators']} ORDER BY name ASC");
        if ($wpdb->last_error) {
            error_log('MP Creator DB: get_all_creators — ' . $wpdb->last_error);
            return [];
        }
        return $results;
    }

    public function update_creator($creator_id, $data)
    {
        global $wpdb;
        if (empty($creator_id) || !is_numeric($creator_id)) {
            return false;
        }
        if (!$this->table_exists('creators')) {
            return false;
        }
        $data['updated_at'] = current_time('mysql');
        $result = $wpdb->update($this->tables['creators'], $data, ['id' => $creator_id]);
        if ($result === false) {
            error_log('MP Creator DB: Erreur update_creator — ' . $wpdb->last_error);
            return false;
        }
        return true;
    }

    public function delete_creator($creator_id)
    {
        global $wpdb;
        if (empty($creator_id) || !is_numeric($creator_id)) {
            return false;
        }
        if (!$this->table_exists('creators')) {
            return false;
        }
        $result = $wpdb->delete($this->tables['creators'], ['id' => $creator_id]);
        if ($result === false) {
            error_log('MP Creator DB: Erreur delete_creator — ' . $wpdb->last_error);
            return false;
        }
        return $result !== false;
    }

    public function creator_exists($id)
    {
        global $wpdb;
        if (!$this->table_exists('creators')) {
            return false;
        }
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tables['creators']} WHERE id = %d",
            $id
        ));
    }

    // =========================================================
    // CRÉATEURS — COMMANDES
    // =========================================================

    public function get_creators_for_order($order_id)
    {
        global $wpdb;
        if (!$this->table_exists('creators')) {
            return [];
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return [];
        }

        $product_ids = [];
        foreach ($order->get_items() as $item) {
            $product_ids[] = (int) $item->get_product_id();
        }
        if (empty($product_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));

        return $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT c.*
             FROM {$this->tables['creators']} c
             JOIN {$wpdb->postmeta} pm ON c.brand_slug = pm.meta_value
             WHERE pm.post_id IN ({$placeholders})
             AND pm.meta_key = 'brand_slug'
             AND c.status = 'active'",
            ...$product_ids
        ));
    }

    public function get_creator_products_in_order($creator_id, $order_id)
    {
        $creator = $this->get_creator($creator_id);
        if (!$creator) {
            return [];
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return [];
        }

        $products = [];
        foreach ($order->get_items() as $item) {
            $brand_slug = get_post_meta($item->get_product_id(), 'brand_slug', true);
            if ($brand_slug === $creator->brand_slug) {
                $products[] = [
                    'id'       => $item->get_product_id(),
                    'name'     => $item->get_name(),
                    'quantity' => $item->get_quantity(),
                    'total'    => $item->get_total(),
                ];
            }
        }
        return $products;
    }

    public function get_creator_order_total($creator_id, $order_id)
    {
        $products = $this->get_creator_products_in_order($creator_id, $order_id);
        return array_sum(array_column($products, 'total'));
    }

    public function update_creator_stats($creator_id, $order_total)
    {
        global $wpdb;
        return $wpdb->query($wpdb->prepare(
            "UPDATE {$this->tables['creators']}
             SET total_orders = total_orders + 1,
                 total_sales = total_sales + %f,
                 last_order_date = NOW(),
                 updated_at = NOW()
             WHERE id = %d",
            $order_total, $creator_id
        ));
    }

    // =========================================================
    // NOTIFICATIONS
    // =========================================================

    public function log_notification($creator_id, $order_id, $subject, $message, $status, $error_message = null)
    {
        global $wpdb;
        if (!$this->table_exists('notifications')) {
            return false;
        }
        return $wpdb->insert($this->tables['notifications'], [
            'creator_id'    => $creator_id,
            'order_id'      => $order_id,
            'subject'       => $subject,
            'message'       => $message,
            'sent_at'       => current_time('mysql'),
            'status'        => $status,
            'error_message' => $error_message,
        ]);
    }

    public function get_creator_notifications($creator_id, $limit = 50)
    {
        global $wpdb;
        if (!$this->table_exists('notifications')) {
            return [];
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->tables['notifications']}
             WHERE creator_id = %d ORDER BY sent_at DESC LIMIT %d",
            $creator_id, $limit
        ));
    }

    // =========================================================
    // WEBHOOKS
    // =========================================================

    public function log_webhook($event_type, $order_id = null, $creator_id = null, $product_id = null, $status = 'pending', $response_code = null, $error_message = null)
    {
        global $wpdb;
        if (!$this->table_exists('webhooks')) {
            return false;
        }

        $data = [
            'event_type' => $event_type,
            'order_id'   => $order_id,
            'status'     => $status,
            'created_at' => current_time('mysql'),
        ];

        if ($this->column_exists('webhooks', 'creator_id'))    $data['creator_id']    = $creator_id;
        if ($this->column_exists('webhooks', 'product_id'))    $data['product_id']    = $product_id;
        if ($this->column_exists('webhooks', 'response_code')) $data['response_code'] = $response_code;
        if ($this->column_exists('webhooks', 'error_message')) $data['error_message'] = $error_message;

        return $wpdb->insert($this->tables['webhooks'], $data);
    }

    public function get_failed_webhooks($hours = 24)
    {
        global $wpdb;
        if (!$this->table_exists('webhooks')) {
            return [];
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->tables['webhooks']}
             WHERE status = 'failed'
             AND created_at > DATE_SUB(NOW(), INTERVAL %d HOUR)
             ORDER BY created_at DESC",
            $hours
        ));
    }

    public function clean_old_webhooks($days = 30)
    {
        global $wpdb;
        if (!$this->table_exists('webhooks')) {
            return 0;
        }
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->tables['webhooks']}
             WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
    }

    // =========================================================
    // STATISTIQUES
    // =========================================================

    public function get_creators_count($status = null)
    {
        global $wpdb;
        if (!$this->table_exists('creators')) {
            return 0;
        }
        if ($status) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->tables['creators']} WHERE status = %s",
                $status
            ));
        }
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->tables['creators']}");
    }

    public function get_active_brands_count()
    {
        global $wpdb;
        if (!$this->table_exists('creators')) {
            return 0;
        }
        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT brand_slug) FROM {$this->tables['creators']} WHERE status = 'active'"
        );
    }

    public function get_synced_products_count()
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE meta_key = '_mp_sync_status' AND meta_value = 'success'"
        );
    }

    public function get_sync_health_percentage()
    {
        global $wpdb;
        if (!$this->table_exists('webhooks')) {
            return 100;
        }
        $stats = $wpdb->get_row(
            "SELECT COUNT(*) as total,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success
             FROM {$this->tables['webhooks']}
             WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        if (!$stats || $stats->total == 0) {
            return 100;
        }
        return (int) round(($stats->success / $stats->total) * 100);
    }

    public function clean_old_data($days = 30)
    {
        global $wpdb;
        $webhooks_deleted = $this->clean_old_webhooks($days);
        $notifications_deleted = 0;
        if ($this->table_exists('notifications')) {
            $notifications_deleted = (int) $wpdb->query($wpdb->prepare(
                "DELETE FROM {$this->tables['notifications']}
                 WHERE sent_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            ));
        }
        return [
            'webhooks_deleted'      => $webhooks_deleted,
            'notifications_deleted' => $notifications_deleted,
        ];
    }

    // =========================================================
    // DIAGNOSTICS (conservé depuis includes/class-db.php)
    // =========================================================

    public function diagnose_table_issues()
    {
        global $wpdb;
        $table_name = $this->get_table_name('creators');
        $diagnosis  = ['table_exists' => $this->table_exists('creators')];

        if (!$diagnosis['table_exists']) {
            $diagnosis['error'] = 'La table creators n\'existe pas.';
            return $diagnosis;
        }

        $diagnosis['structure']  = $wpdb->get_results("DESCRIBE `{$table_name}`");
        $diagnosis['privileges'] = ($wpdb->get_var("SELECT COUNT(*) FROM `{$table_name}`") !== null);
        $status                  = $wpdb->get_row("SHOW TABLE STATUS LIKE '{$table_name}'");
        $diagnosis['charset']    = $status->Collation ?? 'Inconnu';

        return $diagnosis;
    }
}
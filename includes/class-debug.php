<?php

if (!defined('ABSPATH')) {
    exit;
}

class MP_Creator_Debug {
    
    public static function check_database() {
        global $wpdb;
        
        $table_name = MP_Creator_DB::get_table_name();
        $results = array();
        
        // Vérifier si la table existe
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        $results['table_exists'] = $table_exists;
        
        if ($table_exists) {
            // Vérifier la structure
            $structure = $wpdb->get_results("DESCRIBE $table_name");
            $results['structure'] = $structure;
            
            // Compter les créateurs
            $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
            $results['creator_count'] = $count;
        }
        
        return $results;
    }
    
    public static function display_debug_info() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        $db_info = self::check_database();
        ?>
        <div class="notice notice-info">
            <h3>Debug MP Creator Notifier</h3>
            <p><strong>Table exists:</strong> <?php echo $db_info['table_exists'] ? 'Yes' : 'No'; ?></p>
            <?php if ($db_info['table_exists']): ?>
                <p><strong>Number of creators:</strong> <?php echo $db_info['creator_count']; ?></p>
                <p><strong>Table structure:</strong></p>
                <ul>
                    <?php foreach ($db_info['structure'] as $column): ?>
                        <li><?php echo $column->Field . ' (' . $column->Type . ')'; ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }
}
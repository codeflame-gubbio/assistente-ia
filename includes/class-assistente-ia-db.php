<?php
if ( ! defined('ABSPATH') ) { exit; }

class Assistente_IA_DB {
    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'assia_embeddings';
    }

    public static function install() {
        global $wpdb;
        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            post_type VARCHAR(32) NOT NULL DEFAULT 'post',
            chunk_index INT NOT NULL DEFAULT 0,
            content LONGTEXT NOT NULL,
            vector LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY post_type (post_type),
            KEY chunk_index (chunk_index)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function clear_post($post_id) {
        global $wpdb;
        $table = self::table_name();
        $wpdb->delete($table, [ 'post_id' => intval($post_id) ], [ '%d' ]);
    }

    public static function insert_chunk($post_id, $post_type, $chunk_index, $content, $vector) {
        global $wpdb;
        $table = self::table_name();
        $wpdb->insert($table, [
            'post_id'     => intval($post_id),
            'post_type'   => sanitize_key($post_type),
            'chunk_index' => intval($chunk_index),
            'content'     => $content,
            'vector'      => wp_json_encode($vector),
        ], [ '%d','%s','%d','%s','%s' ]);
    }

    public static function fetch_all_vectors() {
        global $wpdb;
        $table = self::table_name();
        
        // ✅ FIX v5.4.1: Validazione nome tabella per sicurezza extra
        $table = esc_sql( $table );
        
        return $wpdb->get_results(
            "SELECT id, post_id, post_type, chunk_index, content, vector FROM {$table}",
            ARRAY_A
        );
    }
}

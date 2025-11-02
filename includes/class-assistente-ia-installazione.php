<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Installazione tabelle e opzioni predefinite.
 * VERSIONE CORRETTA v5.4.1:
 * - Aggiunta opzione assia_chunk_size
 * - Boolean standardizzati ('si'/'no')
 * - Flag assia_content_selector_configured
 * - Flag assia_snapshot_initialized
 */
class Assistente_IA_Installazione {

    public static function all_attivazione(){
        global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate(); $pref = $wpdb->prefix;

        // Tabella chat
        dbDelta("CREATE TABLE {$pref}assistente_ia_chat (
            id_chat BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            hash_sessione VARCHAR(64) NOT NULL,
            data_creazione DATETIME NOT NULL,
            ultimo_aggiornamento DATETIME NOT NULL,
            riassunto_compresso LONGTEXT NULL,
            PRIMARY KEY (id_chat),
            KEY hash_sessione (hash_sessione)
        ) $charset;");

        // Tabella messaggi
        dbDelta("CREATE TABLE {$pref}assistente_ia_messaggi (
            id_messaggio BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_chat BIGINT UNSIGNED NOT NULL,
            ruolo ENUM('utente','assistente','sistema') NOT NULL,
            testo LONGTEXT NOT NULL,
            creato_il DATETIME NOT NULL,
            PRIMARY KEY (id_messaggio),
            KEY id_chat (id_chat)
        ) $charset;");

        // Tabella embeddings (indice RAG)
        dbDelta("CREATE TABLE {$pref}assistente_ia_embeddings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            fonte ENUM('post','prodotto','pdf','custom') NOT NULL,
            id_riferimento BIGINT UNSIGNED NULL,
            indice_chunk INT UNSIGNED NOT NULL,
            testo_chunk LONGTEXT NOT NULL,
            embedding LONGTEXT NOT NULL,
            modello VARCHAR(100) NOT NULL,
            creato_il DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY fonte (fonte,id_riferimento),
            KEY modello (modello)
        ) $charset;");

        // Tabella: registro chiamate al modello
        dbDelta("CREATE TABLE {$pref}assistente_ia_diag_modello (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            creato_il DATETIME NOT NULL,
            id_chat BIGINT UNSIGNED NULL,
            hash_sessione VARCHAR(64) NULL,
            tipo ENUM('generate','embed') NOT NULL,
            endpoint TEXT NOT NULL,
            payload LONGTEXT NOT NULL,
            risposta LONGTEXT NULL,
            http_code INT NULL,
            errore TEXT NULL,
            PRIMARY KEY (id),
            KEY id_chat (id_chat),
            KEY tipo (tipo)
        ) $charset;");

        // ✅ OPZIONI PREDEFINITE CORRETTE
        $predef = [
            // Credenziali
            'assia_progetto_id' => '',
            'assia_localita' => 'us-central1',
            'assia_modello' => 'gemini-2.0-flash-001',
            'assia_modello_embedding' => 'text-embedding-005',
            'assia_credenziali_base64' => '',
            
            // Prompt
            'assia_obiettivo' => 'Sei un assistente utile e preciso.',
            'assia_avviso' => 'Le risposte sono fornite a scopo informativo.',
            'assia_ruolo_sistema' => '',
            
            // Generazione
            'assia_temperature' => 0.2,
            'assia_top_p' => 0.9,
            'assia_top_k' => 40,
            'assia_max_token' => 2048,
            'assia_safety_soglie' => [
                'sexually_explicit'=>'BLOCK_NONE',
                'hate_speech'=>'BLOCK_NONE',
                'harassment'=>'BLOCK_NONE',
                'dangerous_content'=>'BLOCK_NONE'
            ],
            'assia_attiva_google_search' => 'no',
            
            // RAG/Embeddings
            'assia_attiva_embeddings' => 'si',
            'assia_embeddings_top_k' => 3,
            'assia_embeddings_threshold' => '0.30',
            'assia_rag_mode' => 'top-k',
            'assia_chunk_size' => 1200, // ✅ NUOVO
            'assia_chunk_overlap' => 100,
            'assia_auto_regenerate_hash' => 'si',
            
            // Conversazioni
            'assia_turni_modello' => 8,
            'assia_messaggi_ui' => 30,
            'assia_ttl_giorni' => 30,
            
            // Rate limit
            'assia_rate_limite_max' => 8,
            'assia_rate_limite_finestra_sec' => 60,
            
            // UI
            'assia_bottone_testo' => 'Chatta con noi',
            'assia_bottone_posizione' => 'bottom-right',
            'assia_inserimento_automatico_footer' => 'si', // ✅ Standardizzato
            
            // Contesto
            'assia_context_wc' => 'si',
            'assia_context_brief_enable' => 'si',
            
            // Diagnostica
            'assia_registro_modello_attivo' => 'no',
            'assia_registro_modello_retention_giorni' => 7,
            
            // ✅ NUOVI FLAG INTERNI
            'assia_content_selector_configured' => false,
            'assia_snapshot_initialized' => false,
        ];
        
        foreach($predef as $k=>$v){ 
            if(get_option($k,null)===null){ 
                add_option($k,$v); 
            }
        }
    }

    public static function alla_disinstallazione(){
        global $wpdb; $pref = $wpdb->prefix;
        
        // Drop tabelle
        $wpdb->query("DROP TABLE IF EXISTS {$pref}assistente_ia_messaggi");
        $wpdb->query("DROP TABLE IF EXISTS {$pref}assistente_ia_chat");
        $wpdb->query("DROP TABLE IF EXISTS {$pref}assistente_ia_embeddings");
        $wpdb->query("DROP TABLE IF EXISTS {$pref}assistente_ia_diag_modello");
        
        // Elimina tutte le opzioni
        $ops = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'assia_%'");
        foreach($ops as $op) delete_option($op);
        
        // Elimina CRON jobs
        $timestamp = wp_next_scheduled( 'assia_cleanup_old_chats' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'assia_cleanup_old_chats' );
        }
    }
}

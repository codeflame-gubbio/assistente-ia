<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Assistente_IA_Retention {
    public static function maybe_schedule(){
        if ( ! wp_next_scheduled('assia_purge_cron') ){
            wp_schedule_event( time()+3600, 'daily', 'assia_purge_cron' );
        }
    }
    public static function purge_dati_scaduti(){
        $giorni = max(1, (int) get_option('assia_ttl_giorni', 30));
        $limite = gmdate('Y-m-d H:i:s', time() - $giorni * DAY_IN_SECONDS);
        global $wpdb; $pref = $wpdb->prefix;
        $wpdb->query( $wpdb->prepare("DELETE FROM {$pref}assistente_ia_messaggi WHERE creato_il < %s", $limite) );
        $wpdb->query( $wpdb->prepare("DELETE FROM {$pref}assistente_ia_chat WHERE creato_il < %s", $limite) );
        $wpdb->query( $wpdb->prepare("DELETE FROM {$pref}assistente_ia_diag_modello WHERE creato_il < %s", $limite) );
    }
}
add_action('plugins_loaded',['Assistente_IA_Retention','maybe_schedule']);

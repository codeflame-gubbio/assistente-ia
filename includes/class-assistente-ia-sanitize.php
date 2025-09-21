<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Assistente_IA_Sanitize {
    public static function init(){
        foreach(['assia_embeddings_top_k','assia_turni_modello','assia_messaggi_ui','assia_ttl_giorni','assia_rate_limite_max','assia_rate_limite_finestra_sec'] as $o){
            add_filter('pre_update_option_' . $o, [__CLASS__,'absint_filter'], 10, 3);
        }
        foreach(['assia_attiva_embeddings','assia_embeddings_solo_migliori','assia_registro_modello_attivo'] as $o){
            add_filter('pre_update_option_' . $o, [__CLASS__,'yesno_filter'], 10, 3);
        }
        add_filter('pre_update_option_assia_bottone_posizione', [__CLASS__,'pos_filter'], 10, 3);
        foreach(['assia_bottone_testo'] as $o){
            add_filter('pre_update_option_' . $o, [__CLASS__,'text_filter'], 10, 3);
        }
        foreach(['assia_obiettivo','assia_avviso','assia_ruolo_sistema'] as $o){
            add_filter('pre_update_option_' . $o, [__CLASS__,'html_filter'], 10, 3);
        }
    }
    public static function absint_filter($value,$old,$option){ return absint($value); }
    public static function yesno_filter($value,$old,$option){ return in_array($value,['si','no'],true)?$value:'no'; }
    public static function pos_filter($value,$old,$option){ return in_array($value,['bottom-right','bottom-left'],true)?$value:'bottom-right'; }
    public static function text_filter($value,$old,$option){ return sanitize_text_field($value); }
    public static function html_filter($value,$old,$option){ return wp_kses_post($value); }
}
add_action('plugins_loaded',['Assistente_IA_Sanitize','init']);

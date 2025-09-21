<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Assistente_IA_Token_Helpers {
    public static function init(){
        add_filter('option_assia_token_gemini', [__CLASS__,'prefer_transient'], 10, 1);
        add_filter('pre_update_option_assia_token_gemini', [__CLASS__,'mirror_to_transient'], 10, 3);
    }
    public static function prefer_transient($value){
        $t = get_transient('assia_token_gemini');
        return $t !== false ? $t : $value;
    }
    public static function mirror_to_transient($value,$old,$option){
        $exp = 3600; // default 1h se non noto
        if (is_array($value) && isset($value['expires_in'])){
            $exp = max(60, (int)$value['expires_in']);
        }
        set_transient('assia_token_gemini', $value, $exp);
        return $value; // lascia comunque salvare l'opzione per retrocompatibilità
    }
}
add_action('plugins_loaded',['Assistente_IA_Token_Helpers','init']);

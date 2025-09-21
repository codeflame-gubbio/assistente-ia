<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Registro su DB delle chiamate al modello (request/response), attivabile da opzione.
 * Niente error_log: tutto in tabella assistente_ia_diag_modello.
 */
class Assistente_IA_Diagnostica_Modello {

    /** Verifica se il registro è attivo da pannello */
    public static function attivo(): bool {
        return ('si' === get_option('assia_registro_modello_attivo','no'));
    }

    /** Tronca eventuali stringhe e pretty-print JSON/array/oggetti */
    protected static function normalizza_testo( $str, int $max = 50000 ): string {
        if (is_array($str) || is_object($str)) {
            $str = wp_json_encode($str, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
        } else {
            $str = (string)$str;
        }
        $str = trim($str);
        if (strlen($str) > $max) {
            $str = substr($str, 0, $max - 1) . '…';
        }
        return $str;
    }

    /** Salva una riga di diagnostica (se attiva) */
    public static function salva( array $args ): void {
        if ( ! self::attivo() ) return;

        global $wpdb; $pref = $wpdb->prefix;

        $idc    = isset($args['id_chat']) ? (int)$args['id_chat'] : null;
        $hash   = isset($args['hash_sessione']) ? sanitize_text_field($args['hash_sessione']) : '';
        $tipo   = isset($args['tipo']) ? sanitize_text_field($args['tipo']) : '';
        $endp   = isset($args['endpoint']) ? esc_url_raw($args['endpoint']) : '';
        $http   = isset($args['http_code']) ? (int)$args['http_code'] : null;
        $errore = isset($args['errore']) ? self::normalizza_testo($args['errore']) : null;

        $payload  = self::normalizza_testo($args['payload']  ?? '');
        $risposta = isset($args['risposta']) ? self::normalizza_testo($args['risposta']) : null;

        $wpdb->insert( $pref.'assistente_ia_diag_modello', [
            'creato_il'     => current_time('mysql'),
            'id_chat'       => $idc,
            'hash_sessione' => $hash,
            'tipo'          => $tipo,
            'endpoint'      => $endp,
            'payload'       => $payload,
            'risposta'      => $risposta,
            'http_code'     => $http,
            'errore'        => $errore,
        ] );
    }
}
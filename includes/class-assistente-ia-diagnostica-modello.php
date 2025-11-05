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

    /** Tronca eventuali stringhe e re-JSON pretty-print se applicabile */
    protected static function normalizza_testo( $str, int $max = 50000 ): string {
        if ( is_array($str) || is_object($str) ) {
            $str = wp_json_encode( $str );
        }
        $str = (string) $str;
        // Provo a prettificare se è JSON valido
        $decoded = json_decode($str, true);
        if ( is_array($decoded) ) {
            $str = wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
        }
        if ( strlen($str) > $max ) {
            $str = substr($str, 0, $max-3).'...';
        }
        return $str;
    }

    /**
     * Salva un record nel registro.
     * @param array $args [
     *   'tipo' => 'generate'|'embed',
     *   'endpoint' => string,
     *   'payload' => mixed (array|string),
     *   'risposta' => mixed (array|string) opzionale,
     *   'http_code' => int|null,
     *   'errore' => string|null,
     *   'id_chat' => int|null,
     *   'hash_sessione' => string|null
     * ]
     */
    public static function salva( array $args ): void {
        if ( ! self::attivo() ) return;
        global $wpdb; $pref=$wpdb->prefix;

        $tipo = in_array($args['tipo'] ?? '', ['generate','embed'], true) ? $args['tipo'] : 'generate';
        $endpoint = (string)($args['endpoint'] ?? '');
        $payload = self::normalizza_testo( $args['payload'] ?? '' );
        $risposta = isset($args['risposta']) ? self::normalizza_testo($args['risposta']) : null;
        $http = isset($args['http_code']) ? (int)$args['http_code'] : null;
        $errore = isset($args['errore']) ? (string)$args['errore'] : null;
        $idc = isset($args['id_chat']) ? (int)$args['id_chat'] : null;
        $hash = isset($args['hash_sessione']) ? substr((string)$args['hash_sessione'],0,64) : null;

        $wpdb->insert( $pref.'assistente_ia_diag_modello', [
            'creato_il' => current_time('mysql'),
            'id_chat' => $idc,
            'hash_sessione' => $hash,
            'tipo' => $tipo,
            'endpoint' => $endpoint,
            'payload' => $payload,
            'risposta' => $risposta,
            'http_code' => $http,
            'errore' => $errore,
        ] );
    }
}

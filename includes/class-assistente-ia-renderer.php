<?php
if ( ! defined('ABSPATH') ) { exit; }

/**
 * Assistente_IA_Renderer
 *
 * Renderer SEMPLICE e affidabile per ottenere TESTO PULITO da un post
 * costruito con Gutenberg / Divi / Elementor / shortcode custom,
 * anche durante richieste AJAX (admin-ajax.php).
 *
 * Strategia: affidarsi a get_post() + apply_filters('the_content', ...)
 * senza forzare builder o toccare flag globali (is_admin, wp_doing_ajax, ecc.).
 *
 * v6.0.1-slim
 */
class Assistente_IA_Renderer {

    /**
     * Inizializzazione (intenzionalmente vuota).
     * Non forziamo il caricamento di builder o hook particolari.
     */
    public static function init() {
        // no-op
    }

    /**
     * Restituisce testo "plain" renderizzato via the_content + pulizia.
     * La firma mantiene parametri legacy per compatibilità, ma li ignora.
     *
     * @param int  $post_id
     * @param bool $usa_rest_fallback  (ignorato)
     * @param bool $usa_http_fallback  (ignorato)
     * @return string Testo pulito pronto per embeddings
     */
    public static function ottieni_testo_pulito_da_post( $post_id, $usa_rest_fallback = false, $usa_http_fallback = false ) {
        $post_obj = get_post( $post_id );

        // Solo post pubblicati e non protetti da password
        if ( ! $post_obj || 'publish' !== $post_obj->post_status || ! empty( $post_obj->post_password ) ) {
            return '';
        }

        // ---- Contesto minimo: alcuni filtri/shortcode si aspettano $post settato ----
        $prev_post       = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
        $GLOBALS['post'] = $post_obj;

        // Percorso PRINCIPALE (stile Gemini): lascia fare tutto a 'the_content'
        $raw  = (string) $post_obj->post_content;
        $html = apply_filters( 'the_content', $raw );

        // Heuristica di sicurezza: se dopo the_content restano shortcode tipici dei builder,
        // prova una singola esecuzione di do_shortcode sul RAW (evitiamo doppio rendering).
        if ( preg_match( '/\[(?:et_pb_|elementor|vc_)[^\]]*\]/i', $html ) ) {
            $maybe = do_shortcode( $raw );
            // Tieni l'output "più ricco" in termini di testo
            if ( strlen( wp_strip_all_tags( $maybe, true ) ) > strlen( wp_strip_all_tags( $html, true ) ) ) {
                $html = $maybe;
            }
        }

        // Ripristina il post globale
        $GLOBALS['post'] = $prev_post;

        // Sanificazione base
        $html  = str_replace( ']]>', ']]&gt;', $html );
        $testo = self::pulisci_html_in_testo( $html );

        // Prepend del titolo (aiuta il ranking semantico negli embeddings)
        $titolo = get_the_title( $post_obj );
        if ( $titolo ) {
            $testo = wp_strip_all_tags( $titolo ) . '. ' . $testo;
        }

        return $testo;
    }

    /**
     * Converte HTML in testo, rimuovendo il superfluo senza perdere contenuto legittimo.
     *
     * @param string $html
     * @return string
     */
    private static function pulisci_html_in_testo( $html ) {
        $html = (string) $html;

        // Rimuovi blocchi non testuali evidenti
        $html = preg_replace( '#<(script|style|noscript|svg|canvas|iframe)[^>]*>.*?</\1>#si', ' ', $html );

        // Gestione break/paragraph come newline
        $html = preg_replace( '#<br\s*/?>#i', "\n", $html );
        $html = preg_replace( '#</p>#i', "\n\n", $html );

        // Strip tag
        $testo = wp_strip_all_tags( $html, true );

        // Decodifica entità con il charset del sito
        $charset = get_bloginfo( 'charset' );
        if ( ! $charset ) { $charset = 'UTF-8'; }
        $testo = html_entity_decode( $testo, ENT_QUOTES | ENT_HTML5, $charset );

        // Rimozione eventuali shortcode residui [qualcosa]
        $testo = preg_replace( '/\[[^\]]*\]/', '', $testo );

        // Normalizza spazi
        $testo = preg_replace( "/[ \t]+/u", ' ', $testo );
        $testo = preg_replace( "/\n{3,}/u", "\n\n", $testo );
        $testo = trim( $testo );

        return $testo;
    }
}

<?php
if ( ! defined('ABSPATH') ) { exit; }

/**
 * Renderer robusto per ottenere TESTO PULITO da un post costruito con
 * Gutenberg / Divi / Elementor / shortcodes custom, anche durante AJAX.
 *
 * v6.0.0 - Riprogettato per la semplicità (ispirato a GeminiBot)
 *
 * La strategia di GeminiBot.php funziona perché si affida a WordPress
 * per aver già caricato tutti i plugin (incluso i page builder) e
 * registrato i loro shortcode nel contesto di admin-ajax.php.
 *
 * L'approccio precedente (v5.9.2) era troppo complesso:
 * 1. Cercava di chiamare i metodi "nativi" dei builder (es. Elementor)
 * che possono fallire in un contesto AJAX non standard.
 * 2. Usava fallback (REST, HTTP) che sono lenti e inaffidabili.
 *
 * Questa versione torna alla semplicità:
 * 1. Imposta il contesto del post (global $post, setup_postdata).
 * 2. Applica do_blocks() per Gutenberg.
 * 3. Applica il filtro 'the_content', che è il punto in cui
 * Divi, Elementor, WPBakery, ecc. espandono i loro shortcode.
 * 4. Esegue una pulizia finale.
 */
class Assistente_IA_Renderer {

    /**
     * Inizializza il renderer.
     * (Non è più necessario forzare il caricamento dei moduli builder)
     */
    public static function init() {
        // Non è più necessario forzare Divi o Elementor in AJAX.
        // Ci affidiamo al caricamento standard di admin-ajax.php.
    }

    /**
     * Ottiene il testo pulito di un post.
     *
     * @param int  $post_id
     * @return string Testo plain ripulito
     */
    public static function ottieni_testo_pulito_da_post( $post_id, $usa_rest_fallback = false, $usa_http_fallback = false ) {
        $post_obj = get_post( $post_id );
        
        // Controllo base sul post
        if ( ! $post_obj || 'publish' !== $post_obj->post_status || ! empty( $post_obj->post_password ) ) {
            return '';
        }

        // --- Setup del contesto globale (CRITICO per molti shortcode) ---
        // Questo è un passaggio "best practice" che GeminiBot omette, 
        // ma che garantisce che shortcode che dipendono da `global $post` funzionino.
        global $post;
        $old_post = $post;
        $GLOBALS['post'] = $post_obj;
        setup_postdata( $post_obj );

        // --- ESTRAZIONE (La via semplice, come GeminiBot) ---
        $html = $post_obj->post_content;

        // 1. Espandi blocchi Gutenberg
        if ( function_exists( 'has_blocks' ) && function_exists( 'do_blocks' ) && has_blocks( $post_obj ) ) {
            $html = do_blocks( $html );
        }

        // 2. Applica 'the_content'
        // Questo è il filtro chiave dove Divi, Elementor, WPBakery, etc.
        // sostituiscono i loro shortcode [et_pb_...], [vc_...], etc.
        // Questo è il passaggio fondamentale che usa GeminiBot.
        $html = apply_filters( 'the_content', $html );

        // 3. Fallback: Esegui do_shortcode (sicurezza)
        // A volte 'the_content' non esegue do_shortcode o lo fa troppo presto.
        // Eseguirlo di nuovo assicura che gli shortcode (anche quelli
        // registrati senza agganciarsi a 'the_content') siano processati.
        $html = do_shortcode( shortcode_unautop( $html ) );

        // --- PULIZIA TESTO ---
        // Usiamo la funzione di pulizia robusta della v5.9.2
        $testo = self::pulisci_html_in_testo( $html );
        
        // Aggiungi titolo se presente
        $titolo = get_the_title( $post_obj );
        if ( $titolo ) {
            $testo = wp_strip_all_tags( $titolo ) . '. ' . $testo;
        }

        // Ripristina globali
        $GLOBALS['post'] = $old_post;
        wp_reset_postdata();

        return $testo;
    }
    
    /**
     * Pulisce HTML e lo converte in testo plain
     * (Questa funzione dalla v5.9.2 va bene e può essere mantenuta)
     */
    private static function pulisci_html_in_testo( $html ) {
        $html = (string) $html;
        
        // Rimuovi script/style/noscript/svg
        $html = preg_replace( '#<(script|style|noscript|svg)[^>]*>.*?</\1>#si', ' ', $html );
        
        // Gestisci <br> e <p> come newline
        $html = preg_replace( '#<br\s*/?>#i', "\n", $html );
        $html = preg_replace( '#</p>#i', "\n\n", $html );
        
        // Normalizza entità
        $html = str_replace( ']]>', ']]&gt;', $html );

        // Converte in testo (come fa GeminiBot.php)
        $testo = wp_strip_all_tags( $html, true );
        $testo = html_entity_decode( $testo, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        
        // Rimuovi shortcode residui (se 'the_content' ha fallito)
        $testo = preg_replace( '/\[[^\]]*\]/', '', $testo );
        
        // Rimuovi CSS residuo
        $testo = preg_replace( '/#[0-9a-fA-F]{3,6}\b/', '', $testo );
        $testo = preg_replace( '/rgba?\([^)]+\)/', '', $testo );
        $testo = preg_replace( '/\d+(?:px|em|rem|%|vh|vw|pt)\b/i', '', $testo );
        
        // Spazi puliti
        $testo = preg_replace( "/[ \t]+/", ' ', $testo );
        $testo = preg_replace( "/\n{3,}/", "\n\n", $testo );
        $testo = trim( $testo );
        
        return $testo;
    }

    /**
     * Debug rapido: verifica che gli shortcode chiave siano registrati.
     * (Questa funzione è ancora utile per la diagnostica)
     */
    public static function diagnostica_shortcodes() {
        global $shortcode_tags;
        $chiavi = [ 
            'et_pb_section' => 'Divi',
            'et_pb_row' => 'Divi', 
            'et_pb_column' => 'Divi',
            'elementor-template' => 'Elementor',
            'vc_row' => 'Visual Composer',
            'vc_column' => 'Visual Composer'
        ];
        
        $risultato = [
            'registrati' => [],
            'mancanti' => [],
            'totale' => count($shortcode_tags)
        ];
        
        foreach ( $chiavi as $sc => $builder ) {
            if ( isset( $shortcode_tags[$sc] ) ) {
                $risultato['registrati'][$builder][] = $sc;
            } else {
                $risultato['mancanti'][$builder][] = $sc;
            }
        }
        
        return $risultato;
    }
}
<?php
if ( ! defined('ABSPATH') ) { exit; }

/**
 * Renderer robusto per ottenere TESTO PULITO da un post costruito con
 * Gutenberg / Divi / Elementor / shortcodes custom, anche durante AJAX.
 * 
 * v5.9.2 - SOLUZIONE DEFINITIVA per tutti i page builder
 */
class Assistente_IA_Renderer {

    /**
     * Inizializza il renderer e forza il caricamento dei page builder in AJAX
     * DA CHIAMARE UNA SOLA VOLTA nel plugin principale
     */
    public static function init() {
        // CRITICO: Forza Divi a caricarsi anche in AJAX
        if ( defined('ET_BUILDER_VERSION') ) {
            add_filter( 'et_builder_load_modules', '__return_true' );
            add_filter( 'et_builder_should_load_framework', '__return_true' );
            
            // Assicura che Divi sia inizializzato
            add_action( 'init', function() {
                if ( function_exists('et_builder_init_global_settings') ) {
                    et_builder_init_global_settings();
                }
                if ( function_exists('et_builder_add_main_elements') ) {
                    et_builder_add_main_elements();
                }
            }, 1 );
        }
        
        // Forza Elementor in AJAX
        if ( did_action('elementor/loaded') ) {
            add_action( 'init', function() {
                if ( class_exists('\Elementor\Plugin') ) {
                    \Elementor\Plugin::instance()->init();
                }
            }, 1 );
        }
    }

    /**
     * Ottiene il testo pulito di un post.
     *
     * @param int  $post_id
     * @param bool $usa_rest_fallback  Se true, prova anche /wp/v2/posts/{id}
     * @param bool $usa_http_fallback  Ultimo fallback: GET del permalink (costoso)
     * @return string Testo plain ripulito
     */
    public static function ottieni_testo_pulito_da_post( $post_id, $usa_rest_fallback = true, $usa_http_fallback = false ) {
        $post = get_post( $post_id );
        if ( ! $post || 'trash' === $post->post_status ) { 
            return ''; 
        }

        // --- Setup del contesto globale (CRITICO per i page builder) ---
        global $post;
        $old_post = $post;
        $GLOBALS['post'] = $post;
        setup_postdata( $post );

        // --- TENTATIVO 1: percorso "nativo" del builder ---
        $html = '';

        // Elementor: renderer ufficiale front-end
        if ( did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' ) ) {
            try {
                $elementor = \Elementor\Plugin::$instance;
                if ( $elementor->db->is_built_with_elementor( $post_id ) ) {
                    $html = $elementor->frontend->get_builder_content_for_display( $post_id, true );
                }
            } catch ( \Throwable $e ) {
                // continua con i fallback
            }
        }

        // Se non è Elementor o non ha reso nulla, prova Gutenberg + the_content
        if ( '' === trim( $html ) ) {
            $content = $post->post_content;

            // Gutenberg: espandi i blocchi prima del filtro classico
            if ( function_exists( 'has_blocks' ) && function_exists( 'do_blocks' ) && has_blocks( $post ) ) {
                $content = do_blocks( $content );
            }

            // Filtro principale: qui entrano Divi, WPBakery, shortcode registrati su the_content
            $html = apply_filters( 'the_content', $content );

            // Se sospettiamo shortcode non processati, prova anche do_shortcode
            if ( strpos( $html, '[et_pb_' ) !== false || 
                 strpos( $html, '[vc_' ) !== false || 
                 strpos( $content, '[' ) !== false ) {
                $maybe = do_shortcode( shortcode_unautop( $content ) );
                if ( strlen( wp_strip_all_tags( $maybe, true ) ) > strlen( wp_strip_all_tags( $html, true ) ) ) {
                    $html = $maybe;
                }
            }
        }

        // --- TENTATIVO 2: REST API (spesso identico al front-end) ---
        if ( $usa_rest_fallback && '' === trim( wp_strip_all_tags( $html, true ) ) && function_exists( 'rest_do_request' ) ) {
            $req = new \WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
            $res = rest_do_request( $req );
            if ( $res && 200 === $res->get_status() ) {
                $data = $res->get_data();
                if ( isset( $data['content']['rendered'] ) ) {
                    $html = (string) $data['content']['rendered'];
                }
            }
        }

        // --- TENTATIVO 3 (opzionale): GET del permalink ---
        if ( $usa_http_fallback && '' === trim( wp_strip_all_tags( $html, true ) ) ) {
            $permalink = get_permalink( $post_id );
            if ( $permalink ) {
                $r = wp_remote_get( $permalink, [
                    'timeout'    => 20,
                    'user-agent' => 'AssistenteIA/1.0',
                    'headers'    => [ 'Cache-Control' => 'no-cache' ],
                ] );
                if ( ! is_wp_error( $r ) ) {
                    $body = wp_remote_retrieve_body( $r );
                    // Estrai solo il contenuto principale se presente
                    if ( preg_match( '/<main[^>]*>(.*?)<\/main>/si', $body, $matches ) ) {
                        $html = $matches[1];
                    } elseif ( preg_match( '/<article[^>]*>(.*?)<\/article>/si', $body, $matches ) ) {
                        $html = $matches[1];
                    } else {
                        $html = $body;
                    }
                }
            }
        }

        // --- PULIZIA TESTO ---
        $testo = self::pulisci_html_in_testo( $html );
        
        // Aggiungi titolo se presente
        $titolo = get_the_title( $post_id );
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

        // Converte in testo
        $testo = wp_strip_all_tags( $html, true );
        $testo = html_entity_decode( $testo, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        
        // Rimuovi shortcode residui
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

<?php
if ( ! defined('ABSPATH') ) { exit; }

class Assistente_IA_Indexer {

    public static function handle_reindex_request() {
        if ( ! current_user_can('manage_options') ) { wp_die('Forbidden'); }
        check_admin_referer('assia_rag_reindex');

        $count = self::reindex();
        wp_safe_redirect( add_query_arg([ 'page'=>'assistente-ia-rag', 'assia_reindexed'=>$count ], admin_url('admin.php')) );
        exit;
    }

    /**
     * Reindicizza tutti i contenuti in base alla selezione nelle impostazioni RAG
     * @return int numero di chunk indicizzati
     */
    public static function reindex() {
        $opts_general = get_option('assia_options', []);
        $opts_rag     = get_option('assia_rag_options', []);

        $post_ids = self::collect_post_ids($opts_rag);
        $total_chunks = 0;

        foreach ($post_ids as $pid) {
            $post = get_post($pid);
            if ( ! $post || $post->post_status !== 'publish' ) { continue; }

            // Pulisci vecchi embeddings per il post
            Assistente_IA_DB::clear_post($pid);

            $text = self::build_clean_text($post);
            if ( ! $text ) { continue; }

            $chunks = self::chunk_text($text, 1000, 150);
            $chunks = self::dedupe_chunks($chunks);

            // Ottieni vettori dal modello di embedding
            $vectors = self::embed_texts($chunks, $opts_general);
            if ( is_wp_error($vectors) ) {
                // Salta ma continua per gli altri
                continue;
            }

            foreach ($vectors as $i => $vec) {
                Assistente_IA_DB::insert_chunk($pid, $post->post_type, $i, $chunks[$i], $vec);
                $total_chunks++;
            }
        }

        return $total_chunks;
    }

    /**
     * Colleziona gli ID da includere secondo le opzioni (articoli, pagine, prodotti)
     */
    protected static function collect_post_ids($opts_rag) {
        $ids = [];

        // Articoli
        if ( isset($opts_rag['posts_mode']) && $opts_rag['posts_mode'] === 'selected' && ! empty($opts_rag['posts_ids']) ) {
            $ids = array_merge($ids, array_map('intval', $opts_rag['posts_ids']));
        } else {
            $posts = get_posts([ 'post_type'=>'post', 'post_status'=>'publish', 'posts_per_page'=>-1, 'fields'=>'ids' ]);
            $ids = array_merge($ids, $posts);
        }

        // Pagine
        if ( isset($opts_rag['pages_mode']) && $opts_rag['pages_mode'] === 'selected' && ! empty($opts_rag['pages_ids']) ) {
            $ids = array_merge($ids, array_map('intval', $opts_rag['pages_ids']));
        } else {
            $pages = get_posts([ 'post_type'=>'page', 'post_status'=>'publish', 'posts_per_page'=>-1, 'fields'=>'ids' ]);
            $ids = array_merge($ids, $pages);
        }

        // Prodotti WooCommerce (tutti se post_type esiste)
        if ( post_type_exists('product') ) {
            $products = get_posts([ 'post_type'=>'product', 'post_status'=>'publish', 'posts_per_page'=>-1, 'fields'=>'ids' ]);
            $ids = array_merge($ids, $products);
        }

        // Dedup
        $ids = array_values(array_unique(array_map('intval', $ids)));
        return $ids;
    }

    /**
     * Estrae testo pulito dal post usando il nuovo Renderer
     * 
     * ✅ v5.9.3: Usa il renderer robusto che gestisce tutti i page builder
     * 
     * @param WP_Post $post Post da cui estrarre il testo
     * @return string Testo pulito senza HTML/shortcode
     */
    protected static function build_clean_text($post) {
        if ( ! $post || empty( $post->post_content ) ) {
            return '';
        }
        
        // Se il renderer è disponibile, usalo
        if ( class_exists('Assistente_IA_Renderer') ) {
            $testo = Assistente_IA_Renderer::ottieni_testo_pulito_da_post( 
                $post->ID, 
                true,  // usa REST fallback
                false  // non usare HTTP fallback (costoso)
            );
        } else {
            // Fallback se il renderer non è disponibile
            global $post_temp;
            $post_temp_backup = $post_temp;
            $post_temp = $post;
            $GLOBALS['post'] = $post;
            setup_postdata( $post );
            
            $content = $post->post_content;
            
            // Applica filtri standard
            $content = apply_filters( 'the_content', $content );
            
            // Pulizia HTML
            $content = preg_replace( '/<script\b[^>]*>(.*?)<\/script>/is', '', $content );
            $content = preg_replace( '/<style\b[^>]*>(.*?)<\/style>/is', '', $content );
            $content = preg_replace( '/<!--(.*)-->/Uis', '', $content );
            
            // Strip tags
            $content = wp_strip_all_tags( $content );
            
            // Decode entities
            $content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            
            // Rimuovi shortcode residui
            $content = preg_replace( '/\[[^\]]*\]/', '', $content );
            
            // Normalizza spazi
            $content = preg_replace( '/\s+/', ' ', $content );
            
            // Aggiungi titolo
            $title = wp_strip_all_tags( $post->post_title );
            $testo = $title ? $title . '. ' . $content : $content;
            
            // Ripristina globals
            $GLOBALS['post'] = $post_temp_backup;
            wp_reset_postdata();
        }
        
        // Debug se attivo
        if ( defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options') ) {
            error_log('===== ASSIA RAG v5.9.3 DEBUG =====');
            error_log('Post ID: ' . $post->ID);
            error_log('Post Title: ' . $post->post_title);
            error_log('Renderer disponibile: ' . (class_exists('Assistente_IA_Renderer') ? 'SI' : 'NO'));
            
            $shortcode_rimasti = preg_match_all('/\[[\w\-_]+/', $testo);
            error_log('Lunghezza testo: ' . strlen($testo));
            error_log('Shortcode rimasti: ' . $shortcode_rimasti);
            
            if ( $shortcode_rimasti > 0 ) {
                error_log('⚠️ ATTENZIONE: Ancora shortcode nel risultato!');
            } else {
                error_log('✅ SUCCESSO: Nessun shortcode residuo!');
            }
            error_log('===== FINE DEBUG =====');
        }
        
        return trim( $testo );
    }

    /**
     * Chunking semplice per caratteri (~1000) con overlap
     */
    protected static function chunk_text($text, $size = 1000, $overlap = 150) {
        $chunks = [];
        $len = strlen($text);
        $pos = 0;
        while ($pos < $len) {
            $chunk = substr($text, $pos, $size);
            $chunks[] = $chunk;
            if ($pos + $size >= $len) {
                break;
            }
            $pos += ($size - $overlap);
        }
        return $chunks;
    }

    /**
     * Deduplica chunk uguali (trim/normalizzazione base)
     */
    protected static function dedupe_chunks($chunks) {
        $out = [];
        $seen = [];
        foreach ($chunks as $c) {
            $n = trim(preg_replace('/\s+/', ' ', $c));
            $key = md5($n);
            if ( isset($seen[$key]) ) { continue; }
            $seen[$key] = true;
            $out[] = $c;
        }
        return $out;
    }

    /**
     * Chiama il modello di embedding per ottenere il vettore di ciascun chunk.
     * Ritorna array di float[] oppure WP_Error
     */
    protected static function embed_texts($chunks, $opts) {
        $endpoint = isset($opts['embedding_endpoint']) ? $opts['embedding_endpoint'] : '';
        $api_key  = isset($opts['embedding_api_key']) ? $opts['embedding_api_key'] : '';

        if ( empty($endpoint) || empty($api_key) ) {
            return new WP_Error('assia_no_embed_config', 'Endpoint o API Key embeddings mancanti.');
        }

        $instances = [];
        foreach ($chunks as $c) {
            $instances[] = [ 'content' => mb_substr($c, 0, 8000) ];
        }

        $body = wp_json_encode([ 'instances' => $instances ]);
        $args = [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body'    => $body,
            'timeout' => 60,
        ];

        $res = wp_remote_post($endpoint, $args);
        if ( is_wp_error($res) ) {
            return $res;
        }
        $code = wp_remote_retrieve_response_code($res);
        $json = json_decode(wp_remote_retrieve_body($res), true);
        if ($code >= 300 || ! isset($json['predictions']) ) {
            return new WP_Error('assia_embed_http', 'Errore HTTP embeddings: '.$code.' - '.substr(wp_remote_retrieve_body($res),0,300));
        }

        $vectors = [];
        foreach ($json['predictions'] as $pred) {
            if ( isset($pred['embeddings']['values']) && is_array($pred['embeddings']['values']) ) {
                $vectors[] = array_map('floatval', $pred['embeddings']['values']);
            }
        }
        return $vectors;
    }
}

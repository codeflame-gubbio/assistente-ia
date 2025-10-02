<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * RAG AVANZATO con:
 * - Sistema snapshot hashato completo
 * - Chunking intelligente con overlap di frasi
 * - Modalità recupero BEST-1 vs TOP-K
 * - Chunk size aumentato a 1200 caratteri per maggiore contesto
 */
class Assistente_IA_RAG {

    /** 
     * Recupera estratti pertinenti con modalità BEST-1 o TOP-K
     */
    public static function recupera_estratti_rag( string $domanda ): string {
        $mode = get_option('assia_rag_mode', 'top-k');
        $k = (int) get_option('assia_embeddings_top_k', 3);
        $threshold = (float) get_option('assia_embeddings_threshold', 0.30);
        
        if ( 'si' !== get_option('assia_attiva_embeddings','si') ) {
            $schede = self::fallback_keyword( $domanda, max(1,$k) );
            return self::schede_to_contesto( $schede );
        }

        $sigla = get_option('assia_modello_embedding','text-embedding-005');

        $emb = Assistente_IA_Modello_Vertex::calcola_embedding(
            Assistente_IA_Utilita::pulisci_testo($domanda),
            [ 'id_chat' => null, 'hash_sessione' => null ]
        );
        
        if ( empty($emb['vettore']) ) {
            $schede = self::fallback_keyword( $domanda, max(1,$k) );
            return self::schede_to_contesto( $schede );
        }
        
        $vq = $emb['vettore'];

        global $wpdb; 
        $pref = $wpdb->prefix;
        $righe = $wpdb->get_results( $wpdb->prepare(
            "SELECT id_riferimento, fonte, testo_chunk, embedding 
             FROM {$pref}assistente_ia_embeddings 
             WHERE modello=%s",
            $sigla
        ), ARRAY_A );

        $punteggi = [];
        
        if ( ! empty($righe) ){
            foreach( $righe as $idx => $r ){
                $vec = json_decode( $r['embedding'], true );
                if ( ! is_array($vec) ) continue;
                
                $score = self::coseno($vq, $vec);
                
                $punteggi[] = [ 
                    'i' => $idx, 
                    'score' => $score, 
                    'testo' => $r['testo_chunk'],
                    'id_riferimento' => $r['id_riferimento'],
                    'fonte' => $r['fonte']
                ];
            }
            
            usort( $punteggi, function($a,$b){ 
                return $b['score'] <=> $a['score']; 
            });
        }
        
        $totali_prima_filtro = count($punteggi);
        $punteggi_filtrati = array_filter($punteggi, function($p) use ($threshold) {
            return $p['score'] >= $threshold;
        });
        $totali_dopo_filtro = count($punteggi_filtrati);
        $scartati = $totali_prima_filtro - $totali_dopo_filtro;
        
        if ( $scartati > 0 && current_user_can('manage_options') ) {
            error_log(sprintf(
                'ASSIA RAG [%s]: Query "%s" - Scartati %d chunk (score < %.2f). Best: %.3f',
                $mode,
                substr($domanda, 0, 50),
                $scartati,
                $threshold,
                isset($punteggi[0]['score']) ? $punteggi[0]['score'] : 0
            ));
        }
        
        // ✅ APPLICA MODALITÀ: BEST-1 vs TOP-K
        if ( $mode === 'best-1' ) {
            $punteggi_filtrati = array_slice( $punteggi_filtrati, 0, 1 );
        } else {
            $punteggi_filtrati = array_slice( $punteggi_filtrati, 0, max(1,$k) );
        }
        
        $estratti = [];
        $seen_hashes = [];
        
        foreach( $punteggi_filtrati as $p ){
            $testo_pulito = Assistente_IA_Utilita::pulisci_testo($p['testo']);
            $hash = md5($testo_pulito);
            
            if ( isset($seen_hashes[$hash]) ) {
                continue;
            }
            $seen_hashes[$hash] = true;
            
            $fonte_header = self::get_chunk_header($p['fonte'], $p['id_riferimento']);
            
            // ✅ Aumentato limite a 1500 caratteri per chunk più completi
            $chunk_formattato = $fonte_header . "\n" . 
                               Assistente_IA_Utilita::tronca($testo_pulito, 1500);
            
            $estratti[] = $chunk_formattato;
        }

        $best_score = isset($punteggi[0]['score']) ? (float)$punteggi[0]['score'] : 0.0;

        if ( empty($estratti) ){
            error_log(sprintf(
                'ASSIA RAG [%s]: Nessun chunk sopra threshold %.2f per query "%s" (best: %.3f) - Fallback',
                $mode,
                $threshold,
                substr($domanda, 0, 50),
                $best_score
            ));
            
            $schede = self::fallback_keyword( $domanda, max(1,$k) );
            $estratti_fb = self::schede_to_array( $schede );
            
            $estratti_combined = array_values( 
                array_filter( 
                    array_unique( 
                        array_merge($estratti_fb, $estratti) 
                    ) 
                ) 
            );
            
            return implode("\n---\n", $estratti_combined);
        }

        return implode("\n---\n", $estratti);
    }

    protected static function get_chunk_header( string $fonte, int $id_riferimento ): string {
        if ( $fonte === 'prodotto' && function_exists('wc_get_product') ) {
            $product = wc_get_product( $id_riferimento );
            if ( $product ) {
                $titolo = $product->get_name();
                return "[Fonte: Prodotto '{$titolo}']";
            }
        }
        
        $post = get_post( $id_riferimento );
        if ( $post ) {
            $titolo = get_the_title( $id_riferimento );
            $tipo = get_post_type( $id_riferimento );
            $tipo_label = $tipo === 'page' ? 'Pagina' : 'Articolo';
            return "[Fonte: {$tipo_label} '{$titolo}']";
        }
        
        return "[Fonte: Contenuto ID {$id_riferimento}]";
    }

    public static function prepara_job_indicizzazione(): array {
        $auto_regen = get_option('assia_auto_regenerate_hash', 'si') === 'si';
        
        $voci_tutte = [];

        if ( class_exists('Assistente_IA_Content_Selector') ) {
            $selected_ids = Assistente_IA_Content_Selector::get_selected_content_ids();
        } else {
            $q = new WP_Query([
                'post_type' => ['post', 'page'],
                'post_status' => 'publish',
                'numberposts' => -1,
                'fields' => 'ids'
            ]);
            $selected_ids = $q->posts ?? [];
        }

        foreach( $selected_ids as $pid ){
            $pid = (int)$pid;
            $status = get_post_status($pid);
            $type = get_post_type($pid);
            
            if ( $status === 'publish' && in_array($type, ['post', 'page'], true) ) {
                $voci_tutte[] = [ 'fonte' => 'post', 'id' => $pid ];
            }
        }

        if ( self::woo_attivo() ) {
            $qp = new WP_Query([
                'post_type' => ['product'],
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids'
            ]);
            foreach( ($qp->posts ?? []) as $pid ){
                $voci_tutte[] = [ 'fonte' => 'prodotto', 'id' => (int)$pid ];
            }
        }

        $voci_da_processare = [];
        
        if ( $auto_regen ) {
            $voci_da_processare = self::rileva_modifiche_snapshot( $voci_tutte );
        } else {
            $voci_da_processare = $voci_tutte;
        }

        $job = [
            'stato' => 'in_attesa',
            'totale' => count($voci_da_processare),
            'indice' => 0,
            'creati' => 0,
            'modello' => get_option('assia_modello_embedding','text-embedding-005'),
            'voci' => $voci_da_processare,
            'avviato_il' => current_time('mysql'),
            'completato_il' => null,
            'errori' => [],
        ];
        
        set_transient('assia_job_embeddings', $job, HOUR_IN_SECONDS);
        
        return $job;
    }

    protected static function rileva_modifiche_snapshot( array $voci_nuove ): array {
        $snapshot_old = get_option('assia_content_snapshot', []);

  // ✅ FIX: Se lo snapshot è vuoto, rigenera tutto
    if ( empty($snapshot_old) ) {
        error_log('ASSIA SNAPSHOT: Snapshot vuoto, rigenerazione completa');
        $snapshot_new = [];
        foreach ( $voci_nuove as $v ) {
            $key = $v['fonte'] . '_' . $v['id'];
            $testo = ( $v['fonte'] === 'prodotto' )
                ? self::costruisci_testo_prodotto( $v['id'] )
                : self::testo_da_post( $v['id'] );
            if ( ! $testo ) continue;
            $snapshot_new[$key] = md5( $testo );
        }
        update_option('assia_content_snapshot', $snapshot_new);
        return $voci_nuove; // Ritorna TUTTO per rigenerare
    }



        $snapshot_new = [];
        $da_rigenerare = [];
        
        foreach ( $voci_nuove as $v ) {
            $key = $v['fonte'] . '_' . $v['id'];
            
            $testo = ( $v['fonte'] === 'prodotto' )
                ? self::costruisci_testo_prodotto( $v['id'] )
                : self::testo_da_post( $v['id'] );
            
            if ( ! $testo ) continue;
            
            $hash_new = md5( $testo );
            $snapshot_new[$key] = $hash_new;
            
            if ( ! isset($snapshot_old[$key]) || $snapshot_old[$key] !== $hash_new ) {
                $da_rigenerare[] = $v;
                
                if ( isset($snapshot_old[$key]) ) {
                    error_log("ASSIA SNAPSHOT: Modificato {$v['fonte']} ID {$v['id']}");
                } else {
                    error_log("ASSIA SNAPSHOT: Nuovo {$v['fonte']} ID {$v['id']}");
                }
            }
        }
        
        $eliminati = array_diff_key( $snapshot_old, $snapshot_new );
        
        foreach ( $eliminati as $key => $hash ) {
            $parts = explode('_', $key, 2);
            if ( count($parts) === 2 ) {
                $fonte = $parts[0];
                $id = (int)$parts[1];
                
                self::rimuovi_embeddings( $fonte, $id );
                error_log("ASSIA SNAPSHOT: Eliminato {$fonte} ID {$id}");
            }
        }
        
        update_option('assia_content_snapshot', $snapshot_new);
        
        $nuovi = count($da_rigenerare) - count(array_filter($da_rigenerare, function($v) use ($snapshot_old) {
            return isset($snapshot_old[$v['fonte'] . '_' . $v['id']]);
        }));
        $modificati = count($da_rigenerare) - $nuovi;
        $eliminati_count = count($eliminati);
        
        error_log(sprintf(
            'ASSIA SNAPSHOT: Nuovi=%d, Modificati=%d, Eliminati=%d, Da rigenerare=%d',
            $nuovi,
            $modificati,
            $eliminati_count,
            count($da_rigenerare)
        ));
        
        return $da_rigenerare;
    }

    protected static function rimuovi_embeddings( string $fonte, int $id_rif ): void {
        global $wpdb;
        $pref = $wpdb->prefix;
        $modello = get_option('assia_modello_embedding', 'text-embedding-005');
        
        $deleted = $wpdb->delete(
            "{$pref}assistente_ia_embeddings",
            [
                'fonte' => $fonte,
                'id_riferimento' => $id_rif,
                'modello' => $modello
            ],
            ['%s', '%d', '%s']
        );
        
        if ( $deleted !== false && $deleted > 0 ) {
            error_log("ASSIA: Rimossi {$deleted} embeddings per {$fonte} ID {$id_rif}");
        }
    }

    public static function esegui_job_passaggio( int $batch = 5 ): array {
        $job = get_transient('assia_job_embeddings');
        if ( ! is_array($job) ) { return [ 'errore' => 'Nessun job attivo' ]; }
        
        $job['stato'] = 'in_corso';
        $tot = (int)$job['totale'];
        $i   = (int)$job['indice'];
        $modello = $job['modello'];

        global $wpdb; $pref = $wpdb->prefix;

        for ( $step = 0; $step < $batch && $i < $tot; $step++, $i++ ) {
            $voce = $job['voci'][$i] ?? null;
            if ( ! is_array($voce) ) continue;

            $fonte = $voce['fonte'] ?? 'post';
            $pid   = (int)($voce['id'] ?? 0);
            if ( $pid <= 0 ) continue;

            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$pref}assistente_ia_embeddings WHERE fonte=%s AND id_riferimento=%d AND modello=%s",
                $fonte, $pid, $modello
            ) );

            $testo = ( $fonte === 'prodotto' )
                ? self::costruisci_testo_prodotto( $pid )
                : self::testo_da_post( $pid );

            if ( ! $testo ) { continue; }

            // ✅ Chunk size aumentato a 1200 caratteri
            $chunks = self::spezza_testo_smart( $testo, 1200 );
            
            $indice_chunk = 0;
            foreach( $chunks as $ch ){
                $emb = Assistente_IA_Modello_Vertex::calcola_embedding( $ch, [
                    'id_chat' => null,
                    'hash_sessione' => null
                ] );
                
                if ( empty($emb['vettore']) ) { 
                    $job['errori'][] = "Embedding vuoto per {$fonte} {$pid}"; 
                    continue; 
                }
                
                self::salva_embedding( $fonte, $pid, $indice_chunk++, $ch, $emb['vettore'], $modello );
                $job['creati']++;
            }
        }

        $job['indice'] = $i;
        
        if ( $i >= $tot ) {
            $job['stato'] = 'completato';
            $job['completato_il'] = current_time('mysql');
            
            self::appendi_log_embeddings([
                'avviato_il' => $job['avviato_il'],
                'completato_il' => $job['completato_il'],
                'modello' => $modello,
                'tot_voci' => $tot,
                'chunks_creati' => $job['creati'],
                'errori' => $job['errori'],
            ]);
            
            delete_transient('assia_job_embeddings');
        } else {
            set_transient('assia_job_embeddings', $job, HOUR_IN_SECONDS);
        }
        
        $job['percentuale'] = ($tot > 0) ? round(($job['indice'] / $tot) * 100) : 100;
        
        return $job;
    }

    public static function stato_job(): array {
        $job = get_transient('assia_job_embeddings');
        if ( ! is_array($job) ) { return [ 'stato' => 'nessun_job' ]; }
        $tot = (int)$job['totale'];
        $job['percentuale'] = ($tot > 0) ? round(($job['indice'] / $tot) * 100) : 0;
        return $job;
    }

    protected static function appendi_log_embeddings( array $voce ): void {
        $log = get_option('assia_log_embeddings', []);
        if ( ! is_array($log) ) $log = [];
        $log[] = $voce;
        if ( count($log) > 10 ) { $log = array_slice($log, -10); }
        update_option('assia_log_embeddings', $log);
    }

    /** 
     * ✅ CHUNKING INTELLIGENTE CON OVERLAP DI FRASI
     * Default size aumentato a 1200 caratteri
     */
    protected static function spezza_testo_smart( string $testo, int $size = 1200 ): array {
        $overlap = (int) get_option('assia_chunk_overlap', 100);
        
        if ( $overlap <= 0 ) {
            return self::spezza_testo_legacy( $testo, $size );
        }
        
        $frasi = preg_split('/(?<=[.!?])\s+/u', $testo, -1, PREG_SPLIT_NO_EMPTY);
        
        if ( empty($frasi) ) {
            return [ $testo ];
        }
        
        $chunks = [];
        $current_chunk = [];
        $current_length = 0;
        
        foreach ( $frasi as $frase ) {
            $frase_len = mb_strlen( $frase );
            
            if ( $current_length + $frase_len > $size && ! empty($current_chunk) ) {
                $chunks[] = implode(' ', $current_chunk);
                
                $chunk_text = implode(' ', $current_chunk);
                $parole = explode(' ', $chunk_text);
                $overlap_words = array_slice( $parole, -$overlap );
                
                $current_chunk = $overlap_words;
                $current_length = mb_strlen( implode(' ', $current_chunk) );
            }
            
            $current_chunk[] = $frase;
            $current_length += $frase_len + 1;
        }
        
        if ( ! empty($current_chunk) ) {
            $chunks[] = implode(' ', $current_chunk);
        }
        
        return $chunks;
    }

    protected static function spezza_testo_legacy( string $t, int $n ): array {
        $ris = [];
        $t = trim($t);
        while( strlen($t) > $n ){
            $pos = strrpos( substr($t,0,$n), ' ' );
            if ( false === $pos ) $pos = $n;
            $ris[] = substr($t,0,$pos);
            $t = ltrim( substr($t,$pos) );
        }
        if ( $t !== '' ) $ris[] = $t;
        return $ris;
    }

    public static function rigenera_indice_post(): int {
        if ( 'si' !== get_option('assia_attiva_embeddings','si') ) return 0;

        $auto_regen = get_option('assia_auto_regenerate_hash', 'si') === 'si';
        $conteggio = 0;
        $modello   = get_option('assia_modello_embedding','text-embedding-005');
        $avvio     = current_time('mysql');
        $errori    = [];
        
        global $wpdb; $pref = $wpdb->prefix;

        $processa = function(string $fonte, int $pid) use (&$conteggio,&$errori,$modello,$wpdb,$pref){
            if ( $pid <= 0 ) return;

            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$pref}assistente_ia_embeddings WHERE fonte=%s AND id_riferimento=%d AND modello=%s",
                $fonte, $pid, $modello
            ) );

            $testo = ( $fonte === 'prodotto' )
                ? self::costruisci_testo_prodotto( $pid )
                : self::testo_da_post( $pid );

            if ( ! $testo ) return;

            $chunks = self::spezza_testo_smart( $testo, 1200 );
            $indice = 0;
            
            foreach( $chunks as $ch ){
                $emb = Assistente_IA_Modello_Vertex::calcola_embedding( $ch, [
                    'id_chat' => null, 'hash_sessione' => null
                ] );
                
                if ( empty($emb['vettore']) ) { 
                    $errori[] = "Embedding vuoto per {$fonte} {$pid}"; 
                    continue; 
                }
                
                self::salva_embedding( $fonte, $pid, $indice++, $ch, $emb['vettore'], $modello );
                $conteggio++;
            }
        };

        $voci_tutte = [];
        
        if ( class_exists('Assistente_IA_Content_Selector') ) {
            $selected_ids = Assistente_IA_Content_Selector::get_selected_content_ids();
        } else {
            $q = new WP_Query([ 'post_type'=>['post','page'], 'post_status'=>'publish', 'posts_per_page'=>-1, 'fields'=>'ids' ]);
            $selected_ids = $q->posts ?? [];
        }

        foreach( $selected_ids as $pid ){ 
            $pid = (int)$pid;
            $status = get_post_status($pid);
            if ( $status === 'publish' ) {
                $voci_tutte[] = ['fonte' => 'post', 'id' => $pid];
            }
        }

        if ( self::woo_attivo() ) {
            $qp = new WP_Query([ 'post_type'=>['product'], 'post_status'=>'publish', 'posts_per_page'=>-1, 'fields'=>'ids' ]);
            foreach( ($qp->posts ?? []) as $pid ){ 
                $voci_tutte[] = ['fonte' => 'prodotto', 'id' => (int)$pid];
            }
            wp_reset_postdata();
        }

        if ( $auto_regen ) {
            $voci_da_processare = self::rileva_modifiche_snapshot( $voci_tutte );
        } else {
            $voci_da_processare = $voci_tutte;
        }

        foreach ( $voci_da_processare as $v ) {
            $processa($v['fonte'], $v['id']);
        }

        self::appendi_log_embeddings([
            'avviato_il' => $avvio,
            'completato_il' => current_time('mysql'),
            'modello' => $modello,
            'tot_voci' => count($voci_da_processare),
            'chunks_creati' => $conteggio,
            'errori' => $errori,
        ]);
        
        return $conteggio;
    }

    protected static function fallback_keyword( string $query, int $limite = 3 ): array {
        global $wpdb;
        $q = trim( wp_strip_all_tags( $query ) );
        if ( $q === '' ) return [];

        $like = '%' . $wpdb->esc_like( $q ) . '%';
        $sql = "
            SELECT ID, post_type
            FROM {$wpdb->posts}
            WHERE post_status='publish'
              AND post_type IN ('post','page','product')
              AND ( post_title LIKE %s OR post_content LIKE %s )
            ORDER BY post_date_gmt DESC
            LIMIT %d
        ";
        $righe = $wpdb->get_results( $wpdb->prepare( $sql, $like, $like, $limite ), ARRAY_A );
        if ( empty($righe) ) return [];

        $schede = [];
        foreach( $righe as $r ){
            $id = (int)$r['ID'];
            if ( $r['post_type'] === 'product' && self::woo_attivo() ){
                $txt = self::costruisci_testo_prodotto( $id );
            } else {
                $txt = self::testo_da_post( $id );
            }
            if ( $txt ) $schede[] = $txt;
        }
        return $schede;
    }

    protected static function schede_to_contesto( array $schede ): string {
        if ( empty($schede) ) return '';
        $pulite = [];
        foreach($schede as $s){
            $pulite[] = Assistente_IA_Utilita::tronca( Assistente_IA_Utilita::pulisci_testo($s), 1500 );
        }
        return implode("\n---\n", $pulite);
    }
    
    protected static function schede_to_array( array $schede ): array {
        if ( empty($schede) ) return [];
        $pulite = [];
        foreach($schede as $s){
            $pulite[] = Assistente_IA_Utilita::tronca( Assistente_IA_Utilita::pulisci_testo($s), 1500 );
        }
        return $pulite;
    }

    protected static function testo_da_post( int $pid ): string {
        global $post;
        $post_originale = $post;
        
        $post = get_post( $pid );
        if ( ! $post ) {
            return '';
        }
        
        setup_postdata( $post );
        
        $titolo = get_the_title( $pid );
        $estratto = get_the_excerpt( $pid );
        
        $contenuto_raw = $post->post_content;
        $contenuto_renderizzato = apply_filters( 'the_content', $contenuto_raw );
        
        $contenuto_renderizzato = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $contenuto_renderizzato);
        $contenuto_renderizzato = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $contenuto_renderizzato);
        $contenuto_renderizzato = preg_replace('/<!--(.*)-->/Uis', '', $contenuto_renderizzato);
        
        $testo = wp_strip_all_tags( $contenuto_renderizzato );
        
        $testo = html_entity_decode($testo, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $testo = preg_replace('/\s+/', ' ', $testo);
        $testo = preg_replace('/[\x00-\x1F\x7F]/u', '', $testo);
        $testo = trim( $testo );
        
        $testo = preg_replace('/#[0-9a-fA-F]{3,6}\b/', '', $testo);
        $testo = preg_replace('/rgba?\([^)]+\)/', '', $testo);
        $testo = preg_replace('/[a-z\-]+\s*:\s*[^;]+;?/i', '', $testo);
        $testo = preg_replace('/\d+(?:px|em|rem|%|vh|vw|pt)\b/i', '', $testo);
        
        $testo = preg_replace('/\s+/', ' ', trim($testo));
        
        wp_reset_postdata();
        $post = $post_originale;
        
        $estratto = wp_strip_all_tags( $estratto );
        $estratto = html_entity_decode($estratto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $estratto = preg_replace('/\s+/', ' ', trim($estratto));
        
        $link = get_permalink( $pid );

        $blocchi = [];
        
        if ( $titolo ) {
            $blocchi[] = "Titolo: " . $titolo;
        }
        
        if ( $estratto && strlen($estratto) > 10 ) {
            $blocchi[] = "Estratto: " . $estratto;
        }
        
        if ( $testo && strlen($testo) > 50 ) {
            $blocchi[] = "Contenuto: " . $testo;
        } else {
            if ( current_user_can('manage_options') ) {
                error_log("ASSIA RAG: Post ID {$pid} ({$titolo}) ha testo molto breve: " . strlen($testo) . " caratteri");
            }
        }
        
        if ( $link ) {
            $blocchi[] = "Link: " . $link;
        }

        $risultato = trim( implode("\n", $blocchi) );
        $risultato = apply_filters( 'assia_rag_testo_post', $risultato, $pid );
        
        return $risultato;
    }

    protected static function costruisci_testo_prodotto( int $product_id ): string {
        if ( ! self::woo_attivo() ) return '';
        $p = wc_get_product( $product_id );
        if ( ! $p ) return '';

        global $post;
        $post_originale = $post;
        $post = get_post( $product_id );
        setup_postdata( $post );

        $nome = get_the_title( $product_id );
        
        $short_raw = $p->get_short_description();
        $short_rendered = do_shortcode( $short_raw );
        $short_rendered = apply_filters( 'the_content', $short_rendered );
        $short_rendered = preg_replace('/\s+[a-zA-Z_\-]+\s*=\s*["\'][^"\']*["\']/i', '', $short_rendered);
        $short_rendered = strip_shortcodes( $short_rendered );
        $short_rendered = preg_replace('/\[[^\]]*\]/i', ' ', $short_rendered);
        $short = wp_strip_all_tags( $short_rendered );
        $short = preg_replace('/\s+/', ' ', trim($short));
        
        $desc_raw = $p->get_description();
        $desc_rendered = do_shortcode( $desc_raw );
        $desc_rendered = apply_filters( 'the_content', $desc_rendered );
        $desc_rendered = preg_replace('/\s+[a-zA-Z_\-]+\s*=\s*["\'][^"\']*["\']/i', '', $desc_rendered);
        $desc_rendered = strip_shortcodes( $desc_rendered );
        $desc_rendered = preg_replace('/\[[^\]]*\]/i', ' ', $desc_rendered);
        $desc = wp_strip_all_tags( $desc_rendered );
        $desc = preg_replace('/\s+/', ' ', trim($desc));
        
        wp_reset_postdata();
        $post = $post_originale;
        
        $prezzo = method_exists($p,'get_price') ? $p->get_price() : '';
        $valuta = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '€';
        $stock  = method_exists($p,'is_in_stock') ? ($p->is_in_stock() ? 'disponibile' : 'non disponibile') : '';
        $cats   = wp_get_post_terms( $product_id, 'product_cat', ['fields'=>'names'] );
        $cats_s = $cats ? implode(', ', $cats) : '';
        $link   = get_permalink( $product_id );

        $righe = [];
        $righe[] = "Prodotto: {$nome}";
        if($cats_s) $righe[] = "Categorie: {$cats_s}";
        if($prezzo!=='') $righe[] = "Prezzo: {$prezzo}{$valuta}";
        if($stock!=='')  $righe[] = "Disponibilità: {$stock}";
        if($short) $righe[] = "Estratto: {$short}";
        if($desc)  $righe[] = "Descrizione: {$desc}";
        if($link)  $righe[] = "Link: {$link}";

        $risultato = trim( implode("\n", array_filter($righe)) );
        $risultato = apply_filters( 'assia_rag_testo_prodotto', $risultato, $product_id );
        
        return $risultato;
    }

    protected static function woo_attivo(): bool {
        return class_exists('WooCommerce') || function_exists('wc_get_product');
    }

    protected static function salva_embedding( string $fonte, int $id_rif, int $i, string $chunk, array $vettore, string $modello = '' ): void {
        global $wpdb; $pref = $wpdb->prefix;
        if ( $modello === '' ) { $modello = get_option('assia_modello_embedding','text-embedding-005'); }
        $wpdb->insert( $pref.'assistente_ia_embeddings', [
            'fonte' => $fonte,
            'id_riferimento' => $id_rif,
            'indice_chunk' => $i,
            'testo_chunk' => $chunk,
            'embedding' => wp_json_encode( $vettore ),
            'modello' => $modello,
            'creato_il' => current_time('mysql')
        ] );
    }

    protected static function coseno( array $a, array $b ): float {
        $dot=0; $na=0; $nb=0; $len = min( count($a), count($b) );
        for($i=0;$i<$len;$i++){ $dot += $a[$i]*$b[$i]; $na += $a[$i]*$a[$i]; $nb += $b[$i]*$b[$i]; }
        $den = (sqrt($na)*sqrt($nb));
        return $den>0 ? ($dot/$den) : 0.0;
    }
}
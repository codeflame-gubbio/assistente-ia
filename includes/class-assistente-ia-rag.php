<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * RAG (embeddings) + diagnostica + job di rigenerazione a step con storico.
 * VERSIONE CON SELEZIONE MANUALE CONTENUTI
 * - Post/Pagine: solo quelli selezionati dall'admin
 * - Prodotti WooCommerce: sempre inclusi automaticamente
 */
class Assistente_IA_RAG {

    /** Recupera estratti pertinenti dal DB con similarità coseno (Top-K) + fallback keyword */
    public static function recupera_estratti_rag( string $domanda ): string {
    $k = (int) get_option('assia_embeddings_top_k', 3);
    
    // Se embeddings disattivati → fallback keyword
    if ( 'si' !== get_option('assia_attiva_embeddings','si') ) {
        $schede = self::fallback_keyword( $domanda, max(1,$k) );
        return self::schede_to_contesto( $schede );
    }

    $sigla = get_option('assia_modello_embedding','text-embedding-005');

    // Calcola embedding della domanda
    $emb = Assistente_IA_Modello_Vertex::calcola_embedding(
        Assistente_IA_Utilita::pulisci_testo($domanda),
        [ 'id_chat' => null, 'hash_sessione' => null ]
    );
    
    if ( empty($emb['vettore']) ) {
        $schede = self::fallback_keyword( $domanda, max(1,$k) );
        return self::schede_to_contesto( $schede );
    }
    
    $vq = $emb['vettore'];

    // Recupera tutti gli embeddings dal DB
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
        
        // Ordina per score decrescente
        usort( $punteggi, function($a,$b){ 
            return $b['score'] <=> $a['score']; 
        });
    }
    
    // ✅ NOVITÀ 1: THRESHOLD MINIMO 0.30
    // Filtra chunk con score troppo basso (non pertinenti)
    $threshold = 0.30;
    $punteggi_filtrati = array_filter($punteggi, function($p) use ($threshold) {
        return $p['score'] >= $threshold;
    });
    
    // Prendi top-K tra quelli filtrati
    $punteggi_filtrati = array_slice( $punteggi_filtrati, 0, max(1,$k) );
    
    // ✅ NOVITÀ 2: DEDUPLICA CHUNK IDENTICI
    $estratti = [];
    $seen_hashes = [];
    
    foreach( $punteggi_filtrati as $p ){
        $testo_pulito = Assistente_IA_Utilita::pulisci_testo($p['testo']);
        $hash = md5($testo_pulito);
        
        // Salta se già visto
        if ( isset($seen_hashes[$hash]) ) {
            continue;
        }
        $seen_hashes[$hash] = true;
        
        // ✅ NOVITÀ 3: AGGIUNGI HEADER CON FONTE
        $fonte_header = self::get_chunk_header($p['fonte'], $p['id_riferimento']);
        
        $chunk_formattato = $fonte_header . "\n" . 
                           Assistente_IA_Utilita::tronca($testo_pulito, 1200);
        
        $estratti[] = $chunk_formattato;
    }

    $best_score = isset($punteggi[0]['score']) ? (float)$punteggi[0]['score'] : 0.0;

    // ✅ NOVITÀ 4: FALLBACK SOLO SE NECESSARIO (score < 0.30)
    // Query corte NON forzano più il fallback se lo score è buono
    if ( empty($estratti) || $best_score < 0.30 ){
        $schede = self::fallback_keyword( $domanda, max(1,$k) );
        $estratti_fb = self::schede_to_array( $schede );
        
        // Deduplica anche qui
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

/**
 * ✅ NUOVA FUNZIONE: Genera header identificativo per il chunk
 * 
 * Input: fonte ('post' o 'prodotto'), id_riferimento (ID post/prodotto)
 * Output: "[Fonte: Titolo Post/Prodotto]"
 */
protected static function get_chunk_header( string $fonte, int $id_riferimento ): string {
    if ( $fonte === 'prodotto' && function_exists('wc_get_product') ) {
        $product = wc_get_product( $id_riferimento );
        if ( $product ) {
            $titolo = $product->get_name();
            return "[Fonte: Prodotto '{$titolo}']";
        }
    }
    
    // Post o Page
    $post = get_post( $id_riferimento );
    if ( $post ) {
        $titolo = get_the_title( $id_riferimento );
        $tipo = get_post_type( $id_riferimento );
        $tipo_label = $tipo === 'page' ? 'Pagina' : 'Articolo';
        return "[Fonte: {$tipo_label} '{$titolo}']";
    }
    
    return "[Fonte: Contenuto ID {$id_riferimento}]";
}

    /** 
     * Prepara un job di indicizzazione: 
     * - Post/Pagine SELEZIONATI dall'admin
     * - Prodotti WooCommerce (se presente) SEMPRE INCLUSI
     */
    public static function prepara_job_indicizzazione(): array {
        $voci = [];

        // POST E PAGINE: Solo quelli selezionati
        if ( class_exists('Assistente_IA_Content_Selector') ) {
            $selected_ids = Assistente_IA_Content_Selector::get_selected_content_ids();
        } else {
            // Fallback: se la classe non è disponibile, prendi tutti i post/page pubblicati
            $q = new WP_Query([
                'post_type' => ['post', 'page'],
                'post_status' => 'publish',
                'numberposts' => -1,
                'fields' => 'ids'
            ]);
            $selected_ids = $q->posts ?? [];
        }

        // Valida che gli ID siano effettivamente pubblicati
        foreach( $selected_ids as $pid ){
            $pid = (int)$pid;
            $status = get_post_status($pid);
            $type = get_post_type($pid);
            
            if ( $status === 'publish' && in_array($type, ['post', 'page'], true) ) {
                $voci[] = [ 'fonte' => 'post', 'id' => $pid ];
            }
        }

        // PRODOTTI WOOCOMMERCE: Sempre inclusi automaticamente
        if ( self::woo_attivo() ) {
            $qp = new WP_Query([
                'post_type' => ['product'],
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids'
            ]);
            foreach( ($qp->posts ?? []) as $pid ){
                $voci[] = [ 'fonte' => 'prodotto', 'id' => (int)$pid ];
            }
        }

        $job = [
            'stato' => 'in_attesa',
            'totale' => count($voci),
            'indice' => 0,
            'creati' => 0,
            'modello' => get_option('assia_modello_embedding','text-embedding-005'),
            'voci' => $voci,
            'avviato_il' => current_time('mysql'),
            'completato_il' => null,
            'errori' => [],
        ];
        set_transient('assia_job_embeddings', $job, HOUR_IN_SECONDS);
        return $job;
    }

    /** Esegue N voci per step (AJAX), salva embeddings e aggiorna progresso */
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

            $chunks = self::spezza_testo( $testo, 800 );
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

    /** Stato del job per eventuale polling */
    public static function stato_job(): array {
        $job = get_transient('assia_job_embeddings');
        if ( ! is_array($job) ) { return [ 'stato' => 'nessun_job' ]; }
        $tot = (int)$job['totale'];
        $job['percentuale'] = ($tot > 0) ? round(($job['indice'] / $tot) * 100) : 0;
        return $job;
    }

    /** Log conciso delle ultime rigenerazioni (max 10) */
    protected static function appendi_log_embeddings( array $voce ): void {
        $log = get_option('assia_log_embeddings', []);
        if ( ! is_array($log) ) $log = [];
        $log[] = $voce;
        if ( count($log) > 10 ) { $log = array_slice($log, -10); }
        update_option('assia_log_embeddings', $log);
    }

    /** 
     * Rigenerazione sincrona (legacy) 
     * Ora rispetta la selezione manuale dei contenuti
     */
    public static function rigenera_indice_post(): int {
        if ( 'si' !== get_option('assia_attiva_embeddings','si') ) return 0;

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
                ? Assistente_IA_RAG::costruisci_testo_prodotto( $pid )
                : Assistente_IA_RAG::testo_da_post( $pid );

            if ( ! $testo ) return;

            $chunks = Assistente_IA_RAG::spezza_testo( $testo, 800 );
            $indice = 0;
            foreach( $chunks as $ch ){
                $emb = Assistente_IA_Modello_Vertex::calcola_embedding( $ch, [
                    'id_chat' => null, 'hash_sessione' => null
                ] );
                if ( empty($emb['vettore']) ) { $errori[] = "Embedding vuoto per {$fonte} {$pid}"; continue; }
                Assistente_IA_RAG::salva_embedding( $fonte, $pid, $indice++, $ch, $emb['vettore'], $modello );
                $conteggio++;
            }
        };

        // Post/Pagine selezionati
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
                $processa('post', $pid); 
            }
        }

        // Prodotti WooCommerce (sempre inclusi)
        if ( self::woo_attivo() ) {
            $qp = new WP_Query([ 'post_type'=>['product'], 'post_status'=>'publish', 'posts_per_page'=>-1, 'fields'=>'ids' ]);
            foreach( ($qp->posts ?? []) as $pid ){ $processa('prodotto', (int)$pid); }
            wp_reset_postdata();
        }

        self::appendi_log_embeddings([
            'avviato_il' => $avvio,
            'completato_il' => current_time('mysql'),
            'modello' => $modello,
            'tot_voci' => count($selected_ids) + (self::woo_attivo() ? count($qp->posts ?? []) : 0),
            'chunks_creati' => $conteggio,
            'errori' => $errori,
        ]);
        return $conteggio;
    }

    /** ------ Fallback keyword su WP (titolo/contenuto) ------ */
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

    /** Converte array schede → stringa contesto (con separatore) */
    protected static function schede_to_contesto( array $schede ): string {
        if ( empty($schede) ) return '';
        $pulite = [];
        foreach($schede as $s){
            $pulite[] = Assistente_IA_Utilita::tronca( Assistente_IA_Utilita::pulisci_testo($s), 1200 );
        }
        return implode("\n---\n", $pulite);
    }
    
    protected static function schede_to_array( array $schede ): array {
        if ( empty($schede) ) return [];
        $pulite = [];
        foreach($schede as $s){
            $pulite[] = Assistente_IA_Utilita::tronca( Assistente_IA_Utilita::pulisci_testo($s), 1200 );
        }
        return $pulite;
    }

    /** 
     * ========================================
     * ESTRAZIONE TESTO DA POST/PAGINA
     * VERSIONE MIGLIORATA: Pulizia aggressiva
     * ======================================== 
     */
    protected static function testo_da_post( int $pid ): string {
        $titolo   = get_the_title( $pid );
        $estratto = get_the_excerpt( $pid );
        
        global $post;
        $post_originale = $post;
        $post = get_post( $pid );
        
        if ( ! $post ) {
            return '';
        }
        
        setup_postdata( $post );
        
        $contenuto_raw = $post->post_content;
        
        // FASE 1: Espandi shortcode e applica filtri content
        $contenuto_espanso = do_shortcode( $contenuto_raw );
        $renderizzato = apply_filters( 'the_content', $contenuto_espanso );
        
        // FASE 2: Pulizia AGGRESSIVA degli attributi shortcode residui
        $renderizzato = preg_replace('/\s+[a-zA-Z_\-]+\s*=\s*["\'][^"\']*["\']/i', '', $renderizzato);
        
        // Rimuovi pattern page builder comuni
        $builder_patterns = [
            '/\[et_pb_[^\]]*\]/i',
            '/\[\/et_pb_[^\]]*\]/i',
            '/data-[a-zA-Z\-]+=["\'"][^"\']*["\']/i',
            '/_builder_version=["\'][^"\']*["\']/i',
            '/custom_[a-zA-Z_]+=["\'][^"\']*["\']/i',
            '/global_colors_info=["\'][^"\']*["\']/i',
            '/\[vc_[^\]]*\]/i',
            '/\[\/vc_[^\]]*\]/i',
            '/\[elementor-[^\]]*\]/i',
        ];
        
        foreach( $builder_patterns as $pattern ){
            $renderizzato = preg_replace( $pattern, ' ', $renderizzato );
        }
        
        // FASE 3: Rimuovi shortcode residui
        $renderizzato = strip_shortcodes( $renderizzato );
        $renderizzato = preg_replace('/\[[^\]]*\]/i', ' ', $renderizzato);
        
        // FASE 4: Pulisci script, style, commenti
        $renderizzato = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $renderizzato);
        $renderizzato = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $renderizzato);
        $renderizzato = preg_replace('/<!--(.*)-->/Uis', '', $renderizzato);
        
        // FASE 5: Estrai solo testo
        $testo = wp_strip_all_tags( $renderizzato );
        
        // FASE 6: Pulizia finale spazi
        $testo = preg_replace('/\s+/', ' ', $testo);
        $testo = preg_replace('/[\x00-\x1F\x7F]/u', '', $testo);
        $testo = trim( $testo );
        
        // FASE 7: Validazione codice sospetto
        if ( preg_match('/[a-z_]+\s*=\s*["\']|width_|custom_|_builder_|global_/i', $testo) ) {
            $testo = preg_replace('/[a-z_]+\s*=\s*["\'][^"\']*["\']?/i', '', $testo);
            $testo = preg_replace('/\s+/', ' ', trim($testo));
        }
        
        wp_reset_postdata();
        $post = $post_originale;
        
        $estratto = wp_strip_all_tags( $estratto );
        $estratto = preg_replace('/\s+/', ' ', trim($estratto));
        
        $link = get_permalink( $pid );

        $blocchi = [];
        if ( $titolo ) $blocchi[] = "Titolo: " . $titolo;
        if ( $estratto ) $blocchi[] = "Estratto: " . $estratto;
        if ( $testo ) $blocchi[] = "Contenuto: " . $testo;
        if ( $link ) $blocchi[] = "Link: " . $link;

        $risultato = trim( implode("\n", $blocchi) );
        $risultato = apply_filters( 'assia_rag_testo_post', $risultato, $pid );
        
        return $risultato;
    }

    /** ------ Testo descrittivo del prodotto WooCommerce (con pulizia migliorata) ------ */
    protected static function costruisci_testo_prodotto( int $product_id ): string {
        if ( ! self::woo_attivo() ) return '';
        $p = wc_get_product( $product_id );
        if ( ! $p ) return '';

        global $post;
        $post_originale = $post;
        $post = get_post( $product_id );
        setup_postdata( $post );

        $nome = get_the_title( $product_id );
        
        // Short description con pulizia aggressiva
        $short_raw = $p->get_short_description();
        $short_rendered = do_shortcode( $short_raw );
        $short_rendered = apply_filters( 'the_content', $short_rendered );
        $short_rendered = preg_replace('/\s+[a-zA-Z_\-]+\s*=\s*["\'][^"\']*["\']/i', '', $short_rendered);
        $short_rendered = strip_shortcodes( $short_rendered );
        $short_rendered = preg_replace('/\[[^\]]*\]/i', ' ', $short_rendered);
        $short = wp_strip_all_tags( $short_rendered );
        $short = preg_replace('/\s+/', ' ', trim($short));
        
        // Description con pulizia aggressiva
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

    /** WooCommerce presente? */
    protected static function woo_attivo(): bool {
        return class_exists('WooCommerce') || function_exists('wc_get_product');
    }

    /** Spezza testo in chunk "quasi parola" da N caratteri */
    protected static function spezza_testo( string $t, int $n ): array {
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

    /** Insert helper */
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

    /** Similarità coseno tra due vettori */
    protected static function coseno( array $a, array $b ): float {
        $dot=0; $na=0; $nb=0; $len = min( count($a), count($b) );
        for($i=0;$i<$len;$i++){ $dot += $a[$i]*$b[$i]; $na += $a[$i]*$a[$i]; $nb += $b[$i]*$b[$i]; }
        $den = (sqrt($na)*sqrt($nb));
        return $den>0 ? ($dot/$den) : 0.0;
    }
}
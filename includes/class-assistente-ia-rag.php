<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * RAG (embeddings) + diagnostica + job di rigenerazione a step con storico.
 * Esteso: contenuto renderizzato (builder/shortcode) + fallback keyword su DB + WooCommerce.
 */
class Assistente_IA_RAG {

    /** Recupera estratti pertinenti dal DB con similarità coseno (Top-K) + fallback keyword */
    public static function recupera_estratti_rag( string $domanda ): string {
        if ( 'si' !== get_option('assia_attiva_embeddings','si') ) {
            // Embeddings OFF → fallback diretto
            $schede = self::fallback_keyword( $domanda, 3 );
            return self::schede_to_contesto( $schede );
        }

        $k     = (int) get_option('assia_embeddings_top_k', 3);
        $sigla = get_option('assia_modello_embedding','text-embedding-005');

        // 1) Embedding della domanda (log su DB; chat/hash sconosciuti qui)
        $emb = Assistente_IA_Modello_Vertex::calcola_embedding(
            Assistente_IA_Utilita::pulisci_testo($domanda),
            [ 'id_chat' => null, 'hash_sessione' => null ]
        );
        if ( empty($emb['vettore']) ) {
            // Se embedding fallisce → fallback keyword
            $schede = self::fallback_keyword( $domanda, 3 );
            return self::schede_to_contesto( $schede );
        }
        $vq = $emb['vettore'];

        // 2) Similarità sui chunks indicizzati
        global $wpdb; $pref = $wpdb->prefix;
        $righe = $wpdb->get_results( $wpdb->prepare(
            "SELECT testo_chunk, embedding FROM {$pref}assistente_ia_embeddings WHERE modello=%s",
            $sigla
        ), ARRAY_A );

        $punteggi = [];
        if ( ! empty($righe) ){
            foreach( $righe as $idx => $r ){
                $vec = json_decode( $r['embedding'], true );
                if ( ! is_array($vec) ) continue;
                $score = self::coseno($vq, $vec);
                $punteggi[] = [ 'i'=>$idx, 'score'=>$score, 'testo'=>$r['testo_chunk'] ];
            }
            usort( $punteggi, function($a,$b){ return $b['score'] <=> $a['score']; });
            $punteggi = array_slice( $punteggi, 0, max(1,$k) );
        }

        $estratti = [];
        foreach( $punteggi as $p ){
            $estratti[] = Assistente_IA_Utilita::tronca( Assistente_IA_Utilita::pulisci_testo($p['testo']), 1200 );
        }

        // 3) Fallback keyword se:
        //    - non abbiamo trovato nulla con embeddings, OPPURE
        //    - la domanda è molto corta (<= 3 parole) OPPURE
        //    - lo score migliore è basso (< 0.20)
        $parole = preg_split('/\s+/', trim($domanda));
        $short  = ( is_array($parole) && count($parole) <= 3 );
        $best   = isset($punteggi[0]['score']) ? (float)$punteggi[0]['score'] : 0.0;

        if ( empty($estratti) || $short || $best < 0.20 ){
            $schede = self::fallback_keyword( $domanda, max(1,$k) );
            // concateniamo i risultati del fallback agli estratti (senza duplicare)
            $estratti_fb = self::schede_to_array( $schede );
            $estratti    = array_values( array_filter( array_unique( array_merge($estratti_fb, $estratti) ) ) );
        }

        return implode("\n---\n", $estratti);
    }

    /** Prepara un job di indicizzazione: post/pagine + (se presente) prodotti WooCommerce */
    public static function prepara_job_indicizzazione(): array {
        // Post/Pagine
        $q = new WP_Query([
            'post_type'=>['post','page'],
            'post_status'=>'publish',
            'posts_per_page'=>-1,
            'fields'=>'ids'
        ]);
        $voci = [];
        foreach( ($q->posts ?? []) as $pid ){
            $voci[] = [ 'fonte'=>'post', 'id'=>(int)$pid ];
        }

        // Prodotti WooCommerce (se attivo)
        if ( self::woo_attivo() ) {
            $qp = new WP_Query([
                'post_type'=>['product'],
                'post_status'=>'publish',
                'posts_per_page'=>-1,
                'fields'=>'ids'
            ]);
            foreach( ($qp->posts ?? []) as $pid ){
                $voci[] = [ 'fonte'=>'prodotto', 'id'=>(int)$pid ];
            }
        }

        $job = [
            'stato' => 'in_attesa',
            'totale' => count($voci),
            'indice' => 0,
            'creati' => 0,
            'modello' => get_option('assia_modello_embedding','text-embedding-005'),
            'voci' => $voci, // ← ogni voce ha fonte+id
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
            if ( ! is_array($voce) ) break;

            $fonte = $voce['fonte'] ?? 'post';
            $pid   = (int)($voce['id'] ?? 0);
            if ( $pid <= 0 ) continue;

            // Cancella vecchi embeddings della voce
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$pref}assistente_ia_embeddings WHERE fonte=%s AND id_riferimento=%d AND modello=%s",
                $fonte, $pid, $modello
            ) );

            // Testo sorgente
            $testo = ( $fonte === 'prodotto' )
                ? self::costruisci_testo_prodotto( $pid )
                : self::testo_da_post( $pid );

            if ( ! $testo ) { continue; }

            $chunks = self::spezza_testo( $testo, 800 );
            $indice_chunk = 0;
            foreach( $chunks as $ch ){
                // Embedding loggato su DB (indicizzazione offline)
                $emb = Assistente_IA_Modello_Vertex::calcola_embedding( $ch, [
                    'id_chat' => null,
                    'hash_sessione' => null
                ] );
                if ( empty($emb['vettore']) ) { $job['errori'][] = "Embedding vuoto per {$fonte} {$pid}"; continue; }
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

    /** Rigenerazione sincrona (legacy) – ora anche prodotti e contenuto renderizzato */
    public static function rigenera_indice_post(): int {
        if ( 'si' !== get_option('assia_attiva_embeddings','si') ) return 0;

        $conteggio = 0;
        $modello   = get_option('assia_modello_embedding','text-embedding-005');
        $avvio     = current_time('mysql');
        $errori    = [];
        global $wpdb; $pref = $wpdb->prefix;

        $processa = function(string $fonte, int $pid) use (&$conteggio,&$errori,$modello,$wpdb,$pref){
            if ( $pid <= 0 ) return;

            // Cancella vecchi embeddings
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

        // Post/Pagine
        $q = new WP_Query([ 'post_type'=>['post','page'], 'post_status'=>'publish', 'posts_per_page'=>-1, 'fields'=>'ids' ]);
        foreach( ($q->posts ?? []) as $pid ){ $processa('post', (int)$pid); }
        wp_reset_postdata();

        // Prodotti WooCommerce
        if ( self::woo_attivo() ) {
            $qp = new WP_Query([ 'post_type'=>['product'], 'post_status'=>'publish', 'posts_per_page'=>-1, 'fields'=>'ids' ]);
            foreach( ($qp->posts ?? []) as $pid ){ $processa('prodotto', (int)$pid); }
            wp_reset_postdata();
        }

        self::appendi_log_embeddings([
            'avviato_il' => $avvio,
            'completato_il' => current_time('mysql'),
            'modello' => $modello,
            'tot_voci' => (int)(count($q->posts ?? []) + (self::woo_attivo() ? count($qp->posts ?? []) : 0)),
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

        // Ricerco su post/page/product pubblicati
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

    /** ------ Estrazione testo da POST/PAGINA (renderizzato) ------ */
    protected static function testo_da_post( int $pid ): string {
        $titolo   = get_the_title( $pid );
        $estratto = get_the_excerpt( $pid );
        $contenuto_raw = get_post_field( 'post_content', $pid );
        // Renderizza (espande shortcode, blocchi, builder)
        $renderizzato = apply_filters( 'the_content', $contenuto_raw );
        $testo = wp_strip_all_tags( $renderizzato );
        $estratto = wp_strip_all_tags( $estratto );
        $link  = get_permalink( $pid );

        $blocchi = [];
        if($titolo)   $blocchi[] = "Titolo: ".$titolo;
        if($estratto) $blocchi[] = "Estratto: ".$estratto;
        if($testo)    $blocchi[] = "Contenuto: ".$testo;
        if($link)     $blocchi[] = "Link: ".$link;

        return trim( implode("\n", $blocchi) );
    }

    /** ------ Testo descrittivo del prodotto WooCommerce ------ */
    protected static function costruisci_testo_prodotto( int $product_id ): string {
        if ( ! self::woo_attivo() ) return '';
        $p = wc_get_product( $product_id );
        if ( ! $p ) return '';

        $nome   = get_the_title( $product_id );
        $short  = wp_strip_all_tags( $p->get_short_description() );
        $desc   = wp_strip_all_tags( $p->get_description() );
        $prezzo = method_exists($p,'get_price') ? $p->get_price() : '';
        $valuta = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '€';
        $stock  = method_exists($p,'is_in_stock') ? ($p->is_in_stock() ? 'in stock' : 'non disponibile') : '';
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

        return trim( implode("\n", array_filter($righe)) );
    }

    /** WooCommerce presente? */
    protected static function woo_attivo(): bool {
        return class_exists('WooCommerce') || function_exists('wc_get_product');
    }

    /** Spezza testo in chunk “quasi parola” da N caratteri */
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

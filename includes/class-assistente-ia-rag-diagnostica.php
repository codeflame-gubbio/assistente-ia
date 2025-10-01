<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Strumento diagnostico per testare qualità del RAG
 * Permette di vedere ESATTAMENTE cosa recupera il sistema
 */
class Assistente_IA_RAG_Diagnostica {

    public static function init(){
        add_action('admin_menu', [ __CLASS__, 'aggiungi_menu' ], 101);
        add_action('admin_post_assia_test_rag', [ __CLASS__, 'esegui_test' ]);
        add_action('admin_init', [ __CLASS__, 'register_assets' ]);
    }
    
    public static function register_assets(){
        // Nessun asset JS necessario per ora
    }

    public static function aggiungi_menu(){
        add_submenu_page(
            'assia',
            'Test Qualità RAG',
            'Test RAG',
            'manage_options',
            'assia-test-rag',
            [ __CLASS__, 'render_pagina' ]
        );
    }

    public static function render_pagina(){
        if ( ! current_user_can('manage_options') ) {
            wp_die('Permessi insufficienti');
        }

        // Mostra risultati test se presenti
        $risultati = get_transient('assia_test_rag_risultati');

        ?>
        <style>
            .assia-test-wrap { max-width: 1200px; }
            .assia-test-section { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin: 20px 0; }
            .assia-test-title { margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #111827; }
            .assia-test-input { width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; }
            .assia-test-btn { background: #2563eb; color: #fff; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; }
            .assia-test-btn:hover { background: #1d4ed8; }
            .assia-chunk-card { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin: 12px 0; }
            .assia-chunk-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
            .assia-chunk-score { background: #10b981; color: #fff; padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 600; }
            .assia-chunk-score.low { background: #ef4444; }
            .assia-chunk-score.medium { background: #f59e0b; }
            .assia-chunk-meta { font-size: 12px; color: #6b7280; margin-bottom: 8px; }
            .assia-chunk-text { line-height: 1.6; color: #374151; }
            .assia-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin: 20px 0; }
            .assia-stat-card { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 16px; }
            .assia-stat-value { font-size: 32px; font-weight: 700; color: #1e40af; }
            .assia-stat-label { font-size: 14px; color: #6b7280; margin-top: 4px; }
            .assia-warning { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 4px; }
            .assia-info { background: #eff6ff; border-left: 4px solid #3b82f6; padding: 12px 16px; border-radius: 4px; }
        </style>

        <div class="wrap assia-test-wrap">
            <h1>🧪 Test Qualità RAG</h1>

            <div class="assia-info">
                <strong>ℹ️ Come funziona questo strumento:</strong><br>
                Inserisci una domanda esattamente come la farebbe un utente. Il sistema mostrerà:
                <ul style="margin: 8px 0; padding-left: 20px;">
                    <li><strong>Chunk recuperati</strong> - cosa trova il RAG per quella domanda</li>
                    <li><strong>Score di similarità</strong> - quanto sono rilevanti (0-1, dove 1 = identico)</li>
                    <li><strong>Metadata</strong> - da quale post/pagina viene ogni chunk</li>
                    <li><strong>Statistiche</strong> - qualità complessiva del recupero</li>
                </ul>
            </div>

            <!-- Form di test -->
            <div class="assia-test-section">
                <h2 class="assia-test-title">🔍 Testa una query</h2>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <?php wp_nonce_field('assia_test_rag', 'assia_test_nonce'); ?>
                    <input type="hidden" name="action" value="assia_test_rag">
                    
                    <label for="test_query" style="display: block; margin-bottom: 8px; font-weight: 600;">
                        Domanda di test:
                    </label>
                    <input 
                        type="text" 
                        name="query" 
                        id="test_query" 
                        class="assia-test-input" 
                        placeholder="Es: Quali sono i vostri orari di apertura?"
                        required
                    >
                    
                    <div style="margin-top: 16px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 600;">
                            Top-K (numero di chunk da recuperare):
                        </label>
                        <select name="top_k" style="padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                            <option value="3">3 (default)</option>
                            <option value="5">5</option>
                            <option value="7">7</option>
                            <option value="10">10</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="assia-test-btn" style="margin-top: 16px;">
                        🚀 Esegui Test
                    </button>
                </form>
            </div>

            <?php if ( $risultati ): ?>
                <?php self::render_risultati( $risultati ); ?>
                <?php delete_transient('assia_test_rag_risultati'); ?>
            <?php endif; ?>

            <!-- Statistiche Database -->
            <?php self::render_statistiche_db(); ?>
        </div>
        <?php
    }

    public static function esegui_test(){
        if ( ! current_user_can('manage_options') ) {
            wp_die('Permessi insufficienti');
        }

        check_admin_referer('assia_test_rag', 'assia_test_nonce');

        $query = isset($_POST['query']) ? sanitize_text_field($_POST['query']) : '';
        $top_k = isset($_POST['top_k']) ? max(1, intval($_POST['top_k'])) : 3;

        if ( empty($query) ) {
            wp_redirect( admin_url('admin.php?page=assia-test-rag') );
            exit;
        }

        // Esegui il test con diagnostica completa
        $risultati = self::test_rag_con_diagnostica( $query, $top_k );

        set_transient('assia_test_rag_risultati', $risultati, 300);

        wp_redirect( admin_url('admin.php?page=assia-test-rag') );
        exit;
    }

    protected static function test_rag_con_diagnostica( string $query, int $top_k ): array {
        $start_time = microtime(true);
        
        $modello = get_option('assia_modello_embedding', 'text-embedding-005');
        
        // 1. Calcola embedding della query
        $emb_start = microtime(true);
        $emb = Assistente_IA_Modello_Vertex::calcola_embedding(
            Assistente_IA_Utilita::pulisci_testo($query)
        );
        $emb_time = microtime(true) - $emb_start;

        if ( empty($emb['vettore']) ) {
            return [
                'success' => false,
                'error' => 'Impossibile calcolare embedding della query',
                'query' => $query
            ];
        }

        $vq = $emb['vettore'];

        // 2. Recupera tutti gli embeddings dal DB
        global $wpdb;
        $pref = $wpdb->prefix;
        
        $db_start = microtime(true);
        $righe = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, fonte, id_riferimento, indice_chunk, testo_chunk, embedding 
             FROM {$pref}assistente_ia_embeddings 
             WHERE modello=%s",
            $modello
        ), ARRAY_A );
        $db_time = microtime(true) - $db_start;

        // 3. Calcola similarità per ogni chunk
        $calc_start = microtime(true);
        $punteggi = [];
        
        if ( ! empty($righe) ) {
            foreach( $righe as $r ) {
                $vec = json_decode( $r['embedding'], true );
                if ( ! is_array($vec) ) continue;

                $score = self::calcola_coseno($vq, $vec);
                
                // Recupera metadata del post/prodotto
                $metadata = self::get_chunk_metadata( $r['fonte'], $r['id_riferimento'] );
                
                $punteggi[] = [
                    'id' => $r['id'],
                    'score' => $score,
                    'testo' => $r['testo_chunk'],
                    'fonte' => $r['fonte'],
                    'id_riferimento' => $r['id_riferimento'],
                    'indice_chunk' => $r['indice_chunk'],
                    'metadata' => $metadata
                ];
            }
            
            // Ordina per score decrescente
            usort( $punteggi, function($a, $b) {
                return $b['score'] <=> $a['score'];
            });
        }
        $calc_time = microtime(true) - $calc_start;

        // 4. Prendi top-K
        $top_chunks = array_slice( $punteggi, 0, $top_k );

        // 5. Analisi qualità
        $stats = self::analizza_qualita( $punteggi, $top_chunks, $top_k );

        $total_time = microtime(true) - $start_time;

        return [
            'success' => true,
            'query' => $query,
            'top_k' => $top_k,
            'chunks' => $top_chunks,
            'stats' => $stats,
            'performance' => [
                'embedding_time' => round($emb_time * 1000, 2),
                'db_time' => round($db_time * 1000, 2),
                'calc_time' => round($calc_time * 1000, 2),
                'total_time' => round($total_time * 1000, 2),
                'total_chunks' => count($righe)
            ]
        ];
    }

    protected static function calcola_coseno( array $a, array $b ): float {
        $dot = 0;
        $na = 0;
        $nb = 0;
        $len = min( count($a), count($b) );
        
        for($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        
        $den = (sqrt($na) * sqrt($nb));
        return $den > 0 ? ($dot / $den) : 0.0;
    }

    protected static function get_chunk_metadata( string $fonte, int $id_rif ): array {
        $metadata = [
            'title' => '',
            'url' => '',
            'type' => $fonte
        ];

        if ( $fonte === 'prodotto' && function_exists('wc_get_product') ) {
            $product = wc_get_product( $id_rif );
            if ( $product ) {
                $metadata['title'] = $product->get_name();
                $metadata['url'] = get_permalink( $id_rif );
            }
        } else {
            $post = get_post( $id_rif );
            if ( $post ) {
                $metadata['title'] = get_the_title( $id_rif );
                $metadata['url'] = get_permalink( $id_rif );
                $metadata['type'] = get_post_type( $id_rif );
            }
        }

        return $metadata;
    }

    protected static function analizza_qualita( array $tutti_punteggi, array $top_chunks, int $top_k ): array {
        $stats = [
            'total_chunks_db' => count($tutti_punteggi),
            'top_k' => $top_k,
            'best_score' => 0,
            'worst_score' => 0,
            'avg_score' => 0,
            'score_distribution' => [
                'excellent' => 0,  // > 0.7
                'good' => 0,       // 0.5-0.7
                'fair' => 0,       // 0.3-0.5
                'poor' => 0        // < 0.3
            ],
            'qualita' => 'N/A',
            'raccomandazioni' => []
        ];

        if ( empty($top_chunks) ) {
            $stats['qualita'] = 'Critica';
            $stats['raccomandazioni'][] = 'Nessun chunk recuperato! Controlla che ci siano embeddings nel database.';
            return $stats;
        }

        // Calcola statistiche
        $scores = array_column($top_chunks, 'score');
        $stats['best_score'] = round(max($scores), 3);
        $stats['worst_score'] = round(min($scores), 3);
        $stats['avg_score'] = round(array_sum($scores) / count($scores), 3);

        // Distribuzione score
        foreach( $top_chunks as $chunk ) {
            $score = $chunk['score'];
            if ( $score > 0.7 ) $stats['score_distribution']['excellent']++;
            elseif ( $score > 0.5 ) $stats['score_distribution']['good']++;
            elseif ( $score > 0.3 ) $stats['score_distribution']['fair']++;
            else $stats['score_distribution']['poor']++;
        }

        // Valutazione qualità complessiva
        if ( $stats['best_score'] > 0.7 ) {
            $stats['qualita'] = 'Eccellente';
        } elseif ( $stats['best_score'] > 0.5 ) {
            $stats['qualita'] = 'Buona';
        } elseif ( $stats['best_score'] > 0.3 ) {
            $stats['qualita'] = 'Discreta';
            $stats['raccomandazioni'][] = 'Score massimo basso (< 0.5). Considera di migliorare la qualità dei contenuti indicizzati.';
        } else {
            $stats['qualita'] = 'Scarsa';
            $stats['raccomandazioni'][] = 'Score massimo molto basso (< 0.3). Il RAG non trova contenuti pertinenti.';
            $stats['raccomandazioni'][] = 'Verifica che i contenuti nel database siano correlati alla query.';
        }

        // Raccomandazioni addizionali
        if ( $stats['score_distribution']['poor'] > $top_k / 2 ) {
            $stats['raccomandazioni'][] = 'Più della metà dei chunk recuperati ha score < 0.3. Aumenta Top-K o migliora il contenuto.';
        }

        if ( $stats['total_chunks_db'] < 50 ) {
            $stats['raccomandazioni'][] = 'Database con pochi chunk (' . $stats['total_chunks_db'] . '). Indicizza più contenuti per migliorare il RAG.';
        }

        return $stats;
    }

    protected static function render_risultati( array $risultati ) {
        if ( ! $risultati['success'] ) {
            echo '<div class="assia-warning">';
            echo '<strong>⚠️ Errore:</strong> ' . esc_html($risultati['error']);
            echo '</div>';
            return;
        }

        $query = $risultati['query'];
        $chunks = $risultati['chunks'];
        $stats = $risultati['stats'];
        $perf = $risultati['performance'];

        ?>
        <!-- Statistiche generali -->
        <div class="assia-test-section">
            <h2 class="assia-test-title">📊 Risultati per: <em><?php echo esc_html($query); ?></em></h2>
            
            <div class="assia-stats">
                <div class="assia-stat-card">
                    <div class="assia-stat-value"><?php echo $stats['best_score']; ?></div>
                    <div class="assia-stat-label">Score Massimo</div>
                </div>
                <div class="assia-stat-card">
                    <div class="assia-stat-value"><?php echo $stats['avg_score']; ?></div>
                    <div class="assia-stat-label">Score Medio</div>
                </div>
                <div class="assia-stat-card">
                    <div class="assia-stat-value"><?php echo $stats['qualita']; ?></div>
                    <div class="assia-stat-label">Qualità Complessiva</div>
                </div>
                <div class="assia-stat-card">
                    <div class="assia-stat-value"><?php echo $perf['total_time']; ?>ms</div>
                    <div class="assia-stat-label">Tempo Totale</div>
                </div>
            </div>

            <!-- Raccomandazioni -->
            <?php if ( ! empty($stats['raccomandazioni']) ): ?>
                <div class="assia-warning" style="margin-top: 16px;">
                    <strong>💡 Raccomandazioni:</strong>
                    <ul style="margin: 8px 0; padding-left: 20px;">
                        <?php foreach($stats['raccomandazioni'] as $racc): ?>
                            <li><?php echo esc_html($racc); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>

        <!-- Chunk recuperati -->
        <div class="assia-test-section">
            <h2 class="assia-test-title">📄 Chunk Recuperati (Top-<?php echo $risultati['top_k']; ?>)</h2>
            
            <?php if ( empty($chunks) ): ?>
                <div class="assia-warning">
                    <strong>⚠️ Nessun chunk trovato!</strong><br>
                    Il sistema non ha trovato contenuti rilevanti per questa query.
                </div>
            <?php else: ?>
                <?php foreach( $chunks as $idx => $chunk ): 
                    $score = $chunk['score'];
                    $score_class = $score > 0.7 ? '' : ($score > 0.5 ? 'medium' : 'low');
                    $metadata = $chunk['metadata'];
                ?>
                    <div class="assia-chunk-card">
                        <div class="assia-chunk-header">
                            <strong>#<?php echo $idx + 1; ?> - <?php echo esc_html($metadata['title'] ?: 'Senza titolo'); ?></strong>
                            <span class="assia-chunk-score <?php echo $score_class; ?>">
                                Score: <?php echo round($score, 3); ?>
                            </span>
                        </div>
                        
                        <div class="assia-chunk-meta">
                            <strong>Tipo:</strong> <?php echo esc_html(ucfirst($chunk['fonte'])); ?> •
                            <strong>ID:</strong> <?php echo $chunk['id_riferimento']; ?> •
                            <strong>Chunk:</strong> <?php echo $chunk['indice_chunk']; ?> •
                            <strong>URL:</strong> <a href="<?php echo esc_url($metadata['url']); ?>" target="_blank">Visualizza</a>
                        </div>
                        
                        <div class="assia-chunk-text">
                            <?php echo esc_html( wp_trim_words($chunk['testo'], 100) ); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Performance dettagliata -->
        <div class="assia-test-section">
            <h2 class="assia-test-title">⚡ Performance</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Operazione</th>
                        <th>Tempo</th>
                        <th>% del totale</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Calcolo embedding query</td>
                        <td><?php echo $perf['embedding_time']; ?>ms</td>
                        <td><?php echo round(($perf['embedding_time'] / $perf['total_time']) * 100, 1); ?>%</td>
                    </tr>
                    <tr>
                        <td>Recupero embeddings da DB</td>
                        <td><?php echo $perf['db_time']; ?>ms</td>
                        <td><?php echo round(($perf['db_time'] / $perf['total_time']) * 100, 1); ?>%</td>
                    </tr>
                    <tr>
                        <td>Calcolo similarità (<?php echo $perf['total_chunks']; ?> chunk)</td>
                        <td><?php echo $perf['calc_time']; ?>ms</td>
                        <td><?php echo round(($perf['calc_time'] / $perf['total_time']) * 100, 1); ?>%</td>
                    </tr>
                    <tr>
                        <td><strong>TOTALE</strong></td>
                        <td><strong><?php echo $perf['total_time']; ?>ms</strong></td>
                        <td><strong>100%</strong></td>
                    </tr>
                </tbody>
            </table>
            
            <?php if ( $perf['calc_time'] > 500 ): ?>
                <div class="assia-warning" style="margin-top: 12px;">
                    <strong>⚠️ Performance scarsa:</strong> Il calcolo della similarità impiega troppo tempo (<?php echo $perf['calc_time']; ?>ms). 
                    Con <?php echo $perf['total_chunks']; ?> chunk nel database, ogni query calcola la similarità in PHP per tutti i chunk. 
                    Considera di implementare una ricerca vettoriale più efficiente.
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    protected static function render_statistiche_db() {
        global $wpdb;
        $pref = $wpdb->prefix;
        $modello = get_option('assia_modello_embedding', 'text-embedding-005');

        $total_chunks = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$pref}assistente_ia_embeddings WHERE modello=%s",
            $modello
        ));

        $total_posts = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT id_riferimento) FROM {$pref}assistente_ia_embeddings WHERE modello=%s AND fonte='post'",
            $modello
        ));

        $total_products = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT id_riferimento) FROM {$pref}assistente_ia_embeddings WHERE modello=%s AND fonte='prodotto'",
            $modello
        ));

        $avg_chunks_per_post = $total_posts > 0 ? round($total_chunks / ($total_posts + $total_products), 1) : 0;

        ?>
        <div class="assia-test-section">
            <h2 class="assia-test-title">💾 Statistiche Database</h2>
            <table class="widefat striped">
                <tbody>
                    <tr>
                        <td><strong>Chunk totali</strong></td>
                        <td><?php echo number_format($total_chunks, 0, ',', '.'); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Post/Pagine indicizzati</strong></td>
                        <td><?php echo $total_posts; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Prodotti indicizzati</strong></td>
                        <td><?php echo $total_products; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Media chunk per contenuto</strong></td>
                        <td><?php echo $avg_chunks_per_post; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Modello embedding</strong></td>
                        <td><?php echo esc_html($modello); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}
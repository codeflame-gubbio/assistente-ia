<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * ✅ FIX v5.4.0: Rimosso wp_localize_script duplicato dal metodo render()
 */
class Assistente_IA_Admin_RAG {

    public static function init(){
        add_action('admin_menu', [ __CLASS__, 'aggiungi_sottomenu_rag' ], 99);
        add_action('admin_init', [ __CLASS__, 'register_assets' ]);
        add_action('admin_enqueue_scripts', [ __CLASS__, 'maybe_enqueue_assets' ]);
    }

    public static function aggiungi_sottomenu_rag(){
        $parent = 'assia';
        $slug   = 'assistente-ia-rag';
        if ( strpos($slug, '/') !== false || substr($slug, -4) === '.php' ) return;

        add_submenu_page($parent, 'RAG (Embeddings)', 'RAG (Embeddings)', 'manage_options', $slug, [ __CLASS__, 'render' ]);

        add_action('admin_head', function() use ($parent, $slug){
            global $submenu;
            if ( isset($submenu[$parent]) && is_array($submenu[$parent]) ) {
                foreach ($submenu[$parent] as &$item) {
                    if ( isset($item[2]) && ( $item[2] === $slug || strpos($item[2], $slug) !== false ) ) {
                        $item[2] = $slug;
                    }
                }
            }
        });
    }

    public static function register_assets(){
        wp_register_script(
            'assia-rag-admin',
            ASSIA_URL . 'admin/js/assia-rag-admin.js',
            [ 'jquery' ],
            defined('ASSIA_VERSIONE') ? ASSIA_VERSIONE : '1.0.0',
            true
        );
    }

    public static function maybe_enqueue_assets($hook){
        $is_rag = ( isset($_GET['page']) && $_GET['page'] === 'assistente-ia-rag' );
        if ( ! $is_rag ) return;
        
        $nonce = wp_create_nonce('assia_rag_nonce');
        wp_enqueue_script('assia-rag-admin');
        
        // ✅ FIX: Unico localize_script qui (non duplicato in render())
        wp_localize_script('assia-rag-admin', 'AssiaRag', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => $nonce,
        ]);
    }

    public static function render(){
        if ( ! current_user_can('manage_options') ) {
            wp_die( __('Non hai i permessi per accedere a questa pagina.', 'assistente-ia') );
        }

        // Gestione svuotamento embeddings
        if ( isset($_POST['assia_svuota_embeddings']) && current_user_can('manage_options') ) {
            check_admin_referer('assia_svuota_embeddings');
            global $wpdb; $pref = $wpdb->prefix;
            $wpdb->query("TRUNCATE TABLE {$pref}assistente_ia_embeddings");
            echo '<div class="updated"><p><strong>✓ Tutti gli embeddings sono stati cancellati.</strong> Puoi rigenerarli cliccando sul bottone qui sotto.</p></div>';
        }

        // ✅ FIX: Rimosso wp_localize_script duplicato (già fatto in maybe_enqueue_assets)
        
        // Recupera statistiche embeddings
        $stats = self::get_embeddings_stats();

        echo '<style>
            .assia-rag-wrap{max-width:900px}
            .assia-stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin:20px 0}
            .assia-stat-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.05)}
            .assia-stat-card h3{margin:0 0 8px 0;font-size:13px;color:#6b7280;text-transform:uppercase;font-weight:600}
            .assia-stat-card .assia-stat-value{font-size:32px;font-weight:700;color:#111827;margin:8px 0}
            .assia-stat-card .assia-stat-label{font-size:14px;color:#6b7280}
            .assia-bar{height:16px;background:#e5e7eb;border-radius:8px;overflow:hidden;margin:6px 0 12px}
            .assia-bar>span{display:block;height:100%;width:0%;background:#10b981;transition:width .3s}
            .assia-log{background:#0b1020;color:#d1e7ff;padding:10px;border-radius:6px;height:220px;overflow:auto;font:12px/1.4 monospace}
            .assia-meta{color:#374151;margin-bottom:6px}
            .assia-btn{background:#111827;color:#fff;border:0;border-radius:6px;padding:8px 14px;cursor:pointer;font-size:14px}
            .assia-btn[disabled]{opacity:.5;cursor:not-allowed}
            .assia-btn-danger{background:#dc2626;margin-left:8px}
            .assia-hint{font-size:12px;color:#6b7280;margin-top:8px}
            .assia-section{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin:20px 0}
            .assia-section-title{margin:0 0 16px 0;font-size:16px;font-weight:600;color:#111827}
            .assia-info-box{background:#eff6ff;border-left:4px solid #3b82f6;padding:12px 16px;border-radius:4px;margin:16px 0}
            .assia-warning-box{background:#fef3c7;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:4px;margin:16px 0}
        </style>';

        echo '<div class="wrap assia-rag-wrap">';
        echo '<h1>RAG (Embeddings)</h1>';

        // Sezione statistiche
        echo '<div class="assia-section">';
        echo '<h2 class="assia-section-title">📊 Riepilogo Embeddings</h2>';
        
        if ( $stats['totale'] > 0 ) {
            echo '<div class="assia-stats-grid">';
            
            // Totale chunks
            echo '<div class="assia-stat-card">';
            echo '<h3>Chunks Totali</h3>';
            echo '<div class="assia-stat-value">' . number_format($stats['totale'], 0, ',', '.') . '</div>';
            echo '<div class="assia-stat-label">Vettori indicizzati</div>';
            echo '</div>';
            
            // Post/Pagine
            echo '<div class="assia-stat-card">';
            echo '<h3>Post & Pagine</h3>';
            echo '<div class="assia-stat-value">' . $stats['post_count'] . '</div>';
            echo '<div class="assia-stat-label">Contenuti indicizzati</div>';
            echo '</div>';
            
            // Prodotti (se WooCommerce)
            if ( $stats['prodotti_count'] > 0 ) {
                echo '<div class="assia-stat-card">';
                echo '<h3>Prodotti</h3>';
                echo '<div class="assia-stat-value">' . $stats['prodotti_count'] . '</div>';
                echo '<div class="assia-stat-label">WooCommerce</div>';
                echo '</div>';
            }
            
            // Dimensione media chunk
            echo '<div class="assia-stat-card">';
            echo '<h3>Dimensione Media</h3>';
            echo '<div class="assia-stat-value">' . $stats['avg_chars'] . '</div>';
            echo '<div class="assia-stat-label">caratteri/chunk</div>';
            echo '</div>';
            
            echo '</div>';
            
            echo '<div class="assia-info-box">';
            echo '<strong>Modello:</strong> ' . esc_html($stats['modello']) . ' &nbsp;|&nbsp; ';
            echo '<strong>Ultimo aggiornamento:</strong> ' . esc_html($stats['ultimo_aggiornamento']);
            echo '</div>';
            
            // Bottone per svuotare
            echo '<form method="post" style="margin-top:16px" onsubmit="return confirm(\'⚠️ ATTENZIONE: Questa azione cancellerà TUTTI gli embeddings dal database.\\n\\nDovrai rigenerarli nuovamente per utilizzare il RAG.\\n\\nSei sicuro di voler continuare?\');">';
            wp_nonce_field('assia_svuota_embeddings');
            echo '<button type="submit" name="assia_svuota_embeddings" class="assia-btn assia-btn-danger">🗑️ Svuota tutti gli embeddings</button>';
            echo '<span style="margin-left:12px;color:#6b7280;font-size:13px">Questa azione non può essere annullata</span>';
            echo '</form>';
            
        } else {
            echo '<div class="assia-warning-box">';
            echo '<strong>⚠️ Nessun embedding presente nel database.</strong><br>';
            echo 'Avvia la rigenerazione qui sotto per indicizzare i contenuti del sito e attivare il sistema RAG.';
            echo '</div>';
        }
        
        echo '</div>';

        // Sezione rigenerazione
        echo '<div class="assia-section">';
        echo '<h2 class="assia-section-title">🔄 Rigenerazione Embeddings</h2>';
        echo '<p class="assia-meta">Rigenera gli embeddings dei contenuti pubblicati. L\'operazione procede a piccoli step via AJAX.</p>';
        echo '<button id="assia-avvia" class="assia-btn">Avvia rigenerazione</button>';
        echo '<div class="assia-bar"><span id="assia-bar-fill"></span></div>';
        echo '<div class="assia-meta">';
        echo '<span id="assia-perc">0%</span> · ';
        echo '<span id="assia-post">0/0 voci</span> · ';
        echo '<span id="assia-chunks">0 chunks</span>';
        echo '</div>';
        echo '<div id="assia-log" class="assia-log"></div>';
        echo '<p class="assia-hint">Se la barra resta ferma, controlla la Console/Network o il log PHP.</p>';
        echo '</div>';

        echo '</div>';
    }

    /**
     * Recupera statistiche sugli embeddings presenti
     */
    protected static function get_embeddings_stats(): array {
        global $wpdb;
        $pref = $wpdb->prefix;
        $modello = get_option('assia_modello_embedding', 'text-embedding-005');

        $stats = [
            'totale' => 0,
            'post_count' => 0,
            'prodotti_count' => 0,
            'avg_chars' => 0,
            'modello' => $modello,
            'ultimo_aggiornamento' => 'Mai'
        ];

        // Totale chunks
        $stats['totale'] = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$pref}assistente_ia_embeddings WHERE modello=%s",
            $modello
        ));

        if ( $stats['totale'] === 0 ) {
            return $stats;
        }

        // Conta post/pagine indicizzati
        $stats['post_count'] = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT id_riferimento) FROM {$pref}assistente_ia_embeddings WHERE modello=%s AND fonte='post'",
            $modello
        ));

        // Conta prodotti indicizzati
        $stats['prodotti_count'] = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT id_riferimento) FROM {$pref}assistente_ia_embeddings WHERE modello=%s AND fonte='prodotto'",
            $modello
        ));

        // Dimensione media caratteri
        $stats['avg_chars'] = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT AVG(CHAR_LENGTH(testo_chunk)) FROM {$pref}assistente_ia_embeddings WHERE modello=%s",
            $modello
        ));

        // Ultimo aggiornamento
        $ultimo = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(creato_il) FROM {$pref}assistente_ia_embeddings WHERE modello=%s",
            $modello
        ));
        
        if ( $ultimo ) {
            $timestamp = strtotime($ultimo);
            $ora_fa = human_time_diff($timestamp, current_time('timestamp'));
            $stats['ultimo_aggiornamento'] = $ora_fa . ' fa (' . date_i18n('d/m/Y H:i', $timestamp) . ')';
        }

        return $stats;
    }
}

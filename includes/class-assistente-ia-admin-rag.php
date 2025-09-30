<?php
if ( ! defined('ABSPATH') ) exit;

class Assistente_IA_Admin_RAG {

    /**
     * Hook di bootstrap: registra il sottomenu RAG
     */
    public static function init(){
        // Priorità alta per essere sicuri che il menu padre sia già registrato
        add_action('admin_menu', [ __CLASS__, 'aggiungi_sottomenu_rag' ], 99);
    }

    /**
     * Aggiunge il sottomenu RAG sotto il menu principale "Assistente IA"
     */
    public static function aggiungi_sottomenu_rag(){
        $parent = 'assia';               // slug del menu top-level
        $slug   = 'assistente-ia-rag';   // slug della pagina RAG (senza slash / e senza .php)

        // Guard-rail: blocca slug errati (compatibile PHP 7)
        if ( strpos($slug, '/') !== false || substr($slug, -4) === '.php' ) {
            return;
        }

        add_submenu_page(
            $parent,
            'RAG (Embeddings)',          // page title
            'RAG (Embeddings)',          // menu title
            'manage_options',            // capability
            $slug,                       // menu_slug
            [ __CLASS__, 'render' ]      // callback
        );

        // Fix di sicurezza: forza comunque lo slug corretto nel menu generato da WP
        add_action('admin_head', function() use ($parent, $slug){
            global $submenu;
            if ( isset($submenu[$parent]) && is_array($submenu[$parent]) ) {
                foreach ($submenu[$parent] as &$item) {
                    // $item[2] = menu_slug
                    if ( isset($item[2]) && ( $item[2] === $slug || strpos($item[2], $slug) !== false ) ) {
                        $item[2] = $slug;
                    }
                }
            }
        });
    }

    /**
     * Render della pagina RAG (UI completa con JS e progress bar)
     */
    public static function render(){
        if ( ! current_user_can('manage_options') ) {
            wp_die( __('Non hai i permessi per accedere a questa pagina.', 'assistente-ia') );
        }

        // Nonce per le azioni AJAX del job RAG
        $nonce = wp_create_nonce('assia_rag_nonce');

        // Enqueue JS admin per la pagina RAG
        wp_enqueue_script(
            'assia-rag-admin',
            ASSIA_URL . 'admin/js/assia-rag-admin.js',
            [ 'jquery' ],
            defined('ASSIA_VERSIONE') ? ASSIA_VERSIONE : '1.0.0',
            true
        );

        wp_localize_script('assia-rag-admin', 'AssiaRag', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => $nonce,
        ]);

        // Stili minimi inline (se preferisci, spostali in un CSS)
        echo '<style>
            .assia-rag-wrap{max-width:860px}
            .assia-bar{height:16px;background:#e5e7eb;border-radius:8px;overflow:hidden;margin:6px 0 12px}
            .assia-bar>span{display:block;height:100%;width:0%;background:#10b981;transition:width .3s}
            .assia-log{background:#0b1020;color:#d1e7ff;padding:10px;border-radius:6px;height:220px;overflow:auto;font:12px/1.4 monospace}
            .assia-meta{color:#374151;margin-bottom:6px}
            .assia-btn{background:#111827;color:#fff;border:0;border-radius:6px;padding:8px 14px;cursor:pointer}
            .assia-btn[disabled]{opacity:.5;cursor:not-allowed}
            .assia-hint{font-size:12px;color:#6b7280;margin-top:8px}
        </style>';

        // Markup UI
        echo '<div class="wrap assia-rag-wrap">
            <h1>RAG (Embeddings)</h1>
            <p class="assia-meta">Rigenera gli embeddings dei contenuti pubblicati. L’operazione procede a piccoli step via AJAX.</p>

            <button id="assia-avvia" class="assia-btn">Avvia rigenerazione</button>
            <div class="assia-bar"><span id="assia-bar-fill"></span></div>
            <div class="assia-meta">
                <span id="assia-perc">0%</span> ·
                <span id="assia-post">0/0 post</span> ·
                <span id="assia-chunks">0 chunks</span>
            </div>
            <div id="assia-log" class="assia-log"></div>
            <p class="assia-hint">Se la barra resta ferma, controlla la Console/Network o il log PHP.</p>
        </div>';
    }
}

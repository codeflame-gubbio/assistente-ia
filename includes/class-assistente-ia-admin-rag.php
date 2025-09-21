<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Pannello "RAG (Embeddings)" – attivazione, Top-K, solo migliore.
 * Sottomenu sotto il menu principale dell'Assistente IA.
 */
class Assistente_IA_Admin_RAG {

    /** Bootstrap */
    public static function init(){
        // Inizializza default se mancanti (non tocca altri flussi)
        if ( get_option('assia_attiva_embeddings', null) === null ) add_option('assia_attiva_embeddings','si');
        if ( get_option('assia_embeddings_top_k', null) === null ) add_option('assia_embeddings_top_k',5);
        if ( get_option('assia_embeddings_solo_migliori', null) === null ) add_option('assia_embeddings_solo_migliori','no');
        // Default fonti RAG
        if ( get_option('assia_rag_post_scope', null) === null ) add_option('assia_rag_post_scope','tutti');
        if ( get_option('assia_rag_post_ids', null) === null ) add_option('assia_rag_post_ids', []);
        if ( get_option('assia_rag_page_scope', null) === null ) add_option('assia_rag_page_scope','tutti');
        if ( get_option('assia_rag_page_ids', null) === null ) add_option('assia_rag_page_ids', []);


        add_action('admin_menu', [ __CLASS__, 'aggiungi_sottomenu_rag' ]);
        add_action('admin_init', [ __CLASS__, 'registra_impostazioni_rag' ]);
    }

    /** Aggiunge la voce di sottomenu */
    public static function aggiungi_sottomenu_rag(){
        $slug_padre = 'assia'; // Cambia se il tuo slug principale è diverso
        add_submenu_page(
            $slug_padre,
            'RAG (Embeddings)',
            'RAG (Embeddings)',
            'manage_options',
            'assistente-ia-rag',
            [ __CLASS__, 'render_pagina_rag' ]
        );
    }

    /** Registra opzioni e campi */
    public static function registra_impostazioni_rag(){

        // Gruppo impostazioni
        register_setting('assia_rag', 'assia_attiva_embeddings', [
            'type' => 'string',
            'sanitize_callback' => function($v){ return ($v==='si' ? 'si' : 'no'); },
            'default' => 'si'
        ]);

        register_setting('assia_rag', 'assia_embeddings_top_k', [
            'type' => 'integer',
            'sanitize_callback' => function($v){
                $n = (int) $v;
                if ($n < 1)  $n = 1;
                if ($n > 20) $n = 20;
                return $n;
            },
            'default' => 5
        ]);

        register_setting('assia_rag', 'assia_embeddings_solo_migliori', [
            'type' => 'string',
            'sanitize_callback' => function($v){ return ($v==='si' ? 'si' : 'no'); },
            'default' => 'no'
        ]);

        // Sezione descrittiva
        

// ---- Selettori fonti: articoli/pagine ----
register_setting('assia_rag', 'assia_rag_post_scope', [
    'type'=>'string',
    'sanitize_callback'=>function($v){ return ($v==='specifici'?'specifici':'tutti'); },
    'default'=>'tutti'
]);
register_setting('assia_rag', 'assia_rag_post_ids', [
    'type'=>'array',
    'sanitize_callback'=>function($arr){ return array_values(array_unique(array_map('intval', (array)$arr))); },
    'default'=>[]
]);
register_setting('assia_rag', 'assia_rag_page_scope', [
    'type'=>'string',
    'sanitize_callback'=>function($v){ return ($v==='specifici'?'specifici':'tutti'); },
    'default'=>'tutti'
]);
register_setting('assia_rag', 'assia_rag_page_ids', [
    'type'=>'array',
    'sanitize_callback'=>function($arr){ return array_values(array_unique(array_map('intval', (array)$arr))); },
    'default'=>[]
]);

add_settings_section(
            'assia_sezione_rag',
            'RAG (Embeddings)',
            function(){
                echo '<p>Configura l’indicizzazione e il recupero dei contenuti del sito (RAG). '.
                     'Dopo aver salvato, rigenera l’indice in <em>Assistente IA → Diagnostica</em>.</p>';
            },
            'assistente-ia-rag'
        );

        // Campo: Attiva RAG
        add_settings_field(
            'assia_attiva_embeddings',
            'Attiva RAG',
            function(){
                $val = get_option('assia_attiva_embeddings','si'); ?>
                <label><input type="radio" name="assia_attiva_embeddings" value="si" <?php checked('si',$val); ?>> Sì</label>
                &nbsp;&nbsp;
                <label><input type="radio" name="assia_attiva_embeddings" value="no" <?php checked('no',$val); ?>> No</label>
                <p class="description">Se disattivi, il bot non userà gli embeddings (solo prompt puro / fallback, se presente).</p>
                <?php
            },
            'assistente-ia-rag',
            'assia_sezione_rag'
        );

        // Campo: Top-K
        add_settings_field(
            'assia_embeddings_top_k',
            'Top-K (numero estratti)',
            function(){
                $val = (int) get_option('assia_embeddings_top_k', 5); ?>
                <input type="number" min="1" max="20" name="assia_embeddings_top_k" value="<?php echo esc_attr($val); ?>" />
                <p class="description">Quanti estratti pertinenti passare al modello (consigliato 3–7 per qualità/costo).</p>
                <?php
            },
            'assistente-ia-rag',
            'assia_sezione_rag'
        );

        // Campo: Solo il migliore
        add_settings_field(
            'assia_embeddings_solo_migliori',
            'Usa solo il migliore',
            function(){
                $val = get_option('assia_embeddings_solo_migliori','no'); ?>
                <label><input type="radio" name="assia_embeddings_solo_migliori" value="si" <?php checked('si',$val); ?>> Sì</label>
                &nbsp;&nbsp;
                <label><input type="radio" name="assia_embeddings_solo_migliori" value="no" <?php checked('no',$val); ?>> No</label>
                <p class="description">Se “Sì”, passa solo il miglior estratto. Con “No”, passa Top-K estratti (di solito più utile).</p>
                <?php
            },
            'assistente-ia-rag',
            'assia_sezione_rag'
        );
    }

    /** Rendering della pagina */
    public static function render_pagina_rag(){
        if ( ! current_user_can('manage_options') ) { return; } ?>
        <div class="wrap">
            <h1>RAG (Embeddings)</h1>
            <form method="post" action="options.php">
                <?php
                    settings_fields('assia_rag');
                    do_settings_sections('assistente-ia-rag');
                    submit_button('Salva impostazioni');
                ?>
            </form>
            <hr>
            <p><strong>Nota:</strong> dopo il salvataggio vai in <em>Assistente IA → Diagnostica</em> e
               <strong>rigenera l’indice</strong> per aggiornare gli embeddings.</p>
        </div>
        <?php
    }
}

// Avvio
Assistente_IA_Admin_RAG::init();


        // Sezione: Fonti da indicizzare
        add_settings_section(
            'assia_sezione_fonti',
            'Fonti da indicizzare',
            function(){
                echo '<p>Scegli quali contenuti includere nel RAG. I prodotti WooCommerce sono sempre inclusi (se attivo).</p>';
            },
            'assistente-ia-rag'
        );

        // Campo: Articoli
        add_settings_field(
            'assia_rag_post_scope',
            'Articoli (post)',
            function(){
                $scope = get_option('assia_rag_post_scope','tutti');
                $selez = (array) get_option('assia_rag_post_ids',[]);
                ?>
                <p>
                    <label><input type="radio" name="assia_rag_post_scope" value="tutti" <?php checked('tutti',$scope); ?>> Tutti</label>
                    &nbsp;&nbsp;
                    <label><input type="radio" name="assia_rag_post_scope" value="specifici" <?php checked('specifici',$scope); ?>> Solo specifici</label>
                </p>
                <div id="assia-post-specifici" style="<?php echo ($scope==='specifici'?'':'display:none'); ?>">
                    <select name="assia_rag_post_ids[]" multiple size="8" style="min-width: 380px;">
                        <?php
                        $posts = get_posts([ 'post_type'=>'post','post_status'=>'publish','posts_per_page'=>200, 'orderby'=>'date','order'=>'DESC' ]);
                        foreach($posts as $p){
                            echo '<option value="'.esc_attr($p->ID).'" '.(in_array($p->ID,$selez)?'selected':'').'>'.esc_html($p->post_title).' (ID '.$p->ID.')</option>';
                        }
                        ?>
                    </select>
                    <p class="description">Seleziona gli articoli da includere (si mostrano i 200 più recenti).</p>
                </div>
                <script>
                    (function(){
                        var radios = document.getElementsByName('assia_rag_post_scope');
                        var box = document.getElementById('assia-post-specifici');
                        Array.prototype.forEach.call(radios, function(r){
                            r.addEventListener('change', function(){ box.style.display = (this.value==='specifici'?'block':'none'); });
                        });
                    })();
                </script>
                <?php
            },
            'assistente-ia-rag',
            'assia_sezione_fonti'
        );

        // Campo: Pagine
        add_settings_field(
            'assia_rag_page_scope',
            'Pagine',
            function(){
                $scope = get_option('assia_rag_page_scope','tutti');
                $selez = (array) get_option('assia_rag_page_ids',[]);
                ?>
                <p>
                    <label><input type="radio" name="assia_rag_page_scope" value="tutti" <?php checked('tutti',$scope); ?>> Tutte</label>
                    &nbsp;&nbsp;
                    <label><input type="radio" name="assia_rag_page_scope" value="specifici" <?php checked('specifici',$scope); ?>> Solo specifiche</label>
                </p>
                <div id="assia-page-specifiche" style="<?php echo ($scope==='specifici'?'':'display:none'); ?>">
                    <select name="assia_rag_page_ids[]" multiple size="8" style="min-width: 380px;">
                        <?php
                        $pages = get_pages([ 'sort_column'=>'post_date','sort_order'=>'desc','number'=>200 ]);
                        foreach($pages as $p){
                            echo '<option value="'.esc_attr($p->ID).'" '.(in_array($p->ID,$selez)?'selected':'').'>'.esc_html($p->post_title).' (ID '.$p->ID.')</option>';
                        }
                        ?>
                    </select>
                    <p class="description">Seleziona le pagine da includere (mostriamo max 200).</p>
                </div>
                <script>
                    (function(){
                        var radios = document.getElementsByName('assia_rag_page_scope');
                        var box = document.getElementById('assia-page-specifiche');
                        Array.prototype.forEach.call(radios, function(r){
                            r.addEventListener('change', function(){ box.style.display = (this.value==='specifici'?'block':'none'); });
                        });
                    })();
                </script>
                <?php
            },
            'assistente-ia-rag',
            'assia_sezione_fonti'
        );

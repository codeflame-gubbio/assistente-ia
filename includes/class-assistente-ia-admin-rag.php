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

        add_action('admin_menu', [ __CLASS__, 'aggiungi_sottomenu_rag' ], 99);
        add_action('admin_init', [ __CLASS__, 'registra_impostazioni_rag' ]);
    }

    /** Aggiunge la voce di sottomenu */
    public static function aggiungi_sottomenu_rag(){
        
        $parent = 'assia';
        $slug   = 'assistente-ia-rag';
        if ( strpos($slug, '/') !== false || str_ends_with($slug, '.php') ) return;
        $slug_padre = 'assia'; // Cambia se il tuo slug principale è diverso
        add_submenu_page($parent, 'RAG (Embeddings)', 'RAG (Embeddings)', 'manage_options', $slug,
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

    public static function render(){
        
        echo '<div class="wrap"><h1>RAG (Embeddings)</h1><p>Gestione embeddings.</p></div>';
    
    }
}

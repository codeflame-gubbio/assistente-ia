<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Widget chat front-end + shortcode [assistente_ia]
 * ✅ FIX v5.4.0: Aggiunta variabile 'hash' a wp_localize_script
 */
class Assistente_IA_Frontend {
    private static $rendered = false;

    public function __construct(){
        add_action('wp_enqueue_scripts',[ $this,'carica' ]);
        
        if ( 'si' === get_option('assia_inserimento_automatico_footer','si') ) { 
            add_action('wp_footer',[ $this,'render' ]); 
        }
        
        add_shortcode('assistente_ia',[ $this,'shortcode' ]);
    }

    /** Carica asset e passa config al JS */
    public function carica(){
        wp_enqueue_style('assia-css', ASSIA_URL.'public/css/assistente-ia.css',[],ASSIA_VERSIONE);
        wp_enqueue_script('assia-js', ASSIA_URL.'public/js/assistente-ia.js',['jquery'],ASSIA_VERSIONE,true);
        
        // ✅ FIX: Genera hash sessione lato server (opzionale) o lascia vuoto per gestione JS
        $hash_sessione = ''; // Il JS genera/recupera da localStorage
        
        wp_localize_script('assia-js','AssistenteIA',[
            'ajax_url'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce('assistente_ia_nonce'),
            'hash'=>$hash_sessione, // ✅ FIX: Aggiunta variabile hash
            'currentPost'=>function_exists('get_queried_object_id')?(int)get_queried_object_id():0,
            'messaggi_ui'=>(int)get_option('assia_messaggi_ui',30),
            'etichetta_bottone'=>get_option('assia_bottone_testo','Chatta con noi'),
            'posizione_bottone'=>get_option('assia_bottone_posizione','bottom-right'),
        ]);
    }

    public function render(){ if(self::$rendered) return; echo $this->html(); self::$rendered=true; }
    public function shortcode(){ self::$rendered=true; return $this->html(); }

    /** HTML del widget */
    protected function html(): string {
        ob_start(); ?>
        <div id="assistente-ia-bottone" class="assia-pos-<?php echo esc_attr(get_option('assia_bottone_posizione','bottom-right')); ?>">
            <span class="assistente-ia-etichetta"><?php echo esc_html(get_option('assia_bottone_testo','Chatta con noi')); ?></span>
        </div>
        <div id="assistente-ia-popup" class="assia-nascosto" role="dialog" aria-live="polite">
            <div class="assistente-ia-intestazione">
                <strong>Assistente IA</strong>
                <button type="button" class="assistente-ia-chiudi" aria-label="Chiudi">×</button>
            </div>
            <div id="assistente-ia-messaggi" class="assistente-ia-messaggi" aria-live="polite"></div>
            <div class="assistente-ia-inputarea">
                <textarea id="assistente-ia-input" rows="3" placeholder="Scrivi un messaggio…"></textarea>
                <button id="assistente-ia-invia" type="button">Invia</button>
            </div>
        </div>
        <?php return ob_get_clean();
    }
}

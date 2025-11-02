<?php
/**
 * Plugin Name: Assistente IA Conversazioni
 * Description: Chat con cronologia persistente, riidratazione cross-pagina, RAG con embeddings, rate limit, prompt modulare e integrazione Vertex AI. Stile interamente in italiano.
 * Version: 5.4.0
 * Author: Assistente IA
 * Text Domain: assistente-ia-conversazioni
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'ASSIA_VERSIONE', '5.4.0' );
define( 'ASSIA_PATH', plugin_dir_path( __FILE__ ) );
define( 'ASSIA_URL', plugin_dir_url( __FILE__ ) );

require_once ASSIA_PATH . 'includes/class-assistente-ia-installazione.php';
require_once ASSIA_PATH . 'includes/class-assistente-ia-utilita.php';
require_once ASSIA_PATH . 'includes/class-assistente-ia-token.php';
require_once ASSIA_PATH . 'includes/class-assistente-ia-modello-vertex.php';
require_once ASSIA_PATH . 'includes/class-assistente-ia-prompt.php';
require_once ASSIA_PATH . 'includes/class-assistente-ia-rag.php';
require_once ASSIA_PATH . 'includes/class-assistente-ia-diagnostica-modello.php';
require_once ASSIA_PATH . 'includes/class-assistente-ia-admin.php';
require_once ASSIA_PATH . 'includes/class-assistente-ia-frontend.php';
require_once ASSIA_PATH . 'includes/class-assistente-ia-ajax.php';
require_once ASSIA_PATH . 'includes/class-assistente-ia-admin-rag.php';
require_once ASSIA_PATH . 'includes/class-assistente-ia-content-selector.php';
require_once ASSIA_PATH . 'includes/class-assistente-ia-rag-diagnostica.php';

if ( is_admin() ) { 
    add_action('init', function(){
        if ( class_exists('Assistente_IA_Ajax') && empty($GLOBALS['assia_ajax_instance']) ) {
            $GLOBALS['assia_ajax_instance'] = new Assistente_IA_Ajax();
        }
    }, 5);
    add_action('init', ['Assistente_IA_Admin_RAG','init']);
    add_action('init', ['Assistente_IA_Content_Selector','init']);
    add_action('init', ['Assistente_IA_RAG_Diagnostica','init']);
}

register_activation_hook( __FILE__, ['Assistente_IA_Installazione','all_attivazione'] );
register_uninstall_hook( __FILE__, ['Assistente_IA_Installazione','alla_disinstallazione'] );

add_action( 'plugins_loaded', function(){
    new Assistente_IA_Admin();
    new Assistente_IA_Frontend();
    new Assistente_IA_Ajax();
} );

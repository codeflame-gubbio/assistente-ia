<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Endpoint AJAX: chat, recupero cronologia, job embeddings (avvio/step/stato).
 */
class Assistente_IA_Ajax {

    public function __construct(){
        add_action('wp_ajax_assistente_ia_chat',[ $this,'gestisci_chat' ]);
        add_action('wp_ajax_nopriv_assistente_ia_chat',[ $this,'gestisci_chat' ]);

        add_action('wp_ajax_assistente_ia_recupera_chat',[ $this,'recupera_chat' ]);
        add_action('wp_ajax_nopriv_assistente_ia_recupera_chat',[ $this,'recupera_chat' ]);

        add_action('wp_ajax_assia_embeddings_avvia', [ $this, 'embeddings_avvia' ] );
        add_action('wp_ajax_assia_embeddings_step',  [ $this, 'embeddings_step' ] );
        add_action('wp_ajax_assia_embeddings_stato', [ $this, 'embeddings_stato' ] );
    }

    /** Gestione messaggio utente → prompt → risposta modello */
    public function gestisci_chat(){
        check_ajax_referer('assistente_ia_nonce','nonce');

        $messaggio=isset($_POST['messaggio'])?sanitize_text_field(wp_unslash($_POST['messaggio'])):'';
        $hash=isset($_POST['hash_sessione'])?sanitize_text_field(wp_unslash($_POST['hash_sessione'])):'';
        if(empty($messaggio)||empty($hash)) wp_send_json_error(['messaggio'=>'Richiesta non valida']);

        Assistente_IA_Utilita::limita_richieste_utente($hash);

        $id_chat=$this->ottieni_o_crea_chat($hash);
        $this->salva_messaggio($id_chat,'utente',$messaggio);

        $prompt=Assistente_IA_Prompt::costruisci_prompt($id_chat,$messaggio);
        $res=Assistente_IA_Modello_Vertex::genera_testo($prompt, [
    'id_chat'       => $id_chat,
    'hash_sessione' => $hash,
]);
        if(!empty($res['errore'])) wp_send_json_error(['messaggio'=>'Errore: '.$res['errore']]);

        $html=$this->postprocesso_risposta($res['testo']??'');
        $this->salva_messaggio($id_chat,'assistente',$html);

        Assistente_IA_Prompt::aggiorna_riassunto_compresso($id_chat);

        wp_send_json_success(['risposta_html'=>$html,'id_chat'=>$id_chat]);
    }

    /** Riidrata la cronologia recente per la sessione */
    public function recupera_chat(){
        check_ajax_referer('assistente_ia_nonce','nonce');
        $hash=isset($_POST['hash_sessione'])?sanitize_text_field(wp_unslash($_POST['hash_sessione'])):'';
        if(empty($hash)) wp_send_json_error(['messaggio'=>'Hash sessione mancante']);

        $id_chat=$this->ottieni_o_crea_chat($hash);
        $m=(int)get_option('assia_messaggi_ui',30);

        global $wpdb; $pref=$wpdb->prefix;
        $righe=$wpdb->get_results($wpdb->prepare("SELECT ruolo, testo, creato_il FROM {$pref}assistente_ia_messaggi WHERE id_chat=%d ORDER BY id_messaggio DESC LIMIT %d",$id_chat,$m),ARRAY_A);
        $righe=array_reverse($righe);

        wp_send_json_success(['messaggi'=>$righe,'id_chat'=>$id_chat]);
    }

    /** Avvia job embeddings (lista post) */
    public function embeddings_avvia(){
        check_ajax_referer('assistente_ia_nonce','nonce');
        if ( ! current_user_can('manage_options') ) wp_send_json_error(['messaggio'=>'Permessi insufficienti']);
        $job = Assistente_IA_RAG::prepara_job_indicizzazione();
        wp_send_json_success( $job );
    }

    /** Esegue uno step del job (batch N post) */
    public function embeddings_step(){
        check_ajax_referer('assistente_ia_nonce','nonce');
        if ( ! current_user_can('manage_options') ) wp_send_json_error(['messaggio'=>'Permessi insufficienti']);
        $batch = isset($_POST['batch']) ? max(1, (int)$_POST['batch']) : 5;
        $job = Assistente_IA_RAG::esegui_job_passaggio( $batch );
        if ( isset($job['errore']) ) wp_send_json_error( $job );
        wp_send_json_success( $job );
    }

    /** Stato corrente del job */
    public function embeddings_stato(){
        check_ajax_referer('assistente_ia_nonce','nonce');
        if ( ! current_user_can('manage_options') ) wp_send_json_error(['messaggio'=>'Permessi insufficienti']);
        $job = Assistente_IA_RAG::stato_job();
        wp_send_json_success( $job );
    }

    /** Helpers persistenza chat */
    protected function ottieni_o_crea_chat(string $hash): int {
        global $wpdb; $pref=$wpdb->prefix;
        $id=(int)$wpdb->get_var($wpdb->prepare("SELECT id_chat FROM {$pref}assistente_ia_chat WHERE hash_sessione=%s",$hash));
        if($id) return $id;
        $wpdb->insert($pref.'assistente_ia_chat',[
            'hash_sessione'=>$hash,
            'data_creazione'=>current_time('mysql'),
            'ultimo_aggiornamento'=>current_time('mysql')
        ]);
        return (int)$wpdb->insert_id;
    }

    protected function salva_messaggio(int $id_chat,string $ruolo,string $testo): void {
        global $wpdb; $pref=$wpdb->prefix;
        $wpdb->insert($pref.'assistente_ia_messaggi',[
            'id_chat'=>$id_chat,
            'ruolo'=>$ruolo,
            'testo'=>wp_kses_post($testo),
            'creato_il'=>current_time('mysql')
        ]);
        $wpdb->update($pref.'assistente_ia_chat',[ 'ultimo_aggiornamento'=>current_time('mysql') ],[ 'id_chat'=>$id_chat ]);
    }

    /** Post-process della risposta (link cliccabili + card per link WP) */
    protected function postprocesso_risposta(string $testo): string {
        $testo=trim($testo);
        // Link plain → <a>
        $testo=preg_replace('#(https?://[\\w\\-\\.\\?\\,\\:/\\#\\&\\%=\\+\\~]+)#','<a href="$1" target="_blank" rel="noopener nofollow">$1</a>',$testo);

        // Paragrafi
        $out=''; foreach(explode("\n",$testo) as $r){ $out.='<p>'.wp_kses_post($r).'</p>'; }

        // Card per link a post WP
        $out=preg_replace_callback('#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#',function($m){
            $url=$m[1]; $txt=$m[2]; $pid=url_to_postid($url);
            if($pid){
                $ttl=get_the_title($pid); $thumb=get_the_post_thumbnail_url($pid,'thumbnail');
                $estr=wp_trim_words(wp_strip_all_tags(get_the_excerpt($pid)?:get_post_field('post_content',$pid)),18);
                $img=$thumb?'<img src="'.esc_url($thumb).'" alt="" />':'';
                $card='<div class="assia-link-card">'.$img.'<div class="assia-link-card-testo"><strong>'.esc_html($ttl).'</strong><span>'.esc_html($estr).'</span></div></div>';
                return '<a class="assia-link" href="'.esc_url($url).'" target="_blank" rel="noopener">'.wp_kses_post($txt).'</a>'.$card;
            }
            return $m[0];
        },$out);

        return $out;
    }
}

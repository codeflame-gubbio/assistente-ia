<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Costruzione del prompt contestuale (obiettivo, avviso, riassunto chat, ultimi turni, RAG).
 */
class Assistente_IA_Prompt {

public static function costruisci_prompt(int $id_chat, string $domanda): string {
    $obiettivo=get_option('assia_obiettivo','');
    $avviso=get_option('assia_avviso','');

    list($riassunto,$ultimi)=self::recupera_contesto_conversazione($id_chat);
    $estratti=Assistente_IA_RAG::recupera_estratti_rag($domanda);

    $b=[];
    if($obiettivo)$b[]="[Obiettivo]\n".Assistente_IA_Utilita::pulisci_testo($obiettivo);
    if($avviso)$b[]="[Avviso]\n".Assistente_IA_Utilita::pulisci_testo($avviso);
    if($riassunto)$b[]="[Riassunto conversazione]\n".$riassunto;
    if($ultimi)$b[]="[Ultimi turni]\n".$ultimi;
    if($estratti)$b[]="[Contesto pertinente]\n".$estratti;

    // Blocco anti-disclaimer e stile
    $b[]="[Stile e Regole]\n".
         "1) Usa il [Contesto pertinente] come fonte primaria.\n".
         "2) Evita frasi come «non ho accesso al sito»: se manca qualcosa, chiedi 1 chiarimento.\n".
         "3) Tono concreto e sintetico; cita titoli/link se presenti nel contesto.\n".
         "4) Non inventare dati non presenti.";

    $b[]="[Domanda]\n".Assistente_IA_Utilita::pulisci_testo($domanda);
    $b[]="[Risposta]";
    return trim(implode("\n\n",$b));
}


    /** Recupera riassunto compresso e ultimi N turni della chat */
    protected static function recupera_contesto_conversazione(int $id_chat): array {
        global $wpdb; $pref=$wpdb->prefix; $turni=(int)get_option('assia_turni_modello',8);
        $riass=$wpdb->get_var($wpdb->prepare("SELECT riassunto_compresso FROM {$pref}assistente_ia_chat WHERE id_chat=%d",$id_chat));
        $righe=$wpdb->get_results($wpdb->prepare("SELECT ruolo, testo FROM {$pref}assistente_ia_messaggi WHERE id_chat=%d ORDER BY id_messaggio DESC LIMIT %d",$id_chat,$turni),ARRAY_A);
        $righe=array_reverse($righe);
        $txt='';
        foreach($righe as $r){
            $lbl=('utente'===$r['ruolo'])?'Utente':(('assistente'===$r['ruolo'])?'Assistente':'Sistema');
            $txt.=$lbl.': '.Assistente_IA_Utilita::tronca(Assistente_IA_Utilita::pulisci_testo($r['testo']),600)."\n";
        }
        return [(string)$riass, trim($txt)];
    }

    /** Aggiorna il riassunto compresso della conversazione (per chat lunghe) */
    public static function aggiorna_riassunto_compresso(int $id_chat): void {
        global $wpdb; $pref=$wpdb->prefix;
        $righe=$wpdb->get_results($wpdb->prepare("SELECT ruolo, testo FROM {$pref}assistente_ia_messaggi WHERE id_chat=%d ORDER BY id_messaggio DESC LIMIT 20",$id_chat),ARRAY_A);
        $righe=array_reverse($righe);
        $serial='';
        foreach($righe as $r){
            $serial.=($r['ruolo'].': '.Assistente_IA_Utilita::pulisci_testo($r['testo'])."\n");
        }
        $prompt="Riassumi in 120-160 parole i punti salienti della seguente conversazione, evidenziando obiettivi dell'utente, decisioni prese e vincoli citati. Conversazione:\n".$serial;
        $res=Assistente_IA_Modello_Vertex::genera_testo($prompt);
        if(!empty($res['testo'])){
            $wpdb->update($pref.'assistente_ia_chat',[
                'riassunto_compresso'=>Assistente_IA_Utilita::tronca($res['testo'],2000),
                'ultimo_aggiornamento'=>current_time('mysql')
            ],[ 'id_chat'=>$id_chat ]);
        }
    }


    /** Costruisce il prompt e aggiunge il contesto della pagina corrente (post_id) se presente */
    public static function costruisci_prompt_con_post( int $id_chat, string $domanda, int $post_id = 0 ): string {
        $base = self::costruisci_prompt($id_chat, $domanda);
        if($post_id){
            $p = get_post($post_id);
            if($p && $p->post_status==='publish'){
                $titolo = get_the_title($post_id);
                $contenuto = wp_strip_all_tags( get_post_field('post_content',$post_id) );
                $contenuto = preg_replace('/\s+/', ' ', $contenuto);
                $contenuto = trim($contenuto);
                $estratti = 'Titolo: '. $titolo ."\nTesto: ". substr($contenuto,0,1200);
                $base .= "\n\n---\n\nContesto pagina corrente:\n" . $estratti;
            }
        }
        return $base;
    }
    
}

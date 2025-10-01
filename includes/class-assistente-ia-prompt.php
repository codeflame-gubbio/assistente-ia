<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Costruzione del prompt contestuale (obiettivo, avviso, riassunto chat, ultimi turni, RAG).
 */
class Assistente_IA_Prompt {

public static function costruisci_prompt(int $id_chat, string $domanda, int $post_id = 0): string {
    $obiettivo=get_option('assia_obiettivo','');
    $avviso=get_option('assia_avviso','');

    list($riassunto,$ultimi)=self::recupera_contesto_conversazione($id_chat);
    $estratti=Assistente_IA_RAG::recupera_estratti_rag($domanda);

    // --- v5.3.4: Context Smart ---
    // Contesto della pagina corrente (titolo/URL/riassunto), se post pubblico
    $contesto_pagina = '';
    if ( $post_id > 0 ) {
        $p = get_post($post_id);
        if ( $p && $p->post_status === 'publish' && empty($p->post_password) ) {
            $tit = get_the_title($p);
            $url = get_permalink($p);
            $estr = wp_trim_words( wp_strip_all_tags( get_the_excerpt($p) ?: $p->post_content ), 40 );
            $contesto_pagina = "Titolo: {$tit}\nURL: {$url}\nRiassunto: {$estr}";
        }
    }

    // Contesto WooCommerce (solo se abilitato e su product pubblicato)
    $contesto_wc = '';
    if ( 'si' === get_option('assia_context_wc','si') && $post_id > 0 && function_exists('wc_get_product') ) {
        $post_type = get_post_type( $post_id );
        $stato = get_post_status( $post_id );
        if ( $post_type === 'product' && $stato === 'publish' ) {
            $product = wc_get_product( $post_id );
            if ( $product instanceof WC_Product ) {
                $tit = get_the_title($post_id);
                $url = get_permalink($post_id);
                $sku = $product->get_sku() ?: '-';
                // Prezzo con gestione variabili (range)
                $prezzo = '';
                if ( $product->is_type('variable') ) {
                    $min = $product->get_variation_price('min', true);
                    $max = $product->get_variation_price('max', true);
                    if ( $min && $max && $min != $max ) { $prezzo = wc_price($min) . ' – ' . wc_price($max); }
                    else { $prezzo = wc_price($min ?: $max); }
                } else {
                    $prezzo = wc_price( $product->get_price() );
                }
                // Categorie
                $terms = get_the_terms($post_id, 'product_cat'); $cats=[];
                if ( is_array($terms) ) { foreach($terms as $t){ $cats[] = $t->name; } }
                $cats_str = $cats ? implode(', ', $cats) : '-';
                $contesto_wc = "Titolo: {$tit}\nURL: {$url}\nSKU: {$sku}\nPrezzo: {$prezzo}\nCategorie: {$cats_str}";
            }
        }
    }

    // Contesto specifico (mini-brief) per la pagina/post, se abilitato
    $contesto_brief = '';
    if ( 'si' === get_option('assia_context_brief_enable','si') && $post_id > 0 ) {
        $p = get_post($post_id);
        if ( $p && $p->post_status === 'publish' && empty($p->post_password) ) {
            $raw = get_post_meta($post_id, 'assia_context_brief', true);
            if ( is_string($raw) && trim($raw) !== '' ) {
                $brief_txt = wp_strip_all_tags( $raw );
                if ( strlen($brief_txt) > 1000 ) { $brief_txt = substr($brief_txt,0,1000).'…'; }
                $contesto_brief = $brief_txt;
            }
        }
    }
    // --- /Context Smart ---

   $b[]="[Stile e Regole - LEGGI ATTENTAMENTE]\n".
     "1) PRIORITÀ ASSOLUTA: Usa ESCLUSIVAMENTE il [Contesto pertinente] come fonte.\n".
     "   - Ogni chunk nel contesto inizia con [Fonte: Nome]. Quando rispondi, cita la fonte.\n".
     "   - Esempio: \"Come spiegato nella pagina 'Vetrina PRO', il servizio include...\"\n".
     "\n".
     "2) SE LA RISPOSTA È NEL CONTESTO:\n".
     "   - Rispondi in modo diretto, naturale e completo\n".
     "   - Cita la fonte principale tra quelle nel contesto\n".
     "   - Usa un tono conversazionale, non robotico\n".
     "   - Se ci sono link, includili nella risposta\n".
     "\n".
     "3) SE LA RISPOSTA NON È NEL CONTESTO:\n".
     "   - Dillo chiaramente: \"Non ho informazioni specifiche su [argomento] nella nostra base di conoscenza.\"\n".
     "   - NON inventare, NON fare supposizioni\n".
     "   - Suggerisci argomenti correlati presenti nel contesto, se pertinenti\n".
     "   - Esempio: \"Non trovo info su ActiveCampaign, ma posso aiutarti con Vetrina PRO o i nostri servizi di automazione.\"\n".
     "\n".
     "4) EVITA ASSOLUTAMENTE:\n".
     "   - Frasi come «non ho accesso al sito» (SEI il sito!)\n".
     "   - Disclaimer generici («come assistente IA...»)\n".
     "   - Inventare dati, prezzi o caratteristiche non presenti\n".
     "\n".
     "5) STILE:\n".
     "   - Italiano naturale e professionale\n".
     "   - Risposte sintetiche ma complete\n".
     "   - Usa elenchi puntati per informazioni multiple\n".
     "   - Mantieni un tono amichevole ma competente";
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
}

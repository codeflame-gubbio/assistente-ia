<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Costruzione del prompt contestuale (obiettivo, avviso, riassunto chat, ultimi turni, RAG).
 * VERSIONE GENERICA v5.5.0 - Tutti i prompt sono configurabili dal pannello impostazioni
 */
class Assistente_IA_Prompt {

public static function costruisci_prompt(int $id_chat, string $domanda, int $post_id = 0): string {
    $obiettivo = get_option('assia_obiettivo','');
    $avviso = get_option('assia_avviso','');

    list($riassunto, $ultimi) = self::recupera_contesto_conversazione($id_chat);
    $estratti = Assistente_IA_RAG::recupera_estratti_rag($domanda);

    // --- Context Smart ---
    $contesto_pagina = '';
    if ( $post_id > 0 ) {
        $p = get_post($post_id);
        if ( $p && $p->post_status === 'publish' && empty($p->post_password) ) {
            $tit = get_the_title($p);
            $url = get_permalink($p);
            // ✅ 500 PAROLE dalla pagina corrente
            // ✅ CODICE CORRETTO (usa il renderer che già funziona per gli embeddings!)
$testo_pulito = Assistente_IA_Renderer::ottieni_testo_pulito_da_post( $post_id );
$estr = wp_trim_words( $testo_pulito, 500 );
        }
    }

    // Contesto WooCommerce
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
                $prezzo = '';
                if ( $product->is_type('variable') ) {
                    $min = $product->get_variation_price('min', true);
                    $max = $product->get_variation_price('max', true);
                    if ( $min && $max && $min != $max ) { $prezzo = wc_price($min) . ' – ' . wc_price($max); }
                    else { $prezzo = wc_price($min ?: $max); }
                } else {
                    $prezzo = wc_price( $product->get_price() );
                }
                $terms = get_the_terms($post_id, 'product_cat'); $cats=[];
                if ( is_array($terms) ) { foreach($terms as $t){ $cats[] = $t->name; } }
                $cats_str = $cats ? implode(', ', $cats) : '-';
                $contesto_wc = "Titolo: {$tit}\nURL: {$url}\nSKU: {$sku}\nPrezzo: {$prezzo}\nCategorie: {$cats_str}";
            }
        }
    }

    // Contesto specifico (mini-brief)
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

    // ✅ COSTRUZIONE PROMPT CON ISTRUZIONI PERSONALIZZABILI
    $blocchi = [];

    // 1. Obiettivo (se presente)
    if ( $obiettivo ) {
        $blocchi[] = "[Obiettivo]\n" . trim($obiettivo);
    }

    // 2. Regole di stile - ORA PERSONALIZZABILI DAL PANNELLO
    $istruzioni_stile = get_option('assia_istruzioni_stile', '');
    if ( empty($istruzioni_stile) ) {
        // ✅ DEFAULT GENERICO (senza riferimenti specifici a progetti)
        $istruzioni_stile = self::get_default_istruzioni_stile();
    }
    $blocchi[] = "[Stile e Regole - SEGUI ATTENTAMENTE]\n" . trim($istruzioni_stile);

    // ✅ 3. PAGINA CORRENTE - PRIMA POSIZIONE (massima priorità)
    if ( $contesto_pagina ) {
        $blocchi[] = "⭐ [PAGINA CORRENTE - FONTE PRIMARIA]\n" . 
                     "L'utente sta visitando questa pagina. Usa QUESTO contenuto come base della tua risposta:\n\n" .
                     trim($contesto_pagina);
    }

    // 4. Contesto WooCommerce (se presente)
    if ( $contesto_wc ) {
        $blocchi[] = "[Dettagli Prodotto]\n" . trim($contesto_wc);
    }

    // 5. Mini-brief specifico (se presente)
    if ( $contesto_brief ) {
        $blocchi[] = "[Contesto Editoriale]\n" . trim($contesto_brief);
    }

    // 6. Contesto pertinente RAG - SECONDA POSIZIONE (supporto/approfondimento)
    if ( $estratti ) {
        $blocchi[] = "[Contesto Pertinente - APPROFONDIMENTI]\n" . 
                     "Usa queste informazioni per arricchire o confermare quanto nella pagina corrente:\n\n" .
                     trim($estratti);
    } else {
        // Solo se non c'è pagina corrente, segnala assenza RAG
        if ( empty($contesto_pagina) ) {
            $blocchi[] = "[Contesto Pertinente]\nNessun contenuto trovato nella base di conoscenza.";
        }
    }

    // 7. Riassunto conversazione (se presente)
    if ( $riassunto ) {
        $blocchi[] = "[Riassunto Conversazione]\n" . trim($riassunto);
    }

    // 8. Ultimi turni conversazione (se presenti)
    if ( $ultimi ) {
        $blocchi[] = "[Conversazione Recente]\n" . trim($ultimi);
    }

    // 9. Avviso (se presente)
    if ( $avviso ) {
        $blocchi[] = "[Avviso]\n" . trim($avviso);
    }

    // 10. LA DOMANDA DELL'UTENTE (sempre per ultima)
    $blocchi[] = "[Domanda Utente]\n" . trim($domanda);

    return implode("\n\n", $blocchi);
}

    /**
     * ✅ NUOVO: Istruzioni di stile di default GENERICHE
     */
    protected static function get_default_istruzioni_stile(): string {
        return "🎯 PRIORITÀ NELLE FONTI (dall'alto verso il basso):
1. **PAGINA CORRENTE** - Se l'utente sta visitando una pagina specifica, USA PRINCIPALMENTE il suo contenuto
2. **Contesto pertinente (RAG)** - Usa per approfondire, confermare o aggiungere dettagli correlati
3. **Dettagli prodotto** - Se su una pagina prodotto, includi specifiche tecniche

📝 COME RISPONDERE:
- Se c'è [Pagina corrente]: PARTI DA LÌ. Sintetizza il contenuto in modo completo e utile
- Usa [Contesto pertinente] per ARRICCHIRE, non per sostituire
- Cita le fonti naturalmente: \"Come spiegato in questa pagina...\" o \"Secondo la pagina...\"
- Tono conversazionale, diretto, professionale
- Risposte CONCRETE: dati, caratteristiche, benefici specifici

❌ EVITA ASSOLUTAMENTE:
- Risposte vaghe quando hai informazioni dettagliate disponibili
- Dire \"non ho informazioni\" quando [Pagina corrente] contiene dettagli
- Frasi come «consulta la sezione X» - SEI TU che deve spiegare!
- Disclaimer tipo «non ho accesso al sito» - DEVI usare le informazioni fornite
- Inventare dati non presenti nelle fonti

🎯 OBIETTIVO: Dare valore IMMEDIATO all'utente, usando le informazioni contestuali disponibili!";
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

    /** 
     * Aggiorna il riassunto compresso della conversazione (per chat lunghe) 
     * ✅ ORA USA PROMPT PERSONALIZZABILE
     */
    public static function aggiorna_riassunto_compresso(int $id_chat): void {
        global $wpdb; $pref=$wpdb->prefix;
        $righe=$wpdb->get_results($wpdb->prepare("SELECT ruolo, testo FROM {$pref}assistente_ia_messaggi WHERE id_chat=%d ORDER BY id_messaggio DESC LIMIT 20",$id_chat),ARRAY_A);
        $righe=array_reverse($righe);
        $serial='';
        foreach($righe as $r){
            $serial.=($r['ruolo'].': '.Assistente_IA_Utilita::pulisci_testo($r['testo'])."\n");
        }
        
        // ✅ PROMPT PERSONALIZZABILE DAL PANNELLO
        $prompt_riassunto = get_option('assia_prompt_riassunto', '');
        if ( empty($prompt_riassunto) ) {
            $prompt_riassunto = self::get_default_prompt_riassunto();
        }
        
        $prompt = $prompt_riassunto . "\n\nConversazione:\n" . $serial;
        
        $res=Assistente_IA_Modello_Vertex::genera_testo($prompt);
        if(!empty($res['testo'])){
            $wpdb->update($pref.'assistente_ia_chat',[
                'riassunto_compresso'=>Assistente_IA_Utilita::tronca($res['testo'],2000),
                'ultimo_aggiornamento'=>current_time('mysql')
            ],[ 'id_chat'=>$id_chat ]);
        }
    }
    
    /**
     * ✅ NUOVO: Prompt riassunto di default GENERICO
     */
    protected static function get_default_prompt_riassunto(): string {
        return "Riassumi in 120-160 parole i punti salienti della seguente conversazione, evidenziando obiettivi dell'utente, decisioni prese e vincoli citati.";
    }
}

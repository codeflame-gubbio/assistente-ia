<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Pannello impostazioni + Diagnostica + Diagnostica Modello + Archivio conversazioni.
 * VERSIONE CON SNAPSHOT HASHATO + CHUNKING OVERLAP + MODALITÀ RECUPERO
 */
class Assistente_IA_Admin {

    public function __construct(){
        add_action( 'admin_menu', [ $this, 'aggiungi_menu' ] );
        add_action( 'admin_init', [ $this, 'registra_impostazioni' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'carica_script_admin' ] );
    
        add_action('add_meta_boxes', [ $this, 'aggiungi_meta_box_contesto' ]);
        add_action('save_post', [ $this, 'salva_meta_box_contesto' ]);
    }

public function aggiungi_menu(){
    add_menu_page(
        'Assistente IA', 'Assistente IA', 'manage_options', 'assia',
        [ $this, 'pagina_impostazioni' ], 'dashicons-format-chat', 58
    );

    add_submenu_page(
        'assia', 'Impostazioni', 'Impostazioni', 'manage_options', 'assia',
        [ $this, 'pagina_impostazioni' ]
    );

    add_submenu_page(
        'assia', 'Diagnostica', 'Diagnostica', 'manage_options', 'assia-diagnostica',
        [ $this, 'pagina_diagnostica' ]
    );

    add_submenu_page(
        'assia', 'Diagnostica Modello', 'Diagnostica Modello', 'manage_options', 'assia-diagnostica-modello',
        [ $this, 'pagina_diagnostica_modello' ]
    );

    add_submenu_page(
        'assia', 'Archivio conversazioni', 'Archivio conversazioni', 'manage_options', 'assia-archivio',
        [ $this, 'pagina_archivio' ]
    );
}

    public function registra_impostazioni(){
        $opts = array(
            'assia_progetto_id',
            'assia_localita',
            'assia_modello',
            'assia_modello_embedding',
            'assia_credenziali_base64',
            'assia_obiettivo',
            'assia_avviso',
            'assia_temperature',
            'assia_top_p',
            'assia_top_k',
            'assia_max_token',
            'assia_safety_soglie',
            'assia_attiva_google_search',
            'assia_attiva_embeddings',
            'assia_embeddings_top_k',
            'assia_embeddings_threshold',
            'assia_embeddings_solo_migliori',
            'assia_turni_modello',
            'assia_messaggi_ui',
            'assia_ttl_giorni',
            'assia_rate_limite_max',
            'assia_rate_limite_finestra_sec',
            'assia_bottone_testo',
            'assia_bottone_posizione',
            'assia_registro_modello_attivo',
            'assia_ruolo_sistema',
            'assia_inserimento_automatico_footer',
            'assia_context_wc',
            'assia_context_brief_enable',
            'assia_registro_modello_max',
            'assia_registro_modello_retention_giorni',
            // ✅ NUOVE OPZIONI
            'assia_rag_mode',              // 'best-1' o 'top-k'
            'assia_chunk_overlap',         // Overlap in parole (default: 100)
            'assia_auto_regenerate_hash',  // 'si' o 'no'
        );
        foreach($opts as $o){ register_setting('assia_opt', $o); }
    }

public function carica_script_admin( $hook ){
        if ( isset($_GET['page']) && $_GET['page'] === 'assia-diagnostica' ) {
            wp_enqueue_script('assia-admin-js', ASSIA_URL.'public/js/assia-admin.js', ['jquery'], ASSIA_VERSIONE, true );
            wp_localize_script('assia-admin-js', 'AssistenteIAAdmin', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('assistente_ia_nonce')
            ]);
            wp_enqueue_style('assia-css', ASSIA_URL.'public/css/assistente-ia.css', [], ASSIA_VERSIONE );
        }
    }

    /** ------------------ PAGINA IMPOSTAZIONI ------------------ */
    public function pagina_impostazioni(){
        if(isset($_POST['rigenera_embeddings'])&&current_user_can('manage_options')){
            check_admin_referer('assia_rigenera_embeddings'); 
            $n=Assistente_IA_RAG::rigenera_indice_post();
            echo '<div class="updated"><p>Rigenerati '.intval($n).' chunks.</p></div>';
        } ?>
        <div class="wrap"><h1>Assistente IA – Impostazioni</h1>
        <form method="post" action="options.php"><?php settings_fields('assia_opt'); ?>

        <h2>Credenziali e Modelli</h2>
        <table class="form-table">
            <tr><th>Project ID</th><td><input name="assia_progetto_id" class="regular-text" value="<?php echo esc_attr(get_option('assia_progetto_id')); ?>"></td></tr>
            <tr><th>Località</th><td><input name="assia_localita" class="regular-text" value="<?php echo esc_attr(get_option('assia_localita')); ?>"> <em>es. us-central1</em></td></tr>
            <tr><th>Modello</th><td><input name="assia_modello" class="regular-text" value="<?php echo esc_attr(get_option('assia_modello')); ?>"></td></tr>
            <tr><th>Modello Embedding</th><td><input name="assia_modello_embedding" class="regular-text" value="<?php echo esc_attr(get_option('assia_modello_embedding')); ?>"></td></tr>
            <tr>
                <th>Credenziali (JSON o Base64)</th>
                <td>
                    <textarea name="assia_credenziali_base64" rows="4" class="large-text" placeholder="Incolla il JSON del service account OPPURE il Base64"><?php echo esc_textarea(get_option('assia_credenziali_base64')); ?></textarea>
                    <p class="description">Il plugin riconosce automaticamente il formato (JSON in chiaro <strong>o</strong> Base64).</p>
                </td>
            </tr>
        </table>

        <h2>Prompt</h2>
        <table class="form-table">
            <tr><th>Obiettivo</th><td><textarea name="assia_obiettivo" rows="3" class="large-text"><?php echo esc_textarea(get_option('assia_obiettivo')); ?></textarea></td></tr>
            <tr><th>Avviso</th><td><textarea name="assia_avviso" rows="3" class="large-text"><?php echo esc_textarea(get_option('assia_avviso')); ?></textarea></td></tr>
            <tr><th>Ruolo di sistema (anti-disclaimer)</th>
                <td><textarea name="assia_ruolo_sistema" rows="5" class="large-text" placeholder="Istruzioni persistenti per lo stile delle risposte"><?php echo esc_textarea(get_option('assia_ruolo_sistema','')); ?></textarea>
                <p class="description">Se vuoto, viene usato un testo predefinito che obbliga all'uso del Contesto e vieta disclaimer generici.</p></td>
            </tr>
        </table>

        <h2>Generazione & Sicurezza</h2>
        <table class="form-table">
            <tr><th>Temperature</th><td><input type="number" step="0.01" name="assia_temperature" value="<?php echo esc_attr(get_option('assia_temperature')); ?>"></td></tr>
            <tr><th>maxOutputTokens</th><td><input type="number" name="assia_max_token" value="<?php echo esc_attr(get_option('assia_max_token')); ?>"></td></tr>
            <tr><th>topP</th><td><input type="number" step="0.01" name="assia_top_p" value="<?php echo esc_attr(get_option('assia_top_p')); ?>"></td></tr>
            <tr><th>topK</th><td><input type="number" name="assia_top_k" value="<?php echo esc_attr(get_option('assia_top_k')); ?>"></td></tr>
            <tr><th>Safety</th><td>
                <?php $ss=(array)get_option('assia_safety_soglie'); 
                $cats=['sexually_explicit','hate_speech','harassment','dangerous_content'];
                $opts=['BLOCK_NONE','BLOCK_ONLY_HIGH','BLOCK_MEDIUM_AND_ABOVE','BLOCK_LOW_AND_ABOVE'];
                foreach($cats as $c){ $v=$ss[$c]??'BLOCK_NONE';
                    echo '<label>'.esc_html($c).': <select name="assia_safety_soglie['.esc_attr($c).']">';
                    foreach($opts as $opt){ echo '<option '.selected($v,$opt,false).' value="'.esc_attr($opt).'">'.esc_html($opt).'</option>'; }
                    echo '</select></label><br>';
                } ?>
            </td></tr>
            <tr><th>Google Search</th><td><select name="assia_attiva_google_search"><option value="no" <?php selected(get_option('assia_attiva_google_search'),'no');?>>No</option><option value="si" <?php selected(get_option('assia_attiva_google_search'),'si');?>>Sì</option></select></td></tr>
        </table>

        <h2>RAG / Embeddings</h2>
        <table class="form-table">
            <tr><th>Attiva embeddings</th><td>
                <select name="assia_attiva_embeddings">
                    <option value="si" <?php selected(get_option('assia_attiva_embeddings','si'),'si');?>>Sì</option>
                    <option value="no" <?php selected(get_option('assia_attiva_embeddings','si'),'no');?>>No</option>
                </select>
            </td></tr>
            
            <!-- ✅ NUOVA OPZIONE: Modalità recupero -->
            <tr>
                <th>Modalità recupero</th>
                <td>
                    <select name="assia_rag_mode">
                        <option value="top-k" <?php selected(get_option('assia_rag_mode','top-k'),'top-k');?>>Top-K (multipli chunk)</option>
                        <option value="best-1" <?php selected(get_option('assia_rag_mode','top-k'),'best-1');?>>Best-1 (singolo migliore)</option>
                    </select>
                    <p class="description">
                        <strong>Top-K:</strong> Recupera i 3-10 chunk più rilevanti (ideale per documentazione complessa)<br>
                        <strong>Best-1:</strong> Solo il chunk migliore (risparmia token, ideale per e-commerce semplice)
                    </p>
                </td>
            </tr>
            
            <tr><th>Top-K chunks</th><td>
                <input type="number" name="assia_embeddings_top_k" value="<?php echo esc_attr(get_option('assia_embeddings_top_k',3)); ?>" min="1" max="20">
                <p class="description">Numero massimo di chunk da recuperare (solo se modalità = Top-K)</p>
            </td></tr>
            
            <tr><th>Threshold similarità minima</th><td>
                <input type="number" step="0.01" name="assia_embeddings_threshold" value="<?php echo esc_attr(get_option('assia_embeddings_threshold','0.30')); ?>" min="0" max="1">
                <p class="description">Score minimo per considerare un chunk rilevante (0.30 consigliato). Valori più alti = più selettivo.</p>
            </td></tr>
            
            <!-- ✅ NUOVA OPZIONE: Overlap chunking -->
            <tr>
                <th>Overlap chunking (parole)</th>
                <td>
                    <input type="number" name="assia_chunk_overlap" value="<?php echo esc_attr(get_option('assia_chunk_overlap',100)); ?>" min="0" max="300">
                    <p class="description">
                        Parole di sovrapposizione tra chunk consecutivi (default: 100).<br>
                        Migliora la qualità del recupero mantenendo contesto tra chunk.<br>
                        0 = disattiva overlap (vecchio comportamento)
                    </p>
                </td>
            </tr>
            
            <!-- ✅ NUOVA OPZIONE: Auto-rigenerazione -->
            <tr>
                <th>Rigenerazione intelligente</th>
                <td>
                    <select name="assia_auto_regenerate_hash">
                        <option value="si" <?php selected(get_option('assia_auto_regenerate_hash','si'),'si');?>>Sì (consigliato)</option>
                        <option value="no" <?php selected(get_option('assia_auto_regenerate_hash','si'),'no');?>>No</option>
                    </select>
                    <p class="description">
                        Se attivo, rigenera automaticamente solo i contenuti modificati confrontando hash MD5.<br>
                        <strong>Vantaggi:</strong> Risparmia tempo e API calls, rileva automaticamente post/prodotti aggiornati.
                    </p>
                </td>
            </tr>
        </table>

        <h2>Widget chat</h2>
        <table class="form-table">
            <tr><th>Titolo bottone flottante</th><td><input name="assia_bottone_testo" class="regular-text" value="<?php echo esc_attr(get_option('assia_bottone_testo','Chatta con noi')); ?>"></td></tr>
            <tr><th>Posizione bottone</th><td>
                <select name="assia_bottone_posizione">
                    <option value="bottom-right" <?php selected(get_option('assia_bottone_posizione'),'bottom-right');?>>In basso a destra</option>
                    <option value="bottom-left" <?php selected(get_option('assia_bottone_posizione'),'bottom-left');?>>In basso a sinistra</option>
                </select>
            </td></tr>
        <tr><th>Inserimento automatico nel footer</th><td>
                <select name="assia_inserimento_automatico_footer">
                    <option value="1" <?php selected(get_option('assia_inserimento_automatico_footer','1'),'1'); ?>>Sì (predefinito)</option>
                    <option value="0" <?php selected(get_option('assia_inserimento_automatico_footer','1'),'0'); ?>>No (usa solo shortcode)</option>
                </select>
            </td></tr>
        </table>

        <h2>Contesto intelligente</h2>
        <table class="form-table">
            <tr><th>Usa contesto WooCommerce</th><td>
                <select name="assia_context_wc">
                    <option value="si" <?php selected(get_option('assia_context_wc','si'),'si'); ?>>Sì (predefinito)</option>
                    <option value="no" <?php selected(get_option('assia_context_wc','si'),'no'); ?>>No</option>
                </select>
                <p class="description">Se sei su una pagina prodotto pubblica, include nel prompt SKU, prezzo e categorie.</p>
            </td></tr>
            <tr><th>Usa mini-brief per pagina</th><td>
                <select name="assia_context_brief_enable">
                    <option value="si" <?php selected(get_option('assia_context_brief_enable','si'),'si'); ?>>Sì (predefinito)</option>
                    <option value="no" <?php selected(get_option('assia_context_brief_enable','si'),'no'); ?>>No</option>
                </select>
                <p class="description">Aggiungi un contesto specifico (testo libero) per la pagina/post (solo se pubblica).</p>
            </td></tr>
        </table>

        <h2>Registro Modello (diagnostica)</h2>
        <table class="form-table">
            <tr><th>Abilita registro modello</th><td>
                <select name="assia_registro_modello_attivo">
                    <option value="no" <?php selected(get_option('assia_registro_modello_attivo'),'no');?>>No</option>
                    <option value="si" <?php selected(get_option('assia_registro_modello_attivo'),'si');?>>Sì</option>
                </select>
                <p class="description">Se attivo, ogni richiesta/risposta al modello viene registrata in una tabella dedicata. Consulta "Diagnostica Modello".</p>
            </td></tr>
        </table>

        <?php submit_button('Salva impostazioni'); ?></form>

        <hr><h2>Indice Embeddings (modalità sincrona – legacy)</h2>
        <form method="post"><?php wp_nonce_field('assia_rigenera_embeddings'); ?>
            <p>Rigenera l'indice dagli articoli/pagine pubblicati (operazione sincrona).</p>
            <p><button class="button" name="rigenera_embeddings" value="1">Rigenera ora</button></p>
        </form>
        </div><?php
    }

    /** ------------------ PAGINA DIAGNOSTICA CLASSICA ------------------ */
    public function pagina_diagnostica(){
        if ( isset($_POST['assia_esegui_test']) ){
            check_admin_referer('assia_diagnostica');
            $esiti = $this->esegui_batteria_test();
            $diagEmb = $this->diagnostica_embeddings(); ?>
            <div class="wrap"><h1>Diagnostica – Risultati</h1>
            <?php foreach($esiti as $e): $class = $e['ok'] ? 'updated' : 'error'; ?>
                <div class="<?php echo esc_attr($class); ?>"><p><strong><?php echo esc_html($e['titolo']); ?></strong><br><?php echo esc_html($e['messaggio']); ?></p></div>
            <?php endforeach; ?>
            <h2>Embeddings – Analisi</h2>
            <pre style="background:#fff;border:1px solid #eee;padding:12px;border-radius:8px;overflow:auto;"><?php echo esc_html( print_r( $diagEmb, true ) ); ?></pre>
            <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=assia-diagnostica')); ?>">Torna alla Diagnostica</a></p>
            </div><?php
            return;
        }
        $log = get_option('assia_log_embeddings', []); ?>
        <div class="wrap">
            <h1>Assistente IA – Diagnostica</h1>
            <p>Esegui test su credenziali, token OAuth2, modelli Vertex AI e embeddings.</p>
            <form method="post"><?php wp_nonce_field('assia_diagnostica'); ?><p><button class="button button-primary" name="assia_esegui_test" value="1">Esegui test</button></p></form>

            <hr>
            <h2>Embeddings – Rigenerazione con Progresso (AJAX)</h2>
            <p>Prepara un job e processa a step, con messaggi e storico.</p>
            <p><button id="assia-emb-avvia" class="button button-primary">Avvia rigenerazione</button> <button id="assia-emb-step" class="button" disabled>Elabora step</button></p>
            <div id="assia-emb-stato" style="background:#fff;border:1px solid #eee;border-radius:8px;padding:12px;min-height:40px;"></div>

            <hr>
            <h2>Storico rigenerazioni</h2>
            <?php if ( is_array($log) && !empty($log) ): ?>
                <table class="widefat striped">
                    <thead><tr><th>Avviato</th><th>Completato</th><th>Modello</th><th>Post</th><th>Chunks</th><th>Errori</th></tr></thead>
                    <tbody>
                    <?php foreach($log as $r): ?>
                        <tr>
                            <td><?php echo esc_html($r['avviato_il'] ?? ''); ?></td>
                            <td><?php echo esc_html($r['completato_il'] ?? ''); ?></td>
                            <td><?php echo esc_html($r['modello'] ?? ''); ?></td>
                            <td><?php echo esc_html($r['tot_post'] ?? '0'); ?></td>
                            <td><?php echo esc_html($r['chunks_creati'] ?? '0'); ?></td>
                            <td><?php echo esc_html( is_array($r['errori'] ?? []) ? implode('; ',$r['errori']) : '' ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Nessun log disponibile.</p>
            <?php endif; ?>
        </div><?php
    }

    /** ------------------ PAGINA DIAGNOSTICA MODELLO (registro DB) ------------------ */
    public function pagina_diagnostica_modello(){
        if ( isset($_POST['assia_pulisci_registro']) && current_user_can('manage_options') ){
            check_admin_referer('assia_pulisci_registro');
            global $wpdb; $pref=$wpdb->prefix;
            $wpdb->query("TRUNCATE TABLE {$pref}assistente_ia_diag_modello");
            echo '<div class="updated"><p>Registro svuotato.</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>Diagnostica Modello – Registro</h1>
            <p>Stato: <strong><?php echo ('si'===get_option('assia_registro_modello_attivo','no'))?'ATTIVO':'DISATTIVATO'; ?></strong></p>
            <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=assia')); ?>">Vai alle Impostazioni</a></p>
            <form method="post" style="margin-top:10px"><?php wp_nonce_field('assia_pulisci_registro'); ?>
                <button class="button" name="assia_pulisci_registro" value="1" onclick="return confirm('Sicuro di svuotare il registro?');">Svuota registro</button>
            </form>
            <hr>
            <?php
            global $wpdb; $pref=$wpdb->prefix;
            $per_page = 20;
            $pagina = max(1, (int)($_GET['paged'] ?? 1));
            $offset = ($pagina-1)*$per_page;

            $f_tipo = isset($_GET['tipo']) && in_array($_GET['tipo'],['generate','embed'],true) ? $_GET['tipo'] : '';
            $where = '1=1';
            if ( $f_tipo ) $where .= $wpdb->prepare(" AND tipo=%s", $f_tipo);

            $tot = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$pref}assistente_ia_diag_modello WHERE $where");
            $righe = $wpdb->get_results( $wpdb->prepare("SELECT * FROM {$pref}assistente_ia_diag_modello WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d",$per_page,$offset), ARRAY_A );

            echo '<form method="get"><input type="hidden" name="page" value="assia-diagnostica-modello">';
            echo 'Filtro tipo: <select name="tipo">';
            echo '<option value="">Tutti</option>';
            echo '<option value="generate" '.selected($f_tipo,'generate',false).'>generate</option>';
            echo '<option value="embed" '.selected($f_tipo,'embed',false).'>embed</option>';
            echo '</select> <button class="button">Filtra</button></form>';

            if ( $righe ){
                echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Quando</th><th>Tipo</th><th>Chat</th><th>Hash</th><th>HTTP</th><th>Errore</th><th>Dettaglio</th></tr></thead><tbody>';
                foreach($righe as $r){
                    $link = esc_url( add_query_arg(['page'=>'assia-diagnostica-modello','dettaglio'=>$r['id']], admin_url('admin.php')) );
                    echo '<tr>';
                    echo '<td>'.intval($r['id']).'</td>';
                    echo '<td>'.esc_html($r['creato_il']).'</td>';
                    echo '<td>'.esc_html($r['tipo']).'</td>';
                    echo '<td>'.esc_html($r['id_chat']).'</td>';
                    echo '<td>'.esc_html($r['hash_sessione']).'</td>';
                    echo '<td>'.esc_html($r['http_code']).'</td>';
                    echo '<td>'.esc_html($r['errore']).'</td>';
                    echo '<td><a class="button" href="'.$link.'">Apri</a></td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';

                // Paginazione semplice
                $pagine = max(1, ceil($tot/$per_page));
                if ( $pagine > 1 ){
                    echo '<p>';
                    for($i=1;$i<=$pagine;$i++){
                        $url = esc_url( add_query_arg(['page'=>'assia-diagnostica-modello','paged'=>$i,'tipo'=>$f_tipo], admin_url('admin.php')) );
                        if($i===$pagina){ echo '<strong>['.$i.']</strong> '; } else { echo '<a href="'.$url.'">'.$i.'</a> '; }
                    }
                    echo '</p>';
                }
            } else {
                echo '<p>Nessun record trovato.</p>';
            }

            // Dettaglio
            if ( isset($_GET['dettaglio']) ){
                $id = (int)$_GET['dettaglio'];
                $row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$pref}assistente_ia_diag_modello WHERE id=%d",$id), ARRAY_A );
                if($row){
                    $payload_pp = self::pp_json($row['payload']);
                    $risposta_pp = self::pp_json($row['risposta']);
                    echo '<hr><h2>Dettaglio #'.intval($row['id']).'</h2>';
                    echo '<p><strong>Endpoint:</strong> '.esc_html($row['endpoint']).'</p>';
                    echo '<p><strong>Chat:</strong> '.esc_html($row['id_chat']).' | <strong>Hash:</strong> '.esc_html($row['hash_sessione']).'</p>';
                    echo '<h3>Payload</h3><pre style="white-space:pre-wrap;background:#fff;border:1px solid #eee;padding:12px;border-radius:8px;overflow:auto;">'.esc_html($payload_pp).'</pre>';
                    echo '<h3>Risposta</h3><pre style="white-space:pre-wrap;background:#fff;border:1px solid #eee;padding:12px;border-radius:8px;overflow:auto;">'.esc_html($risposta_pp).'</pre>';
                }
            }
            ?>
        </div>
        <?php
    }

    /** Helper pretty-print JSON (anche se è plain text) */
    protected static function pp_json( $txt ): string {
        if ( is_array($txt) || is_object($txt) ) $txt = wp_json_encode($txt);
        $txt = (string)$txt;
        $dec = json_decode($txt, true);
        if ( is_array($dec) ) return (string) wp_json_encode($dec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return $txt;
    }

    /** ------------------ PAGINA ARCHIVIO CONVERSAZIONI ------------------ */
    public function pagina_archivio(){
        
        // Gestione svuota archivio (v5.1+)
        if ( isset($_POST['assia_svuota_archivio']) ){
            if ( ! current_user_can('manage_options') ) { wp_die('Permessi insufficienti'); }
            check_admin_referer('assia_svuota_archivio');
            global $wpdb; $pref=$wpdb->prefix;
            $ok1 = $wpdb->query("TRUNCATE TABLE {$pref}assistente_ia_messaggi");
            if ($ok1 === false){ $wpdb->query("DELETE FROM {$pref}assistente_ia_messaggi"); }
            $ok2 = $wpdb->query("TRUNCATE TABLE {$pref}assistente_ia_chat");
            if ($ok2 === false){ $wpdb->query("DELETE FROM {$pref}assistente_ia_chat"); }
            echo '<div class="updated"><p>Archivio conversazioni svuotato.</p></div>';
        }
global $wpdb; $pref=$wpdb->prefix;

        // Vista dettaglio chat
        if ( isset($_GET['chat']) ){
            $chat_id = (int) $_GET['chat'];
            $chat = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$pref}assistente_ia_chat WHERE id_chat=%d",$chat_id), ARRAY_A );
            $msgs = $wpdb->get_results( $wpdb->prepare("SELECT ruolo, testo, creato_il FROM {$pref}assistente_ia_messaggi WHERE id_chat=%d ORDER BY id_messaggio ASC",$chat_id), ARRAY_A );
            ?>
            <div class="wrap">
                <h1>Conversazione #<?php echo intval($chat_id); ?></h1>
                <?php if ($chat): ?>
                    <p><strong>Hash sessione:</strong> <?php echo esc_html($chat['hash_sessione']); ?> |
                       <strong>Creato il:</strong> <?php echo esc_html($chat['data_creazione']); ?> |
                       <strong>Ultimo aggiornamento:</strong> <?php echo esc_html($chat['ultimo_aggiornamento']); ?></p>
                <?php endif; ?>
                <table class="widefat striped">
                    <thead><tr><th>Quando</th><th>Ruolo</th><th>Testo</th></tr></thead>
                    <tbody>
                    <?php foreach($msgs as $m): ?>
                        <tr>
                            <td><?php echo esc_html($m['creato_il']); ?></td>
                            <td><?php echo esc_html($m['ruolo']); ?></td>
                            <td><?php echo wp_kses_post($m['testo']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=assia-archivio')); ?>">Torna all'archivio</a></p>
            </div>
            <?php
            return;
        }

        // Vista elenco
        $per_page = 20;
        $pagina = max(1, (int)($_GET['paged'] ?? 1));
        $offset = ($pagina-1)*$per_page;

        $tot = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$pref}assistente_ia_chat");
        $righe = $wpdb->get_results( $wpdb->prepare("
            SELECT c.*, (
                SELECT COUNT(*) FROM {$pref}assistente_ia_messaggi m WHERE m.id_chat=c.id_chat
            ) AS n_messaggi
            FROM {$pref}assistente_ia_chat c
            ORDER BY c.ultimo_aggiornamento DESC
            LIMIT %d OFFSET %d
        ", $per_page, $offset), ARRAY_A );

        ?>
        <div class="wrap">
            <h1>Archivio conversazioni</h1>
        <form method="post" style="margin:12px 0 18px 0;" onsubmit="return confirm('Confermi di svuotare tutte le conversazioni? Questa azione è irreversibile.');">
            <?php wp_nonce_field('assia_svuota_archivio'); ?>
            <button type="submit" name="assia_svuota_archivio" class="button button-danger" style="background:#dc2626;border-color:#dc2626;color:#fff;">Svuota archivio conversazioni</button>
        </form>
            <?php if ($righe): ?>
                <table class="widefat striped">
                    <thead><tr><th>ID</th><th>Hash sessione</th><th>Creato</th><th>Ultimo agg.</th><th># messaggi</th><th>Azione</th></tr></thead>
                    <tbody>
                    <?php foreach($righe as $r): 
                        $link = esc_url( add_query_arg(['page'=>'assia-archivio','chat'=>$r['id_chat']], admin_url('admin.php')) ); ?>
                        <tr>
                            <td><?php echo intval($r['id_chat']); ?></td>
                            <td><?php echo esc_html($r['hash_sessione']); ?></td>
                            <td><?php echo esc_html($r['data_creazione']); ?></td>
                            <td><?php echo esc_html($r['ultimo_aggiornamento']); ?></td>
                            <td><?php echo intval($r['n_messaggi']); ?></td>
                            <td><a class="button" href="<?php echo $link; ?>">Apri</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php
                $pagine = max(1, ceil($tot/$per_page));
                if ( $pagine > 1 ){
                    echo '<p>';
                    for($i=1;$i<=$pagine;$i++){
                        $url = esc_url( add_query_arg(['page'=>'assia-archivio','paged'=>$i], admin_url('admin.php')) );
                        if($i===$pagina){ echo '<strong>['.$i.']</strong> '; } else { echo '<a href="'.$url.'">'.$i.'</a> '; }
                    }
                    echo '</p>';
                }
                ?>
            <?php else: ?>
                <p>Nessuna conversazione trovata.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    /** ------------------ TEST E DIAGNOSTICA EMBEDDINGS ------------------ */
    protected function esegui_batteria_test(): array {
        $esiti = [];
        $cred = Assistente_IA_Token::diagnostica_credenziali();
        $esiti[] = [ 'titolo'=>'Lettura credenziali (JSON/Base64)', 'ok'=>(bool)$cred['ok'], 'messaggio'=>$cred['note'] ];

        $token = Assistente_IA_Token::genera_token_accesso();
        $ok_token = ! empty($token);
        $esiti[] = [ 'titolo'=>'Richiesta token OAuth2', 'ok'=>$ok_token, 'messaggio'=> $ok_token ? 'Token ottenuto correttamente.' : 'Errore nel generare il token. Verifica service account (ruolo aiplatform.user) e API Vertex.' ];

        $gen = $ok_token ? Assistente_IA_Modello_Vertex::genera_testo('Scrivi solo: OK') : ['errore'=>'Token mancante'];
        if ( empty($gen['errore']) ) {
            $ok_gen = (stripos($gen['testo'] ?? '', 'OK') !== false) || !empty($gen['testo']);
            $esiti[] = [ 'titolo'=>'Chiamata generateContent', 'ok'=>$ok_gen, 'messaggio'=> $ok_gen ? 'Risposta ricevuta dal modello.' : 'Nessun testo ricevuto; controlla modello/località.' ];
        } else {
            $esiti[] = [ 'titolo'=>'Chiamata generateContent', 'ok'=>false, 'messaggio'=>'Errore: '.$gen['errore'] ];
        }

        $emb = $ok_token ? Assistente_IA_Modello_Vertex::calcola_embedding('prova di embedding') : ['errore'=>'Token mancante'];
        if ( empty($emb['errore']) ) {
            $ok_emb = is_array($emb['vettore'] ?? null) && count($emb['vettore']) > 0;
            $esiti[] = [ 'titolo'=>'Chiamata embeddings (predict)', 'ok'=>$ok_emb, 'messaggio'=> $ok_emb ? 'Embedding ricevuto correttamente.' : 'Embedding vuoto; verifica modello embedding e località.' ];
        } else {
            $esiti[] = [ 'titolo'=>'Chiamata embeddings (predict)', 'ok'=>false, 'messaggio'=>'Errore: '.$emb['errore'] ];
        }
        return $esiti;
    }

    protected function diagnostica_embeddings(): array {
        global $wpdb; $pref = $wpdb->prefix;
        $modello = get_option('assia_modello_embedding','text-embedding-005');
        $res = [
            'modello' => $modello,
            'righe_totali' => 0,
            'dimensione_vettori' => null,
            'dimensione_vettori_attesa' => null,
            'copertura_post' => ['indicizzati'=>0,'pubblicati'=>0,'percentuale'=>0],
            'duplicati_chunks' => 0,
            'medio_caratteri_chunk' => null,
            'stale_post' => 0,
            'stale_lista' => [],
        ];

        $res['righe_totali'] = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM {$pref}assistente_ia_embeddings WHERE modello=%s", $modello) );
        $row = $wpdb->get_row( $wpdb->prepare("SELECT embedding FROM {$pref}assistente_ia_embeddings WHERE modello=%s LIMIT 1", $modello), ARRAY_A );
        if ( $row && !empty($row['embedding']) ) {
            $vec = json_decode( $row['embedding'], true );
            if ( is_array($vec) ) $res['dimensione_vettori'] = count($vec);
        }
        $emb = Assistente_IA_Modello_Vertex::calcola_embedding('test');
        if ( empty($emb['errore']) && is_array($emb['vettore']) ) {
            $res['dimensione_vettori_attesa'] = count($emb['vettore']);
        }
        $pubblicati = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('post','page')");
        $indicizzati = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(DISTINCT id_riferimento) FROM {$pref}assistente_ia_embeddings WHERE modello=%s AND fonte='post'", $modello) );
        $res['copertura_post'] = [
            'indicizzati' => $indicizzati,
            'pubblicati'  => $pubblicati,
            'percentuale' => ($pubblicati>0) ? round(($indicizzati/$pubblicati)*100,1) : 0
        ];

        $duplicati = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM ( SELECT MD5(testo_chunk) h, COUNT(*) c FROM {$pref}assistente_ia_embeddings WHERE modello=%s GROUP BY h HAVING c>1 ) t", $modello
        ) );
        $res['duplicati_chunks'] = $duplicati;

        $medio = (int) $wpdb->get_var( $wpdb->prepare("SELECT AVG(CHAR_LENGTH(testo_chunk)) FROM {$pref}assistente_ia_embeddings WHERE modello=%s", $modello) );
        $res['medio_caratteri_chunk'] = $medio;

        $ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('post','page')");
        $stale = [];
        foreach( $ids as $pid ){
            $ult = $wpdb->get_var( $wpdb->prepare("SELECT MAX(creato_il) FROM {$pref}assistente_ia_embeddings WHERE modello=%s AND fonte='post' AND id_riferimento=%d", $modello, $pid) );
            if ( empty($ult) ) { $stale[] = (int)$pid; continue; }
            $mod = get_post_field('post_modified', (int)$pid );
            if ( $mod && strtotime($mod) > strtotime($ult) ) { $stale[] = (int)$pid; }
        }
        $res['stale_post'] = count($stale);
        $res['stale_lista'] = $stale;
        return $res;
    }
/** Meta-box: Contesto specifico della pagina (mini-brief) */
    public function aggiungi_meta_box_contesto(){
        if ( 'no' === get_option('assia_context_brief_enable','si') ) { return; }
        $screens = apply_filters('assia_context_brief_screens', ['post','page','product']);
        foreach($screens as $scr){
            add_meta_box(
                'assia_context_brief_mb',
                'Assistente IA – Contesto specifico',
                [ $this, 'render_meta_box_contesto' ],
                $scr,
                'normal',
                'default'
            );
        }
    }

    public function render_meta_box_contesto( $post ){
        if ( 'no' === get_option('assia_context_brief_enable','si') ) {
            echo '<p style="color:#6b7280">Il mini-brief è disabilitato nelle impostazioni.</p>';
            return;
        }
        $val = get_post_meta($post->ID, 'assia_context_brief', true);
        $val = is_string($val) ? esc_textarea($val) : '';
        wp_nonce_field('assia_context_brief_save','assia_context_brief_nonce');
        echo '<p style="margin-top:0">Scrivi qui un contesto editoriale operativo che l\'assistente deve considerare per questa pagina/prodotto (es. tono, USP, CTA, vincoli).</p>';
        echo '<textarea name="assia_context_brief" rows="6" style="width:100%;">'.$val.'</textarea>';
        echo '<p class="description">Questo testo verrà incluso nel prompt solo se la pagina è pubblica e l\'opzione è attiva.</p>';
    }

    public function salva_meta_box_contesto( $post_id ){
        if ( ! isset($_POST['assia_context_brief_nonce']) ) return;
        if ( ! wp_verify_nonce( $_POST['assia_context_brief_nonce'], 'assia_context_brief_save' ) ) return;
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( ! current_user_can('edit_post', $post_id) ) return;

        $raw = isset($_POST['assia_context_brief']) ? wp_unslash($_POST['assia_context_brief']) : '';
        if ( is_string($raw) ) {
            $san = wp_kses_post( $raw );
            if ( trim($san) === '' ){
                delete_post_meta($post_id, 'assia_context_brief');
            } else {
                update_post_meta($post_id, 'assia_context_brief', $san);
            }
        }
    }
}
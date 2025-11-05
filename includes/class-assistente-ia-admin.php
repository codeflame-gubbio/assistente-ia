<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Pannello impostazioni + Diagnostica + Diagnostica Modello + Archivio conversazioni.
 * VERSIONE CORRETTA v5.4.1:
 * - Validazione/sanitizzazione completa input
 * - Chunk size configurabile
 * - Pulizia automatica conversazioni vecchie
 * - Boolean standardizzati (si/no)
 * - Rimossa opzione zombie assia_embeddings_solo_migliori
 */
class Assistente_IA_Admin {

    public function __construct(){
        add_action( 'admin_menu', [ $this, 'aggiungi_menu' ] );
        add_action( 'admin_init', [ $this, 'registra_impostazioni' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'carica_script_admin' ] );
    
        add_action('add_meta_boxes', [ $this, 'aggiungi_meta_box_contesto' ]);
        add_action('save_post', [ $this, 'salva_meta_box_contesto' ]);
        
        // CRON per pulizia conversazioni vecchie
        if ( ! wp_next_scheduled( 'assia_cleanup_old_chats' ) ) {
            wp_schedule_event( time(), 'daily', 'assia_cleanup_old_chats' );
        }
        add_action( 'assia_cleanup_old_chats', [ $this, 'pulisci_conversazioni_vecchie' ] );
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
            'assia_istruzioni_stile', // ✅ NUOVO v5.5.0 - Prompt stile personalizzabile
            'assia_prompt_riassunto', // ✅ NUOVO v5.5.0 - Prompt riassunto personalizzabile
            'assia_temperature',
            'assia_top_p',
            'assia_top_k',
            'assia_max_token',
            'assia_safety_soglie',
            'assia_attiva_google_search',
            'assia_attiva_embeddings',
            'assia_embeddings_top_k',
            'assia_embeddings_threshold',
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
            'assia_registro_modello_retention_giorni',
            'assia_rag_mode',
            'assia_chunk_overlap',
            'assia_chunk_size',
            'assia_auto_regenerate_hash',
            'assia_rag_context_window', // ✅ NUOVO v5.6.0 - Context Window per chunk adiacenti
        );
        
        foreach($opts as $o){ 
            register_setting('assia_opt', $o, [
                'sanitize_callback' => [ $this, 'sanitize_option' ]
            ]); 
        }
    }

    /**
     * ✅ NUOVA FUNZIONE: Sanitizzazione/validazione completa
     */
    public function sanitize_option( $value ) {
        $option = isset($_POST['option_page']) ? $_POST['option_page'] : '';
        
        // Identifica quale opzione stiamo sanitizzando
        foreach ( $_POST as $key => $val ) {
            if ( strpos($key, 'assia_') === 0 && $val === $value ) {
                return $this->sanitize_by_type( $key, $value );
            }
        }
        
        return $value;
    }
    
    protected function sanitize_by_type( $option_name, $value ) {
        // Float (0-1)
        $float_options = ['assia_temperature', 'assia_top_p', 'assia_embeddings_threshold'];
        if ( in_array($option_name, $float_options) ) {
            $val = floatval($value);
            return max(0.0, min(1.0, $val));
        }
        
        // Integer positivi
        $int_options = [
            'assia_top_k', 'assia_max_token', 'assia_embeddings_top_k', 
            'assia_turni_modello', 'assia_messaggi_ui', 'assia_ttl_giorni',
            'assia_rate_limite_max', 'assia_rate_limite_finestra_sec',
            'assia_chunk_overlap', 'assia_chunk_size', 'assia_registro_modello_retention_giorni',
            'assia_rag_context_window' // ✅ NUOVO v5.6.0
        ];
        if ( in_array($option_name, $int_options) ) {
            return max(0, intval($value));
        }
        
        // Enum si/no
        $bool_options = [
            'assia_attiva_google_search', 'assia_attiva_embeddings',
            'assia_registro_modello_attivo', 'assia_inserimento_automatico_footer',
            'assia_context_wc', 'assia_context_brief_enable', 'assia_auto_regenerate_hash'
        ];
        if ( in_array($option_name, $bool_options) ) {
            // Accetta anche '1'/'0' e converte in 'si'/'no'
            if ( $value === '1' || $value === 1 || $value === true ) return 'si';
            if ( $value === '0' || $value === 0 || $value === false ) return 'no';
            return in_array($value, ['si', 'no']) ? $value : 'no';
        }
        
        // Enum posizione
        if ( $option_name === 'assia_bottone_posizione' ) {
            return in_array($value, ['bottom-right', 'bottom-left']) ? $value : 'bottom-right';
        }
        
        // Enum RAG mode
        if ( $option_name === 'assia_rag_mode' ) {
            return in_array($value, ['best-1', 'top-k']) ? $value : 'top-k';
        }
        
        // Array (safety)
        if ( $option_name === 'assia_safety_soglie' && is_array($value) ) {
            $allowed = ['BLOCK_NONE', 'BLOCK_ONLY_HIGH', 'BLOCK_MEDIUM_AND_ABOVE', 'BLOCK_LOW_AND_ABOVE'];
            foreach ( $value as $k => $v ) {
                if ( ! in_array($v, $allowed) ) {
                    $value[$k] = 'BLOCK_NONE';
                }
            }
            return $value;
        }
        
        // Text/textarea (sanitize)
        if ( in_array($option_name, ['assia_obiettivo', 'assia_avviso', 'assia_ruolo_sistema', 'assia_istruzioni_stile', 'assia_prompt_riassunto']) ) {
            return wp_kses_post($value);
        }
        
        // Default: sanitize_text_field
        return is_string($value) ? sanitize_text_field($value) : $value;
    }

    /**
     * ✅ NUOVA FUNZIONE: Pulizia automatica conversazioni vecchie
     */
    public function pulisci_conversazioni_vecchie() {
        $ttl_giorni = (int) get_option('assia_ttl_giorni', 30);
        if ( $ttl_giorni <= 0 ) return; // Disabilitato
        
        global $wpdb;
        $pref = $wpdb->prefix;
        $data_limite = date('Y-m-d H:i:s', strtotime("-{$ttl_giorni} days"));
        
        // Recupera ID chat vecchie
        $chat_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id_chat FROM {$pref}assistente_ia_chat WHERE ultimo_aggiornamento < %s",
            $data_limite
        ));
        
        if ( empty($chat_ids) ) return;
        
        // Elimina messaggi
        $placeholders = implode(',', array_fill(0, count($chat_ids), '%d'));
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$pref}assistente_ia_messaggi WHERE id_chat IN ($placeholders)",
            ...$chat_ids
        ));
        
        // Elimina chat
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$pref}assistente_ia_chat WHERE id_chat IN ($placeholders)",
            ...$chat_ids
        ));
        
        error_log("ASSIA: Pulite " . count($chat_ids) . " conversazioni più vecchie di {$ttl_giorni} giorni");
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
        ?>
        
        <style>
            .assia-settings-wrap { max-width: 1200px; margin: 20px 0; }
            .assia-settings-header { margin-bottom: 30px; }
            .assia-settings-title { font-size: 32px; font-weight: 600; color: #1e293b; margin: 0 0 10px 0; }
            .assia-settings-subtitle { color: #64748b; font-size: 16px; }
            
            .assia-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            .assia-card-title { font-size: 20px; font-weight: 600; color: #1e293b; margin: 0 0 20px 0; padding-bottom: 12px; border-bottom: 2px solid #f1f5f9; display: flex; align-items: center; gap: 10px; }
            .assia-card-icon { font-size: 24px; }
            
            .assia-form-group { margin-bottom: 24px; }
            .assia-form-group:last-child { margin-bottom: 0; }
            .assia-label { display: block; font-weight: 600; color: #475569; margin-bottom: 8px; font-size: 14px; }
            .assia-input { width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; transition: all 0.2s; }
            .assia-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
            .assia-textarea { width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; transition: all 0.2s; resize: vertical; }
            .assia-textarea:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
            .assia-select { padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; background: #fff; cursor: pointer; transition: all 0.2s; }
            .assia-select:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
            
            .assia-help { color: #64748b; font-size: 13px; margin-top: 6px; line-height: 1.5; }
            .assia-help strong { color: #475569; }
            .assia-help-box { background: #f8fafc; border-left: 4px solid #3b82f6; padding: 12px 16px; border-radius: 4px; margin-top: 8px; }
            
            .assia-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
            .assia-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
            @media (max-width: 768px) { .assia-grid-2 { grid-template-columns: 1fr; } }
            
            .assia-submit-wrapper { position: sticky; bottom: 20px; background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 -2px 10px rgba(0,0,0,0.1); margin-top: 30px; display: flex; justify-content: space-between; align-items: center; }
            .assia-submit { background: #3b82f6; color: #fff; padding: 12px 24px; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
            .assia-submit:hover { background: #2563eb; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59,130,246,0.3); }
            .assia-submit:active { transform: translateY(0); }
        </style>
        
        <div class="wrap assia-settings-wrap">
            <div class="assia-settings-header">
                <h1 class="assia-settings-title">⚙️ Impostazioni Assistente IA</h1>
                <p class="assia-settings-subtitle">Configura le credenziali, personalizza il comportamento e ottimizza le performance del tuo assistente</p>
            </div>
            
            <form method="post" action="options.php">
                <?php settings_fields('assia_opt'); ?>
                
                <!-- Credenziali e Modelli -->
                <div class="assia-card">
                    <h2 class="assia-card-title">
                        <span class="assia-card-icon">🔐</span>
                        Credenziali e Modelli
                    </h2>
                    
                    <div class="assia-grid-2">
                        <div class="assia-form-group">
                            <label class="assia-label" for="assia_progetto_id">Project ID Google Cloud</label>
                            <input type="text" id="assia_progetto_id" name="assia_progetto_id" class="assia-input" value="<?php echo esc_attr(get_option('assia_progetto_id')); ?>" placeholder="my-project-123456">
                        </div>
                        
                        <div class="assia-form-group">
                            <label class="assia-label" for="assia_localita">Località (Region)</label>
                            <input type="text" id="assia_localita" name="assia_localita" class="assia-input" value="<?php echo esc_attr(get_option('assia_localita')); ?>" placeholder="us-central1">
                            <p class="assia-help">Es: us-central1, europe-west1, asia-northeast1</p>
                        </div>
                    </div>
                    
                    <div class="assia-grid-2">
                        <div class="assia-form-group">
                            <label class="assia-label" for="assia_modello">Modello AI</label>
                            <input type="text" id="assia_modello" name="assia_modello" class="assia-input" value="<?php echo esc_attr(get_option('assia_modello')); ?>" placeholder="gemini-1.5-flash-002">
                            <p class="assia-help">Es: gemini-1.5-pro, gemini-1.5-flash-002</p>
                        </div>
                        
                        <div class="assia-form-group">
                            <label class="assia-label" for="assia_modello_embedding">Modello Embedding</label>
                            <input type="text" id="assia_modello_embedding" name="assia_modello_embedding" class="assia-input" value="<?php echo esc_attr(get_option('assia_modello_embedding')); ?>" placeholder="text-embedding-005">
                            <p class="assia-help">Per il sistema RAG (default: text-embedding-005)</p>
                        </div>
                    </div>
                    
                    <div class="assia-form-group">
                        <label class="assia-label" for="assia_credenziali_base64">Credenziali Service Account</label>
                        <textarea id="assia_credenziali_base64" name="assia_credenziali_base64" rows="5" class="assia-textarea" placeholder="Incolla qui il JSON del service account o la stringa Base64..."><?php echo esc_textarea(get_option('assia_credenziali_base64')); ?></textarea>
                        <div class="assia-help-box">
                            <strong>📌 Nota:</strong> Il plugin riconosce automaticamente il formato. Puoi incollare:
                            <br>• Il JSON completo del service account (inizia con {)
                            <br>• La versione Base64 del JSON
                        </div>
                    </div>
                </div>

                <!-- Prompt e Comportamento -->
                <div class="assia-card">
                    <h2 class="assia-card-title">
                        <span class="assia-card-icon">💬</span>
                        Prompt e Comportamento
                    </h2>
                    
                    <div class="assia-form-group">
                        <label class="assia-label" for="assia_obiettivo">Obiettivo dell'Assistente</label>
                        <textarea id="assia_obiettivo" name="assia_obiettivo" rows="3" class="assia-textarea" placeholder="Descrivi il ruolo principale dell'assistente..."><?php echo esc_textarea(get_option('assia_obiettivo')); ?></textarea>
                        <p class="assia-help">Definisci lo scopo principale e il ruolo dell'assistente (es. "Sei un esperto di prodotti che aiuta i clienti")</p>
                    </div>
                    
                    <div class="assia-form-group">
                        <label class="assia-label" for="assia_avviso">Avviso Iniziale</label>
                        <textarea id="assia_avviso" name="assia_avviso" rows="3" class="assia-textarea" placeholder="Messaggio di benvenuto o avviso..."><?php echo esc_textarea(get_option('assia_avviso')); ?></textarea>
                        <p class="assia-help">Messaggio mostrato all'apertura della chat (es. disclaimer, orari di servizio, etc.)</p>
                    </div>
                    
                    <div class="assia-form-group">
                        <label class="assia-label" for="assia_istruzioni_stile">🎯 Istruzioni di Stile Personalizzate</label>
                        <textarea id="assia_istruzioni_stile" name="assia_istruzioni_stile" rows="8" class="assia-textarea" placeholder="Lascia vuoto per usare le istruzioni predefinite..."><?php echo esc_textarea(get_option('assia_istruzioni_stile','')); ?></textarea>
                        <div class="assia-help-box">
                            <strong>Personalizza il comportamento dell'assistente:</strong><br>
                            • Priorità delle fonti di informazione<br>
                            • Stile comunicativo (formale, amichevole, tecnico)<br>
                            • Cosa evitare nelle risposte<br>
                            • Esempi: "Usa sempre un tono formale", "Fornisci sempre esempi pratici", "Cita sempre le fonti"
                        </div>
                    </div>
                    
                    <div class="assia-grid-2">
                        <div class="assia-form-group">
                            <label class="assia-label" for="assia_prompt_riassunto">📝 Prompt Riassunto Conversazioni</label>
                            <textarea id="assia_prompt_riassunto" name="assia_prompt_riassunto" rows="3" class="assia-textarea" placeholder="Lascia vuoto per il prompt predefinito..."><?php echo esc_textarea(get_option('assia_prompt_riassunto','')); ?></textarea>
                            <p class="assia-help">Come riassumere conversazioni lunghe (default: 120-160 parole)</p>
                        </div>
                        
                        <div class="assia-form-group">
                            <label class="assia-label" for="assia_ruolo_sistema">Ruolo di Sistema</label>
                            <textarea id="assia_ruolo_sistema" name="assia_ruolo_sistema" rows="3" class="assia-textarea" placeholder="Istruzioni anti-disclaimer..."><?php echo esc_textarea(get_option('assia_ruolo_sistema','')); ?></textarea>
                            <p class="assia-help">Istruzioni persistenti per evitare disclaimer generici</p>
                        </div>
                    </div>
                </div>

                <!-- Generazione e Sicurezza -->
                <div class="assia-card">
                    <h2 class="assia-card-title">
                        <span class="assia-card-icon">🎛️</span>
                        Generazione e Sicurezza
                    </h2>
                    
                    <div class="assia-grid">
                        <div class="assia-form-group">
                            <label class="assia-label" for="assia_temperature">Temperature</label>
                            <input type="number" id="assia_temperature" name="assia_temperature" class="assia-input" step="0.01" min="0" max="1" value="<?php echo esc_attr(get_option('assia_temperature')); ?>">
                            <p class="assia-help">0 = Deterministico, 1 = Creativo (consigliato: 0.7)</p>
                        </div>
                        
                        <div class="assia-form-group">
                            <label class="assia-label" for="assia_max_token">Max Output Tokens</label>
                            <input type="number" id="assia_max_token" name="assia_max_token" class="assia-input" min="1" value="<?php echo esc_attr(get_option('assia_max_token')); ?>">
                            <p class="assia-help">Lunghezza massima della risposta</p>
                        </div>
                        
                        <div class="assia-form-group">
                            <label class="assia-label" for="assia_top_p">Top P</label>
                            <input type="number" id="assia_top_p" name="assia_top_p" class="assia-input" step="0.01" min="0" max="1" value="<?php echo esc_attr(get_option('assia_top_p')); ?>">
                            <p class="assia-help">Nucleus sampling (0-1)</p>
                        </div>
                        
                        <div class="assia-form-group">
                            <label class="assia-label" for="assia_top_k">Top K</label>
                            <input type="number" id="assia_top_k" name="assia_top_k" class="assia-input" min="1" value="<?php echo esc_attr(get_option('assia_top_k')); ?>">
                            <p class="assia-help">Token candidates (consigliato: 40)</p>
                        </div>
                        
                        <div class="assia-form-group">
                            <label class="assia-label" for="assia_attiva_google_search">Google Search</label>
                            <select id="assia_attiva_google_search" name="assia_attiva_google_search" class="assia-select">
                                <option value="no" <?php selected(get_option('assia_attiva_google_search'),'no');?>>🚫 Disattivato</option>
                                <option value="si" <?php selected(get_option('assia_attiva_google_search'),'si');?>>✅ Attivato</option>
                            </select>
                            <p class="assia-help">Abilita ricerca web per info aggiornate</p>
                        </div>
                    </div>
                    
                    <div class="assia-form-group">
                        <label class="assia-label">🛡️ Filtri di Sicurezza</label>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; padding: 15px; background: #f8fafc; border-radius: 8px;">
                            <?php 
                            $ss = (array)get_option('assia_safety_soglie'); 
                            $cats = [
                                'sexually_explicit' => '🔞 Contenuti Espliciti',
                                'hate_speech' => '🚫 Incitamento all\'Odio',
                                'harassment' => '⚠️ Molestie',
                                'dangerous_content' => '☠️ Contenuti Pericolosi'
                            ];
                            $opts = [
                                'BLOCK_NONE' => 'Nessun Blocco',
                                'BLOCK_ONLY_HIGH' => 'Solo Alto Rischio',
                                'BLOCK_MEDIUM_AND_ABOVE' => 'Medio e Alto',
                                'BLOCK_LOW_AND_ABOVE' => 'Tutti i Livelli'
                            ];
                            foreach($cats as $key => $label){ 
                                $v = $ss[$key] ?? 'BLOCK_NONE';
                                ?>
                                <div>
                                    <label style="display: block; font-weight: 600; margin-bottom: 5px; font-size: 13px; color: #475569;">
                                        <?php echo $label; ?>
                                    </label>
                                    <select name="assia_safety_soglie[<?php echo esc_attr($key); ?>]" class="assia-select" style="width: 100%;">
                                        <?php foreach($opts as $opt_key => $opt_label){ ?>
                                            <option <?php selected($v, $opt_key); ?> value="<?php echo esc_attr($opt_key); ?>">
                                                <?php echo esc_html($opt_label); ?>
                                            </option>
                                        <?php } ?>
                                    </select>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>

                <!-- RAG / Embeddings -->
                <div class="assia-card">
                    <h2 class="assia-card-title">
                        <span class="assia-card-icon">🔍</span>
                        RAG / Embeddings
                    </h2>
            <tr><th>Attiva embeddings</th><td>
                <select name="assia_attiva_embeddings">
                    <option value="si" <?php selected(get_option('assia_attiva_embeddings','si'),'si');?>>Sì</option>
                    <option value="no" <?php selected(get_option('assia_attiva_embeddings','si'),'no');?>>No</option>
                </select>
            </td></tr>
            
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
                <input type="number" step="0.01" min="0" max="1" name="assia_embeddings_threshold" value="<?php echo esc_attr(get_option('assia_embeddings_threshold','0.30')); ?>">
                <p class="description">Score minimo per considerare un chunk rilevante (0.30 consigliato). Valori più alti = più selettivo.</p>
            </td></tr>
            
            <tr>
                <th>Chunk size (caratteri)</th>
                <td>
                    <input type="number" name="assia_chunk_size" value="<?php echo esc_attr(get_option('assia_chunk_size',1200)); ?>" min="500" max="3000">
                    <p class="description">
                        Dimensione massima di ogni chunk in caratteri (default: 1200).<br>
                        Valori più alti = più contesto ma meno chunk. Valori più bassi = più chunk ma meno contesto.
                    </p>
                </td>
            </tr>
            
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
            
            <tr>
                <th>Context Window 🆕</th>
                <td>
                    <input type="number" name="assia_rag_context_window" value="<?php echo esc_attr(get_option('assia_rag_context_window',1)); ?>" min="0" max="3">
                    <p class="description">
                        <strong>✨ NUOVO v5.6.0 - Context-Aware RAG</strong><br>
                        Quando trova un chunk rilevante, include anche N chunk adiacenti della <strong>stessa pagina</strong>.<br>
                        <strong>0</strong> = Disattivato (comportamento classico)<br>
                        <strong>1</strong> = ±1 chunk (consigliato) - Aggiunge 1 chunk prima e 1 dopo<br>
                        <strong>2</strong> = ±2 chunk - Aggiunge 2 chunk prima e 2 dopo<br>
                        <strong>3</strong> = ±3 chunk - Massima contestualizzazione<br>
                        <br>
                        💡 <strong>Perché è importante:</strong> I chunk della stessa pagina sono contenuto continuo. 
                        Questa opzione ricostruisce il contesto sequenziale per risposte più complete e coerenti.<br>
                        ⚠️ Nota: Aumenta i token usati ma migliora significativamente la qualità delle risposte.
                    </p>
                </td>
            </tr>
            
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

        <h2>Conversazioni</h2>
        <table class="form-table">
            <tr><th>Turni modello (contesto)</th><td>
                <input type="number" name="assia_turni_modello" value="<?php echo esc_attr(get_option('assia_turni_modello',8)); ?>" min="1" max="50">
                <p class="description">Numero di messaggi recenti da includere nel contesto (default: 8)</p>
            </td></tr>
            <tr><th>Messaggi UI (frontend)</th><td>
                <input type="number" name="assia_messaggi_ui" value="<?php echo esc_attr(get_option('assia_messaggi_ui',30)); ?>" min="1" max="100">
                <p class="description">Numero di messaggi da mostrare nell'interfaccia chat (default: 30)</p>
            </td></tr>
            <tr><th>Pulizia automatica conversazioni</th><td>
                <input type="number" name="assia_ttl_giorni" value="<?php echo esc_attr(get_option('assia_ttl_giorni',30)); ?>" min="0" max="365">
                <p class="description">Elimina conversazioni più vecchie di N giorni (0 = mai, default: 30). Eseguito giornalmente via CRON.</p>
            </td></tr>
            <tr><th>Rate limit massimo</th><td>
                <input type="number" name="assia_rate_limite_max" value="<?php echo esc_attr(get_option('assia_rate_limite_max',8)); ?>" min="1">
                <p class="description">Numero massimo di messaggi per finestra temporale</p>
            </td></tr>
            <tr><th>Rate limit finestra (secondi)</th><td>
                <input type="number" name="assia_rate_limite_finestra_sec" value="<?php echo esc_attr(get_option('assia_rate_limite_finestra_sec',60)); ?>" min="10">
                <p class="description">Durata della finestra in secondi (default: 60)</p>
            </td></tr>
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
                    <option value="si" <?php selected(get_option('assia_inserimento_automatico_footer','si'),'si'); ?>>Sì (predefinito)</option>
                    <option value="no" <?php selected(get_option('assia_inserimento_automatico_footer','si'),'no'); ?>>No (usa solo shortcode)</option>
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
            <tr><th>Conserva log per N giorni</th><td>
                <input type="number" name="assia_registro_modello_retention_giorni" value="<?php echo esc_attr(get_option('assia_registro_modello_retention_giorni',7)); ?>" min="1" max="90">
                <p class="description">Elimina automaticamente log più vecchi di N giorni (default: 7)</p>
            </td></tr>
        </table>

        <?php submit_button('Salva impostazioni'); ?>
        </form>
        </div><?php
    }

    /** ------------------ PAGINA DIAGNOSTICA MODERNIZZATA ------------------ */
    public function pagina_diagnostica(){
        // Gestione cancellazione storico rigenerazioni
        if ( isset($_POST['assia_cancella_storico']) && current_user_can('manage_options') ){
            check_admin_referer('assia_cancella_storico');
            delete_option('assia_log_embeddings');
            echo '<div class="notice notice-success" style="margin:20px 0;padding:12px 16px;border-left:4px solid #10b981;background:#f0fdf4"><p style="margin:0"><strong>✅ Storico rigenerazioni cancellato con successo</strong></p></div>';
        }
        
        if ( isset($_POST['assia_esegui_test']) ){
            check_admin_referer('assia_diagnostica');
            $esiti = $this->esegui_batteria_test();
            $diagEmb = $this->diagnostica_embeddings(); ?>
            
            <style>
                .assia-diag-wrap { max-width: 1200px; margin: 20px 0; }
                .assia-diag-header { margin-bottom: 30px; }
                .assia-diag-title { font-size: 32px; font-weight: 600; color: #1e293b; margin: 0 0 10px 0; }
                .assia-diag-subtitle { color: #64748b; font-size: 16px; }
                .assia-test-result { background: #fff; border-left: 4px solid; border-radius: 8px; padding: 16px 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
                .assia-test-result.success { border-color: #10b981; background: #f0fdf4; }
                .assia-test-result.error { border-color: #ef4444; background: #fef2f2; }
                .assia-test-result h3 { margin: 0 0 8px 0; font-size: 16px; font-weight: 600; color: #1e293b; display: flex; align-items: center; gap: 8px; }
                .assia-test-result.success h3:before { content: '✅'; }
                .assia-test-result.error h3:before { content: '❌'; }
                .assia-test-result p { margin: 0; color: #475569; line-height: 1.6; }
                .assia-code-block { background: #0f172a; color: #e2e8f0; padding: 16px; border-radius: 8px; overflow: auto; font-family: 'Courier New', monospace; font-size: 13px; line-height: 1.6; max-height: 400px; margin: 16px 0; }
                .assia-btn-primary { background: #3b82f6; color: #fff; padding: 12px 24px; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; transition: all 0.2s; }
                .assia-btn-primary:hover { background: #2563eb; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59,130,246,0.3); }
                .assia-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
                .assia-card-title { font-size: 20px; font-weight: 600; color: #1e293b; margin: 0 0 20px 0; padding-bottom: 12px; border-bottom: 2px solid #f1f5f9; display: flex; align-items: center; gap: 10px; }
            </style>
            
            <div class="wrap assia-diag-wrap">
                <div class="assia-diag-header">
                    <h1 class="assia-diag-title">🔍 Diagnostica – Risultati Test</h1>
                    <p class="assia-diag-subtitle">Report completo dei test di connettività e funzionalità</p>
                </div>
                
                <div class="assia-card">
                    <h2 class="assia-card-title">
                        <span>📋</span>
                        Risultati Test Sistema
                    </h2>
                    <?php foreach($esiti as $e): 
                        $class = $e['ok'] ? 'success' : 'error'; ?>
                        <div class="assia-test-result <?php echo esc_attr($class); ?>">
                            <h3><?php echo esc_html($e['titolo']); ?></h3>
                            <p><?php echo esc_html($e['messaggio']); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="assia-card">
                    <h2 class="assia-card-title">
                        <span>🧬</span>
                        Analisi Embeddings
                    </h2>
                    <pre class="assia-code-block"><?php echo esc_html( print_r( $diagEmb, true ) ); ?></pre>
                </div>
                
                <p><a class="assia-btn-primary" href="<?php echo esc_url(admin_url('admin.php?page=assia-diagnostica')); ?>">← Torna alla Diagnostica</a></p>
            </div>
            <?php
            return;
        }
        
        $log = get_option('assia_log_embeddings', []); 
        ?>
        
        <style>
            .assia-diag-wrap { max-width: 1200px; margin: 20px 0; }
            .assia-diag-header { margin-bottom: 30px; }
            .assia-diag-title { font-size: 32px; font-weight: 600; color: #1e293b; margin: 0 0 10px 0; }
            .assia-diag-subtitle { color: #64748b; font-size: 16px; }
            .assia-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            .assia-card-title { font-size: 20px; font-weight: 600; color: #1e293b; margin: 0 0 20px 0; padding-bottom: 12px; border-bottom: 2px solid #f1f5f9; display: flex; align-items: center; gap: 10px; }
            .assia-card-description { color: #64748b; margin-bottom: 20px; line-height: 1.6; }
            .assia-btn-primary { background: #3b82f6; color: #fff; padding: 12px 24px; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
            .assia-btn-primary:hover { background: #2563eb; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59,130,246,0.3); }
            .assia-btn-secondary { background: #64748b; color: #fff; padding: 12px 24px; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.2s; margin-left: 12px; }
            .assia-btn-secondary:hover { background: #475569; }
            .assia-btn-secondary:disabled { opacity: 0.5; cursor: not-allowed; }
            .assia-btn-danger { background: #ef4444; color: #fff; padding: 12px 24px; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
            .assia-btn-danger:hover { background: #dc2626; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); }
            .assia-status-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; min-height: 60px; margin-top: 16px; font-family: 'Courier New', monospace; font-size: 13px; line-height: 1.6; }
            .assia-table-modern { width: 100%; border-collapse: separate; border-spacing: 0; }
            .assia-table-modern thead th { background: #f8fafc; padding: 12px 16px; text-align: left; font-weight: 600; color: #475569; font-size: 14px; border-bottom: 2px solid #e2e8f0; }
            .assia-table-modern thead th:first-child { border-radius: 8px 0 0 0; }
            .assia-table-modern thead th:last-child { border-radius: 0 8px 0 0; }
            .assia-table-modern tbody td { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; color: #1e293b; }
            .assia-table-modern tbody tr:hover { background: #f8fafc; }
            .assia-table-modern tbody tr:last-child td { border-bottom: none; }
            .assia-empty-state { text-align: center; padding: 40px 20px; color: #64748b; }
            .assia-empty-state-icon { font-size: 48px; margin-bottom: 16px; opacity: 0.5; }
        </style>
        
        <div class="wrap assia-diag-wrap">
            <div class="assia-diag-header">
                <h1 class="assia-diag-title">🔍 Diagnostica Sistema</h1>
                <p class="assia-diag-subtitle">Verifica la connessione e il funzionamento dei componenti principali</p>
            </div>
            
            <div class="assia-card">
                <h2 class="assia-card-title">
                    <span>🧪</span>
                    Test Completo Sistema
                </h2>
                <p class="assia-card-description">
                    Esegui una batteria completa di test per verificare: credenziali service account, 
                    token OAuth2, connessione a Vertex AI e funzionamento embeddings.
                </p>
                <form method="post">
                    <?php wp_nonce_field('assia_diagnostica'); ?>
                    <button type="submit" class="assia-btn-primary" name="assia_esegui_test" value="1">
                        ▶️ Esegui Test Completo
                    </button>
                </form>
            </div>

            <div class="assia-card">
                <h2 class="assia-card-title">
                    <span>📊</span>
                    Storico Rigenerazioni
                </h2>
                <p class="assia-card-description">
                    Registro delle rigenerazioni embeddings eseguite. Per avviare una nuova rigenerazione, vai alla sezione 
                    <a href="<?php echo esc_url(admin_url('admin.php?page=assistente-ia-rag')); ?>" style="color:#3b82f6;font-weight:600">RAG (Embeddings)</a>.
                </p>
                <?php if ( is_array($log) && !empty($log) ): ?>
                    <table class="assia-table-modern">
                        <thead>
                            <tr>
                                <th>Avviato</th>
                                <th>Completato</th>
                                <th>Modello</th>
                                <th>Post</th>
                                <th>Chunks</th>
                                <th>Errori</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($log as $r): ?>
                            <tr>
                                <td><?php echo esc_html($r['avviato_il'] ?? '—'); ?></td>
                                <td><?php echo esc_html($r['completato_il'] ?? '—'); ?></td>
                                <td><code style="background:#f1f5f9;padding:2px 8px;border-radius:4px;font-size:12px"><?php echo esc_html($r['modello'] ?? '—'); ?></code></td>
                                <td><strong><?php echo esc_html($r['tot_voci'] ?? '0'); ?></strong></td>
                                <td><strong><?php echo esc_html($r['chunks_creati'] ?? '0'); ?></strong></td>
                                <td style="color:#ef4444"><?php echo esc_html( is_array($r['errori'] ?? []) ? implode('; ',$r['errori']) : '—' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <form method="post" style="margin-top:20px" onsubmit="return confirm('⚠️ Confermi di cancellare tutto lo storico delle rigenerazioni?\n\nQuesta azione non può essere annullata.');">
                        <?php wp_nonce_field('assia_cancella_storico'); ?>
                        <button type="submit" name="assia_cancella_storico" class="assia-btn-danger">
                            🗑️ Cancella Storico
                        </button>
                    </form>
                <?php else: ?>
                    <div class="assia-empty-state">
                        <div class="assia-empty-state-icon">📭</div>
                        <p><strong>Nessuna rigenerazione eseguita</strong></p>
                        <p style="margin-top:8px;font-size:14px">Lo storico apparirà qui dopo la prima esecuzione</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /** ------------------ PAGINA DIAGNOSTICA MODELLO MODERNIZZATA ------------------ */
    public function pagina_diagnostica_modello(){
        if ( isset($_POST['assia_pulisci_registro']) && current_user_can('manage_options') ){
            check_admin_referer('assia_pulisci_registro');
            global $wpdb; $pref=$wpdb->prefix;
            $wpdb->query("TRUNCATE TABLE {$pref}assistente_ia_diag_modello");
            echo '<div class="notice notice-success" style="margin:20px 0;padding:12px 16px;border-left:4px solid #10b981;background:#f0fdf4"><p style="margin:0"><strong>✅ Registro svuotato con successo</strong></p></div>';
        }
        ?>
        
        <style>
            .assia-diagmod-wrap { max-width: 1400px; margin: 20px 0; }
            .assia-diagmod-header { margin-bottom: 30px; }
            .assia-diagmod-title { font-size: 32px; font-weight: 600; color: #1e293b; margin: 0 0 10px 0; }
            .assia-diagmod-subtitle { color: #64748b; font-size: 16px; }
            .assia-status-badge { display: inline-block; padding: 6px 12px; border-radius: 6px; font-weight: 600; font-size: 13px; }
            .assia-status-badge.active { background: #d1fae5; color: #065f46; }
            .assia-status-badge.inactive { background: #fee2e2; color: #991b1b; }
            .assia-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            .assia-card-title { font-size: 20px; font-weight: 600; color: #1e293b; margin: 0 0 20px 0; padding-bottom: 12px; border-bottom: 2px solid #f1f5f9; display: flex; align-items: center; gap: 10px; }
            .assia-btn-group { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 16px; }
            .assia-btn { padding: 10px 20px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s; font-size: 14px; text-decoration: none; display: inline-block; }
            .assia-btn-primary { background: #3b82f6; color: #fff; }
            .assia-btn-primary:hover { background: #2563eb; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59,130,246,0.3); }
            .assia-btn-secondary { background: #64748b; color: #fff; }
            .assia-btn-secondary:hover { background: #475569; }
            .assia-btn-danger { background: #ef4444; color: #fff; }
            .assia-btn-danger:hover { background: #dc2626; }
            .assia-filter-form { display: flex; gap: 12px; align-items: flex-end; margin-bottom: 20px; }
            .assia-filter-form select { padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; }
            .assia-table-modern { width: 100%; border-collapse: separate; border-spacing: 0; }
            .assia-table-modern thead th { background: #f8fafc; padding: 12px 16px; text-align: left; font-weight: 600; color: #475569; font-size: 14px; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
            .assia-table-modern thead th:first-child { border-radius: 8px 0 0 0; }
            .assia-table-modern thead th:last-child { border-radius: 0 8px 0 0; }
            .assia-table-modern tbody td { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; color: #1e293b; font-size: 14px; }
            .assia-table-modern tbody tr:hover { background: #f8fafc; }
            .assia-table-modern tbody tr:last-child td { border-bottom: none; }
            .assia-pagination { display: flex; gap: 8px; margin-top: 20px; flex-wrap: wrap; }
            .assia-pagination a, .assia-pagination strong { padding: 8px 12px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 14px; }
            .assia-pagination a { background: #f1f5f9; color: #64748b; transition: all 0.2s; }
            .assia-pagination a:hover { background: #e2e8f0; color: #475569; }
            .assia-pagination strong { background: #3b82f6; color: #fff; }
            .assia-detail-section { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-top: 20px; }
            .assia-detail-title { font-size: 18px; font-weight: 600; color: #1e293b; margin: 0 0 16px 0; }
            .assia-detail-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px; }
            .assia-detail-meta-item { display: flex; flex-direction: column; }
            .assia-detail-meta-label { font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase; margin-bottom: 4px; }
            .assia-detail-meta-value { font-size: 14px; color: #1e293b; font-weight: 500; }
            .assia-code-block { background: #0f172a; color: #e2e8f0; padding: 16px; border-radius: 8px; overflow: auto; font-family: 'Courier New', monospace; font-size: 13px; line-height: 1.6; max-height: 500px; white-space: pre-wrap; word-break: break-all; }
            .assia-empty-state { text-align: center; padding: 60px 20px; color: #64748b; }
            .assia-empty-state-icon { font-size: 64px; margin-bottom: 16px; opacity: 0.3; }
            .assia-type-badge { display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; }
            .assia-type-badge.generate { background: #dbeafe; color: #1e40af; }
            .assia-type-badge.embed { background: #fce7f3; color: #9f1239; }
        </style>
        
        <div class="wrap assia-diagmod-wrap">
            <div class="assia-diagmod-header">
                <h1 class="assia-diagmod-title">📊 Diagnostica Modello</h1>
                <p class="assia-diagmod-subtitle">Registro completo delle chiamate API a Vertex AI</p>
            </div>
            
            <div class="assia-card">
                <h2 class="assia-card-title">
                    <span>⚙️</span>
                    Stato Sistema
                </h2>
                <p style="margin-bottom:20px">
                    Stato registro: 
                    <?php if ('si'===get_option('assia_registro_modello_attivo','no')): ?>
                        <span class="assia-status-badge active">● ATTIVO</span>
                    <?php else: ?>
                        <span class="assia-status-badge inactive">○ DISATTIVATO</span>
                    <?php endif; ?>
                </p>
                <div class="assia-btn-group">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=assia')); ?>" class="assia-btn assia-btn-primary">
                        ⚙️ Vai alle Impostazioni
                    </a>
                    <form method="post" style="display:inline">
                        <?php wp_nonce_field('assia_pulisci_registro'); ?>
                        <button class="assia-btn assia-btn-danger" name="assia_pulisci_registro" value="1" 
                                onclick="return confirm('⚠️ Confermi di svuotare il registro?\n\nQuesta azione è irreversibile.');">
                            🗑️ Svuota Registro
                        </button>
                    </form>
                </div>
            </div>
            
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
            ?>
            
            <div class="assia-card">
                <h2 class="assia-card-title">
                    <span>📋</span>
                    Registro Chiamate API
                </h2>
                
                <form method="get" class="assia-filter-form">
                    <input type="hidden" name="page" value="assia-diagnostica-modello">
                    <div>
                        <label style="display:block;font-size:12px;color:#64748b;margin-bottom:4px;font-weight:600">FILTRA PER TIPO</label>
                        <select name="tipo">
                            <option value="">Tutti i tipi</option>
                            <option value="generate" <?php selected($f_tipo,'generate');?>>Generate</option>
                            <option value="embed" <?php selected($f_tipo,'embed');?>>Embed</option>
                        </select>
                    </div>
                    <button class="assia-btn assia-btn-secondary" style="align-self:flex-end">🔍 Filtra</button>
                </form>

                <?php if ( $righe ): ?>
                    <table class="assia-table-modern">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Data/Ora</th>
                                <th>Tipo</th>
                                <th>Chat ID</th>
                                <th>Hash</th>
                                <th>HTTP Code</th>
                                <th>Errore</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($righe as $r): 
                            $link = esc_url( add_query_arg(['page'=>'assia-diagnostica-modello','dettaglio'=>$r['id']], admin_url('admin.php')) ); 
                            $tipo_class = $r['tipo'] === 'generate' ? 'generate' : 'embed';
                        ?>
                            <tr>
                                <td><strong><?php echo intval($r['id']); ?></strong></td>
                                <td><?php echo esc_html($r['creato_il']); ?></td>
                                <td><span class="assia-type-badge <?php echo $tipo_class; ?>"><?php echo esc_html($r['tipo']); ?></span></td>
                                <td><?php echo esc_html($r['id_chat']); ?></td>
                                <td><code style="background:#f1f5f9;padding:2px 8px;border-radius:4px;font-size:11px"><?php echo esc_html(substr($r['hash_sessione'], 0, 12)); ?>...</code></td>
                                <td>
                                    <?php 
                                    $http_code = intval($r['http_code']);
                                    $code_color = $http_code >= 200 && $http_code < 300 ? '#10b981' : '#ef4444';
                                    ?>
                                    <strong style="color:<?php echo $code_color; ?>"><?php echo $http_code; ?></strong>
                                </td>
                                <td style="color:#ef4444;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?php echo esc_attr($r['errore']); ?>">
                                    <?php echo esc_html($r['errore'] ?: '—'); ?>
                                </td>
                                <td>
                                    <a class="assia-btn assia-btn-secondary" style="padding:6px 12px;font-size:13px" href="<?php echo $link; ?>">
                                        🔍 Dettagli
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php
                    // Paginazione
                    $pagine = max(1, ceil($tot/$per_page));
                    if ( $pagine > 1 ): ?>
                        <div class="assia-pagination">
                            <?php for($i=1;$i<=$pagine;$i++): 
                                $url = esc_url( add_query_arg(['page'=>'assia-diagnostica-modello','paged'=>$i,'tipo'=>$f_tipo], admin_url('admin.php')) );
                                if($i===$pagina): ?>
                                    <strong><?php echo $i; ?></strong>
                                <?php else: ?>
                                    <a href="<?php echo $url; ?>"><?php echo $i; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <div class="assia-empty-state">
                        <div class="assia-empty-state-icon">📭</div>
                        <p><strong>Nessun record trovato</strong></p>
                        <p style="margin-top:8px;font-size:14px">Le chiamate API appariranno qui quando il registro sarà attivo</p>
                    </div>
                <?php endif; ?>
            </div>

            <?php
            // Dettaglio record
            if ( isset($_GET['dettaglio']) ){
                $id = (int)$_GET['dettaglio'];
                $row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$pref}assistente_ia_diag_modello WHERE id=%d",$id), ARRAY_A );
                if($row):
                    $payload_pp = self::pp_json($row['payload']);
                    $risposta_pp = self::pp_json($row['risposta']);
                ?>
                    <div class="assia-card">
                        <h2 class="assia-card-title">
                            <span>🔎</span>
                            Dettaglio Chiamata #<?php echo intval($row['id']); ?>
                        </h2>
                        
                        <div class="assia-detail-meta">
                            <div class="assia-detail-meta-item">
                                <span class="assia-detail-meta-label">Endpoint</span>
                                <span class="assia-detail-meta-value"><?php echo esc_html($row['endpoint']); ?></span>
                            </div>
                            <div class="assia-detail-meta-item">
                                <span class="assia-detail-meta-label">Chat ID</span>
                                <span class="assia-detail-meta-value"><?php echo esc_html($row['id_chat']); ?></span>
                            </div>
                            <div class="assia-detail-meta-item">
                                <span class="assia-detail-meta-label">Hash Sessione</span>
                                <span class="assia-detail-meta-value"><code style="background:#f1f5f9;padding:4px 8px;border-radius:4px;font-size:12px"><?php echo esc_html($row['hash_sessione']); ?></code></span>
                            </div>
                            <div class="assia-detail-meta-item">
                                <span class="assia-detail-meta-label">Data/Ora</span>
                                <span class="assia-detail-meta-value"><?php echo esc_html($row['creato_il']); ?></span>
                            </div>
                        </div>
                        
                        <div class="assia-detail-section">
                            <h3 class="assia-detail-title">📤 Payload Richiesta</h3>
                            <pre class="assia-code-block"><?php echo esc_html($payload_pp); ?></pre>
                        </div>
                        
                        <div class="assia-detail-section">
                            <h3 class="assia-detail-title">📥 Risposta API</h3>
                            <pre class="assia-code-block"><?php echo esc_html($risposta_pp); ?></pre>
                        </div>
                        
                        <div style="margin-top:20px">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=assia-diagnostica-modello')); ?>" class="assia-btn assia-btn-primary">
                                ← Torna alla lista
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            <?php } ?>
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

    /** ------------------ PAGINA ARCHIVIO CONVERSAZIONI MODERNIZZATA ------------------ */
    public function pagina_archivio(){
        
        // Gestione svuota archivio
        if ( isset($_POST['assia_svuota_archivio']) ){
            if ( ! current_user_can('manage_options') ) { wp_die('Permessi insufficienti'); }
            check_admin_referer('assia_svuota_archivio');
            global $wpdb; $pref=$wpdb->prefix;
            $ok1 = $wpdb->query("TRUNCATE TABLE {$pref}assistente_ia_messaggi");
            if ($ok1 === false){ $wpdb->query("DELETE FROM {$pref}assistente_ia_messaggi"); }
            $ok2 = $wpdb->query("TRUNCATE TABLE {$pref}assistente_ia_chat");
            if ($ok2 === false){ $wpdb->query("DELETE FROM {$pref}assistente_ia_chat"); }
            echo '<div class="notice notice-success" style="margin:20px 0;padding:12px 16px;border-left:4px solid #10b981;background:#f0fdf4"><p style="margin:0"><strong>✅ Archivio conversazioni svuotato con successo</strong></p></div>';
        }
        
        global $wpdb; $pref=$wpdb->prefix;

        // Vista dettaglio chat
        if ( isset($_GET['chat']) ){
            $chat_id = (int) $_GET['chat'];
            $chat = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$pref}assistente_ia_chat WHERE id_chat=%d",$chat_id), ARRAY_A );
            $msgs = $wpdb->get_results( $wpdb->prepare("SELECT ruolo, testo, creato_il FROM {$pref}assistente_ia_messaggi WHERE id_chat=%d ORDER BY id_messaggio ASC",$chat_id), ARRAY_A );
            ?>
            
            <style>
                .assia-archive-wrap { max-width: 1000px; margin: 20px 0; }
                .assia-archive-header { margin-bottom: 30px; }
                .assia-archive-title { font-size: 32px; font-weight: 600; color: #1e293b; margin: 0 0 10px 0; }
                .assia-archive-subtitle { color: #64748b; font-size: 16px; }
                .assia-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
                .assia-card-title { font-size: 20px; font-weight: 600; color: #1e293b; margin: 0 0 20px 0; padding-bottom: 12px; border-bottom: 2px solid #f1f5f9; display: flex; align-items: center; gap: 10px; }
                .assia-meta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; margin-bottom: 24px; }
                .assia-meta-item { background: #f8fafc; padding: 12px 16px; border-radius: 8px; border: 1px solid #e2e8f0; }
                .assia-meta-label { font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase; margin-bottom: 4px; }
                .assia-meta-value { font-size: 14px; color: #1e293b; font-weight: 500; word-break: break-all; }
                .assia-chat-message { padding: 16px; border-radius: 8px; margin-bottom: 16px; border-left: 4px solid; }
                .assia-chat-message.user { background: #eff6ff; border-color: #3b82f6; }
                .assia-chat-message.model { background: #f0fdf4; border-color: #10b981; }
                .assia-chat-message-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
                .assia-chat-message-role { font-weight: 600; font-size: 13px; text-transform: uppercase; }
                .assia-chat-message.user .assia-chat-message-role { color: #1e40af; }
                .assia-chat-message.model .assia-chat-message-role { color: #065f46; }
                .assia-chat-message-time { font-size: 12px; color: #64748b; }
                .assia-chat-message-text { color: #1e293b; line-height: 1.6; }
                .assia-btn-primary { background: #3b82f6; color: #fff; padding: 12px 24px; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; transition: all 0.2s; }
                .assia-btn-primary:hover { background: #2563eb; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59,130,246,0.3); }
            </style>
            
            <div class="wrap assia-archive-wrap">
                <div class="assia-archive-header">
                    <h1 class="assia-archive-title">💬 Conversazione #<?php echo intval($chat_id); ?></h1>
                    <p class="assia-archive-subtitle">Visualizzazione completa della conversazione</p>
                </div>
                
                <?php if ($chat): ?>
                    <div class="assia-card">
                        <h2 class="assia-card-title">
                            <span>ℹ️</span>
                            Informazioni Conversazione
                        </h2>
                        <div class="assia-meta-grid">
                            <div class="assia-meta-item">
                                <div class="assia-meta-label">Hash Sessione</div>
                                <div class="assia-meta-value"><code style="background:#fff;padding:4px 8px;border-radius:4px;font-size:12px"><?php echo esc_html($chat['hash_sessione']); ?></code></div>
                            </div>
                            <div class="assia-meta-item">
                                <div class="assia-meta-label">Data Creazione</div>
                                <div class="assia-meta-value"><?php echo esc_html($chat['data_creazione']); ?></div>
                            </div>
                            <div class="assia-meta-item">
                                <div class="assia-meta-label">Ultimo Aggiornamento</div>
                                <div class="assia-meta-value"><?php echo esc_html($chat['ultimo_aggiornamento']); ?></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="assia-card">
                    <h2 class="assia-card-title">
                        <span>💬</span>
                        Messaggi (<?php echo count($msgs); ?>)
                    </h2>
                    <?php if ($msgs): ?>
                        <?php foreach($msgs as $m): 
                            $role_class = $m['ruolo'] === 'user' ? 'user' : 'model';
                            $role_label = $m['ruolo'] === 'user' ? '👤 Utente' : '🤖 Assistente';
                        ?>
                            <div class="assia-chat-message <?php echo $role_class; ?>">
                                <div class="assia-chat-message-header">
                                    <span class="assia-chat-message-role"><?php echo $role_label; ?></span>
                                    <span class="assia-chat-message-time"><?php echo esc_html($m['creato_il']); ?></span>
                                </div>
                                <div class="assia-chat-message-text"><?php echo wp_kses_post($m['testo']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="text-align:center;color:#64748b;padding:20px">Nessun messaggio trovato</p>
                    <?php endif; ?>
                </div>
                
                <p>
                    <a class="assia-btn-primary" href="<?php echo esc_url(admin_url('admin.php?page=assia-archivio')); ?>">
                        ← Torna all'archivio
                    </a>
                </p>
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
        
        <style>
            .assia-archive-wrap { max-width: 1400px; margin: 20px 0; }
            .assia-archive-header { margin-bottom: 30px; }
            .assia-archive-title { font-size: 32px; font-weight: 600; color: #1e293b; margin: 0 0 10px 0; }
            .assia-archive-subtitle { color: #64748b; font-size: 16px; }
            .assia-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            .assia-card-title { font-size: 20px; font-weight: 600; color: #1e293b; margin: 0 0 20px 0; padding-bottom: 12px; border-bottom: 2px solid #f1f5f9; display: flex; align-items: center; gap: 10px; }
            .assia-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
            .assia-stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; border-radius: 12px; color: #fff; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3); }
            .assia-stat-value { font-size: 36px; font-weight: 700; margin-bottom: 4px; }
            .assia-stat-label { font-size: 14px; opacity: 0.9; }
            .assia-btn-danger { background: #ef4444; color: #fff; padding: 10px 20px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s; font-size: 14px; }
            .assia-btn-danger:hover { background: #dc2626; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); }
            .assia-table-modern { width: 100%; border-collapse: separate; border-spacing: 0; }
            .assia-table-modern thead th { background: #f8fafc; padding: 12px 16px; text-align: left; font-weight: 600; color: #475569; font-size: 14px; border-bottom: 2px solid #e2e8f0; }
            .assia-table-modern thead th:first-child { border-radius: 8px 0 0 0; }
            .assia-table-modern thead th:last-child { border-radius: 0 8px 0 0; }
            .assia-table-modern tbody td { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; color: #1e293b; font-size: 14px; }
            .assia-table-modern tbody tr:hover { background: #f8fafc; }
            .assia-table-modern tbody tr:last-child td { border-bottom: none; }
            .assia-btn-secondary { background: #64748b; color: #fff; padding: 8px 16px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; transition: all 0.2s; font-size: 13px; }
            .assia-btn-secondary:hover { background: #475569; }
            .assia-pagination { display: flex; gap: 8px; margin-top: 20px; flex-wrap: wrap; }
            .assia-pagination a, .assia-pagination strong { padding: 8px 12px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 14px; }
            .assia-pagination a { background: #f1f5f9; color: #64748b; transition: all 0.2s; }
            .assia-pagination a:hover { background: #e2e8f0; color: #475569; }
            .assia-pagination strong { background: #3b82f6; color: #fff; }
            .assia-empty-state { text-align: center; padding: 60px 20px; color: #64748b; }
            .assia-empty-state-icon { font-size: 64px; margin-bottom: 16px; opacity: 0.3; }
            .assia-message-badge { display: inline-block; background: #3b82f6; color: #fff; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        </style>
        
        <div class="wrap assia-archive-wrap">
            <div class="assia-archive-header">
                <h1 class="assia-archive-title">💾 Archivio Conversazioni</h1>
                <p class="assia-archive-subtitle">Tutte le conversazioni salvate con l'assistente IA</p>
            </div>
            
            <div class="assia-stats">
                <div class="assia-stat-card">
                    <div class="assia-stat-value"><?php echo number_format($tot, 0, ',', '.'); ?></div>
                    <div class="assia-stat-label">Conversazioni Totali</div>
                </div>
                <div class="assia-stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <div class="assia-stat-value"><?php 
                        $tot_msg = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$pref}assistente_ia_messaggi");
                        echo number_format($tot_msg, 0, ',', '.'); 
                    ?></div>
                    <div class="assia-stat-label">Messaggi Totali</div>
                </div>
            </div>
            
            <div class="assia-card">
                <h2 class="assia-card-title">
                    <span>⚙️</span>
                    Gestione Archivio
                </h2>
                <form method="post" style="margin:0" onsubmit="return confirm('⚠️ ATTENZIONE: Questa azione cancellerà TUTTE le conversazioni in modo permanente.\n\nQuesta operazione non può essere annullata.\n\nSei sicuro di voler continuare?');">
                    <?php wp_nonce_field('assia_svuota_archivio'); ?>
                    <button type="submit" name="assia_svuota_archivio" class="assia-btn-danger">
                        🗑️ Svuota Archivio Conversazioni
                    </button>
                    <span style="margin-left:12px;color:#64748b;font-size:13px">Questa azione è irreversibile</span>
                </form>
            </div>
            
            <div class="assia-card">
                <h2 class="assia-card-title">
                    <span>📋</span>
                    Lista Conversazioni
                </h2>
                
                <?php if ($righe): ?>
                    <table class="assia-table-modern">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Hash Sessione</th>
                                <th>Data Creazione</th>
                                <th>Ultimo Aggiornamento</th>
                                <th>Messaggi</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($righe as $r): 
                            $link = esc_url( add_query_arg(['page'=>'assia-archivio','chat'=>$r['id_chat']], admin_url('admin.php')) ); 
                        ?>
                            <tr>
                                <td><strong><?php echo intval($r['id_chat']); ?></strong></td>
                                <td><code style="background:#f1f5f9;padding:4px 8px;border-radius:4px;font-size:11px"><?php echo esc_html(substr($r['hash_sessione'], 0, 20)); ?>...</code></td>
                                <td><?php echo esc_html($r['data_creazione']); ?></td>
                                <td><?php echo esc_html($r['ultimo_aggiornamento']); ?></td>
                                <td><span class="assia-message-badge"><?php echo intval($r['n_messaggi']); ?> msg</span></td>
                                <td>
                                    <a class="assia-btn-secondary" href="<?php echo $link; ?>">
                                        🔍 Visualizza
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <?php
                    $pagine = max(1, ceil($tot/$per_page));
                    if ( $pagine > 1 ): ?>
                        <div class="assia-pagination">
                            <?php for($i=1;$i<=$pagine;$i++): 
                                $url = esc_url( add_query_arg(['page'=>'assia-archivio','paged'=>$i], admin_url('admin.php')) );
                                if($i===$pagina): ?>
                                    <strong><?php echo $i; ?></strong>
                                <?php else: ?>
                                    <a href="<?php echo $url; ?>"><?php echo $i; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <div class="assia-empty-state">
                        <div class="assia-empty-state-icon">💬</div>
                        <p><strong>Nessuna conversazione trovata</strong></p>
                        <p style="margin-top:8px;font-size:14px">Le conversazioni appariranno qui quando gli utenti inizieranno a chattare</p>
                    </div>
                <?php endif; ?>
            </div>
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

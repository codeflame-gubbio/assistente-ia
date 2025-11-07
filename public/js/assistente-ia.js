(function($){
    'use strict';

    /* ============================================
       ASSISTENTE IA - JAVASCRIPT MIGLIORATO v2.0
       Con effetto typewriter e gestione overlay
       ============================================ */

    // Variabile per throttling
    var invioInCorso = false;

    // Genera o recupera un hash sessione locale (riidratazione cross-pagina)
    function ottieni_o_crea_hash_sessione(){
        var k='assistente_ia_hash', h=localStorage.getItem(k);
        if(!h){
            h=(Date.now().toString(36)+Math.random().toString(36).slice(2,10)).toUpperCase();
            localStorage.setItem(k,h);
        }
        return h;
    }

    /* ============================================
       EFFETTO TYPEWRITER per messaggi AI
       Gestisce correttamente l'HTML con animazione parola per parola
       ============================================ */
    function assiaTypewriter(html, elemento) {
        // Renderizza l'HTML nel div
        elemento.html(html);
        
        // Trova tutti i nodi di testo all'interno
        var testo_nodes = [];
        function trovaTesto(node) {
            if (node.nodeType === 3) { // Text node
                if (node.nodeValue.trim()) {
                    testo_nodes.push(node);
                }
            } else {
                $(node).contents().each(function() {
                    trovaTesto(this);
                });
            }
        }
        trovaTesto(elemento[0]);
        
        // Nascondi tutto il testo inizialmente
        testo_nodes.forEach(function(node) {
            node.originalText = node.nodeValue;
            node.nodeValue = '';
        });
        
        // Anima il testo parola per parola
        var currentNodeIndex = 0;
        var currentText = '';
        var words = [];
        var currentWordIndex = 0;
        
        if (testo_nodes.length > 0) {
            words = testo_nodes[currentNodeIndex].originalText.split(' ');
        }
        
        var velocita = 30; // millisecondi per parola
        var intervallo = setInterval(function() {
            if (currentNodeIndex >= testo_nodes.length) {
                clearInterval(intervallo);
                var container = $('.assistente-ia-messaggi');
                container.scrollTop(container[0].scrollHeight);
                return;
            }
            
            if (currentWordIndex < words.length) {
                currentText += (currentWordIndex > 0 ? ' ' : '') + words[currentWordIndex];
                testo_nodes[currentNodeIndex].nodeValue = currentText;
                currentWordIndex++;
                
                var container = $('.assistente-ia-messaggi');
                container.scrollTop(container[0].scrollHeight);
            } else {
                // Passa al nodo successivo
                currentNodeIndex++;
                if (currentNodeIndex < testo_nodes.length) {
                    words = testo_nodes[currentNodeIndex].originalText.split(' ');
                    currentWordIndex = 0;
                    currentText = '';
                }
            }
        }, velocita);
    }

    /* ============================================
       FUNZIONI MESSAGGI
       ============================================ */
    function appendi_messaggio(ruolo, html, useTypewriter){
        var $b=$('#assistente-ia-messaggi'),
            cls=(ruolo==='utente')?'assia-msg-utente':'assia-msg-assistente';
        
        var msgDiv = $('<div/>',{class:'assia-messaggio '+cls});
        
        if(ruolo === 'assistente' && useTypewriter !== false) {
            // Usa effetto typewriter per l'assistente
            msgDiv.appendTo($b);
            assiaTypewriter(html, msgDiv);
        } else {
            // Mostra immediatamente per messaggi utente o quando specificato
            msgDiv.html(html).appendTo($b);
            $b.scrollTop($b.prop('scrollHeight'));
        }
    }

    function render_preloader(){
        var $b=$('#assistente-ia-messaggi');
        $('<div/>',{class:'assia-preloader',id:'assia-preloader'})
            .html('<span class="assia-pallino"></span><span class="assia-pallino"></span><span class="assia-pallino"></span>')
            .appendTo($b);
        $b.scrollTop($b.prop('scrollHeight'));
    }
    
    function rimuovi_preloader(){ 
        $('#assia-preloader').remove(); 
    }

    /* ============================================
       GESTIONE ERRORI
       ============================================ */
    function assiaErrore(msg, dettagli){
        try{
            var box = $('#assistente-ia-messaggi'); 
            if(!box.length){ 
                console.error('Assistente IA:', msg, dettagli); 
                return; 
            }
            var el = $('<div class="assia-messaggio assia-msg-assistente assia-errore"></div>')
                .html('<em>⚠️ ' + msg + '</em>');
            box.append(el);
            box.scrollTop(box.prop('scrollHeight'));
            
            if (dettagli) {
                console.error('Assistente IA - Dettagli errore:', dettagli);
            }
        }catch(e){ 
            console.error('Assistente IA:', msg, e); 
        }
    }

    /* ============================================
       RIIDRATAZIONE CHAT
       ============================================ */
    function riidrata_chat(){
        $.post(AssistenteIA.ajax_url,{
            action:'assistente_ia_recupera_chat',
            hash_sessione: ottieni_o_crea_hash_sessione(),
            nonce: AssistenteIA.nonce
        }, function(resp){
            if(!resp||!resp.success) return;
            var arr=resp.data.messaggi||[],
                $b=$('#assistente-ia-messaggi');
            
            // Salva l'avviso iniziale se presente
            var avvisoIniziale = $b.find('.assia-avviso-iniziale').clone();
            
            $b.empty();
            
            // Se non ci sono messaggi, rimetti l'avviso iniziale
            if (arr.length === 0 && avvisoIniziale.length) {
                $b.append(avvisoIniziale);
            } else {
                // Mostra i messaggi senza typewriter (caricamento storico)
                arr.forEach(function(r){ 
                    appendi_messaggio(r.ruolo, r.testo, false); 
                });
            }
        });
    }

    /* ============================================
       CARICA STORICO
       ============================================ */
    function assiaCaricaStorico(){
        try{
            var hash = ottieni_o_crea_hash_sessione();
            if(!hash){ 
                console.warn('Assistente IA: Hash sessione non disponibile');
                return; 
            }
            
            $.post(AssistenteIA.ajax_url, { 
                action:'assistente_ia_storico', 
                _wpnonce: AssistenteIA.nonce, 
                hash_sessione: hash, 
                limite: 50 
            })
            .done(function(r){
                if(!r || !r.success || !r.data){ 
                    assiaErrore('Errore: risposta non valida dal server.', r); 
                    return; 
                }
                
                var box = $('#assistente-ia-messaggi'); 
                if(!box.length){ 
                    return; 
                }
                
                // Salva l'avviso iniziale se presente
                var avvisoIniziale = box.find('.assia-avviso-iniziale').clone();
                
                box.empty();
                
                var messaggi = r.data.messaggi || [];
                
                // Se non ci sono messaggi, rimetti l'avviso iniziale
                if (messaggi.length === 0 && avvisoIniziale.length) {
                    box.append(avvisoIniziale);
                } else {
                    // Mostra i messaggi senza typewriter
                    messaggi.forEach(function(m){
                        var ruolo = m.tipo === 'utente' ? 'utente' : 'assistente';
                        appendi_messaggio(ruolo, m.testo, false);
                    });
                }
                
                box.scrollTop(box.prop('scrollHeight'));
            })
            .fail(function(xhr, status, error){ 
                console.error('Assistente IA - storico fail:', {xhr: xhr, status: status, error: error});
                
                var msg = 'Errore nel caricamento delle conversazioni precedenti.';
                if (xhr.status === 403) {
                    msg = 'Accesso negato. Ricarica la pagina.';
                } else if (xhr.status === 404) {
                    msg = 'Endpoint non trovato. Verifica configurazione.';
                } else if (xhr.status === 500) {
                    msg = 'Errore del server. Riprova tra poco.';
                } else if (status === 'timeout') {
                    msg = 'Richiesta scaduta. Controlla la connessione.';
                }
                
                assiaErrore(msg);
            });
        }catch(e){
            console.error('Assistente IA - caricaStorico exception:', e);
        }
    }

    /* ============================================
       FUNZIONE INVIO MESSAGGIO
       ============================================ */
    function invia(){
        if (invioInCorso) {
            return;
        }
        
        var $input=$('#assistente-ia-input'), txt=$.trim($input.val());
        if(!txt){ 
            $input.focus(); 
            return; 
        }
        
        invioInCorso = true;
        $('#assistente-ia-invia').prop('disabled', true);
        
        appendi_messaggio('utente', txt, false);
        $input.val('');
        render_preloader();
        
        $.post(AssistenteIA.ajax_url, {
            action:'assistente_ia_chat',
            nonce: AssistenteIA.nonce,
            messaggio: txt,
            hash_sessione: ottieni_o_crea_hash_sessione(),
            post_id: AssistenteIA.currentPost||0
        })
        .done(function(res){
            rimuovi_preloader();
            
            if(res && res.success && res.data && res.data.risposta_html){
                // USA TYPEWRITER per la risposta AI
                appendi_messaggio('assistente', res.data.risposta_html, true);
            } else {
                var errMsg = (res && res.data && res.data.messaggio) 
                    ? res.data.messaggio 
                    : 'Risposta non valida dal server.';
                assiaErrore(errMsg);
            }
        })
        .fail(function(xhr, status, error){
            rimuovi_preloader();
            
            var msg = 'Errore di connessione. Riprova.';
            
            if (xhr.status === 403) {
                msg = 'Accesso negato. Ricarica la pagina e riprova.';
            } else if (xhr.status === 404) {
                msg = 'Endpoint non trovato. Contatta il supporto tecnico.';
            } else if (xhr.status === 500) {
                msg = 'Errore del server. Riprova tra qualche minuto.';
            } else if (xhr.status === 429) {
                msg = 'Troppe richieste. Attendi un momento prima di riprovare.';
            } else if (status === 'timeout') {
                msg = 'Richiesta scaduta. Controlla la tua connessione internet.';
            } else if (status === 'parsererror') {
                msg = 'Errore nella risposta del server. Contatta il supporto.';
            } else if (status === 'abort') {
                msg = 'Richiesta annullata.';
            }
            
            assiaErrore(msg, {xhr: xhr, status: status, error: error});
        })
        .always(function(){
            invioInCorso = false;
            $('#assistente-ia-invia').prop('disabled', false);
            $input.focus();
        });
    }

    /* ============================================
       GESTIONE OVERLAY E POPUP
       ============================================ */
    function apriChat() {
        $('#assistente-ia-popup').removeClass('assia-nascosto');
        $('#assistente-ia-overlay').addClass('attivo');
        
        setTimeout(function(){ 
            $('#assistente-ia-input').focus(); 
        }, 350);
        
        assiaCaricaStorico();
    }
    
    function chiudiChat() {
        $('#assistente-ia-popup').addClass('assia-nascosto');
        $('#assistente-ia-overlay').removeClass('attivo');
    }

    /* ============================================
       EVENTI UI
       ============================================ */
    $(document).on('click','#assistente-ia-bottone', function(){
        if($('#assistente-ia-popup').hasClass('assia-nascosto')){
            apriChat();
        } else {
            chiudiChat();
        }
    });
    
    // Chiudi cliccando sull'overlay
    $(document).on('click','#assistente-ia-overlay', function(){
        chiudiChat();
    });
    
    // Chiudi con il bottone X
    $(document).on('click','.assistente-ia-chiudi', function(){
        chiudiChat();
    });
    
    // Chiudi con ESC
    $(document).on('keydown', function(e){
        if(e.key === 'Escape' && !$('#assistente-ia-popup').hasClass('assia-nascosto')){
            chiudiChat();
        }
    });
    
    $(document).on('click','#assistente-ia-invia', invia);
    
    $(document).on('keydown','#assistente-ia-input',function(e){ 
        if(e.key==='Enter' && !e.shiftKey){ 
            e.preventDefault();
            invia(); 
        }
    });

    /* ============================================
       INIZIALIZZAZIONE
       ============================================ */
    $(function(){ 
        riidrata_chat(); 
    });

})(jQuery);

(function($){
    'use strict';

    // ✅ FIX v5.6.1: Tutte le funzioni dentro il closure + migliore gestione errori
    // ✅ FIX v5.6.1: Aggiunto throttling per prevenire invii multipli

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

    function appendi_messaggio(ruolo, html){
        var $b=$('#assistente-ia-messaggi'),
            cls=(ruolo==='utente')?'assia-msg-utente':'assia-msg-assistente';
        $('<div/>',{class:'assia-messaggio '+cls}).html(html).appendTo($b);
        $b.scrollTop($b.prop('scrollHeight'));
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

    // ✅ FIX v5.6.1: Gestione errori migliorata
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
            
            // Log dettagli in console per debug
            if (dettagli) {
                console.error('Assistente IA - Dettagli errore:', dettagli);
            }
        }catch(e){ 
            console.error('Assistente IA:', msg, e); 
        }
    }

    function riidrata_chat(){
        $.post(AssistenteIA.ajax_url,{
            action:'assistente_ia_recupera_chat',
            hash_sessione: ottieni_o_crea_hash_sessione(),
            nonce: AssistenteIA.nonce
        }, function(resp){
            if(!resp||!resp.success) return;
            var arr=resp.data.messaggi||[],
                $b=$('#assistente-ia-messaggi');
            
            // ✅ Salva l'avviso iniziale se presente
            var avvisoIniziale = $b.find('.assia-avviso-iniziale').clone();
            
            $b.empty();
            
            // Se non ci sono messaggi, rimetti l'avviso iniziale
            if (arr.length === 0 && avvisoIniziale.length) {
                $b.append(avvisoIniziale);
            } else {
                // Altrimenti mostra i messaggi
                arr.forEach(function(r){ 
                    appendi_messaggio(r.ruolo, r.testo); 
                });
            }
        });
    }

    // Funzione assiaCaricaStorico DENTRO il closure
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
                
                // ✅ Salva l'avviso iniziale se presente
                var avvisoIniziale = box.find('.assia-avviso-iniziale').clone();
                
                box.empty();
                
                var messaggi = r.data.messaggi || [];
                
                // Se non ci sono messaggi, rimetti l'avviso iniziale
                if (messaggi.length === 0 && avvisoIniziale.length) {
                    box.append(avvisoIniziale);
                } else {
                    // Altrimenti mostra i messaggi
                    messaggi.forEach(function(m){
                        var ruolo = m.tipo === 'utente' ? 'utente' : 'assistente';
                        appendi_messaggio(ruolo, m.testo);
                    });
                }
                
                box.scrollTop(box.prop('scrollHeight'));
            })
            .fail(function(xhr, status, error){ 
                console.error('Assistente IA - storico fail:', {xhr: xhr, status: status, error: error});
                
                // ✅ FIX v5.6.1: Messaggi di errore più specifici
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
                
                assiaErrore(msg, {xhr: xhr, status: status, error: error}); 
            });
        }catch(e){ 
            console.error('Assistente IA - storico exception', e); 
            assiaErrore('Errore imprevisto nel caricamento storico.', e);
        }
    }

    // Esponi globalmente solo se necessario per chiamate esterne
    window.assiaCaricaStorico = assiaCaricaStorico;

    /** ✅ FIX v5.6.1: Aggiunto throttling per prevenire invii multipli */
    function invia(){
        // ✅ THROTTLING: Previene invii multipli
        if (invioInCorso) {
            console.warn('Attendi la risposta precedente prima di inviare un nuovo messaggio');
            return;
        }
        
        var t=$('#assistente-ia-input').val().trim();
        if(!t) return;
        
        invioInCorso = true; // ✅ Blocca invii multipli
        
        $('#assistente-ia-input').val('');
        appendi_messaggio('utente',$('<div/>').text(t).html());
        render_preloader();
        
        $.post(AssistenteIA.ajax_url,{
            action:'assistente_ia_chat',
            messaggio:t,
            hash_sessione: ottieni_o_crea_hash_sessione(),
            post_id: AssistenteIA.currentPost,
            nonce: AssistenteIA.nonce
        }, function(resp){
            rimuovi_preloader();
            invioInCorso = false; // ✅ Rilascia il lock
            
            if(!resp||!resp.success){
                var errMsg = 'Si è verificato un errore.';
                if(resp && resp.data && resp.data.messaggio) {
                    errMsg += ' ' + resp.data.messaggio;
                }
                assiaErrore(errMsg, resp);
                return;
            }
            appendi_messaggio('assistente', resp.data.risposta_html);
        })
        .fail(function(xhr, status, error){
            rimuovi_preloader();
            invioInCorso = false; // ✅ Rilascia anche in caso di errore
            
            console.error('Assistente IA - invio fail:', {xhr: xhr, status: status, error: error});
            
            // ✅ FIX v5.6.1: Messaggi di errore più specifici basati sul tipo
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
        });
    }

    // UI eventi
    $(document).on('click','#assistente-ia-bottone',function(){
        $('#assistente-ia-popup').toggleClass('assia-nascosto'); 
        if(!$('#assistente-ia-popup').hasClass('assia-nascosto')){ 
            setTimeout(function(){ 
                $('#assistente-ia-input').focus(); 
            }, 100); 
            // Carica storico quando si apre
            assiaCaricaStorico();
        }
    });
    
    $(document).on('click','.assistente-ia-chiudi',function(){
        $('#assistente-ia-popup').addClass('assia-nascosto');
    });
    
    $(document).on('click','#assistente-ia-invia', invia);
    
    $(document).on('keydown','#assistente-ia-input',function(e){ 
        if(e.key==='Enter' && !e.shiftKey){ 
            e.preventDefault();
            invia(); 
        }
    });

    // All'avvio: riidrata chat se disponibile
    $(function(){ 
        riidrata_chat(); 
    });

})(jQuery);

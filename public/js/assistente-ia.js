(function($){
    'use strict';

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
    function rimuovi_preloader(){ $('#assia-preloader').remove(); }

    function riidrata_chat(){
        $.post(AssistenteIA.ajax_url,{
            action:'assistente_ia_recupera_chat',
            hash_sessione: ottieni_o_crea_hash_sessione(),
            nonce: AssistenteIA.nonce
        }, function(resp){
            if(!resp||!resp.success) return;
            var arr=resp.data.messaggi||[],
                $b=$('#assistente-ia-messaggi');
            $b.empty();
            arr.forEach(function(r){ appendi_messaggio(r.ruolo, r.testo); });
        });
    }

    function invia(){
        var t=$('#assistente-ia-input').val().trim();
        if(!t) return;
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
            if(!resp||!resp.success){
                appendi_messaggio('assistente','<em>Si è verificato un errore. '+(resp&&resp.data&&resp.data.messaggio?resp.data.messaggio:'')+'</em>');
                return;
            }
            appendi_messaggio('assistente', resp.data.risposta_html);
        });
    }

    // UI eventi
    $(document).on('click','#assistente-ia-bottone',function(){
        $('#assistente-ia-popup').toggleClass('assia-nascosto');
    });
    $(document).on('click','.assistente-ia-chiudi',function(){
        $('#assistente-ia-popup').addClass('assia-nascosto');
    });
    $(document).on('click','#assistente-ia-invia',invia);
    $(document).on('keypress','#assistente-ia-input',function(e){ if(e.which===13) invia(); });

    // All’avvio: riidrata
    $(function(){ riidrata_chat(); });

})(jQuery);

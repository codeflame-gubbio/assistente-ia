(function($){
    'use strict';

    // Genera o recupera un hash sessione locale (riidratazione cross-pagina)
    function ottieni_o_crea_hash_sessione(){
  if (window.AssistenteIA && AssistenteIA.hash) {
    return AssistenteIA.hash;
  }
  try {
    var k='assistente_ia_hash', h=localStorage.getItem(k);
    if(!h){
      h=(Date.now().toString(36)+Math.random().toString(36).slice(2,10)).toUpperCase();
      localStorage.setItem(k,h);
    }
    return h;
  } catch(e) {
    console.warn('localStorage non disponibile, uso hash temporaneo');
    if(!window._assia_temp_hash){
      window._assia_temp_hash = Date.now().toString(36)+Math.random().toString(36).slice(2,10);
    }
    return window._assia_temp_hash;
  }
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
        $('#assistente-ia-popup').toggleClass('assia-nascosto'); if(!$('#assistente-ia-popup').hasClass('assia-nascosto')){ setTimeout(function(){ $('#assistente-ia-input').focus(); }, 0); assiaCaricaStorico && assiaCaricaStorico(); }
    });
    $(document).on('click','.assistente-ia-chiudi',function(){
        $('#assistente-ia-popup').addClass('assia-nascosto');
    });
    $(document).on('click','#assistente-ia-invia',invia);
    $(document).on('keydown','#assistente-ia-input',function(e){ if(e.key==='Enter' && !e.shiftKey) invia(); });

    // All’avvio: riidrata
    function assiaErrore(msg){
  try{
    var box = $('#assistente-ia-messaggi'); if(!box.length){ console.error('Assistente IA:', msg); return; }
    var el = $('<div class="assia-msg assia-errore"></div>').text(msg); box.append(el);
    box.scrollTop(box.prop('scrollHeight'));
  }catch(e){ console.error('Assistente IA:', msg, e); }
}
$(function(){ riidrata_chat(); });

})(jQuery);

function assiaCaricaStorico(){
  try{
    var hash = (window.AssistenteIA && AssistenteIA.hash) || '';
    if(!hash){ return; }
    $.post(AssistenteIA.ajax_url, { action:'assistente_ia_storico', _wpnonce: AssistenteIA.nonce, hash_sessione: hash, limite: 50 })
     .done(function(r){
        if(!r || !r.success || !r.data){ assiaErrore('Errore: risposta non valida dal server.'); return; }
        var box = $('#assistente-ia-messaggi'); if(!box.length){ return; } box.empty();
        (r.data.messaggi || []).forEach(function(m){
          var el = $('<div class="assia-msg"></div>').addClass(m.tipo==='utente'?'assia-utente':'assia-bot'); el.text(m.testo); box.append(el);
        });
        box.scrollTop(box.prop('scrollHeight'));
     })
     .fail(function(){ assiaErrore('Errore nel caricamento delle conversazioni precedenti.'); });
  }catch(e){ console.error('Assistente IA - storico exception', e); }
}

(function($){
  'use strict';

  function stampa(msg){
    var $box = $('#assia-emb-stato');
    var p = $('<div/>').text('• '+msg);
    $box.append(p);
    $box.scrollTop($box.prop('scrollHeight'));
  }

  var inCorso = false;

  $('#assia-emb-avvia').on('click', function(){
    if(inCorso) return;
    inCorso = true;
    $('#assia-emb-stato').empty();
    stampa('Avvio rigenerazione...');
    $.post(AssistenteIAAdmin.ajax_url, { action:'assia_embeddings_avvia', nonce: AssistenteIAAdmin.nonce }, function(resp){
      if(!resp || !resp.success){
        stampa('Errore: '+(resp && resp.data && resp.data.messaggio ? resp.data.messaggio : 'avvio fallito'));
        inCorso=false;
        return;
      }
      stampa('Job preparato: '+resp.data.totale+' post da processare.');
      $('#assia-emb-step').prop('disabled', false);
    });
  });

  $('#assia-emb-step').on('click', function(){
    if(!inCorso) return;
    $('#assia-emb-step').prop('disabled', true);
    $.post(AssistenteIAAdmin.ajax_url, { action:'assia_embeddings_step', nonce: AssistenteIAAdmin.nonce, batch: 5 }, function(resp){
      $('#assia-emb-step').prop('disabled', false);
      if(!resp || !resp.success){
        stampa('Errore: '+(resp && resp.data && resp.data.errore ? resp.data.errore : 'step fallito'));
        inCorso=false;
        return;
      }
      var d = resp.data;
      stampa('Progresso: '+d.indice+'/'+d.totale+' post, '+d.creati+' chunks creati. ('+d.percentuale+'%)');
      if(d.stato === 'completato'){
        stampa('Completato!');
        inCorso = false;
        $('#assia-emb-step').prop('disabled', true);
      }
    });
  });

})(jQuery);


(function($){
  console.info('AssiaRag:', typeof AssiaRag !== 'undefined' ? AssiaRag : '(missing)');

  function appendErr(msg){
    try {
      const box = $('#assia-log');
      box.append('[ERRORE] ' + msg + '\n');
      box.scrollTop(box[0].scrollHeight);
    } catch(e){ console.error(msg); }
  }

  // Helper per inviare sempre action + nonce
  function ajaxPost(action, data){
    data = data || {};
    data.action = action;
    data._ajax_nonce = (window.AssiaRag && AssiaRag.nonce) ? AssiaRag.nonce : '';
    return $.post(AssiaRag.ajax_url, data);
  }

  let polling = null, runningStep = false, lastUpdateTs = 0;

  function drawState(s){
    const p = parseInt(s.percentuale||0,10);
    $('#assia-perc').text(p + '%');
    $('#assia-bar-fill').css('width', p + '%');
    $('#assia-post').text((s.posts_done||0) + '/' + (s.posts_total||0) + ' post');
    const chunksTxt = (s.chunks_total ? (s.chunks_done||0) + '/' + s.chunks_total : (s.chunks_done||0)) + ' chunks';
    $('#assia-chunks').text(chunksTxt);
  }

  function logAppend(rows){
    if(!rows || !rows.length) return;
    const box = $('#assia-log');
    rows.forEach(r=>{
      const t = r.t ? new Date(r.t*1000).toLocaleTimeString() : '';
      box.append('['+t+'] ' + (r.msg||'') + '\n');
    });
    box.scrollTop(box[0].scrollHeight);
  }

  function stato(){
    return ajaxPost('assia_embeddings_stato', {})
      .done(res=>{
        if(!res || !res.success){ appendErr('stato: risposta non valida'); return; }
        const s = res.data || {};
        drawState(s);
        logAppend(s.log || []);
        lastUpdateTs = Date.now();
        if(s.status === 'running' && !runningStep){
          runningStep = true;
          step().always(()=> runningStep = false);
        }
        if(s.status === 'done'){
          clearInterval(polling);
          $('#assia-avvia').prop('disabled', false).text('Avvia nuova rigenerazione');
        }
      })
      .fail(xhr=> appendErr('stato FAIL ' + (xhr && xhr.status)));
  }

  function step(){
    return ajaxPost('assia_embeddings_step', {})
      .fail(xhr=> appendErr('step FAIL ' + (xhr && xhr.status)));
  }

  function watchdog(){
    if(!lastUpdateTs) return;
    const diff = Date.now() - lastUpdateTs;
    if(diff > 10000 && !runningStep){
      runningStep = true;
      step().always(()=> runningStep = false);
    }
  }

  $(document).on('click', '#assia-avvia', function(){
    const $btn = $(this).prop('disabled', true).text('In corso…');
    $('#assia-log').text('');
    ajaxPost('assia_embeddings_avvia', {})
      .done(res=>{
        if(!res || !res.success){
          appendErr('avvia: risposta non valida');
          $btn.prop('disabled',false).text('Avvia rigenerazione');
          return;
        }
        if(polling) clearInterval(polling);
        polling = setInterval(stato, 2500);
        stato();
        setInterval(watchdog, 4000);
      })
      .fail(xhr=>{
        appendErr('avvia FAIL ' + (xhr && xhr.status));
        $btn.prop('disabled', false).text('Avvia rigenerazione');
      });
  });

  $(function(){
    polling = setInterval(stato, 2500);
    stato();
    setInterval(watchdog, 4000);
  });
})(jQuery);

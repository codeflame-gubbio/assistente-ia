(function($){
  console.info('AssiaRag:', typeof AssiaRag !== 'undefined' ? AssiaRag : '(missing)');

  function appendErr(msg){
    try {
      const box = $('#assia-log');
      box.append('[ERRORE] ' + msg + '\n');
      box.scrollTop(box[0].scrollHeight);
      console.error('ASSIA RAG:', msg); // Debug aggiuntivo
    } catch(e){ console.error(msg); }
  }

  function appendInfo(msg){
    try {
      const box = $('#assia-log');
      box.append('[INFO] ' + msg + '\n');
      box.scrollTop(box[0].scrollHeight);
    } catch(e){ console.log(msg); }
  }

  // Helper per inviare sempre action + nonce
  function ajaxPost(action, data){
    data = data || {};
    data.action = action;
    data._ajax_nonce = (window.AssiaRag && AssiaRag.nonce) ? AssiaRag.nonce : '';
    return $.post(AssiaRag.ajax_url, data);
  }

  let polling = null, runningStep = false, lastUpdateTs = 0;
  let jobAttivo = false; // ← NUOVO: flag per sapere se c'è un job

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
        // ✅ GESTIONE MIGLIORATA DELLO STATO
        if(!res || !res.success){ 
          // Se non c'è success, ma non è un errore critico
          if(res && res.data && res.data.msg === 'bad-nonce'){
            appendErr('stato: nonce non valido. Ricarica la pagina.');
            clearInterval(polling);
            return;
          }
          // Non logghiamo errore se semplicemente non c'è un job
          if(!jobAttivo){
            console.log('ASSIA RAG: Nessun job attivo (normale)');
            return;
          }
          appendErr('stato: risposta non valida'); 
          return; 
        }
        
        const s = res.data || {};
        
        // ✅ GESTIONE STATO "nessun_job"
        if(s.stato === 'nessun_job'){
          if(jobAttivo){
            appendInfo('Job completato o interrotto');
            jobAttivo = false;
            clearInterval(polling);
            $('#assia-avvia').prop('disabled', false).text('Avvia rigenerazione');
          }
          // Non è un errore, semplicemente non c'è un job
          return;
        }
        
        // ✅ Job attivo trovato
        jobAttivo = true;
        drawState(s);
        logAppend(s.log || []);
        lastUpdateTs = Date.now();
        
        if(s.status === 'running' && !runningStep){
          runningStep = true;
          step().always(()=> runningStep = false);
        }
        
        if(s.status === 'done' || s.stato === 'completato'){
          clearInterval(polling);
          jobAttivo = false;
          $('#assia-avvia').prop('disabled', false).text('Avvia nuova rigenerazione');
          appendInfo('✓ Rigenerazione completata!');
        }
      })
      .fail(xhr=> {
        const status = xhr && xhr.status ? xhr.status : 'unknown';
        const responseText = xhr && xhr.responseText ? xhr.responseText : '';
        
        // ✅ GESTIONE ERRORI HTTP DETTAGLIATA
        if(status === 403){
          appendErr('stato FAIL 403: Permessi insufficienti o sessione scaduta. Ricarica la pagina.');
          clearInterval(polling);
        } else if(status === 500){
          appendErr('stato FAIL 500: Errore server. Controlla i log PHP.');
          console.error('Response:', responseText);
        } else {
          appendErr('stato FAIL ' + status);
          console.error('ASSIA RAG stato fail:', { status, responseText });
        }
      });
  }

  function step(){
    return ajaxPost('assia_embeddings_step', {})
      .fail(xhr=> {
        const status = xhr && xhr.status ? xhr.status : 'unknown';
        appendErr('step FAIL ' + status);
      });
  }

  function watchdog(){
    if(!lastUpdateTs || !jobAttivo) return;
    const diff = Date.now() - lastUpdateTs;
    if(diff > 10000 && !runningStep){
      runningStep = true;
      step().always(()=> runningStep = false);
    }
  }

  $(document).on('click', '#assia-avvia', function(){
    const $btn = $(this).prop('disabled', true).text('In corso…');
    $('#assia-log').text('');
    appendInfo('Avvio rigenerazione embeddings...');
    
    ajaxPost('assia_embeddings_avvia', {})
      .done(res=>{
        if(!res || !res.success){
          const errMsg = (res && res.data && res.data.msg) || 'risposta non valida';
          appendErr('avvia: ' + errMsg);
          $btn.prop('disabled',false).text('Avvia rigenerazione');
          return;
        }
        
        jobAttivo = true; // ← IMPOSTA FLAG
        appendInfo('Job preparato: ' + (res.data.totale || 0) + ' voci da processare');
        
        if(polling) clearInterval(polling);
        polling = setInterval(stato, 2500);
        stato();
        setInterval(watchdog, 4000);
      })
      .fail(xhr=>{
        const status = xhr && xhr.status ? xhr.status : 'unknown';
        appendErr('avvia FAIL ' + status);
        $btn.prop('disabled', false).text('Avvia rigenerazione');
      });
  });

  // ✅ POLLING INIZIALE SOLO SE C'È UN JOB ATTIVO
  $(function(){
    // Prima verifica se c'è un job attivo
    ajaxPost('assia_embeddings_stato', {})
      .done(res=>{
        if(res && res.success && res.data && res.data.stato !== 'nessun_job'){
          // Job attivo trovato, avvia polling
          jobAttivo = true;
          appendInfo('Job in corso rilevato, riprendo il monitoraggio...');
          polling = setInterval(stato, 2500);
          stato();
          setInterval(watchdog, 4000);
        } else {
          // Nessun job, non serve polling
          appendInfo('Pronto. Nessun job in corso.');
        }
      })
      .fail(xhr=>{
        // Se fallisce la prima chiamata, non è critico
        console.warn('ASSIA RAG: Impossibile verificare stato iniziale');
      });
  });
  
})(jQuery);
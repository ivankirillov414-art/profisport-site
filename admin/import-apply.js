(()=>{
  const btn=document.getElementById('importApplyBtn');
  const out=document.getElementById('importApplyResult');
  if(!btn)return;
  const sleep=ms=>new Promise(r=>setTimeout(r,ms));
  btn.addEventListener('click',async()=>{
    btn.disabled=true;
    btn.textContent='Переношу товары…';
    let offset=0,total=0,created=0,updated=0,reused=0,batches=0;
    try{
      if(typeof window.refreshProfisportAuth==='function') await window.refreshProfisportAuth();
      while(true){
        const headers={};
        const token=typeof window.getProfisportCsrf==='function'?window.getProfisportCsrf():'';
        if(token)headers['X-CSRF-Token']=token;
        const r=await fetch(`../api/import-apply.php?offset=${offset}&limit=500`,{method:'POST',credentials:'same-origin',headers});
        const d=await r.json().catch(()=>({ok:false,error:'bad_json'}));
        if(!r.ok||!d.ok)throw new Error(d.error||`HTTP ${r.status}`);
        batches++;
        total=Number(d.total_rows||total||0);
        created+=Number(d.created||0);
        updated+=Number(d.updated||0);
        reused+=Number(d.rows_reusing_existing_images||0);
        offset=Number(d.next_offset||offset+Number(d.processed||0));
        if(out)out.textContent=`Перенос: ${Math.min(offset,total).toLocaleString('ru-RU')} из ${total.toLocaleString('ru-RU')} · новых ${created.toLocaleString('ru-RU')} · обновлено ${updated.toLocaleString('ru-RU')}`;
        if(d.done)break;
        if(!d.processed)throw new Error('import_stalled');
        await sleep(80);
      }
      if(out)out.textContent=`Готово: ${total.toLocaleString('ru-RU')} строк обработано. Новых ${created.toLocaleString('ru-RU')}, обновлено ${updated.toLocaleString('ru-RU')}, существующие фото сохранены у ${reused.toLocaleString('ru-RU')} строк.`;
      btn.textContent='Повторно синхронизировать выгрузку';
      if(typeof window.refreshProfisportDashboard==='function') await window.refreshProfisportDashboard();
    }catch(e){
      if(out)out.textContent='Ошибка переноса: '+e.message;
      btn.textContent='Повторить перенос';
    }finally{btn.disabled=false;}
  });
})();
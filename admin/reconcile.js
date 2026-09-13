(()=>{
  const anchor=document.getElementById('analyzeImportResult');
  if(!anchor||document.getElementById('reconcileImportBtn'))return;
  const button=document.createElement('button');
  button.id='reconcileImportBtn';button.className='secondary';button.type='button';
  button.textContent='Сверить с живой базой';
  const message=document.createElement('div');message.className='msg muted';
  const output=document.createElement('div');
  anchor.insertAdjacentElement('afterend',output);
  output.insertAdjacentElement('beforebegin',message);
  message.insertAdjacentElement('beforebegin',button);
  anchor.insertAdjacentHTML('afterend','<br>');

  const number=value=>Number(value||0).toLocaleString('ru-RU');
  button.addEventListener('click',async()=>{
    button.disabled=true;message.className='msg muted';message.textContent='Сверяю исходные связи с живой MySQL…';output.innerHTML='';
    try{
      const response=await fetch('../api/import-reconcile.php',{cache:'no-store',credentials:'same-origin'});
      const data=await response.json().catch(()=>({error:'bad_json'}));
      if(!response.ok||!data.ok)throw new Error(data.detail||data.error||('HTTP '+response.status));
      const stats=data.stats||{};
      message.className='msg ok';message.textContent='Сверка с живой MySQL завершена. Данные не изменены.';
      const metrics=[
        [stats.db_products,'всего товаров в MySQL'],[stats.db_active_products,'активных товаров'],
        [stats.source_exact_unique_rows,'безопасных точных связей в выгрузке'],[stats.matched_products,'найдено товаров по коду'],
        [stats.correct_main_photo,'уже имеют родное основное фото'],[stats.repairable_existing_products,'можно восстановить автоматически'],
        [stats.wrong_or_unlinked_photo,'имеют чужое или несвязанное фото'],[stats.empty_photo_fields,'не имеют фото в базе'],
        [stats.native_photo_only_secondary,'родное фото есть только в галерее'],[stats.missing_products,'товаров из выгрузки нет в базе']
      ];
      output.innerHTML='<style>#reconcileImportResult .analysisGrid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:12px 0}#reconcileImportResult .analysisGrid>div{padding:12px;border:1px solid #e3e3df;border-radius:12px;background:#fff;min-width:0}#reconcileImportResult .analysisGrid b{display:block;font-size:23px}#reconcileImportResult .analysisGrid span{display:block;font-size:13px;overflow-wrap:anywhere}#reconcileImportResult .analysisSample{padding:10px 0;border-bottom:1px solid #e3e3df;overflow-wrap:anywhere}</style>'+
        '<div class="analysisGrid">'+metrics.map(([value,label])=>'<div><b>'+number(value)+'</b><span>'+label+'</span></div>').join('')+'</div>';
      output.id='reconcileImportResult';
      const wrong=data.examples?.wrong||[];
      if(wrong.length){const heading=document.createElement('h3');heading.textContent='Примеры неправильных связей';output.appendChild(heading);for(const item of wrong){const row=document.createElement('div');row.className='analysisSample';const title=document.createElement('b');title.textContent=item.name+' · код '+item.source_id;const detail=document.createElement('div');detail.className='muted';detail.textContent='Сейчас: '+(item.current||'пусто')+' · должно быть: '+item.expected.join(', ');row.append(title,detail);output.appendChild(row);}}
    }catch(error){message.className='msg err';message.textContent='Ошибка сверки: '+error.message;}
    finally{button.disabled=false;}
  });
})();

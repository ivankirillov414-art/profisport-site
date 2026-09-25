<?php require __DIR__.'/guard.php'; ?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>ПрофиСпорт — фото из 1С</title>
<style>
:root{--y:#f2c94c;--bg:#f5f5f3;--tx:#202124;--muted:#747a80;--line:#e3e3df;--green:#19763b;--red:#a92b20;--page:980px}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--tx);font:15px/1.45 system-ui,-apple-system,"Segoe UI",sans-serif}
.top{background:linear-gradient(135deg,#454b51,#2f3439);color:#fff}.topin{width:min(var(--page),calc(100% - 28px));min-height:62px;margin:auto;display:flex;align-items:center;justify-content:space-between;gap:12px}
.brand{font-size:20px;font-weight:900}.brand i{font-style:normal;color:var(--y)}.back{color:#fff;text-decoration:none;background:#ffffff17;border:1px solid #ffffff26;padding:10px 13px;border-radius:10px}
main{width:min(var(--page),calc(100% - 28px));margin:22px auto 60px}.card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:20px;margin:14px 0}.notice{background:#fff8d8;border-color:#efd36a}
h1{margin:0 0 8px}.muted{color:var(--muted)}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px}.metric{background:#f7f7f4;border-radius:13px;padding:13px}.metric b{display:block;font-size:24px}.metric span{font-size:12px;color:var(--muted)}
button{border:0;border-radius:11px;padding:11px 15px;font:inherit;font-weight:800;background:var(--y);cursor:pointer}.ok{color:var(--green)}.bad{color:var(--red)}.list{display:grid;gap:8px;margin-top:12px}.row{padding:10px 12px;border:1px solid var(--line);border-radius:11px;background:#fafaf8}
@media(max-width:720px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
</head>
<body>
<header class="top"><div class="topin"><div class="brand">Профи<i>Спорт</i></div><a class="back" href="index.php">← Админка</a></div></header>
<main>
<section class="card notice">
<h1>Фотографии из актуальной выгрузки 1С</h1>
<p>Фото товара берётся только из текущей выгрузки 1С / записи MySQL. Ручная загрузка, подбор из старого каталога и поиск картинок в интернете отключены.</p>
<p class="muted">Если у товара в выгрузке нет физического фото, сайт сохраняет это как отсутствие фото. Ни администратор, ни фоновый процесс не подменяют его сторонним изображением.</p>
</section>
<section class="card">
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap"><div><h2 style="margin:0">Диагностика</h2><div id="generated" class="muted">Загрузка…</div></div><button id="refresh" type="button">Обновить</button></div>
<div id="metrics" class="grid"></div>
<div id="message" class="muted" style="margin-top:12px"></div>
</section>
<section class="card">
<h2 style="margin-top:0">Товары без рабочего фото</h2>
<div id="examples" class="list"><div class="muted">Загрузка…</div></div>
</section>
</main>
<script>
(()=>{
const $=id=>document.getElementById(id),esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
async function load(){
 $('refresh').disabled=true;$('message').textContent='Проверяю актуальные записи MySQL и локальные файлы из выгрузки…';
 try{
   const r=await fetch('../api/photo-health.php?v=2',{cache:'no-store'}),j=await r.json();
   if(!r.ok||!j.ok)throw new Error(j.error||('HTTP '+r.status));
   const s=j.stats||{};
   $('generated').textContent='Проверено: '+(j.generated_at||'сейчас');
   const cards=[
     [s.active_products||0,'активных товаров'],
     [s.products_with_working_image||0,'с рабочим фото'],
     [s.products_without_db_image||0,'без фото в выгрузке'],
     [s.products_with_broken_local_only||0,'с битой локальной ссылкой']
   ];
   $('metrics').innerHTML=cards.map(x=>'<div class="metric"><b>'+Number(x[0])+'</b><span>'+esc(x[1])+'</span></div>').join('');
   $('message').innerHTML='<span class="ok">Источник фото: только текущая 1С/MySQL. Автоподбор и ручная подмена отключены.</span>';
   const list=j.unresolved_examples||[];
   $('examples').innerHTML=list.length?list.map(x=>'<div class="row"><b>#'+Number(x.id)+' · '+esc(x.name)+'</b><div class="muted">'+esc(x.category||'Без категории')+' · '+esc(x.reason||'нет фото')+'</div></div>').join(''):'<div class="ok">У активных товаров нет проблем с рабочими фотографиями.</div>';
 }catch(e){$('message').innerHTML='<span class="bad">Не удалось выполнить диагностику: '+esc(e.message)+'</span>';$('examples').innerHTML='';}
 finally{$('refresh').disabled=false}
}
$('refresh').onclick=load;load();
})();
</script>
</body>
</html>

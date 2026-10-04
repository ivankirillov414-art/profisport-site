<?php require __DIR__.'/guard.php'; ?>
<!doctype html><html lang="ru"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow">
<title>ПрофиСпорт — фото из 1С и ручная загрузка</title>
<link rel="stylesheet" href="photos.css?v=manual-photos-2">
<link rel="stylesheet" href="layout.css?v=1"></head><body>
<header><div class="bar"><b>Профи<span>Спорт</span></b><a href="index.php">← Админка</a></div></header>
<main>
<section class="card">
<h1>Фото из 1С</h1>
<p>Найдите товар и добавьте фотографию вручную, замените основную или удалите ненужные снимки. Изменения фотографий сохраняются при следующих обновлениях каталога.</p>
<form id="photoSearch" role="search"><label for="photoQuery">Поиск товара</label><div class="search"><input id="photoQuery" type="search" maxlength="160" placeholder="Название, модель, артикул, код 1С или ID" autocomplete="off"><button type="submit">Найти</button></div></form>
<div class="filters"><label><input id="photoMissing" type="checkbox"> Только без фото</label><label><input id="photoArchived" type="checkbox"> Включая скрытые товары</label><button id="photoRefresh" class="secondary" type="button">Обновить список</button></div>
<div id="photoStatus" role="status" aria-live="polite">Загружаю каталог…</div>
</section>
<section id="photoResults" class="products" aria-label="Товары"></section>
<nav class="pager" aria-label="Страницы каталога"><button id="photoPrev" type="button" class="secondary">← Назад</button><span id="photoPage">—</span><button id="photoNext" type="button" class="secondary">Далее →</button></nav>
<details class="card" id="photoDiagnostics"><summary>Диагностика фотографий 1С</summary><button id="photoAuditRefresh" type="button" class="secondary">Проверить</button><p id="photoAuditStatus" role="status">Проверяет ссылки и наличие локальных файлов. Доступность внешних сайтов не проверяется.</p></details>
</main>
<dialog id="photoEditor" aria-labelledby="photoEditorTitle">
<div class="dialog-head"><div><small>ФОТОГРАФИИ ТОВАРА</small><h2 id="photoEditorTitle">Загрузка…</h2></div><button id="photoClose" class="secondary" type="button" aria-label="Закрыть редактор">Закрыть</button></div>
<p id="photoProductMeta" class="muted"></p><a id="photoProductLink" target="_blank" rel="noopener">Открыть карточку на сайте ↗</a>
<div id="photoGallery" class="gallery"></div>
<section class="upload-box"><h3>Добавить фотографию</h3>
<label for="photoFile">Изображение JPG, PNG или WebP</label><input id="photoFile" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
<p id="photoLimit" class="muted"></p><img id="photoPreview" alt="Предпросмотр выбранной фотографии" hidden>
<label class="choice"><input id="photoMakePrimary" type="checkbox" checked> Сделать основным / заменить главное фото</label>
<p class="muted">Снимите галочку, чтобы только добавить фото в галерею. Прежние изображения не удаляются.</p>
<button id="photoSave" type="button" disabled>Загрузить и сохранить</button>
</section>
<p id="photoEditorStatus" role="status" aria-live="polite"></p>
</dialog>
<script src="photos.js?v=manual-photos-2"></script>
</body></html>

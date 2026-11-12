<?php require __DIR__.'/guard.php'; ?>
<!doctype html>
<html lang="ru">
<head>
 <meta charset="utf-8">
 <meta name="viewport" content="width=device-width,initial-scale=1">
 <title>Ссылки для 2ГИС — ПрофиСпорт</title>
 <link rel="stylesheet" href="../styles.css">
 <link rel="stylesheet" href="layout.css?v=2">
 <link rel="stylesheet" href="product-links.css?v=1">
</head>
<body class="motion-admin">
<?php require __DIR__.'/menu.php'; ?>
<main class="productLinks">
 <a href="index.php">← Управление</a>
 <h1>Ссылки на товары для 2ГИС</h1>
 <p>Найдите товар и скопируйте ссылку в карточку товара в 2ГИС. UTM-ссылка открывает ту же карточку и помечает источник перехода как 2gis.</p>
 <form id="linkSearch" role="search">
  <label for="linkQuery">Название или артикул товара</label>
  <div class="linkSearchRow"><input id="linkQuery" type="search" placeholder="Например: велосипед или артикул" maxlength="200" autocomplete="off"><button type="submit">Найти</button></div>
 </form>
 <p id="linkStatus" role="status" aria-live="polite"></p>
 <div id="linkList" aria-busy="true"></div>
 <nav class="linkPages" aria-label="Страницы товаров"><button id="linkPrev" type="button" disabled>Назад</button><span id="linkPageInfo"></span><button id="linkNext" type="button" disabled>Далее</button></nav>
 <p id="copyStatus" role="status" aria-live="polite"></p>
</main>
<script src="product-links.js?v=1" defer></script>
</body>
</html>

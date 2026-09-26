<?php require __DIR__.'/guard.php'; ?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title>Центр лояльности — ПрофиСпорт</title>
  <link rel="stylesheet" href="loyalty-calculator.css?v=3">
</head>
<body>
<header><div class="top"><div class="brand">Профи<span>Спорт</span></div><a class="back" href="index.php">← Админка</a></div></header>
<main>
  <div class="pageHead"><div><h1>Центр лояльности</h1><p>Единственное место для категорийных скидок, персональных скидок и бонусных правил.</p></div><a class="customersLink" href="customers.php">Карточки клиентов →</a></div>
  <section id="programState" class="programState"><div><b>Загрузка…</b><p>Проверяем активную и черновую конфигурации.</p></div></section>
  <section id="stats" class="stats"></section>

  <div class="layout">
    <div class="editorColumn">
      <section class="panel">
        <div class="sectionHead"><div><h2>Скидочные группы</h2><p>Группа означает только те категории, которые вы отметили флажками. Новая категория из 1С не попадёт сюда автоматически.</p></div><button id="addDiscountGroup" type="button" class="secondaryAction">+ Добавить группу</button></div>
        <div id="discountGroups" class="discountGroups"></div>
      </section>

      <section class="panel">
        <h2>Правила пересечения</h2>
        <p class="panelIntro">Эти чекпойнты обязательны, чтобы система не принимала скрытых решений.</p>
        <div class="ruleGrid">
          <label><span>Если действует категорийная и персональная скидка</span><select id="discountStackRule"><option value="max">Применять максимальную</option><option value="sum">Складывать, максимум 90%</option><option value="personal_overrides">Персональная заменяет категорийную</option></select></label>
          <label><span>Бонусы начислять от суммы</span><select id="earnBasis"><option value="after_discounts">После всех скидок</option><option value="before_discounts">До скидок</option></select></label>
        </div>
      </section>

      <section class="panel">
        <h2>Бонусная система</h2>
        <div class="optionCard"><div class="optionHead"><div><strong>Начислять бонусы за покупки</strong><small>Начисление после статуса «Завершён».</small></div><label class="switch"><input id="earnEnabled" type="checkbox"><span></span></label></div><div id="earnControls"><div class="controlRow"><input id="earnPercentRange" type="range" min="0" max="3000" step="50" value="500"><input id="earnPercentNumber" type="number" min="0" max="30" step="0.5" value="5"></div><div class="controlRow"><input id="minOrderRange" type="range" min="0" max="50000" step="500" value="0"><input id="minOrderNumber" type="number" min="0" max="10000000" step="500" value="0"></div></div></div>
        <div class="optionCard"><div class="optionHead"><div><strong>Оплата бонусами</strong><small>Максимальная доля заказа, которую можно закрыть бонусами.</small></div><label class="switch"><input id="redeemEnabled" type="checkbox"><span></span></label></div><div id="redeemControls" class="controlRow"><input id="redeemPercentRange" type="range" min="0" max="10000" step="500" value="3000"><input id="redeemPercentNumber" type="number" min="0" max="100" step="5" value="30"></div></div>
        <div class="optionCard"><div class="optionHead"><div><strong>Стоимость 1 бонуса</strong><small>Рублёвая стоимость одного бонуса при списании.</small></div></div><div class="controlRow"><input id="pointValueRange" type="range" min="10" max="500" step="10" value="100"><input id="pointValueNumber" type="number" min="0.1" max="5" step="0.1" value="1"></div></div>
        <div class="optionCard"><div class="optionHead"><div><strong>Срок действия бонусов</strong><small>FIFO расходует старые партии первыми.</small></div><label class="switch"><input id="expirationEnabled" type="checkbox"><span></span></label></div><div id="expirationControls" class="controlRow"><input id="expirationDaysRange" type="range" min="30" max="1095" step="30" value="365"><input id="expirationDaysNumber" type="number" min="1" max="3650" step="30" value="365"></div></div>
        <div class="optionCard"><div class="optionHead"><div><strong>Бонус за опубликованный отзыв</strong><small>Фиксированное начисление после одобрения проверенного отзыва.</small></div><label class="switch"><input id="reviewBonusEnabled" type="checkbox"><span></span></label></div><div id="reviewControls" class="controlRow"><input id="reviewBonusRange" type="range" min="0" max="1000" step="25" value="50"><input id="reviewBonusNumber" type="number" min="0" max="1000000" step="25" value="50"></div></div>
        <div class="optionCard"><div class="optionHead"><div><strong>Исключить категории из начисления бонусов</strong><small>Отдельно от скидочных групп.</small></div><label class="switch"><input id="categoryExclusionsEnabled" type="checkbox"><span></span></label></div><div id="categoryArea" class="categoryTools" hidden><input id="categorySearch" class="categorySearch" placeholder="Поиск категории"><div id="categoryList" class="categoryList"></div><div id="selectedCategories" class="selectedCategories"></div></div></div>
      </section>

      <section class="panel">
        <h2>Готовность</h2>
        <div class="readiness"><div class="readinessRow"><span>Скидочные группы</span><span id="readyGroups"></span></div><div class="readinessRow"><span>Правило пересечения скидок</span><span id="readyStack"></span></div><div class="readinessRow"><span>База начисления бонусов</span><span id="readyBasis"></span></div><div class="readinessRow"><span>Бонусная конфигурация</span><span id="readyConfig"></span></div></div>
      </section>
    </div>

    <aside class="previewColumn">
      <section class="panel stickyPanel"><h2>Предпросмотр скидок</h2><p class="panelIntro">До 10 реальных товаров из актуального каталога. Это предпросмотр черновика — сайт ещё не изменён.</p><div id="productPreview" class="productPreview"></div></section>
      <section class="panel"><h2>Экономика бонусов</h2><div class="scenarioGrid"><div class="scenarioField"><label><span>Сумма заказа</span><output id="scenarioOrderOut">10 000 ₽</output></label><input id="scenarioOrder" type="range" min="500" max="200000" step="500" value="10000"></div><div class="scenarioField"><label><span>Участвует в начислении</span><output id="scenarioEligibleOut">100%</output></label><input id="scenarioEligibleShare" type="range" min="0" max="100" step="5" value="100"></div><div class="scenarioField"><label><span>Баланс клиента</span><output id="scenarioBalanceOut">2 000 бонусов</output></label><input id="scenarioBalance" type="range" min="0" max="50000" step="100" value="2000"></div></div><div class="results"><div class="result emphasis"><span>Начислим</span><b id="earnedPoints">0</b></div><div class="result"><span>Можно списать</span><b id="maxSpendPoints">0</b></div><div class="result"><span>Скидка бонусами</span><b id="discountRub">0 ₽</b></div><div class="result emphasis"><span>К оплате деньгами</span><b id="payableRub">10 000 ₽</b></div></div></section>
    </aside>
  </div>

  <section class="publishPanel">
    <label class="masterToggle"><input id="programEnabled" type="checkbox"><span><b>Система лояльности активна</b><small>Флажок вступит в силу только после публикации.</small></span></label>
    <div id="changeSummary" class="changeSummary"></div>
    <label class="passwordField">Текущий пароль администратора<input id="publishPassword" type="password" autocomplete="current-password" placeholder="Нужен только для применения"></label>
    <div class="publishActions"><span id="saveMsg">Изменения не опубликованы.</span><button id="resetDraft" type="button" class="secondaryAction">Вернуть опубликованную версию</button><button id="saveDraft" type="button" class="secondaryAction">Сохранить черновик</button><button id="publishChanges" type="button">Применить изменения</button></div>
  </section>
</main>
<script src="loyalty-calculator.js?v=3"></script>
</body>
</html>
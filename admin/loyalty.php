<?php require __DIR__.'/guard.php'; ?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title>Калькулятор бонусной системы — ПрофиСпорт</title>
  <link rel="stylesheet" href="loyalty-calculator.css?v=1">
</head>
<body>
<header>
  <div class="top">
    <div class="brand">Профи<span>Спорт</span></div>
    <a class="back" href="index.php">← Админка</a>
  </div>
</header>
<main>
  <div class="pageHead">
    <div>
      <h1>Калькулятор бонусной системы</h1>
      <p>Соберите механику программы, сравнивайте экономику и сохраняйте варианты как безопасный черновик.</p>
    </div>
  </div>

  <section id="programState" class="programState">
    <div><b>Загрузка…</b><p>Проверяем состояние бонусного движка.</p></div>
  </section>
  <section id="stats" class="stats" aria-label="Статистика бонусной системы"></section>

  <div class="layout">
    <div>
      <section class="panel">
        <h2>Общие параметры</h2>
        <p class="panelIntro">Стоимость бонуса влияет и на начисление, и на максимальную рублёвую скидку.</p>
        <div class="optionCard">
          <div class="optionHead">
            <div><strong>Стоимость 1 бонуса</strong><small>Сколько рублей клиент получает при списании одного бонуса.</small></div>
          </div>
          <div class="controlRow">
            <input id="pointValueRange" type="range" min="10" max="500" step="10" value="100" aria-label="Стоимость одного бонуса">
            <input id="pointValueNumber" type="number" min="0.1" max="5" step="0.1" value="1" aria-label="Стоимость одного бонуса в рублях">
          </div>
          <div class="hint">Диапазон: 0,10–5 ₽ за 1 бонус.</div>
        </div>
      </section>

      <section class="panel">
        <h2>Опции программы</h2>
        <p class="panelIntro">Каждую механику можно включать независимо и смотреть её влияние в калькуляторе справа.</p>

        <div class="optionCard">
          <div class="optionHead">
            <div><strong>Начислять бонусы за покупки</strong><small>Начисление после перевода заказа в статус «Завершён».</small></div>
            <label class="switch"><input id="earnEnabled" type="checkbox"><span></span></label>
          </div>
          <div id="earnControls" class="controlRow">
            <input id="earnPercentRange" type="range" min="0" max="3000" step="50" value="500" aria-label="Процент начисления">
            <input id="earnPercentNumber" type="number" min="0" max="30" step="0.5" value="5" aria-label="Процент начисления">
          </div>
          <div class="hint">Процент от суммы товаров, участвующих в программе.</div>
          <div id="earnControls" class="controlRow">
            <input id="minOrderRange" type="range" min="0" max="50000" step="500" value="0" aria-label="Минимальная сумма заказа">
            <input id="minOrderNumber" type="number" min="0" max="10000000" step="500" value="0" aria-label="Минимальная сумма заказа">
          </div>
          <div class="hint">Минимальная сумма участвующих товаров для начисления.</div>
        </div>

        <div class="optionCard">
          <div class="optionHead">
            <div><strong>Разрешить оплату бонусами</strong><small>Ограничивает долю заказа, которую в будущем можно будет закрыть бонусами.</small></div>
            <label class="switch"><input id="redeemEnabled" type="checkbox"><span></span></label>
          </div>
          <div id="redeemControls" class="controlRow">
            <input id="redeemPercentRange" type="range" min="0" max="10000" step="500" value="3000" aria-label="Максимальный процент оплаты бонусами">
            <input id="redeemPercentNumber" type="number" min="0" max="100" step="5" value="30" aria-label="Максимальный процент оплаты бонусами">
          </div>
          <div class="hint">Например, 30% означает: не более 30% стоимости заказа можно закрыть бонусами.</div>
        </div>

        <div class="optionCard">
          <div class="optionHead">
            <div><strong>Срок действия бонусов</strong><small>Дата будущего сгорания сохраняется в ledger. Автоматическое списание подключим после выбора FIFO-логики.</small></div>
            <label class="switch"><input id="expirationEnabled" type="checkbox"><span></span></label>
          </div>
          <div id="expirationControls" class="controlRow">
            <input id="expirationDaysRange" type="range" min="30" max="1095" step="30" value="365" aria-label="Срок действия бонусов">
            <input id="expirationDaysNumber" type="number" min="1" max="3650" step="30" value="365" aria-label="Срок действия бонусов в днях">
          </div>
        </div>

        <div class="optionCard">
          <div class="optionHead">
            <div><strong>Бонус за опубликованный отзыв</strong><small>Фиксированное количество после одобрения проверенного отзыва.</small></div>
            <label class="switch"><input id="reviewBonusEnabled" type="checkbox"><span></span></label>
          </div>
          <div id="reviewControls" class="controlRow">
            <input id="reviewBonusRange" type="range" min="0" max="1000" step="25" value="50" aria-label="Бонус за отзыв">
            <input id="reviewBonusNumber" type="number" min="0" max="1000000" step="25" value="50" aria-label="Бонус за отзыв">
          </div>
        </div>

        <div class="optionCard">
          <div class="optionHead">
            <div><strong>Исключить отдельные категории</strong><small>Для них начисление не будет рассчитываться.</small></div>
            <label class="switch"><input id="categoryExclusionsEnabled" type="checkbox"><span></span></label>
          </div>
          <div id="categoryArea" class="categoryTools" hidden>
            <input id="categorySearch" class="categorySearch" placeholder="Найти категорию из текущего каталога">
            <div id="categoryList" class="categoryList"></div>
            <div class="customCategory">
              <input id="customCategory" placeholder="Или добавить префикс категории вручную">
              <button id="addCustomCategory" type="button">Добавить</button>
            </div>
            <div id="selectedCategories" class="selectedCategories"></div>
          </div>
        </div>
      </section>

      <section class="panel">
        <h2>Готовность конфигурации</h2>
        <div class="readiness">
          <div class="readinessRow"><span>Стоимость бонуса</span><span id="readyPoint"></span></div>
          <div class="readinessRow"><span>Выбрана хотя бы одна клиентская механика</span><span id="readyFeatures"></span></div>
          <div class="readinessRow"><span>Диапазоны и обязательные поля</span><span id="readyConfig"></span></div>
        </div>
        <div class="activationLock">
          <b>Боевой запуск пока заблокирован</b>
          <p>Опции и цифры сохраняются, но клиентам ничего автоматически не начисляется и не списывается. Сначала подключим фактическое списание бонусов в checkout и правила расходования/сгорания.</p>
        </div>
      </section>
    </div>

    <aside class="calcPanel">
      <section class="panel">
        <h2>Экономика на примере заказа</h2>
        <p class="panelIntro">Меняйте сценарий — результат обновляется мгновенно, без сохранения.</p>
        <div class="scenarioGrid">
          <div class="scenarioField">
            <label><span>Сумма заказа</span><output id="scenarioOrderOut">10 000 ₽</output></label>
            <input id="scenarioOrder" type="range" min="500" max="200000" step="500" value="10000">
          </div>
          <div class="scenarioField">
            <label><span>Товаров участвует в программе</span><output id="scenarioEligibleOut">100%</output></label>
            <input id="scenarioEligibleShare" type="range" min="0" max="100" step="5" value="100">
          </div>
          <div class="scenarioField">
            <label><span>Баланс клиента до покупки</span><output id="scenarioBalanceOut">2 000 бонусов</output></label>
            <input id="scenarioBalance" type="range" min="0" max="50000" step="100" value="2000">
          </div>
          <div class="scenarioField">
            <label><span>Валовая маржа заказа</span><output id="scenarioMarginOut">30%</output></label>
            <input id="scenarioMargin" type="range" min="5" max="80" step="1" value="30">
          </div>
        </div>
        <div class="results">
          <div class="result emphasis"><span>Начислим</span><b id="earnedPoints">0 бонусов</b></div>
          <div class="result"><span>Новая бонусная обязанность</span><b id="earnedValue">0 ₽</b></div>
          <div class="result"><span>Можно списать</span><b id="maxSpendPoints">0 бонусов</b></div>
          <div class="result"><span>Скидка бонусами</span><b id="discountRub">0 ₽</b></div>
          <div class="result emphasis"><span>К оплате деньгами</span><b id="payableRub">10 000 ₽</b></div>
          <div class="result"><span>Маржа после списания</span><b id="marginAfter">3 000 ₽</b></div>
          <div class="result"><span>Маржа с резервом будущих бонусов</span><b id="conservativeMargin">3 000 ₽</b></div>
        </div>
        <p class="calcNote">Последний показатель — консервативный: из маржи вычитается и текущая скидка бонусами, и полная рублёвая стоимость новых начисленных бонусов как будущая обязанность.</p>
      </section>
    </aside>
  </div>

  <div class="actionsBar">
    <span id="saveMsg" class="saveMsg">Изменения пока не сохранены.</span>
    <button id="saveDraft" type="button">Сохранить черновик программы</button>
  </div>
</main>
<script src="loyalty-calculator.js?v=1"></script>
</body>
</html>

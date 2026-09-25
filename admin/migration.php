<?php require __DIR__.'/guard.php'; ?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Перенос сайта — ПрофиСпорт</title>
<link rel="stylesheet" href="../styles.css">
<style>
body{background:#f5f5f3;color:#202124;font-family:system-ui,-apple-system,"Segoe UI",sans-serif}
.wrap{width:min(980px,calc(100% - 28px));margin:24px auto 70px}.topline{display:flex;align-items:center;justify-content:space-between;gap:12px}.card{background:#fff;border:1px solid #e3e3df;border-radius:18px;padding:20px;margin:14px 0}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.field{display:grid;gap:6px}.field input,.field select{width:100%;padding:12px;border:1px solid #ccd0d3;border-radius:10px;font:inherit}.wide{grid-column:1/-1}.actions{display:flex;gap:10px;flex-wrap:wrap}.btn{border:0;border-radius:11px;padding:12px 16px;font-weight:800;cursor:pointer;background:#f2c94c;color:#111}.btn.secondary{background:#e9e9e5}.btn.danger{background:#bd3a31;color:#fff}.muted{color:#747a80}.ok{color:#19763b}.bad{color:#a92b20}.status{display:grid;gap:8px}.job{border-top:1px solid #eee;padding:12px 0}.job:first-child{border-top:0}.pill{display:inline-block;padding:3px 8px;border-radius:999px;background:#eee;font-size:12px;font-weight:800}@media(max-width:700px){.grid{grid-template-columns:1fr}.wide{grid-column:auto}}
</style>
<script src="migration.js?v=1" defer></script>
</head>
<body>
<main class="wrap">
<div class="topline"><div><h1>Перенос ProfiSport</h1><p class="muted">Копирование файлов и базы на новый PHP/MySQL-хостинг.</p></div><a href="index.php">← Админка</a></div>
<section class="card">
<h2>Новый хостинг</h2>
<p class="muted">Поддерживается защищённый FTPS и MySQL. Целевая база должна быть пустой. Пароли шифруются на текущем сервере и удаляются из задания после успешного переноса.</p>
<form id="migrationForm">
<div class="grid">
<label class="field wide">Публичный HTTPS-адрес нового сайта (необязательно)<input name="target_url" type="url" placeholder="https://shop.example.ru"></label>
<label class="field">FTPS-сервер<input name="ftp_host" required placeholder="ftp.example.ru"></label>
<label class="field">FTPS-порт<input name="ftp_port" type="number" min="1" max="65535" value="21" required></label>
<label class="field">FTPS-логин<input name="ftp_user" autocomplete="username" required></label>
<label class="field">FTPS-пароль<input name="ftp_pass" type="password" autocomplete="new-password" required></label>
<label class="field wide">Папка сайта на новом сервере<input name="ftp_root" value="/htdocs" required></label>
<label class="field">MySQL-сервер<input name="db_host" required placeholder="localhost"></label>
<label class="field">MySQL-порт<input name="db_port" type="number" min="1" max="65535" value="3306" required></label>
<label class="field">Имя базы<input name="db_name" required></label>
<label class="field">Пользователь базы<input name="db_user" required></label>
<label class="field wide">Пароль базы<input name="db_pass" type="password" autocomplete="new-password" required></label>
<label class="field">Запустить перенос через
<select name="delay_minutes">
<option value="10">10 минут</option>
<option value="30">30 минут</option>
<option value="60" selected>1 час</option>
<option value="180">3 часа</option>
<option value="360">6 часов</option>
<option value="1440">24 часа</option>
</select></label>
<label class="field">Текущий пароль админки — только для подтверждения<input name="current_password" type="password" autocomplete="current-password"></label>
</div>
<p id="migrationMessage" class="muted" role="status"></p>
<div class="actions">
<button class="btn secondary" type="button" id="preflightBtn">Проверить новый сервер</button>
<button class="btn" type="submit">Подтвердить паролем и запланировать</button>
</div>
</form>
</section>
<section class="card">
<h2>Что проверяется до запуска</h2>
<ul>
<li>FTPS-подключение и возможность создать/удалить тестовый файл.</li>
<li>Подключение к новой MySQL-базе.</li>
<li>Целевая база должна быть пустой, чтобы не затереть чужие данные.</li>
<li>После переноса сверяется количество строк во всех таблицах и, если указан HTTPS-адрес, <code>api/health.php</code>.</li>
</ul>
</section>
<section class="card">
<div class="topline"><h2>Задания переноса</h2><button class="btn secondary" id="refreshMigration" type="button">Обновить</button></div>
<div id="migrationJobs" class="status"><p class="muted">Загрузка…</p></div>
</section>
</main>
</body>
</html>
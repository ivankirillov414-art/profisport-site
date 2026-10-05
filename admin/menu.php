<?php require_once __DIR__.'/sections.php'; $adminHome = basename($_SERVER['SCRIPT_NAME'] ?? '') === 'index.php'; ?>
<?php if(!$adminHome): ?><nav class="adminToolbar" aria-label="Навигация управления"><a href="index.php">ПрофиСпорт · Управление</a><button type="button" class="adminMenuTrigger">Все разделы</button><a href="../index.html" target="_blank" rel="noopener">Открыть магазин ↗</a></nav><?php endif; ?>
<dialog id="adminMenu" aria-labelledby="adminMenuTitle">
 <div class="adminMenuHead"><h2 id="adminMenuTitle">Разделы управления</h2><button id="closeAdminMenu" type="button">Закрыть</button></div>
 <label class="adminMenuSearch">Найти раздел<input id="adminSectionSearch" type="search" placeholder="Например: фото, бонусы, ростовки" autocomplete="off"></label>
 <nav class="adminDirectory" aria-label="Все разделы">
 <?php foreach($adminSections as $section): ?><section class="adminMenuGroup"><h3><?=adminEsc($section['title'])?></h3>
 <?php foreach($section['links'] as [$href,$title,$description]): ?><a href="<?=adminEsc($href)?>" <?=basename(parse_url($href, PHP_URL_PATH))===basename($_SERVER['SCRIPT_NAME']??'') && !str_contains($href,'#') ? 'aria-current="page"' : ''?>><b><?=adminEsc($title)?></b><span><?=adminEsc($description)?></span></a><?php endforeach; ?>
 </section><?php endforeach; ?>
 </nav><p id="adminSectionEmpty" hidden>Раздел не найден. Попробуйте другое название.</p>
 <div class="adminMenuFooter"><a href="../index.html" target="_blank" rel="noopener">Открыть магазин ↗</a><button id="logoutAdmin" type="button">Выйти из аккаунта</button></div><p id="menuMessage" role="status"></p>
</dialog><script src="navigation.js?v=2" defer></script>

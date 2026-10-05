<?php
declare(strict_types=1);
require __DIR__.'/server/bootstrap.php';
require __DIR__.'/server/legal-documents.php';
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
$documents=legal_public_documents(legal_load($pdo));$slug=is_string($_GET['document']??null)?$_GET['document']:'';
$doc=$documents[$slug]??null;
if($slug!==''&&!$doc)http_response_code(404);
function legal_html(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$title=$doc?$doc['title']:($slug!==''?'Документ недоступен':'Юридическая информация');
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=legal_html($title)?> — ПрофиСпорт</title><link rel="stylesheet" href="legal.css?v=1"></head><body><header class="legalHeader"><a href="index.html" class="legalBrand">Профи<span>Спорт</span></a><a href="buyer-info.html">Покупателям</a></header><main class="legalPage"><nav aria-label="Путь по разделам"><a href="index.html">Главная</a> / <a href="legal.php">Юридическая информация</a></nav><h1><?=legal_html($title)?></h1>
<?php if($doc):?><p class="legalMuted">Редакция <?= (int)$doc['version']?> · <?=legal_html(substr($doc['published_at'],0,10))?></p><article class="legalText"><?=legal_html($doc['body'])?></article><button type="button" onclick="window.print()">Распечатать</button>
<?php elseif($slug!==''):?><p>Этот документ сейчас недоступен. По вопросам покупки и обслуживания обратитесь в магазин.</p><a href="buyer-info.html">Информация для покупателей</a>
<?php else:?><p>Условия покупки, обслуживания и использования сайта.</p><div class="legalDocuments"><?php foreach($documents as $item):?><a href="legal.php?document=<?=rawurlencode($item['slug'])?>"><?=legal_html($item['title'])?> <span aria-hidden="true">→</span></a><?php endforeach;?></div><p><a href="buyer-info.html">Оплата, доставка, возврат и контакты магазинов</a></p><?php endif;?></main><footer class="legalPage"><a href="index.html">Вернуться в магазин</a></footer></body></html>

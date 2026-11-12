<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/website/content.php';
require_once dirname(__DIR__).'/website/pricing.php';
return [
    'GET website/prices'=>static fn():array=>['catalog'=>kp_price_catalog(),'event'=>kp_read_published()['content']['event'],'sales_ready'=>kp_catalog_live_sales_ready()],
    'GET website/quote'=>static fn():array=>kp_public_price_quote(),
];

<?php
declare(strict_types=1);

/** Configuration inventory only. No credentials, network requests or sales writes. */
function kp_payment_https(mixed $value): bool
{
    return is_string($value) && strlen($value) <= 1500
        && filter_var($value, FILTER_VALIDATE_URL) !== false
        && strtolower((string)parse_url($value, PHP_URL_SCHEME)) === 'https'
        && parse_url($value, PHP_URL_HOST) !== null
        && parse_url($value, PHP_URL_USER) === null
        && parse_url($value, PHP_URL_PASS) === null;
}

function kp_payment_positive_price(mixed $value): bool
{
    return (is_int($value) || is_float($value)) && is_finite((float)$value)
        && $value > 0 && $value <= 1000000 && round((float)$value, 2) === (float)$value;
}

function kp_payment_readiness_report(array $private, array $published, ?array $catalog=null): array
{
    $payment = is_array($private['payments'] ?? null) ? $private['payments'] : [];
    $website = is_array($private['website'] ?? null) ? $private['website'] : [];
    $tickets = is_array($published['tickets'] ?? null) ? $published['tickets'] : [];
    $catalogReady=is_array($catalog) && is_int($catalog['version']??null) && ($catalog['version']??0)>0 && is_array($catalog['admission']??null);
    foreach (['weekday','weekend'] as $day) foreach (['child','adult'] as $kind) {
        if (!is_array($catalog['admission'][$day]??null) || !kp_payment_positive_price($catalog['admission'][$day][$kind]??null)) $catalogReady=false;
    }
    $activityPricesReady=is_array($catalog['activities']['master_classes']??null) && count($catalog['activities']['master_classes'])===3 && kp_payment_positive_price($catalog['activities']['vr']['price']??null);
    foreach (($catalog['activities']['master_classes']??[]) as $activity) if (!is_array($activity)||!kp_payment_positive_price($activity['price']??null)) $activityPricesReady=false;
    $comboPricesReady=is_array($catalog['combos']??null) && count($catalog['combos'])===3;
    foreach (($catalog['combos']??[]) as $combo) if (!is_array($combo)||!kp_payment_positive_price($combo['price']??null)) $comboPricesReady=false;
    $provider = in_array($payment['provider'] ?? '', ['yookassa', 'tbank', 'alfabank', 'hosted_link'], true) ? $payment['provider'] : null;
    $mode = in_array($payment['mode'] ?? '', ['disabled', 'test', 'live'], true) ? $payment['mode'] : 'disabled';
    $merchant = in_array($payment['merchant_status'] ?? '', ['not_started', 'test', 'under_review', 'approved'], true) ? $payment['merchant_status'] : 'not_started';
    $present = static fn(string $key): bool => is_string($payment[$key] ?? null) && trim($payment[$key]) !== '';
    $email = $website['contact_email'] ?? '';
    $phone = $website['contact_phone'] ?? '';
    $contact = (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
        || (is_string($phone) && preg_match('/^\+?\d[\d\s()\-]{8,24}$/D', $phone) === 1);
    $checks = [
        ['id'=>'provider', 'label'=>'Выбран платёжный сервис', 'configured'=>$provider !== null],
        ['id'=>'merchant_id', 'label'=>'Идентификатор магазина задан в приватной конфигурации', 'configured'=>$present('merchant_id')],
        ['id'=>'secret_key', 'label'=>'Ключ задан в приватной конфигурации', 'configured'=>$present('secret_key')],
        ['id'=>'price', 'label'=>'Указаны действующие положительные цены билетов', 'configured'=>$catalogReady || kp_payment_positive_price($tickets['price'] ?? null)],
        ['id'=>'activities', 'label'=>'Указаны цены мастер-классов и VR', 'configured'=>$activityPricesReady],
        ['id'=>'combos', 'label'=>'Указаны цены наборов активностей', 'configured'=>$comboPricesReady],
        ['id'=>'public_url', 'label'=>'Задан HTTPS-адрес сайта', 'configured'=>kp_payment_https($website['public_url'] ?? null)],
        ['id'=>'return_url', 'label'=>'Задан HTTPS-адрес возврата после оплаты', 'configured'=>kp_payment_https($payment['return_url'] ?? null)],
        ['id'=>'webhook_url', 'label'=>'Задан HTTPS-адрес уведомлений', 'configured'=>kp_payment_https($payment['webhook_url'] ?? null)],
        ['id'=>'organizer', 'label'=>'В настройках указано юридическое имя организатора', 'configured'=>is_string($website['organizer_name'] ?? null) && trim($website['organizer_name']) !== ''],
        ['id'=>'privacy', 'label'=>'Указана HTTPS-ссылка на политику обработки данных', 'configured'=>kp_payment_https($website['privacy_url'] ?? null)],
        ['id'=>'offer', 'label'=>'Указана HTTPS-ссылка на условия покупки и возврата', 'configured'=>kp_payment_https($payment['offer_url'] ?? null)],
        ['id'=>'contact', 'label'=>'Указана контактная почта или телефон организатора', 'configured'=>$contact],
        ['id'=>'fiscalization', 'label'=>'Выбран способ выдачи фискального чека', 'configured'=>in_array($payment['fiscalization'] ?? '', ['provider_receipts','own_online_register'], true)],
        ['id'=>'hosted_checkout', 'label'=>'Задана HTTPS-ссылка внешней страницы оплаты', 'configured'=>kp_payment_https($tickets['checkout_url'] ?? null)],
    ];
    $byId = array_column($checks, 'configured', 'id');
    $blockers = [];
    foreach ($checks as $check) if (!$check['configured']) $blockers[] = $check['id'];
    $blockers = array_merge($blockers, ['merchant_verification','provider_order_api','verified_payment_notifications','paid_ticket_fulfillment','fiscalization_verification']);
    return [
        'provider'=>$provider, 'mode'=>$mode, 'merchant_status'=>$merchant,
        'merchant_status_source'=>'private_configuration_not_provider_verification',
        'configuration_only'=>true, 'provider_verified'=>false,
        'credentials'=>['merchant_id_present'=>$byId['merchant_id'], 'secret_key_present'=>$byId['secret_key']],
        'published_price'=>kp_payment_positive_price($tickets['price'] ?? null) ? $tickets['price'] : null,
        'price_source'=>$catalogReady?'owner_confirmed_catalog':'legacy_cms',
        'price_catalog'=>$catalogReady?array_intersect_key($catalog,array_flip(['version','confirmed_on','currency','admission','activities','combos'])):null,
        'sales_open'=>($tickets['sales_open'] ?? null) === true,
        'integration_type'=>$catalogReady?'price_catalog_without_payment_provider':'payment_not_integrated',
        'implemented'=>['price_catalog'=>$catalogReady,'hosted_checkout_redirect'=>false,'provider_orders'=>false,'verified_webhook'=>false,'paid_ticket_fulfillment'=>false],
        'hosted_redirect_configured'=>false,
        'hosted_checkout_url_present'=>$byId['hosted_checkout'],
        'live_ready'=>false, 'automated_callback_access'=>'not_verified',
        'checkout_route_enabled'=>false,
        'checks'=>$checks, 'blockers'=>array_values(array_unique($blockers)),
        'next_steps'=>[
            $catalogReady?'Выбрать платёжный сервис; цены входа уже подтверждены в каталоге.':'Выбрать сервис и подтвердить действующие цены билетов.',
            'Зарегистрировать магазин, заполнить данные организатора и пройти проверку сервиса; договор подписывает владелец.',
            'Подключить выбранный API, подтверждение платежей, выдачу оплаченных билетов и фискальные чеки.',
            'Проверить тестовый платёж, повтор уведомления, отмену и возврат; отдельно проверить доступ сервиса к HTTPS-уведомлениям без браузерной проверки.',
            'Открывать реальные продажи только после проверки рабочего магазина и полного сценария получения билета.',
        ],
        'notice'=>$catalogReady?'Ценовой каталог и расчёт входа готовы. Онлайн-оплата закрыта: магазин, API заказов, подтверждение оплаты и выдача оплаченного билета ещё не подключены. Заполненные поля не доказывают проверку магазина или платежей.':'Это инвентаризация настроек. Заполненные поля не доказывают проверку магазина, работоспособность оплаты или выдачу билета. Платёжный маршрут закрыт до реальной интеграции.',
    ];
}

function kp_payment_readiness(): array
{
    $user = crm_current_user();
    if (!$user) crm_fail('AUTH_REQUIRED', 'Войдите в CRM.', 401);
    // crm_require_roles gives admins a global bypass; payment setup is owner-only.
    if (($user['role'] ?? null) !== 'owner') crm_fail('FORBIDDEN', 'Настройки подключения оплаты доступны владельцу CRM.', 403);
    $private = crm_config();
    $published = kp_read_published();
    require_once dirname(__DIR__).'/website/pricing.php';
    return kp_payment_readiness_report(is_array($private) ? $private : [], is_array($published['content'] ?? null) ? $published['content'] : [],kp_price_catalog());
}

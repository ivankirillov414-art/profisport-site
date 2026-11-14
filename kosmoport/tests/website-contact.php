<?php
declare(strict_types=1);
// Pure server-render checks: no database, browser scripts, call or message is used.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/website/content.php';
require dirname(__DIR__).'/app/website/funnel.php';

function render_contact_page(array $content): string {
    $field=static fn(string $id): string=>kp_escape($content['fields'][$id]??'');
    $brand=kp_escape($content['brand']); $event=$content['event'];
    $siteSettings=kp_site_settings(); $requestsReady=false;
    $dates=kp_escape(kp_event_dates($event));
    $venue=kp_escape($event['city'].' · '.$event['venue']);
    $clientConfig=[];
    ob_start(); require dirname(__DIR__).'/app/website/template.php';
    return (string)ob_get_clean();
}
function expect_contact(bool $condition,string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function planner_contact(string $html): string {
    preg_match('~<section class="planner-panel" data-planner-panel="4".*?</section>~s',$html,$match);
    return $match[0]??'';
}
$defaults=kp_default_content();
$empty=$defaults; $empty['contact']['phone']='';
$before=$empty;
$fallback=render_contact_page($empty);
expect_contact(str_contains(planner_contact($fallback),'id="planner-organizer-call" href="tel:+79920800228">Уточнить визит: +7 (992) 080-02-28</a>'),'Empty published phone uses the confirmed organizer number on step 4');
expect_contact($empty===$before,'Rendering fallback does not mutate published CMS content');
expect_contact(!str_contains(planner_contact($fallback),'id="visit-request-form"'),'Closed enquiry flow collects no personal data');
echo "PASS empty published phone gets server-rendered fallback without CMS mutation\n";

$custom=$defaults; $custom['contact']=['phone'=>'+7 (3532) 12-34-56','email'=>'team@example.com','vk_url'=>'https://vk.com/example'];
$custom['fields']['k4-text']='Наш сохранённый заголовок';
$customHtml=render_contact_page($custom);
expect_contact(str_contains(planner_contact($customHtml),'href="tel:+73532123456">Уточнить визит: +7 (3532) 12-34-56</a>'),'Custom published phone has priority');
expect_contact(!str_contains($customHtml,'tel:+79920800228'),'Fallback does not replace a custom phone');
expect_contact(str_contains($customHtml,'mailto:team@example.com')&&str_contains($customHtml,'https://vk.com/example')&&str_contains($customHtml,'Наш сохранённый заголовок'),'Other saved CMS fields stay rendered');
echo "PASS custom phone and other saved content are preserved\n";

$withoutScripts=preg_replace('~<script\b[^>]*>.*?</script>~is','',$fallback);
preg_match('~<footer class="footer">.*?</footer>~s',$withoutScripts,$footer);
expect_contact(str_contains($footer[0]??'','href="tel:+79920800228">+7 (992) 080-02-28</a>'),'Organizer remains reachable outside the closed planner dialog without JavaScript');
expect_contact(!str_contains($footer[0]??'','<dialog'),'No-JS contact is outside modal interaction');
echo "PASS no-JavaScript footer exposes a normal telephone link\n";

<?php
declare(strict_types=1);

// Isolated website-module behaviour. Integrated auth/HTTP/MySQL tests are separate.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
ob_start(); // Keep entrypoint header checks valid while rendering after PASS lines.
final class KpReply extends RuntimeException {
    public function __construct(public array $payload, public int $status) { parent::__construct((string)$status); }
}
$kpDb=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$kpDb->exec('CREATE TABLE kcrm_website (id INTEGER PRIMARY KEY,draft TEXT NOT NULL,published TEXT,revision INTEGER NOT NULL,published_revision INTEGER NOT NULL,updated_by TEXT,updated_at TEXT,published_at TEXT)');
$kpRole='owner'; $kpAudits=[]; $kpAuditFail=false;
function crm_db(): PDO { return $GLOBALS['kpDb']; }
function crm_require_roles(array $roles): array {
    $role=$GLOBALS['kpRole'];
    if (!$role) crm_json(['ok'=>false,'error'=>['code'=>'unauthenticated']],401);
    if (!in_array($role,['owner','admin'],true) && !in_array($role,$roles,true)) crm_json(['ok'=>false,'error'=>['code'=>'forbidden']],403);
    return ['id'=>'website-test-owner','role'=>$role];
}
function crm_json(array $payload,int $status=200): never { throw new KpReply($payload,$status); }
function crm_input(): array { return $GLOBALS['kpInput']??[]; }
function crm_audit(string $action,string $type,?string $id,array $details=[]): void {
    if ($GLOBALS['kpAuditFail']) throw new RuntimeException('Fixture audit write failed');
    $GLOBALS['kpAudits'][]=[$action,$type,$id,$details];
}
require dirname(__DIR__).'/app/website/content.php';
$routes=require dirname(__DIR__).'/app/modules/website.php';
$count=0;
function check_website(string $name,callable $operation): void {
    $operation(); $GLOBALS['count']++; echo 'PASS '.$name.PHP_EOL;
}
function expect_website(bool $value,string $message='Assertion failed'): void { if (!$value) throw new RuntimeException($message); }
function rejects_website(callable $operation,string $exception=InvalidArgumentException::class,?int $status=null): void {
    try { $operation(); } catch (Throwable $error) {
        expect_website($error instanceof $exception,'Unexpected exception '.get_class($error).': '.$error->getMessage());
        if ($status!==null) expect_website($error->status===$status,'Wrong response status');
        return;
    }
    throw new RuntimeException('Expected rejection');
}
$default=kp_default_content();
check_website('packaged content validates and confirmed event is preserved',static function()use($default):void {
    expect_website(kp_validate_content($default)===$default);
    expect_website($default['brand']==='КОСМОПОРТ' && $default['event']['opening_date']==='2026-11-25');
    expect_website($default['tickets']['price']===null && $default['event']['closing_date']==='2027-01-18');
});
check_website('known former event pairs upgrade only their dates and remain idempotent',static function()use($default):void {
    foreach (['2026-11-24','2026-11-25'] as $opening) foreach (['2027-01-18','2027-01-20'] as $closing) {
        $legacy=$default; $legacy['event']['opening_date']=$opening; $legacy['event']['closing_date']=$closing;
        $legacy['brand']='Название редактора'; $legacy['event']['hours']='12:00–19:00';
        $legacy['fields']['k14-text']='Авторская программа'; $legacy['tickets']['price']=950;
        $expected=$legacy; $expected['event']['opening_date']='2026-11-25'; $expected['event']['closing_date']='2027-01-18';
        $upgraded=kp_validate_content($legacy);
        expect_website($upgraded===$expected,'Only the known date pair may change');
        expect_website(kp_validate_content($upgraded)===$upgraded,'Date upgrade must be idempotent');
    }
});
check_website('other editor event periods are preserved',static function()use($default):void {
    foreach ([['2026-11-24','2027-01-19'],['2026-11-26','2027-01-20'],['2026-11-24',null]] as [$opening,$closing]) {
        $custom=$default; $custom['event']['opening_date']=$opening; $custom['event']['closing_date']=$closing;
        expect_website(kp_validate_content($custom)===$custom,'Do not migrate an unrelated editor period');
    }
});
check_website('legacy published and draft reads upgrade dates without writes or revision changes',static function()use($default,$routes):void {
    $db=crm_db(); $db->beginTransaction();
    try {
        $legacy=$default; $legacy['event']['opening_date']='2026-11-24'; $legacy['event']['closing_date']='2027-01-20';
        $legacy['fields']['k14-text']='Сохранённая авторская программа'; $legacy['contact']['phone']='+7 992 080-02-28';
        $json=json_encode($legacy,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $db->prepare('INSERT INTO kcrm_website(id,draft,published,revision,published_revision) VALUES(1,?,?,17,12)')->execute([$json,$json]);
        $before=kp_website_row(); $audits=count($GLOBALS['kpAudits']);
        $expected=$legacy; $expected['event']['opening_date']='2026-11-25'; $expected['event']['closing_date']='2027-01-18';
        $published=kp_read_published();
        expect_website($published['content']===$expected && $published['revision']===12);
        $reply=null;
        try { $routes['GET website/draft'](); } catch (KpReply $error) { $reply=$error; }
        expect_website($reply instanceof KpReply && $reply->status===200);
        expect_website($reply->payload['data']['content']===$expected);
        expect_website($reply->payload['data']['revision']===17 && $reply->payload['data']['published_revision']===12);
        expect_website(kp_website_row()===$before,'A content read must not update stored JSON or revisions');
        expect_website(count($GLOBALS['kpAudits'])===$audits,'A content read must not create audit writes');
    } finally { $db->rollBack(); }
});
check_website('script and HTML injection rejected',static function()use($default):void {
    $bad=$default; $bad['fields']['k4-text']='<img src=x onerror=alert(1)>';
    rejects_website(static fn()=>kp_validate_content($bad));
});
check_website('former program heading upgrades without resetting edited content',static function()use($default):void {
    $legacy=$default;
    $legacy['fields']['k8-text']='НАУКА × ИГРА × ОТКРЫТИЯ';
    $legacy['fields']['k9-text']='НЕ ПРОСТО СМОТРЕТЬ.';
    $legacy['fields']['k10-text']='БЫТЬ ВНУТРИ СОБЫТИЙ!';
    $legacy['fields']['k20-text']='Посещение выставки вместе с детьми.';
    $legacy['fields']['k22-text']='Посещение школьным классом по предварительному согласованию.';
    $legacy['fields']['k48-text']='ТАК НАЧИНАЕТСЯ УДИВЛЕНИЕ';
    $legacy['fields']['k14-text']='Текст программы, сохранённый редактором.';
    $expected=$default; $expected['fields']['k14-text']=$legacy['fields']['k14-text'];
    expect_website(kp_validate_content($legacy)===$expected);
    $custom=$default; $custom['fields']['k9-text']='Наш заголовок'; $custom['fields']['k10-text']='Наша программа';
    $custom['fields']['k20-text']='Собственный текст для родителей';
    $custom['fields']['k48-text']='Авторский заголовок галереи';
    expect_website(kp_validate_content($custom)===$custom);
});
check_website('traversal and missing images rejected',static function()use($default):void {
    foreach (['site/assets/../../config.php','site/assets/missing-photo.webp'] as $name) {
        $bad=$default; $bad['fields']['k1-image']=$name;
        rejects_website(static fn()=>kp_validate_content($bad));
    }
});
check_website('invalid dates rejected',static function()use($default):void {
    $bad=$default; $bad['event']['closing_date']='2026-02-30'; rejects_website(static fn()=>kp_validate_content($bad));
    $bad['event']['closing_date']='2026-01-01'; rejects_website(static fn()=>kp_validate_content($bad));
});
check_website('sales require price and secure checkout',static function()use($default):void {
    $bad=$default; $bad['tickets']['sales_open']=true; rejects_website(static fn()=>kp_validate_content($bad));
    $bad['tickets']['price']=800; $bad['tickets']['checkout_url']='http://example.com/checkout'; rejects_website(static fn()=>kp_validate_content($bad));
    $bad['tickets']['checkout_url']='https://example.com/checkout'; expect_website(kp_validate_content($bad)['tickets']['sales_open']);
});
check_website('draft save does not change public content',static function()use($default):void {
    $draft=$default; $draft['fields']['k4-text']='Проверяемый заголовок';
    $result=kp_website_change(['content'=>$draft,'expected_revision'=>0],false);
    expect_website($result['revision']===1);
    expect_website(kp_read_published()['content']['fields']['k4-text']===$default['fields']['k4-text']);
});
check_website('publish exposes only reviewed stored draft',static function():void {
    $result=kp_website_change(['expected_revision'=>1],true);
    expect_website($result['revision']===2);
    expect_website(kp_read_published()['content']['fields']['k4-text']==='Проверяемый заголовок');
});
check_website('stale saves and publishes return 409 without data loss',static function()use($default):void {
    rejects_website(static fn()=>kp_website_change(['content'=>$default,'expected_revision'=>1],false),KpReply::class,409);
    rejects_website(static fn()=>kp_website_change(['expected_revision'=>1],true),KpReply::class,409);
    expect_website(kp_website_row()['revision']===2);
});
check_website('audit failure rolls back draft update',static function()use($default):void {
    $GLOBALS['kpAuditFail']=true;
    rejects_website(static fn()=>kp_website_change(['content'=>$default,'expected_revision'=>2],false),RuntimeException::class);
    $GLOBALS['kpAuditFail']=false;
    expect_website(kp_website_row()['revision']===2);
});
check_website('cashier cannot read private draft or publish',static function()use($routes):void {
    $GLOBALS['kpRole']='cashier';
    rejects_website($routes['GET website/draft'],KpReply::class,403);
    rejects_website(static fn()=>kp_website_change(['expected_revision'=>2],true),KpReply::class,403);
});
check_website('anonymous visitor cannot read draft',static function()use($routes):void {
    $GLOBALS['kpRole']=''; rejects_website($routes['GET website/draft'],KpReply::class,401); $GLOBALS['kpRole']='owner';
});
check_website('scalar content and unknown fields rejected',static function()use($default):void {
    rejects_website(static fn()=>kp_website_change(['content'=>'wrong','expected_revision'=>2],false));
    $bad=$default; $bad['fields']['unexpected']='value'; rejects_website(static fn()=>kp_validate_content($bad));
});
check_website('public output escapes HTML metacharacters',static function():void {
    expect_website(kp_escape('" onload="x <test>')==='&quot; onload=&quot;x &lt;test&gt;');
});
function rendered_website_headings(): array {
    ob_start();
    require dirname(__DIR__).'/public/index.php';
    $html=(string)ob_get_clean();
    preg_match_all('~<h([1-6])\b[^>]*>(.*?)</h\1>~su',$html,$matches);
    return array_map(static fn(string $markup):string=>trim((string)preg_replace('/\s+/u',' ',html_entity_decode(strip_tags($markup),ENT_QUOTES|ENT_HTML5,'UTF-8'))),$matches[2]);
}
check_website('packaged page headings keep word boundaries when line breaks are hidden',static function():void {
    $db=crm_db();$db->beginTransaction();
    try {
        $db->exec('UPDATE kcrm_website SET published=NULL WHERE id=1');
        $headings=rendered_website_headings();
        foreach (['ПРИХОДИТЕ ВМЕСТЕ','БОЛЬШИЕ ОТКРЫТИЯ НАЧИНАЮТСЯ С ОДНОГО БИЛЕТА','ВОПРОСЫ И ОТВЕТЫ'] as $heading) {
            expect_website(in_array($heading,$headings,true),'Missing spaced packaged heading: '.$heading);
        }
    } finally { $db->rollBack(); }
});
check_website('public entry renders edited CMS heading fragments with spaces',static function()use($default):void {
    $edited=$default;
    foreach (['k27-text'=>'НОВЫЕ','k28-text'=>'СОБЫТИЯ','k29-text'=>'ДЛЯ','k30-text'=>'ВСЕХ','k66-text'=>'ВАШИ','k67-text'=>'ВОПРОСЫ','k68-text'=>'И ОТВЕТЫ'] as $id=>$text) $edited['fields'][$id]=$text;
    $db=crm_db();$db->beginTransaction();
    try {
        $stmt=$db->prepare('UPDATE kcrm_website SET published=? WHERE id=1');
        $stmt->execute([json_encode($edited,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        $headings=rendered_website_headings();
        foreach (['НОВЫЕ СОБЫТИЯ ДЛЯ ВСЕХ','ВАШИ ВОПРОСЫ И ОТВЕТЫ'] as $heading) {
            expect_website(in_array($heading,$headings,true),'Missing spaced CMS heading: '.$heading);
        }
    } finally { $db->rollBack(); }
});
expect_website(count($kpAudits)===2,'Only successful save/publish should be audited');
echo 'Website module: '.$count.' behaviour checks passed (isolated SQLite fixture).'.PHP_EOL;
ob_end_flush();

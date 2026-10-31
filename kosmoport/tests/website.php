<?php
declare(strict_types=1);

// Isolated website-module behaviour. Integrated auth/HTTP/MySQL tests are separate.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
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
    expect_website($default['brand']==='КОСМОПОРТ' && $default['event']['opening_date']==='2026-11-24');
    expect_website($default['tickets']['price']===null && $default['event']['closing_date']==='2027-01-18');
});
check_website('script and HTML injection rejected',static function()use($default):void {
    $bad=$default; $bad['fields']['k4-text']='<img src=x onerror=alert(1)>';
    rejects_website(static fn()=>kp_validate_content($bad));
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
expect_website(count($kpAudits)===2,'Only successful save/publish should be audited');
echo 'Website module: '.$count.' behaviour checks passed (isolated SQLite fixture).'.PHP_EOL;

<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(!class_exists('ZipArchive')){fwrite(STDERR,"ZIP extension is required for marketing bundle validation.\n");exit(1);}
define('KCRM_ROOT',dirname(__DIR__));
const KCRM_ROLES=['owner','admin','manager','cashier','editor','document_manager','reader'];
$private=rtrim(sys_get_temp_dir(),'/\\').DIRECTORY_SEPARATOR.'kcrm-marketing-bundle-'.bin2hex(random_bytes(8));mkdir($private,0700,true);
define('MK_TEST_PRIVATE',realpath($private));
$config=['environment'=>'test','base_path'=>'','app_key'=>bin2hex(random_bytes(32)),'storage_path'=>$private.'/storage','marketing'=>['approved_preview_sha256'=>['simaz-cmyk.jpg'=>hash_file('sha256',__DIR__.'/fixtures/marketing-preview.jpg')]]];
file_put_contents($private.'/config.php','<?php return '.var_export($config,true).';');putenv('KCRM_CONFIG='.$private.'/config.php');
require KCRM_ROOT.'/app/core/http.php';require KCRM_ROOT.'/app/core/config.php';
$actor=['id'=>'test-owner','name'=>'Fixture Owner','role'=>'owner'];$checks=[];$audit=[];
function crm_require_roles(array $roles): array {global $actor;if($actor===null)crm_fail('UNAUTHENTICATED','Sign in',401);return $actor;}
function crm_audit(string $action,string $entity,?string $id,array $details=[]): void {global $audit;$audit[]=$action;}
require KCRM_ROOT.'/app/marketing/service.php';require KCRM_ROOT.'/app/marketing/bundle.php';
function expect(string $name,bool $ok): void {global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$name);$checks[]=$name;}
function rejection(string $name,Closure $action,string $code,int $status=422): void {try{$action();}catch(CrmHttpException $error){expect($name,$error->errorCode===$code&&$error->status===$status);return;}throw new RuntimeException('Expected rejection: '.$name);}
function remove_fixture(string $path): void {
    if(!file_exists($path))return;$root=MK_TEST_PRIVATE;$real=realpath($path);
    $normal=static fn(string $value): string=>strtolower(str_replace('\\','/',$value));
    if($real===false||($normal($real)!==$normal($root)&&!str_starts_with($normal($real),$normal($root).'/')))throw new RuntimeException('Fixture cleanup escaped its verified private directory.');
    if(is_dir($path)){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $entry){if($entry->isLink()||!$entry->isDir())unlink($entry->getPathname());else rmdir($entry->getPathname());}rmdir($path);}else unlink($path);
}
function reset_marketing(): void {remove_fixture(crm_storage_path('marketing'));}
function fixture_zip(array $entries,string $name): string {
    $file=MK_TEST_PRIVATE.'/'.$name.'.zip';$zip=new ZipArchive();if($zip->open($file,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Cannot create fixture ZIP.');foreach($entries as $path=>$bytes)$zip->addFromString($path,$bytes);$zip->close();return $file;
}
function clean_failure(): void {expect('no partial import marker',!is_file(crm_storage_path('marketing/import.json')));expect('own staging folders cleaned',count(glob(crm_storage_path('marketing/bundle-stage-*'))?:[])===0);}
try {
    $seed=mk_empty_workspace();$seed['title']='Fixture marketing';$seed['excluded_names']=['Excluded'];
    $seed['sources']=[['id'=>'fixture-source','title'=>'Fixture source','url'=>'https://example.invalid/source']];
    $seed['documents']=[['id'=>'fixture-doc','title'=>'Fixture document','body'=>'Sanitized test text only.','category'=>'knowledge','source_ids'=>['fixture-source']]];
    $seed['datasets']=[['id'=>'shops','title'=>'Fixture contacts','source_ids'=>['fixture-source'],'columns'=>[['key'=>'name','label'=>'Name']],'rows'=>[['id'=>'fixture-point','name'=>'Fixture point']]]];
    $seed['assets']=[['id'=>'fixture-asset','title'=>'Fixture visual','filename'=>'fixture.eps','status'=>'approved','file'=>'assets/simaz-cmyk.eps','preview_file'=>'previews/simaz-cmyk.jpg']];
    $json=json_encode($seed,JSON_THROW_ON_ERROR);$jpeg=(string)file_get_contents(__DIR__.'/fixtures/marketing-preview.jpg');$normal=['workspace.json'=>$json,'previews/simaz-cmyk.jpg'=>$jpeg];
    $good=fixture_zip($normal,'good');
    $actor=null;rejection('anonymous cannot upload',fn()=>mk_stage_bundle($good,1),'UNAUTHENTICATED',401);
    $actor=['id'=>'old-admin','name'=>'Fixture Admin','role'=>'admin'];rejection('legacy admin cannot upload',fn()=>mk_stage_bundle($good,1),'FORBIDDEN',403);
    $actor=['id'=>'excluded-owner','name'=>'Excluded Person','role'=>'owner'];rejection('seed exclusion enforced',fn()=>mk_stage_bundle($good,1),'MARKETING_FORBIDDEN',403);
    $actor=['id'=>'test-owner','name'=>'Fixture Owner','role'=>'owner'];
    rejection('web route rejects arbitrary local file',fn()=>mk_upload_bundle(['bundle'=>['error'=>UPLOAD_ERR_OK,'tmp_name'=>$good]],['expected_revision'=>'1']),'BUNDLE_UPLOAD_FAILED');
    $traversal=fixture_zip([...$normal,'../escape.php'=>'not executable'],'traversal');rejection('traversal rejected',fn()=>mk_stage_bundle($traversal,1),'BUNDLE_PATH_REJECTED');clean_failure();
    $extra=fixture_zip([...$normal,'notes.txt'=>'extra'],'extra');rejection('unknown file rejected',fn()=>mk_stage_bundle($extra,1),'BUNDLE_PATH_REJECTED');clean_failure();
    $duplicate=fixture_zip([...$normal,'WORKSPACE.JSON'=>$json],'duplicate');rejection('case-fold duplicate rejected',fn()=>mk_stage_bundle($duplicate,1),'BUNDLE_DUPLICATE');
    $many=[];for($i=0;$i<11;$i++)$many['file-'.$i.'.txt']='fixture';$tooMany=fixture_zip($many,'count');rejection('file count bound',fn()=>mk_stage_bundle($tooMany,1),'BUNDLE_FILE_LIMIT');
    $oversize=fixture_zip(['workspace.json'=>str_repeat('x',8*1024*1024+1)],'expanded');rejection('expanded-size bound before extraction',fn()=>mk_stage_bundle($oversize,1),'BUNDLE_TOO_LARGE',413);clean_failure();
    $link=fixture_zip($normal,'symlink');$zip=new ZipArchive();$zip->open($link);$zip->setExternalAttributesName('workspace.json',ZipArchive::OPSYS_UNIX,0120777<<16);$zip->close();rejection('symlink entry rejected',fn()=>mk_stage_bundle($link,1),'BUNDLE_SPECIAL_FILE');
    $badSeed=$seed;$badSeed['members']=[['user_id'=>'old-manager','permission'=>'edit']];$withMembers=fixture_zip(['workspace.json'=>json_encode($badSeed,JSON_THROW_ON_ERROR),'previews/simaz-cmyk.jpg'=>$jpeg],'members');rejection('bundle cannot grant members',fn()=>mk_stage_bundle($withMembers,1),'INVALID_IMPORT');
    $badSeed=$seed;$badSeed['documents'][0]['source_ids']=['unknown-source'];$badSources=fixture_zip(['workspace.json'=>json_encode($badSeed,JSON_THROW_ON_ERROR),'previews/simaz-cmyk.jpg'=>$jpeg],'source');rejection('shared source reference validation',fn()=>mk_stage_bundle($badSources,1),'INVALID_IMPORT');
    $badSeed=$seed;$badSeed['documents'][0]['source_ids']=['object-key'=>'fixture-source'];$badList=fixture_zip(['workspace.json'=>json_encode($badSeed,JSON_THROW_ON_ERROR),'previews/simaz-cmyk.jpg'=>$jpeg],'source-object');rejection('object source references cannot break UI',fn()=>mk_stage_bundle($badList,1),'INVALID_IMPORT');
    $badSeed=$seed;$badSeed['datasets'][0]['columns']=['name'=>['key'=>'name','label'=>'Name']];$badColumns=fixture_zip(['workspace.json'=>json_encode($badSeed,JSON_THROW_ON_ERROR),'previews/simaz-cmyk.jpg'=>$jpeg],'columns-object');rejection('object columns cannot break UI',fn()=>mk_stage_bundle($badColumns,1),'INVALID_IMPORT');
    $badSeed=$seed;$badSeed['datasets'][0]['rows'][0]['estimate']='__NONFINITE__';$nonfiniteJson=str_replace('"__NONFINITE__"','1e400',json_encode($badSeed,JSON_THROW_ON_ERROR));$nonfinite=fixture_zip(['workspace.json'=>$nonfiniteJson,'previews/simaz-cmyk.jpg'=>$jpeg],'nonfinite-cell');rejection('nonfinite dataset number rejected before staging',fn()=>mk_stage_bundle($nonfinite,1),'INVALID_IMPORT');
    $nonfiniteRoot=str_replace('"schema":1','"unknown_root_number":1e400,"schema":1',$json);$nonfiniteTop=fixture_zip(['workspace.json'=>$nonfiniteRoot,'previews/simaz-cmyk.jpg'=>$jpeg],'nonfinite-root');rejection('final serialization guard rejects retained nonfinite fields',fn()=>mk_stage_bundle($nonfiniteTop,1),'INVALID_IMPORT');
    $missing=fixture_zip(['workspace.json'=>$json],'missing');rejection('referenced preview required',fn()=>mk_stage_bundle($missing,1),'BUNDLE_PREVIEW_MISSING');
    $invalid=fixture_zip(['workspace.json'=>$json,'previews/simaz-cmyk.jpg'=>'not jpeg'],'invalid-jpeg');rejection('unknown image content rejected',fn()=>mk_stage_bundle($invalid,1),'BUNDLE_INVALID_IMAGE');
    $truncated=fixture_zip(['workspace.json'=>$json,'previews/simaz-cmyk.jpg'=>substr($jpeg,0,-2)],'truncated');rejection('truncated jpeg rejected',fn()=>mk_stage_bundle($truncated,1),'BUNDLE_INVALID_IMAGE');
    $sos=strpos($jpeg,"\xFF\xDA");expect('jpeg fixture contains scan',$sos!==false);$scanLength=unpack('n',substr($jpeg,$sos+2,2))[1];$scanless=substr($jpeg,0,$sos+2+$scanLength)."\xFF\xD9";$missingScan=fixture_zip(['workspace.json'=>$json,'previews/simaz-cmyk.jpg'=>$scanless],'missing-scan');rejection('header-only JPEG rejected by approved integrity pin',fn()=>mk_stage_bundle($missingScan,1),'BUNDLE_UNAPPROVED_IMAGE');
    $sof=strpos($jpeg,"\xFF\xC0");expect('jpeg fixture contains baseline frame',$sof!==false);$hugeJpeg=substr_replace($jpeg,pack('n',3001),$sof+7,2);$dimensions=fixture_zip(['workspace.json'=>$json,'previews/simaz-cmyk.jpg'=>$hugeJpeg],'dimensions');rejection('image dimension bound',fn()=>mk_stage_bundle($dimensions,1),'BUNDLE_INVALID_IMAGE');clean_failure();
    $result=mk_stage_bundle($good,1);expect('owner stages sanitized bundle',$result['staged']===true&&$result['initialized']===false);expect('counts ready for separate review',$result['counts']['documents']===1&&$result['counts']['visuals']===1&&$result['counts']['contact_records']===1&&$result['counts']['members']===0);expect('upload never initializes workspace',!is_file(crm_storage_path('marketing/workspace.json')));expect('preview bytes preserved',hash_file('sha256',crm_storage_path('marketing/previews/simaz-cmyk.jpg'))===hash('sha256',$jpeg));expect('review available to owner',mk_workspace_view()['pending_import']['visuals']===1);expect('successful stage cleaned',count(glob(crm_storage_path('marketing/bundle-stage-*'))?:[])===0);
    $before=hash_file('sha256',crm_storage_path('marketing/import.json'));rejection('staged seed cannot be overwritten',fn()=>mk_stage_bundle($good,1),'BUNDLE_ALREADY_STAGED',409);expect('staged marker unchanged',hash_file('sha256',crm_storage_path('marketing/import.json'))===$before);
    mk_initialize(['expected_revision'=>1,'confirm'=>true]);expect('separate owner initialization succeeds',mk_read_file()['revision']===2);$before=hash_file('sha256',crm_storage_path('marketing/workspace.json'));rejection('existing business workspace protected',fn()=>mk_stage_bundle($good,2),'WORKSPACE_EXISTS',409);expect('business workspace unchanged',hash_file('sha256',crm_storage_path('marketing/workspace.json'))===$before);
    reset_marketing();mkdir(crm_storage_path('marketing/previews'),0700,true);file_put_contents(crm_storage_path('marketing/previews/keep.txt'),'existing business file');rejection('existing previews not overwritten',fn()=>mk_stage_bundle($good,1),'BUNDLE_TARGET_EXISTS',409);expect('existing preview directory preserved',file_get_contents(crm_storage_path('marketing/previews/keep.txt'))==='existing business file');clean_failure();
    reset_marketing();mkdir(crm_storage_path('marketing'),0700,true);$held=fopen(crm_storage_path('marketing/workspace.lock'),'c+');flock($held,LOCK_EX);$started=microtime(true);try{rejection('common lock is bounded',fn()=>mk_stage_bundle($good,1),'WORKSPACE_BUSY',409);}finally{flock($held,LOCK_UN);fclose($held);}expect('lock timeout under five seconds',microtime(true)-$started<5);clean_failure();
    echo json_encode(['pass'=>true,'checks'=>$checks,'count'=>count($checks),'scope'=>'Sanitized source fixture only; exact-owner staging, limits, hostile ZIPs, common lock and no-overwrite; no production data/accounts'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
} finally {remove_fixture(MK_TEST_PRIVATE);}

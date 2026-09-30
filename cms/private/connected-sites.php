<?php
declare(strict_types=1);
// Bundles are reviewed, deployment-owned files, never client-supplied HTML or URLs.
function cms_connector_bundle(string $key): ?array {
    if(!preg_match('/^[a-z0-9][a-z0-9-]{1,63}$/D',$key))return null;
    $file=__DIR__.'/connectors/'.$key.'.json';
    if(!is_file($file))return null;
    if(!in_array($key,cms_config()['connected_sites']??[],true))return null;
    $bundle=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    if(($bundle['manifest']['site']??null)!==$key)throw new RuntimeException('Неверный пакет подключения сайта.');
    return $bundle;
}
function cms_register_connector(string $key): void {
    $bundle=cms_connector_bundle($key);if(!$bundle)return;
    $db=cms_db();$s=$db->prepare('SELECT site_key FROM ps_cms_sites WHERE site_key=?');$s->execute([$key]);if($s->fetch())return;
    $draft=['pages'=>[]];
    foreach($bundle['manifest']['pages'] as $page=>$meta){
        $draft['pages'][$page]=['fields'=>array_column($meta['fields'],'value','id'),'blocks'=>array_map(fn($b)=>['id'=>$b['id'],'visible'=>$b['visible']],$meta['blocks'])];
    }
    $db->beginTransaction();
    try {
        $q=$db->prepare('INSERT IGNORE INTO ps_cms_sites(site_key,name,url,manifest) VALUES(?,?,?,?)');
        $q->execute([$key,$bundle['name'],rtrim(cms_config()['site_url'],'/').'/cms/sites/'.$key.'/',cms_encode($bundle['manifest'])]);
        if($q->rowCount()){
            $db->prepare('INSERT INTO ps_cms_documents(site_key,draft) VALUES(?,?)')->execute([$key,cms_encode($draft)]);
            $db->prepare('INSERT INTO ps_cms_history(site_key,actor,action,content) VALUES(?,?,?,?)')->execute([$key,'deployment','install',cms_encode($draft)]);
        }
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

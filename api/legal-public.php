<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
require __DIR__.'/../server/legal-documents.php';
if($_SERVER['REQUEST_METHOD']!=='GET')json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
try{json_response(['ok'=>true,'documents'=>array_values(legal_public_documents(legal_load($pdo)))]);}
catch(Throwable $e){error_log('legal_public: '.$e->getMessage());json_response(['ok'=>false,'error'=>'unavailable'],503);}

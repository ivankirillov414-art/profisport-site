<?php
declare(strict_types=1);
// Render packaged content with a public URL so Event metadata is emitted.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
function crm_config(?string $key=null): mixed {
    $value=['website'=>['public_url'=>'https://example.invalid/kosmoport']];
    if ($key===null) return $value;
    foreach (explode('.',$key) as $part) { if (!is_array($value)||!array_key_exists($part,$value)) return null; $value=$value[$part]; }
    return $value;
}
require dirname(__DIR__).'/public/index.php';

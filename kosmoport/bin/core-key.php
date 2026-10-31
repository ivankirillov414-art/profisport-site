<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
echo "Store these values only in a private config/password manager. Do not publish this output.\n";
echo 'app_key=' . bin2hex(random_bytes(32)) . PHP_EOL;
echo 'setup_key_hash=' . hash('sha256', $token) . PHP_EOL;
echo 'setup_key=' . $token . PHP_EOL;
echo "Remove setup_key_hash from private config after successful owner installation.\n";

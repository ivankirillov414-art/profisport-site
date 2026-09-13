<?php
declare(strict_types=1);

// Parser galleries also contain recommendations and banners. They cannot prove
// ownership of an image. Keep this retired route safe for already-open pages;
// native photos continue to be served by product-image.php/product-db-image.php.
header('Cache-Control: no-store');
http_response_code(404);
exit;

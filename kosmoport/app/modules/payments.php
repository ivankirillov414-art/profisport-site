<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/website/content.php';
require_once dirname(__DIR__).'/payments/readiness.php';

return ['GET payments/readiness'=>static fn(): array=>kp_payment_readiness()];

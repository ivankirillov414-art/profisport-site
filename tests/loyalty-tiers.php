<?php
declare(strict_types=1);
require __DIR__.'/../server/loyalty-tiers.php';
foreach([[0,500000,0],[0,500001,500],[0,5000000,500],[500,2999999,500],[500,3000000,700],[500,5000000,700],[700,4999999,700],[700,5000000,1000],[1000,9000000,1000]] as [$before,$amount,$after]){
    if(loyalty_next_tier($before,$amount)!==$after)throw new RuntimeException('Threshold or single-level advancement failed');
}
echo "PASS: exact thresholds, pre-discount basis and one level per purchase\n";

<?php
declare(strict_types=1);

/** Owner-confirmed rates, independent of the legacy single-price CMS contract. */
function kp_price_catalog(): array
{
    return [
        'version'=>2, 'currency'=>'RUB', 'confirmed_on'=>'2026-11-14',
        'admission'=>['weekday'=>['child'=>790,'adult'=>890], 'weekend'=>['child'=>890,'adult'=>990]],
        'weekend_iso_days'=>[6,7],
        'activities'=>[
            'master_classes'=>[['id'=>'mc399','price'=>399],['id'=>'mc499','price'=>499],['id'=>'mc599','price'=>599]],
            'vr'=>['price'=>499],
        ],
        'combos'=>[
            ['id'=>'combo999','master_classes'=>2,'vr'=>1,'price'=>999,'includes_admission'=>false],
            ['id'=>'combo1499','master_classes'=>3,'vr'=>1,'price'=>1499,'includes_admission'=>false],
            ['id'=>'combo1999','master_classes'=>3,'vr'=>3,'price'=>1999,'includes_admission'=>false],
        ],
        'child_age_range'=>null, 'free_entry_terms'=>null, 'holiday_override'=>null,
        'sales_enabled'=>false,
    ];
}

function kp_catalog_live_sales_ready(): bool
{
    // Real provider orders, verified payment and ticket fulfillment are not implemented.
    // A configured link or boolean alone must never turn the new catalog into a paid flow.
    return false;
}

function kp_price_date(mixed $date): ?DateTimeImmutable
{
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) || (int)substr($date,0,4)<1) return null;
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
    return $parsed!==false && $parsed->format('Y-m-d')===$date ? $parsed : null;
}

function kp_price_quote(array $catalog,array $event,array $input): array
{
    $date=kp_price_date($input['date']??null);
    if ($date===null) return ['ok'=>false,'error'=>'invalid_date'];
    $opening=kp_price_date($event['opening_date']??null); $closing=kp_price_date($event['closing_date']??null);
    if ($opening===null || $closing===null || $opening>$closing || $date<$opening || $date>$closing) return ['ok'=>false,'error'=>'outside_event'];
    $children=$input['children']??null; $adults=$input['adults']??null;
    if (!is_int($children)||!is_int($adults)||$children<0||$adults<0||$children>200||$adults>200||$children+$adults<1||$children+$adults>200) return ['ok'=>false,'error'=>'invalid_quantity'];
    $day=in_array((int)$date->format('N'),[6,7],true)?'weekend':'weekday';
    $rates=$catalog['admission'][$day];
    return ['ok'=>true,'date'=>$date->format('Y-m-d'),'day_type'=>$day,'children'=>$children,'adults'=>$adults,
        'guest_count'=>$children+$adults,'unit_prices'=>['child'=>$rates['child'],'adult'=>$rates['adult']],
        'admission_total'=>$children*$rates['child']+$adults*$rates['adult'],'currency'=>'RUB','catalog_version'=>$catalog['version']];
}

function kp_public_price_quote(): array
{
    $integer=static function(mixed $value): ?int {
        return is_string($value)&&preg_match('/^(?:0|[1-9]\d{0,2})$/D',$value) ? (int)$value : null;
    };
    $published=kp_read_published();
    $quote=kp_price_quote(kp_price_catalog(),$published['content']['event'],[
        'date'=>$_GET['date']??null,'children'=>$integer($_GET['children']??null),'adults'=>$integer($_GET['adults']??null),
    ]);
    if (!$quote['ok']) crm_fail('PRICE_'.strtoupper($quote['error']),$quote['error']==='invalid_quantity'?'Укажите количество детей и взрослых: от 1 до 200 гостей.':'Выберите действительную дату в период выставки.',422);
    return $quote;
}

/** Planned composition only; a bundle never charges its included activities again. */
function kp_price_basket(array $catalog,array $event,array $input): array
{
    $admission=kp_price_quote($catalog,$event,$input);
    if (!$admission['ok']) return $admission;
    $labels=['child'=>'Детский входной билет','adult'=>'Взрослый входной билет','combo999'=>'2 мастер-класса + 1 VR','combo1499'=>'3 мастер-класса + 1 VR','combo1999'=>'3 мастер-класса + 3 VR','mc399'=>'Мастер-класс 399 ₽','mc499'=>'Мастер-класс 499 ₽','mc599'=>'Мастер-класс 599 ₽','vr'=>'VR'];
    $groups=['combos'=>$catalog['combos'],'extras'=>[...$catalog['activities']['master_classes'],['id'=>'vr','price'=>$catalog['activities']['vr']['price']]]];
    $counts=[];
    foreach ($groups as $kind=>$items) {
        $map=array_key_exists($kind,$input)?$input[$kind]:[];
        if ($map instanceof stdClass) $map=(array)$map;
        if (!is_array($map) || ($map!==[] && array_is_list($map))) return ['ok'=>false,'error'=>'invalid_selection'];
        $counts[$kind]=array_fill_keys(array_column($items,'id'),0);
        foreach ($map as $id=>$quantity) {
            if (!is_string($id)||!array_key_exists($id,$counts[$kind])||!is_int($quantity)||$quantity<0||$quantity>20) return ['ok'=>false,'error'=>'invalid_selection'];
            $counts[$kind][$id]=$quantity;
        }
    }
    $lines=[];$bundlesTotal=0;$extrasTotal=0;$terms=false;
    $append=static function(string $id,int $quantity,int $unit,string $kind)use(&$lines,$labels):void{
        if ($quantity>0)$lines[]=['id'=>$id,'label'=>$labels[$id],'quantity'=>$quantity,'unit_price'=>$unit,'total'=>$quantity*$unit,'kind'=>$kind];
    };
    $append('child',$admission['children'],$admission['unit_prices']['child'],'admission');
    $append('adult',$admission['adults'],$admission['unit_prices']['adult'],'admission');
    foreach($groups['combos'] as $item){$quantity=$counts['combos'][$item['id']];$append($item['id'],$quantity,$item['price'],'combo');$bundlesTotal+=$quantity*$item['price'];if($quantity>0&&$item['includes_admission']===null)$terms=true;}
    foreach($groups['extras'] as $item){$quantity=$counts['extras'][$item['id']];$append($item['id'],$quantity,$item['price'],'extra');$extrasTotal+=$quantity*$item['price'];}
    return ['ok'=>true,'admission'=>$admission,'lines'=>$lines,'admission_total'=>$admission['admission_total'],'bundles_total'=>$bundlesTotal,'extras_total'=>$extrasTotal,'planned_total'=>$admission['admission_total']+$bundlesTotal+$extrasTotal,'currency'=>'RUB','requires_terms_confirmation'=>$terms,'payable_total'=>null];
}

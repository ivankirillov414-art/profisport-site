<?php
declare(strict_types=1);

// Manufacturer criteria are model scoped. Inspection reminders are not wear estimates.
function ensure_equipment_care_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS vehicle_care_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        vehicle_id BIGINT UNSIGNED NOT NULL,
        care_key VARCHAR(60) NOT NULL,
        event_type VARCHAR(20) NOT NULL,
        condition_state VARCHAR(20) NOT NULL,
        usage_value DECIMAL(12,1) NULL,
        usage_unit VARCHAR(20) NOT NULL,
        measurement_value DECIMAL(12,4) NULL,
        include_learning TINYINT(1) NOT NULL DEFAULT 1,
        note VARCHAR(500) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_care_vehicle(vehicle_id,care_key,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $events=table_columns($pdo,'vehicle_care_events');
    if(!isset($events['measurement_value']))$pdo->exec('ALTER TABLE vehicle_care_events ADD COLUMN measurement_value DECIMAL(12,4) NULL');
    $cols=table_columns($pdo,'customer_vehicles');
    if(!isset($cols['care_usage_value']))$pdo->exec('ALTER TABLE customer_vehicles ADD COLUMN care_usage_value DECIMAL(12,1) NULL');
}

function equipment_care_unit(string $type): string {
    return in_array($type,['skis','snowboard'],true)?'ski_days':(in_array($type,['bicycle','scooter'],true)?'km':'hours');
}

function equipment_care_templates(array $vehicle,array $components=[]): array {
    $type=(string)$vehicle['vehicle_type'];
    $sram='https://docs.sram.com/en-US/publications/6sfLCOGTn6FE98W8vXLqm0/UM%20-%20Chains';
    $shimano='https://si.shimano.com/en/pdfs/um/8KZ0A/UM-8KZ0A-005-ENG.pdf';
    $roller='https://www.rollerblade.com/assets/pdf/manual.pdf';
    $bones='https://bonesbearings.com/support/maintenance';
    $ski='https://www.rossignol.com/sk-en/care-instructions.html';
    $fischer='https://www.fischersports.com/help-support/guides-manuals/';
    $rows=[];
    $add=function(string $key,string $label,string $criterion,string $routine,?string $url=null)use(&$rows):void {
        $rows[$key]=['key'=>$key,'label'=>$label,'criterion'=>$criterion,'routine'=>$routine,'source_url'=>$url,'interval'=>null,'interval_unit'=>null,'measurement_threshold'=>null,'measurement_unit'=>null];
    };
    if($type==='bicycle'){
        $add('chain','Цепь','Замена по удлинению, измеренному совместимым калибром; порог зависит от цепи.','Очищайте и смазывайте цепь, проверяйте после грязи и дождя.');
        $add('brake_pads','Тормозные колодки','Замена по толщине и метке износа для вашей тормозной системы.','Проверяйте торможение перед каждой поездкой.');
        $add('tires','Покрышки','Замена при повреждениях, видимом каркасе или достижении индикатора износа.','Проверяйте давление и повреждения перед поездкой.','https://www.schwalbe.com/en/technology-faq/tire-wear/');
        $add('cassette','Кассета и звёзды','Замена по износу зубьев и работе трансмиссии с исправной цепью.','Контролируйте износ цепи и переключение.',$sram);
        $add('fork','Вилка и подвеска','Обслуживание по руководству именно вашей модели. Жёсткая вилка не требует сервиса амортизатора.','Проверяйте люфт, повреждения и утечки.');
        $add('hubs','Втулки, каретка и рулевая','Сервис при люфте, шуме и затруднённом вращении.','Проверяйте вращение и крепления.');
    }elseif($type==='inline_skates'){
        $add('wheels','Колёса','Универсального срока нет: замена по износу, повреждениям и потере профиля.','Переставляйте колёса по схеме вашей модели для равномерного износа.',$roller);
        $add('bearings','Подшипники','Сервис при загрязнении и ухудшении вращения; замена при повреждении.','Сушите после влаги, обслуживайте по инструкции.',$roller);
        $add('brake','Штатный тормоз, если установлен','Замените тормозную накладку по метке износа вашей модели.','Проверяйте тормоз перед катанием.',$roller);
        $add('fasteners','Оси, рама и застёжки','Не катайтесь при трещинах, повреждённых застёжках или ослабленных осях.','Проверяйте крепления перед катанием.',$roller);
    }elseif($type==='skateboard'){
        $add('bearings','Подшипники','Фиксированного срока нет: очистка при загрязнении и ухудшении вращения.','После очистки используйте рекомендованную смазку.',$bones);
        $add('wheels','Колёса','Замена при повреждениях и выраженных плоских участках.','Проверяйте равномерность качения.');
        $add('deck','Дека и гриптейп','Замена повреждённой деки; гриптейп — при потере сцепления.','Проверяйте трещины, расслоение и сцепление обуви.');
        $add('trucks','Подвески, бушинги и крепления','Сервис при трещинах, люфте и повреждении бушингов.','Проверяйте крепления и поворот подвесок перед катанием.');
    }elseif(in_array($type,['skis','snowboard'],true)){
        $nordic=$type==='skis'&&!preg_match('/горн|alpine|downhill/ui',(string)$vehicle['title']);
        $add('base','Скользящая поверхность','Подготовка и ремонт по состоянию: сухая база, ухудшение скольжения, царапины.','Очищайте и сушите после катания; подготовьте к хранению.',$nordic?$fischer:$ski);
        $add('wax','Смазка и подготовка базы','Обновление по состоянию скольжения и условиям снега; фиксированного срока нет.','Подбирайте средства для вашей базы; не наносите обычный парафин на камус.',$nordic?$fischer:$ski);
        $add('bindings','Крепления','Проверка перед сезоном; повреждения и неисправности требуют сервиса.','Контролируйте крепление и работу механизма.',$nordic?$fischer:$ski);
        if(!$nordic)$add('edges','Канты','Обработка по состоянию: ржавчина, заусенцы, плохое удержание.','Сушите после катания, не храните мокрыми.',$ski);
        if($nordic&&preg_match('/skin|камус/ui',(string)$vehicle['title']))$add('skins','Камус','Уход и замена по загрязнению и потере удержания.','Применяйте средства и процедуру для конкретного камуса.',$fischer);
        // Only Rossignol alpine skis: do not apply alpine binding intervals to Nordic skis.
        if(!$nordic&&$type==='skis'&&preg_match('/rossignol/ui',(string)$vehicle['title'])){
            $rows['bindings']['criterion']='Проверка у специалиста перед сезоном и не реже каждых 30 дней катания (Rossignol).';
            $rows['bindings']['interval']=30;$rows['bindings']['interval_unit']='ski_days';
        }
    }else{
        $add('wheels','Колёса и подшипники','Замена по износу и повреждениям, сервис при люфте и шуме.','Проверяйте колёса перед поездкой.');
        $add('brake','Тормоз','Замена по метке или измерению для вашей модели.','Проверяйте торможение перед поездкой.');
        $add('fasteners','Руль, дека и крепления','Сервис при люфте и повреждениях.','Проверяйте крепления перед поездкой.');
    }
    foreach($components as $c){
        if($type!=='bicycle')continue;
        $key=(string)$c['component_key'];$text=trim((string)($c['manufacturer']??'').' '.(string)($c['model']??''));
        if($key==='chain'&&preg_match('/sram/ui',$text)){$rows['chain']['criterion']='Для подтверждённой цепи SRAM: замена при удлинении 0,8%, замер совместимым калибром.';$rows['chain']['source_url']=$sram;$rows['chain']['measurement_threshold']=0.8;$rows['chain']['measurement_unit']='%';}
        if($key==='fork'&&preg_match('/rockshox/ui',$text)){$rows['fork']['criterion']='RockShox: сверьте модельный регламент; типовой сервис нижних ног — 50 часов, полный — 200 часов. До уточнения модели автоматический срок не назначен.';$rows['fork']['source_url']='https://www.sram.com/en/service';}
        if(str_contains($key,'brake')&&preg_match('/shimano/ui',$text)){$rows['brake_pads']['criterion']='Для дисковых колодок Shimano из указанного руководства: заменить при толщине материала 0,5 мм. Для других тормозов нужен их регламент.';$rows['brake_pads']['source_url']=$shimano;}
        if(in_array($key,['tires','front_tire','rear_tire'],true)&&preg_match('/schwalbe/ui',$text))$rows['tires']['criterion']='Schwalbe приводит ориентир 2 000–5 000 км для стандартных покрышек; это диапазон, а не гарантия. Заменяйте раньше при повреждениях.';
    }
    return array_values($rows);
}

function equipment_care_status(array $item,array $events,?float $usage,string $unit): array {
    $own=array_values(array_filter($events,fn($e)=>$e['care_key']===$item['key']));
    $latest=$own[0]??null;$state=$latest['condition_state']??'unknown';$wear=null;$remaining=null;$life=null;
    $replacements=array_reverse(array_values(array_filter($own,fn($e)=>$e['event_type']==='replaced')));
    $cycles=[];$previous=null;
    foreach($replacements as $r){
        if($previous&&$r['usage_unit']===$unit&&$previous['usage_unit']===$unit&&$r['usage_value']!==null&&$previous['usage_value']!==null&&(int)$r['include_learning']===1){$delta=(float)$r['usage_value']-(float)$previous['usage_value'];if($delta>0)$cycles[]=$delta;}
        $previous=$r;
    }
    if(count($cycles)>=2&&$usage!==null&&$previous&&$previous['usage_value']!==null){
        sort($cycles);$n=count($cycles);$life=$n%2?$cycles[intdiv($n,2)]:($cycles[$n/2-1]+$cycles[$n/2])/2;
        $elapsed=max(0,$usage-(float)$previous['usage_value']);$wear=min(100,$elapsed/$life*100);$remaining=max(0,$life-$elapsed);
        $calculated=$wear>=95?'due':($wear>=80?'soon':($wear>=60?'watch':'good'));
        if(!in_array($state,['watch','due'],true))$state=$calculated;
    }
    $basis=$wear!==null?'personal':'inspection';
    $measured=array_values(array_filter($own,fn($e)=>($e['measurement_value']??null)!==null))[0]??null;
    // A replacement invalidates any earlier measurement; a later inspection must not reset it.
    if($item['measurement_threshold']&&$measured&&(!$previous||(int)$measured['id']>(int)$previous['id'])){
        $wear=min(100,(float)$measured['measurement_value']/$item['measurement_threshold']*100);$remaining=null;$life=null;$basis='measurement';
        $state=$wear>=95?'due':($wear>=80?'soon':($wear>=60?'watch':'good'));
        if(($latest['condition_state']??'')==='due')$state='due';
        elseif(($latest['condition_state']??'')==='watch'&&$state==='good')$state='watch';
    }
    $service=array_values(array_filter($own,fn($e)=>in_array($e['event_type'],['serviced','replaced'],true)))[0]??null;
    $serviceRemaining=null;
    if($item['interval']&&$unit===$item['interval_unit']&&$usage!==null&&$service&&$service['usage_value']!==null){
        $serviceRemaining=max(0,$item['interval']-max(0,$usage-(float)$service['usage_value']));
        if($serviceRemaining<=0)$state='due';
    }
    return $item+['state'=>$state,'wear_percent'=>$wear!==null?round($wear,1):null,'remaining'=>$remaining!==null?round($remaining,1):null,'predicted_life'=>$life,'personal_cycles'=>count($cycles),'unit'=>$unit,'service_remaining'=>$serviceRemaining,'basis'=>$basis,'last_event'=>$latest,'history'=>array_slice($own,0,8)];
}

function equipment_care_payload(PDO $pdo,array $vehicle,array $components): array {
    $id=(int)$vehicle['id'];$s=$pdo->prepare('SELECT care_usage_value,odometer_km FROM customer_vehicles WHERE id=?');$s->execute([$id]);$counters=$s->fetch()?:[];
    $unit=equipment_care_unit((string)$vehicle['vehicle_type']);$usage=$unit==='km'?($counters['odometer_km']??null):($counters['care_usage_value']??null);
    $s=$pdo->prepare('SELECT * FROM vehicle_care_events WHERE vehicle_id=? ORDER BY id DESC');$s->execute([$id]);$events=$s->fetchAll();
    return ['usage_value'=>$usage!==null?(float)$usage:null,'usage_unit'=>$unit,'items'=>array_map(fn($item)=>equipment_care_status($item,$events,$usage!==null?(float)$usage:null,$unit),equipment_care_templates($vehicle,$components))];
}

function equipment_care_save(PDO $pdo,int $customerId,array $input): void {
    $id=(int)($input['vehicle_id']??0);
    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();else $pdo->exec('SAVEPOINT equipment_care');
    try{
        $s=$pdo->prepare('SELECT * FROM customer_vehicles WHERE id=? AND customer_id=? AND is_active=1 FOR UPDATE');$s->execute([$id,$customerId]);$v=$s->fetch();
        if(!$v)throw new InvalidArgumentException('vehicle_not_found');
        $unit=equipment_care_unit((string)$v['vehicle_type']);$value=$input['usage_value']??null;
        $old=$unit==='km'?($v['odometer_km']??null):($v['care_usage_value']??null);
        if($value!==null){
            if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<0||(float)$value>10000000||($old!==null&&(float)$value<(float)$old)||($unit==='ski_days'&&(float)$value!==floor((float)$value)))throw new InvalidArgumentException('bad_usage');
            $field=$unit==='km'?'odometer_km':'care_usage_value';
            $pdo->prepare("UPDATE customer_vehicles SET `$field`=? WHERE id=?")->execute([(float)$value,$id]);
            if($unit==='km')$pdo->prepare('UPDATE customer_vehicles SET odometer_updated_at=NOW() WHERE id=?')->execute([$id]);
        }else $value=$old;
        $key=(string)($input['care_key']??'');
        if($key!==''){
            $components=vehicle_passport_components($pdo,$id,false);$templates=equipment_care_templates($v,$components);$valid=array_column($templates,'key');
            $kind=(string)($input['event_type']??'checked');$state=(string)($input['condition_state']??'unknown');
            if(!in_array($key,$valid,true)||!in_array($kind,['checked','serviced','replaced'],true)||!in_array($state,['good','watch','due'],true))throw new InvalidArgumentException('bad_care_event');
            $template=array_values(array_filter($templates,fn($t)=>$t['key']===$key))[0];
            $measurement=$input['measurement_value']??null;
            if($measurement!==null&&(!$template['measurement_threshold']||!is_numeric($measurement)||!is_finite((float)$measurement)||(float)$measurement<0||(float)$measurement>100||$kind==='replaced'))throw new InvalidArgumentException('bad_measurement');
            $note=mb_substr(trim((string)($input['note']??'')),0,500);$learn=empty($input['exclude_learning'])?1:0;
            $pdo->prepare('INSERT INTO vehicle_care_events(vehicle_id,care_key,event_type,condition_state,usage_value,usage_unit,measurement_value,include_learning,note) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$id,$key,$kind,$state,$value,$unit,$measurement,$learn,$note]);
            // Reset a confirmed, unambiguously identified component only after physical replacement.
            $matching=array_values(array_filter($components,fn($c)=>$c['component_key']===$key));
            if($kind==='replaced'&&count($matching)===1)vehicle_passport_record_event($pdo,(int)$matching[0]['id'],['event_type'=>'replaced','odometer_km'=>$unit==='km'?$value:null,'include_learning'=>(bool)$learn,'note'=>'Замена подтверждена владельцем. '.$note],0);
        }
        if($owns)$pdo->commit();else $pdo->exec('RELEASE SAVEPOINT equipment_care');
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();elseif(!$owns){$pdo->exec('ROLLBACK TO SAVEPOINT equipment_care');$pdo->exec('RELEASE SAVEPOINT equipment_care');}throw $e;}

}

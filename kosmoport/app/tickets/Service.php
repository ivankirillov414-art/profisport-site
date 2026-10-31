<?php
declare(strict_types=1);
namespace Kosmoport\Tickets;

final class TicketError extends \RuntimeException {
    public function __construct(public string $errorCode, string $message, public int $status=422, public array $conflicts=[]) { parent::__construct($message); }
}
final class Service {
    public const FORMAT='kosmoport.tickets';
    public function __construct(private \PDO $db, private $audit, private string $actor='') { $db->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION); }
    private function rows(string $sql,array $args=[]):array { $q=$this->db->prepare($sql);$q->execute($args);return $q->fetchAll(\PDO::FETCH_ASSOC); }
    private function run(string $sql,array $args=[]):void { $this->db->prepare($sql)->execute($args); }
    private function tx(callable $fn):mixed {
        $this->db->beginTransaction();
        try { // Serialize admissions, reversals and browser imports, also across workers.
            $this->run('UPDATE kcrm_ticket_lock SET touched=touched+1 WHERE id=1');
            $r=$fn();$this->db->commit();return $r;
        } catch(\Throwable $e) {if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public static function json(mixed $v):string {return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private static function canonical(mixed $v):mixed {if(!is_array($v))return $v;if(!array_is_list($v))ksort($v);foreach($v as &$x)$x=self::canonical($x);return $v;}
    private static function equal(array $a,array $b):bool {return self::json(self::canonical($a))===self::json(self::canonical($b));}
    public static function id(mixed $v):string {if(!is_string($v)||!preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D',$v))throw new TicketError('invalid_id','Некорректный идентификатор');return $v;}
    public static function code(mixed $v):string {if(!is_string($v)||!preg_match('/^[0-9]{18}$/D',$v)||$v==='123456789012345678')throw new TicketError('invalid_code','Нужен действительный номер билета из 18 цифр');return $v;}
    private static function stamp(mixed $v):string {if(!is_string($v)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D',$v))throw new TicketError('invalid_time','Некорректное время');try{$d=new \DateTimeImmutable($v);$err=\DateTimeImmutable::getLastErrors();if($err&&($err['warning_count']||$err['error_count']))throw new \RuntimeException();}catch(\Throwable){throw new TicketError('invalid_time','Некорректное время');}return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');}
    private static function text(array $r,string $key,int $max,bool $empty=false):string {$s=$r[$key]??null;if(!is_string($s)||(!$empty&&trim($s)==='')||strlen($s)>$max)throw new TicketError('invalid_field','Проверьте поле '.$key);return $s;}
    private function log(string $action,string $id,array $data):void {($this->audit)($action,'ticket',$id,$data);}
    public function sources():array{return $this->rows('SELECT id,name,category,address FROM kcrm_ticket_sources ORDER BY name');}
    public function batches():array{return array_map(fn($r)=>json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR),$this->rows('SELECT payload FROM kcrm_ticket_batches ORDER BY id'));}
    private function ticket(string $code):array {
        $r=$this->rows('SELECT t.*,b.active,b.payload AS batch_payload,s.name AS source,s.id AS source_id FROM kcrm_tickets t JOIN kcrm_ticket_batches b ON b.id=t.batch_id JOIN kcrm_ticket_sources s ON s.id=b.source_id WHERE t.code=?',[$code]);
        if(!$r)throw new TicketError('not_found','Билет не найден',404);return $r[0];
    }
    private function eligible(array $t):void {
        if(!(int)$t['active'])throw new TicketError('blocked','Партия заблокирована',409);
        $b=json_decode($t['batch_payload'],true);$cfg=$b['print_config']??[];if(is_string($cfg))$cfg=json_decode($cfg,true)?:[];
        $day=(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Yekaterinburg')))->format('Y-m-d');
        if(!empty($cfg['startDate'])&&$day<$cfg['startDate'])throw new TicketError('not_started','Срок действия ещё не начался',409);
        if(!empty($cfg['endDate'])&&$day>$cfg['endDate'])throw new TicketError('expired','Срок действия закончился',409);
        if($t['used_at']!==null)throw new TicketError('already_used','Билет уже использован',409);
    }
    public function check(string $code):array {$t=$this->ticket(self::code($code));$this->eligible($t);return ['status'=>'valid','code'=>$code,'source'=>$t['source'],'source_id'=>$t['source_id'],'batch_id'=>$t['batch_id'],'consumed'=>false,'requires_manual_adult_payment_confirmation'=>true];}
    public function admit(array $input):array {
        $code=self::code($input['code']??null);$rid=self::id($input['request_id']??null);
        if(($input['adult_payment_confirmed']??false)!==true)throw new TicketError('adult_confirmation_required','Подтвердите оплату взрослого билета вручную');
        $fp=hash('sha256',self::json([$code,true,$this->actor]));
        return $this->tx(function()use($code,$rid,$fp){
            $old=$this->rows('SELECT * FROM kcrm_ticket_requests WHERE id=?',[$rid]);
            if($old){if(!hash_equals($old[0]['fingerprint'],$fp))throw new TicketError('request_conflict','Этот request_id уже использован для другого действия',409);return json_decode($old[0]['response'],true);}
            $t=$this->ticket($code);$this->eligible($t);$id=bin2hex(random_bytes(16));$at=gmdate('Y-m-d\TH:i:s.000\Z');
            $a=['id'=>$id,'code'=>$code,'admitted_at'=>$at,'reversed_at'=>null,'origin'=>'crm','manual_adult_payment_confirmed'=>true,'fiscal_payment_verified'=>false,'actor_id'=>$this->actor];
            $this->run('INSERT INTO kcrm_ticket_admissions(id,code,admitted_at,reversed_at,payload) VALUES(?,?,?,?,?)',[$id,$code,$at,null,self::json($a)]);
            $this->run('UPDATE kcrm_tickets SET used_at=?,admission_id=? WHERE code=? AND used_at IS NULL',[$at,$id,$code]);
            $result=['status'=>'accepted','admission_id'=>$id,'code'=>$code,'used_at'=>$at,'source'=>$t['source'],'manual_adult_payment_confirmed'=>true,'fiscal_payment_verified'=>false];
            $this->run('INSERT INTO kcrm_ticket_requests(id,fingerprint,response) VALUES(?,?,?)',[$rid,$fp,self::json($result)]);$this->log('ticket.admit',$id,$result);return $result;
        });
    }
    public function reverse(array $input):array {
        $id=self::id($input['admission_id']??null);$reason=self::text($input,'reason',1000);
        return $this->tx(function()use($id,$reason){$rows=$this->rows('SELECT * FROM kcrm_ticket_admissions WHERE id=?',[$id]);if(!$rows)throw new TicketError('not_found','Проход не найден',404);$a=$rows[0];
            if($a['reversed_at']!==null)throw new TicketError('already_reversed','Проход уже отменён',409);
            $t=$this->ticket($a['code']);if($t['admission_id']!==$id)throw new TicketError('state_conflict','Состояние билета изменилось',409);
            $at=gmdate('Y-m-d\TH:i:s.000\Z');$payload=json_decode($a['payload'],true);$payload['reversed_at']=$at;$payload['reversal_reason']=$reason;$payload['reversed_by']=$this->actor;
            $this->run('UPDATE kcrm_ticket_admissions SET reversed_at=?,payload=? WHERE id=?',[$at,self::json($payload),$id]);$this->run('UPDATE kcrm_tickets SET used_at=NULL,admission_id=NULL WHERE code=?',[$a['code']]);$this->log('ticket.reverse',$id,['reason'=>$reason,'code'=>$a['code']]);return ['status'=>'reversed','admission_id'=>$id];});
    }
    public static function range(string $period,string $date):array {
        $zone=new \DateTimeZone('Asia/Yekaterinburg');$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,$zone);
        if(!$d||$d->format('Y-m-d')!==$date)throw new TicketError('invalid_date','Некорректная дата');
        if($period==='all')return ['0000','9999'];
        if($period==='week')$d=$d->modify('-'.((int)$d->format('N')-1).' days');elseif($period==='month')$d=$d->modify('first day of this month');elseif($period!=='day')throw new TicketError('invalid_period','Неизвестный период');
        $end=$d->modify($period==='month'?'+1 month':($period==='week'?'+7 days':'+1 day'));
        return array_map(fn($x)=>$x->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z'),[$d,$end]);
    }
    public function statistics(string $period,string $date):array {
        [$from,$to]=self::range($period,$date);
        $rows=$this->rows('SELECT s.id,s.name,s.category,s.address,COUNT(*) AS used FROM kcrm_tickets t JOIN kcrm_ticket_batches b ON b.id=t.batch_id JOIN kcrm_ticket_sources s ON s.id=b.source_id WHERE t.used_at>=? AND t.used_at<? GROUP BY s.id,s.name,s.category,s.address ORDER BY used DESC,s.name',[$from,$to]);
        foreach($rows as &$r)$r['used']=(int)$r['used'];unset($r);
        return ['period'=>$period,'date'=>$date,'timezone'=>'Asia/Yekaterinburg','from'=>$from,'to'=>$to,'used_invitations'=>array_sum(array_column($rows,'used')),'top_source'=>$rows[0]??null,'sources'=>$rows,'meaning'=>'Место распространения; только действующие проходы по приглашениям, не все посетители и не продажи'];
    }
    public function export():array {
        return $this->tx(function(){ $out=['format'=>self::FORMAT,'version'=>1,'exported_at'=>gmdate('c'),'origin'=>'crm'];
            foreach(['sources'=>'kcrm_ticket_sources','batches'=>'kcrm_ticket_batches','tickets'=>'kcrm_tickets','admissions'=>'kcrm_ticket_admissions','events'=>'kcrm_ticket_events'] as $key=>$table){$out[$key]=[];foreach($this->rows('SELECT * FROM '.$table) as $r){$v=json_decode($r['payload'],true);if($key==='tickets'){$v['used_at']=$r['used_at'];$v['scan_id']=$r['admission_id'];}$out[$key][]=$v;}}return $out;});
    }
    public function import(array $doc):array {
        if(($doc['format']??null)!==self::FORMAT||($doc['version']??null)!==1)throw new TicketError('unsupported_format','Нужен формат kosmoport.tickets версии 1');
        $spec=['sources'=>['kcrm_ticket_sources','id'],'batches'=>['kcrm_ticket_batches','id'],'tickets'=>['kcrm_tickets','code'],'admissions'=>['kcrm_ticket_admissions','id'],'events'=>['kcrm_ticket_events','id']];
        foreach($spec as $key=>$_)if(!isset($doc[$key])||!is_array($doc[$key])||!array_is_list($doc[$key])||count($doc[$key])>100000)throw new TicketError('invalid_collection','Некорректная коллекция '.$key);
        return $this->tx(function()use($doc,$spec){$added=0;$same=0;$conflicts=[];
            foreach($spec as $key=>[$table,$pk]){ $seen=[];
                foreach($doc[$key] as $r){if(!is_array($r))throw new TicketError('invalid_row','Некорректная строка');$id=$pk==='code'?self::code($r[$pk]??null):self::id($r[$pk]??null);if(isset($seen[$id]))throw new TicketError('duplicate_id','Повтор ID внутри файла');$seen[$id]=true;
                    $old=$this->rows('SELECT * FROM '.$table.' WHERE '.$pk.'=?',[$id]);
                    if($old){$previous=json_decode($old[0]['payload'],true);if($key==='tickets'){$previous['used_at']=$old[0]['used_at'];$previous['scan_id']=$old[0]['admission_id'];}if(self::equal($previous,$r)){$same++;continue;}$conflicts[]=['collection'=>$key,'id'=>$id,'reason'=>'existing_record_differs'];continue;}
                    if($key==='sources'){$this->run('INSERT INTO kcrm_ticket_sources(id,name,category,address,payload) VALUES(?,?,?,?,?)',[$id,self::text($r,'name',250),self::text($r,'category',150),self::text($r,'address',500,true),self::json($r)]);}
                    elseif($key==='batches'){self::id($r['source_id']??null);if(!in_array($r['active']??null,[0,1],true))throw new TicketError('invalid_batch','Некорректное состояние партии');$cfg=$r['print_config']??[];if(is_string($cfg))$cfg=$cfg===''?[]:json_decode($cfg,true);if(!is_array($cfg))throw new TicketError('invalid_batch','Некорректные реквизиты партии');foreach(['startDate','endDate'] as $keyDate)if(!empty($cfg[$keyDate]))self::range('day',(string)$cfg[$keyDate]);if(!empty($cfg['startDate'])&&!empty($cfg['endDate'])&&$cfg['startDate']>$cfg['endDate'])throw new TicketError('invalid_batch','Неверный срок действия');if(!$this->rows('SELECT id FROM kcrm_ticket_sources WHERE id=?',[$r['source_id']]))throw new TicketError('missing_source','Сначала импортируйте точку распространения');$this->run('INSERT INTO kcrm_ticket_batches(id,source_id,active,payload) VALUES(?,?,?,?)',[$id,$r['source_id'],$r['active'],self::json($r)]);}
                    elseif($key==='tickets'){self::id($r['batch_id']??null);if(!$this->rows('SELECT id FROM kcrm_ticket_batches WHERE id=?',[$r['batch_id']]))throw new TicketError('missing_batch','Сначала импортируйте партию');$used=$r['used_at']??null;$aid=$r['scan_id']??null;if(($used===null)!==($aid===null))throw new TicketError('invalid_state','Неполное состояние прохода');if($used!==null){$used=self::stamp($used);self::id($aid);}$r['used_at']=$used;$r['scan_id']=$aid;$this->run('INSERT INTO kcrm_tickets(code,batch_id,used_at,admission_id,payload) VALUES(?,?,?,?,?)',[$id,$r['batch_id'],$used,$aid,self::json($r)]);}
                    elseif($key==='admissions'){self::code($r['code']??null);if(!$this->rows('SELECT code FROM kcrm_tickets WHERE code=?',[$r['code']]))throw new TicketError('missing_ticket','Сначала импортируйте билет');$at=self::stamp($r['admitted_at']??null);$rev=isset($r['reversed_at'])?self::stamp($r['reversed_at']):null;if($rev!==null&&$rev<$at)throw new TicketError('invalid_time','Отмена раньше прохода');$this->run('INSERT INTO kcrm_ticket_admissions(id,code,admitted_at,reversed_at,payload) VALUES(?,?,?,?,?)',[$id,$r['code'],$at,$rev,self::json($r)]);}
                    else $this->run('INSERT INTO kcrm_ticket_events(id,payload) VALUES(?,?)',[$id,self::json($r)]);
                    $added++;
                }
            }
            if($conflicts)throw new TicketError('import_conflict','Импорт отменён целиком: найдены расхождения. Сохраните обе базы и разберите конфликты.',409,array_slice($conflicts,0,100));
            foreach($this->rows('SELECT t.code,t.used_at,t.admission_id,a.id AS found,a.code AS admitted_code,a.reversed_at FROM kcrm_tickets t LEFT JOIN kcrm_ticket_admissions a ON a.id=t.admission_id WHERE t.used_at IS NOT NULL') as $r)if(!$r['found']||$r['admitted_code']!==$r['code']||$r['reversed_at']!==null)throw new TicketError('invalid_admission','Использованный билет не связан с действующим проходом');
            foreach($this->rows('SELECT a.id,a.code,a.admitted_at,t.admission_id,t.used_at FROM kcrm_ticket_admissions a JOIN kcrm_tickets t ON t.code=a.code WHERE a.reversed_at IS NULL') as $r)if($r['admission_id']!==$r['id']||$r['used_at']!==$r['admitted_at'])throw new TicketError('invalid_admission','Несогласованный действующий проход');
            $this->log('ticket.import','snapshot',['added'=>$added,'unchanged'=>$same]);return ['added'=>$added,'unchanged'=>$same,'conflicts'=>[]];
        });
    }
}

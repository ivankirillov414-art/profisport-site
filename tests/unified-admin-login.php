<?php
declare(strict_types=1);
require __DIR__.'/../server/admin-auth.php';
ini_set('session.save_path',sys_get_temp_dir());
function start_secure_session(): void { if(session_status()!==PHP_SESSION_ACTIVE)session_start(); }
function auth_rate_check(PDO $pdo,string $scope,string $identity): void {}
function auth_rate_failure(PDO $pdo,string $scope,string $identity): void {}
function auth_rate_clear(PDO $pdo,string $scope,string $identity): void {}
function audit(PDO $pdo,string $action,?string $type=null,?string $id=null): void {}
function json_response(array $data,int $status=200): never { throw new RuntimeException($data['error'],$status); }
class LoginStatement extends PDOStatement {
    public function __construct(private LoginDatabase $db,private string $sql) {}
    public function execute(?array $params=null): bool {
        if(str_starts_with($this->sql,'UPDATE'))$this->db->promotions++;
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0): mixed { return $this->db->user; }
}
class LoginDatabase extends PDO {
    public int $promotions=0;
    public function __construct(public array|false $user) {}
    public function prepare(string $query,array $options=[]): PDOStatement|false { return new LoginStatement($this,$query); }
}
function check(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
$owner=['id'=>1,'username'=>'Иван Кириллов 414','password_hash'=>password_hash('test-only-password',PASSWORD_DEFAULT),'role'=>'staff','is_active'=>1];
foreach([false,$owner,array_replace($owner,['is_active'=>0])] as $user){
    $db=new LoginDatabase($user);$_SESSION=[];
    try{admin_login($db,'Иван Кириллов 414',$user!==false && !$user['is_active']?'test-only-password':'wrong-password');throw new LogicException('Login unexpectedly succeeded');}
    catch(RuntimeException $e){check($e->getCode()===401,'Invalid or inactive accounts must be rejected');}
    check($db->promotions===0 && empty($_SESSION['admin']),'Rejected login must not grant admin rights');
}
$db=new LoginDatabase($owner);$result=admin_login($db,$owner['username'],'test-only-password');
check($db->promotions===1 && $result['admin']['role']==='owner','Verified owner must receive owner rights');
check($result['redirect']==='admin/index.php' && strlen($result['csrf'])===48,'Admin login must create CSRF and destination');
$staff=array_replace($owner,['id'=>2,'username'=>'Other administrator']);$db=new LoginDatabase($staff);
$result=admin_login($db,$staff['username'],'test-only-password');
check($db->promotions===0 && $result['admin']['role']==='staff','Other accounts must retain their role');
echo "Unified admin login permission checks passed.\n";

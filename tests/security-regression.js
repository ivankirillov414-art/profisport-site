const fs=require('fs');
const assert=require('assert');
const read=file=>fs.readFileSync(file,'utf8');

const bootstrap=read('server/bootstrap.php');
const adminApi=read('server/api.php');
const customerApi=read('api/customer.php');
const deploy=read('.github/workflows/deploy-infinityfree.yml');
const recovery=read('admin/recover-access.php');
const csv=read('api/customer-admin.php');
const headers=read('.htaccess');

assert(bootstrap.includes('CREATE TABLE IF NOT EXISTS auth_rate_limits'));
assert(bootstrap.includes("'secure'=>true")&&bootstrap.includes("'httponly'=>true"));
assert(adminApi.includes("auth_rate_check($pdo,'admin_login'"));
assert(customerApi.includes("auth_rate_check($pdo,'customer_login'"));
assert(customerApi.includes("auth_rate_check($pdo,'customer_register'"));
assert(!recovery.includes('const BOOT_HASH='));
assert(recovery.includes("$config['recovery_bootstrap_hash']"));
assert(csv.includes("array_map('csv_safe'"));
assert(deploy.includes('protocol: ftps-legacy'));
assert(deploy.includes('security: strict'));
assert(!deploy.includes('protocol: ftp\n'));
assert(!deploy.includes('secrets.CATALOG_TOKEN'));
assert(deploy.includes('openssl rand -hex 32'));
assert(headers.includes('Content-Security-Policy'));
assert(headers.includes('Strict-Transport-Security'));
console.log('Security regression checks passed.');

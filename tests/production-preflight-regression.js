const fs=require('fs');
const path=require('path');
const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const exists=p=>fs.existsSync(path.join(root,p));

const ht=read('.htaccess');
const serverHt=read('server/.htaccess');
const gitignore=read('.gitignore');
const h404=read('404.html');
const h500=read('500.html');
const health=read('api/health.php');
const adminHealth=read('admin/health.php');
const deploy=read('.github/workflows/deploy-infinityfree.yml');
const pages=read('.github/workflows/pages.yml');

assert.match(ht,/ErrorDocument 404 \/404\.html/,'Apache must route missing pages to branded 404');
assert.match(ht,/ErrorDocument 500 \/500\.html/,'Apache must route server errors to branded 500');
assert.match(h404,/Ошибка 404/,'404 page must be explicit');
assert.match(h404,/meta name="robots" content="noindex"/,'404 page must not be indexed');
assert.match(h500,/Ошибка 500/,'500 page must be explicit');
assert.match(h500,/meta name="robots" content="noindex"/,'500 page must not be indexed');
assert.doesNotMatch(h404,/<script\b/i,'error pages must stay static');
assert.doesNotMatch(h500,/<script\b/i,'error pages must stay static');

for(const table of ['products','customers','orders','loyalty_transactions','customer_vehicles','service_requests','service_request_status_history']){
  assert.ok(health.includes("'"+table+"'"),'public health must verify '+table);
}
assert.match(health,/'schema'\s*=>\s*true/,'healthy response must certify schema readiness');
assert.match(health,/503/,'unhealthy response must use service-unavailable status');
assert.match(health,/try \{\s*require __DIR__\.\'\/\.\.\/server\/bootstrap\.php\'/,'health must catch bootstrap failures instead of dying before JSON response');
assert.doesNotMatch(health,/getMessage\(\)/,'public health must not expose exception messages');
for(const table of ['loyalty_transactions','customer_vehicles','service_request_status_history']) assert.ok(adminHealth.includes("'"+table+"'"),'admin health missing '+table);

assert.match(gitignore,/server\/config\.php/,'private server config must remain ignored');
assert.ok(serverHt.includes('config\\.php')||serverHt.includes('config.php'),'server config must be denied over HTTP');
assert.equal(exists('server/config.php'),false,'production credentials must not be committed');

assert.match(deploy,/preflight:/,'deployment must have a dedicated preflight job');
assert.match(deploy,/schema: \[fresh, legacy\]/,'deploy preflight must cover fresh and legacy schemas');
assert.match(deploy,/deploy:\s*\n\s*needs: preflight/,'FTP deployment must wait for preflight');
for(const check of ['profile-phone-login-regression.js','customer-phone-login-regression.js','admin-customer-card-regression.js','loyalty-live-regression.js','production-preflight-regression.js','tests/loyalty-live.php','tests/phone-login-http.py']){
  assert.ok(deploy.includes(check),'deploy gate missing '+check);
}
for(const deployed of ['404.html','500.html','admin/customer.php']) assert.ok(deploy.includes(deployed),'FTP verification missing '+deployed);
assert.ok(deploy.includes("ErrorDocument 404 /404.html"),'FTP verification must inspect 404 routing');
assert.ok(deploy.includes("ErrorDocument 500 /500.html"),'FTP verification must inspect 500 routing');
assert.ok(deploy.includes('Smoke-check public health and 404'),'deploy must probe the public health endpoint and branded 404');
assert.ok(deploy.includes("payload.get('schema') is True"),'live health smoke must require schema readiness');
assert.match(pages,/workflow_dispatch:/,'GitHub Pages preview must stay available manually');
assert.doesNotMatch(pages,/\bpush\s*:/,'GitHub Pages must not auto-publish an incomplete static copy on main pushes');
assert.match(pages,/manual static preview only/,'Pages workflow must clearly identify its non-production role');

console.log('Production preflight, error handling, health and deployment gate checks passed.');

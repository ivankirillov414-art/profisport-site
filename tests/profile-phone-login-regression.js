const fs=require('fs');
const path=require('path');
const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');

const api=read('api/customer.php');
const profile=read('profile.html');

assert.match(profile,/Email или телефон/,'login form must explain both identifiers');
assert.match(profile,/name="login"/,'login form must submit a unified login field');
assert.match(profile,/autocomplete="username"/,'login identifier should use username autocomplete');

assert.match(api,/\$in\['login'\]\?\?\$in\['email'\]/,'API must preserve legacy email login payloads');
assert.match(api,/customer_phone\(\$rawLogin\)/,'API must normalize phone identifiers');
assert.match(api,/WHERE phone=\?/,'API must support phone lookup');
assert.match(api,/LIMIT 2/,'phone login must detect ambiguous duplicate account phones');
assert.match(api,/phone_exists/,'registration/profile updates must protect phone-login uniqueness');
assert.match(api,/password_verify/,'phone login must keep password verification');

const inline=[...profile.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map(m=>m[1]).filter(Boolean);
for(const source of inline)new Function(source);

console.log('Customer phone-or-email login regression checks passed.');

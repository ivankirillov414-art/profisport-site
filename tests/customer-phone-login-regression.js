const fs=require('fs');
const path=require('path');
const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');

const api=read('api/customer.php');
const profile=read('profile.html');

assert.match(profile,/Email или телефон/,'login form must explain both identifiers');
assert.match(profile,/name="login"/,'login form must submit a neutral login identifier');
assert.match(profile,/autocomplete="username"/,'identifier must use username autocomplete');
assert.match(profile,/Проверьте email\/телефон и пароль/,'login error must mention both supported identifiers');

assert.match(api,/\$in\['login'\]\?\?\$in\['email'\]/,'API must keep legacy email payload compatibility');
assert.match(api,/customer_phone\(\$raw\)/,'API must normalize phone login with the same phone helper');
assert.match(api,/WHERE phone=\? AND password_hash IS NOT NULL ORDER BY id LIMIT 2/,'phone lookup must detect ambiguous legacy duplicates');
assert.match(api,/count\(\$matches\)===1/,'phone login must never choose between duplicate phone accounts');
assert.match(api,/auth_rate_check\(\$pdo,'customer_login',\$identity\)/,'rate limiting must use normalized login identity');

const inline=[...profile.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map(m=>m[1]).filter(Boolean);
for(const source of inline)new Function(source);
console.log('Customer email/phone login regression checks passed.');

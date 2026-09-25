const fs=require('fs');
const path=require('path');
const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');

const profile=read('profile.html');
const css=read('profile-dashboard.css');
const api=read('api/customer.php');
const bootstrap=read('server/bootstrap.php');
const checkout=read('checkout.js');
const cookieJs=read('cookie-consent.js');
const cookieCss=read('cookie-consent.css');
const buyer=read('buyer-info.html');

assert.match(bootstrap,/preferred_store/,'customer schema must include preferred_store');
assert.match(api,/action==='profile_update'/,'customer API must support profile updates');
assert.match(api,/password_required/,'email changes must require current password');
assert.match(api,/invalid_password/,'email changes must validate current password');
assert.match(api,/email_exists/,'email changes must preserve unique email');
assert.match(api,/customer_preferred_store/,'profile update must validate preferred store');
assert.match(profile,/id="profile-settings"/,'profile must have settings section');
assert.match(profile,/id="profileEditForm"/,'profile must have edit form');
assert.match(profile,/id="profilePasswordConfirm"/,'profile must show password confirmation only for email changes');
assert.match(profile,/id="editProfile"/,'profile hero must link to editable profile');
assert.match(profile,/Проспект Победы, 118 строение 2/,'profile must offer both stores');
assert.match(checkout,/preferred_store/,'checkout must use preferred pickup store');
for(const cls of ['profileSettingsLayout','profileSettingsSummary','profileEditForm','profileHeroActions']) assert.match(css,new RegExp('\\.'+cls+'(?:\\{|[,.:])'),'profile CSS missing .'+cls);

assert.match(cookieJs,/profisport_cookie_consent_v1/,'cookie consent must persist acceptance');
assert.match(cookieJs,/localStorage\.setItem/,'cookie consent must save acceptance locally');
assert.match(cookieJs,/buyer-info\.html#cookies/,'cookie consent must link to browser-storage explanation');
assert.match(cookieJs,/cookie-consent\.css\?v=1/,'cookie consent script must lazy-load its stylesheet');
assert.match(cookieCss,/\.cookieConsent/,'cookie consent must be styled');
assert.match(cookieCss,/cookieConsentHasMobileNav/,'cookie consent must avoid mobile bottom navigation');
assert.match(buyer,/id="cookies"/,'buyer info must document cookie/browser storage');

for(const page of ['index.html','product.html','checkout.html','profile.html','service.html','shop.html','workshop.html','buyer-info.html']){
  const html=read(page);
  assert.doesNotMatch(html,/cookie-consent\.css\?v=1/,'cookie CSS must stay lazy-loaded on '+page);
  assert.match(html,/cookie-consent\.js\?v=1/,'missing cookie JS on '+page);
}

const ids=[...profile.matchAll(/\bid="([^"]+)"/g)].map(m=>m[1]);
assert.deepEqual([...new Set(ids.filter((id,i)=>ids.indexOf(id)!==i))],[],'profile.html must not contain duplicate ids');
const inline=[...profile.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map(m=>m[1]).filter(Boolean);
for(const source of inline)new Function(source);
new Function(cookieJs);
new Function(checkout);

console.log('Customer profile block 5 and cookie consent regression checks passed.');

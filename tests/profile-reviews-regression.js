const fs=require('fs');
const path=require('path');
const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');

const profile=read('profile.html');
const css=read('profile-dashboard.css');
const api=read('api/customer.php');
const product=read('product-kant.js');
const productCss=read('product-info.css');

assert.match(api,/function customer_review_details/,'account API must expose submitted reviews');
assert.match(api,/function customer_review_eligible/,'account API must expose completed-purchase review candidates');
assert.match(api,/o\.status='completed'/,'review eligibility must require completed orders');
assert.match(api,/review_not_eligible/,'review submit must reject non-purchases');
assert.match(api,/duplicate_review/,'review submit must reject duplicates');
assert.match(api,/\$review\['status'\]\)!=='rejected'/,'rejected review must be resubmittable instead of duplicated');
assert.match(api,/GET_LOCK/,'review creation must serialize customer/product submissions');
assert.match(api,/verified_purchase/,'public and account review payloads must include verified purchase');
assert.match(api,/review_details/,'me payload must include review details');
assert.match(api,/review_eligible/,'me payload must include review eligibility');

for(const fn of ['renderReviewDashboard','reviewEligibleCard','reviewDetailCard','openReviewComposer','submitCustomerReview']){
  assert.match(profile,new RegExp('function '+fn+'\\b'),'profile must implement '+fn);
}
assert.match(profile,/Куплено в ProfiSport/,'profile reviews must show verified purchase label');
assert.match(profile,/На модерации/,'profile reviews must show moderation status');
assert.match(profile,/Исправить и отправить снова/,'rejected reviews must support resubmission');
assert.match(profile,/id="reviewComposer"/,'profile must have review composer');

for(const cls of ['reviewEligibleCard','customerReviewCard','reviewStatusChip','reviewComposer','reviewRatingPicker','verifiedPurchaseBadge']){
  assert.match(css,new RegExp('\\.'+cls+'(?:\\{|[,.:])'),'review CSS must contain .'+cls);
}

assert.match(product,/function reviewAccess/,'product page must gate review form by purchase status');
assert.match(product,/review_not_eligible/,'product page must surface verified purchase requirement');
assert.match(product,/verifiedReviewBadge/,'public review list must render verified purchase badge');
assert.match(product,/review_eligible/,'product page must load review eligibility from account');
assert.match(productCss,/\.verifiedReviewBadge/,'public verified badge must be styled');

const inline=[...profile.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map(m=>m[1]).filter(Boolean);
for(const source of inline)new Function(source);
new Function(product);

console.log('Customer reviews block 4 regression checks passed.');

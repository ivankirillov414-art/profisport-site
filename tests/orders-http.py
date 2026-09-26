import json, urllib.request, urllib.error, urllib.parse
from datetime import datetime
from zoneinfo import ZoneInfo
BASE='http://127.0.0.1:8080/'
def call(path, data=None, cookie=None, csrf=None):
    headers={'Content-Type':'application/json'}
    if cookie: headers['Cookie']=cookie
    if csrf: headers['X-CSRF-Token']=csrf
    req=urllib.request.Request(BASE+path,data=json.dumps(data).encode() if data is not None else None,headers=headers)
    try: r=urllib.request.urlopen(req,timeout=15)
    except urllib.error.HTTPError as e: r=e
    raw=r.read()
    return r.status,json.loads(raw),r.headers
base={'name':'Test buyer','phone':'+79991234567','pickup_store':'Проспект Победы, 118 строение 2','items':[1,1],'request_key':'a'*64}
status,j,_=call('api/order-create.php',{**base,'items':[1,1,1]});assert (status,j['error'])==(409,'insufficient_stock')
status,j,_=call('api/order-create.php',{**base,'items':[2]});assert (status,j['error'])==(409,'price_unavailable')
status,j,_=call('api/order-create.php',{**base,'delivery':'orenburg_delivery'});assert (status,j['error'])==(422,'address_required')
status,j,_=call('api/order-create.php',{**base,'pickup_store':'Неизвестный магазин'});assert (status,j['error'])==(422,'invalid_pickup_store')
status,order,_=call('api/order-create.php',base);assert status==200 and order['total_rub']==300,(status,order)
status,replay,_=call('api/order-create.php',base);assert replay==order
status,j,_=call('api/order-create.php',{**base,'name':'Changed buyer'});assert (status,j['error'])==(409,'request_conflict')
assert call('api/orders.php')[0]==401
status,auth,h=call('server/api.php?action=login',{'username':'Иван Кириллов 414','password':'test-only-password'});assert status==200
cookies=h.get_all('Set-Cookie');cookie=next(c.split(';')[0] for c in reversed(cookies) if c.startswith('PROFISPORT_ADMIN='));csrf=auth['csrf']
status,j,_=call('api/orders.php',cookie=cookie);assert status==200 and j['total']==1
id=j['items'][0]['id']
created=datetime.fromisoformat(j['items'][0]['created_at']).replace(tzinfo=ZoneInfo('Asia/Yekaterinburg'))
assert abs((datetime.now(ZoneInfo('Asia/Yekaterinburg'))-created).total_seconds())<120
status,j,_=call('api/orders.php?id='+str(id),cookie=cookie);assert len(j['items'])==1 and j['items'][0]['quantity']==2
assert j['order']['pickup_store']=='Проспект Победы, 118 строение 2' and [h['status'] for h in j['history']]==['new']
payload={'id':id,'status':'confirmed','previous_status':'new'}
assert call('api/orders.php',payload,cookie)[0]==403
assert call('api/orders.php',payload,cookie,csrf)[0]==200
assert call('api/orders.php',payload,cookie,csrf)[0]==409
status,j,_=call('api/orders.php?status=confirmed',cookie=cookie);assert j['total']==1
assert call('api/orders.php',{'id':id,'status':'processing','previous_status':'confirmed'},cookie,csrf)[0]==200
assert call('api/orders.php?status=processing',cookie=cookie)[1]['total']==1
status,hist,_=call('api/orders.php?id='+str(id),cookie=cookie);assert [h['status'] for h in hist['history']]==['new','confirmed','processing']
print('PASS: stock, price, address, order persistence, deduplication, admin auth, detail, CSRF, status conflict')
# Requests reach the workshop and survive a retry.
service={'name':'Test service','phone':'+79991234567','type':'Диагностика','bike':'Test bike','problem':'Test repair request','request_key':'b'*64}
status,created,_=call('api/service.php',service);assert status==200,(status,created)
assert call('api/service.php',service)[1]==created
assert call('api/service.php')[0]==401
status,j,_=call('api/service.php',cookie=cookie);assert len(j['items'])==1
assert call('api/service.php?action=status',{'id':j['items'][0]['id'],'status':'contacted'},cookie,csrf)[0]==200
# Product edits persist, reject stale edits, and appear in the public catalog.
status,j,_=call('api/product-admin.php?q=Test',cookie=cookie);assert status==200
p=j['items'][0]
p['price_rub']=200;p['old_price_rub']=300;p['stock_qty']=2;p['is_active']=1;p['short_description']='Updated description';p['category_path']='Sport / Balls'
assert call('api/product-admin.php',p,cookie)[0]==403
assert call('api/product-admin.php',p,cookie,csrf)[0]==200
assert call('api/product-admin.php',p,cookie,csrf)[0]==409
status,j,_=call('api/catalog.php?limit=24');assert status==200
p=next(p for p in j['items'] if p['id']==1);assert p['price_rub']==200 and p['description']=='Updated description'
assert call('api/product-admin.php?category=Sport%20%2F%20Balls',cookie=cookie)[1]['total']==1
assert call('api/product-admin.php?category=No%20such%20category',cookie=cookie)[1]['total']==0
print('PASS: workshop persistence, product editing, stale-write protection, public catalog')
# An authenticated buyer sees only their own order history.
status,customer,h=call('api/customer.php?action=register',{'name':'Account','last_name':'Buyer','email':'buyer@example.test','phone':'+79991234567','birth_date':'1990-01-01','password':'test-only-password','consent':True})
assert status==200
customer_cookie=next(c.split(';')[0] for c in reversed(h.get_all('Set-Cookie')) if c.startswith('PROFISPORT_CUSTOMER='))
status,j,_=call('api/order-create.php',{**base,'items':[1],'request_key':'c'*64},customer_cookie);assert status==200
status,j,_=call('api/customer.php?action=me',cookie=customer_cookie);assert len(j['orders'])==1 and j['orders'][0]['total_rub']==200
customer_order_number=j['orders'][0]['order_number'];customer_csrf=customer['csrf']
assert j['orders'][0]['pickup_store']=='Проспект Победы, 118 строение 2'
assert call('api/customer.php?action=repeat_order',{'order_number':customer_order_number},customer_cookie,customer_csrf)[0]==409
admin_order=call('api/orders.php?q='+urllib.parse.quote(customer_order_number),cookie=cookie)[1]['items'][0]
assert call('api/orders.php',{'id':admin_order['id'],'status':'completed','previous_status':'new'},cookie,csrf)[0]==200
account_after_complete=call('api/customer.php?action=me',cookie=customer_cookie)[1]
assert account_after_complete['customer']['bonus_balance']==0
status,detail,_=call('api/customer.php?action=order&number='+urllib.parse.quote(customer_order_number),cookie=customer_cookie);assert status==200
assert detail['order']['pickup_store']=='Проспект Победы, 118 строение 2' and detail['items'][0]['image']=='https://example.test/test-ball.jpg'
assert [h['status'] for h in detail['history']]==['new','completed'] and detail['history_complete'] is True
review_ready=call('api/customer.php?action=me',cookie=customer_cookie)[1]
assert len(review_ready['review_eligible'])==1 and review_ready['review_eligible'][0]['product_id']==1
assert review_ready['review_details']==[] and review_ready['reviews_count']==0
status,repeated,_=call('api/customer.php?action=repeat_order',{'order_number':customer_order_number},customer_cookie,customer_csrf);assert status==200
assert repeated['cart_items']==['1'] and repeated['added_count']==1 and repeated['skipped']==[]
live=call('api/product-admin.php?q=Test',cookie=cookie)[1]['items'];p1=next(x for x in live if x['id']==1)
p1['stock_qty']=0;p1['is_active']=0
assert call('api/product-admin.php',p1,cookie,csrf)[0]==200
status,unavailable,_=call('api/customer.php?action=repeat_order',{'order_number':customer_order_number},customer_cookie,customer_csrf);assert status==200
assert unavailable['added_count']==0 and unavailable['cart_items']==[] and unavailable['skipped'][0]['reason']=='unavailable'
live=call('api/product-admin.php?q=Test',cookie=cookie)[1]['items'];p1=next(x for x in live if x['id']==1)
p1['price_rub']=200;p1['old_price_rub']=300;p1['stock_qty']=2;p1['is_active']=1
assert call('api/product-admin.php',p1,cookie,csrf)[0]==200
assert call('api/customer.php?action=me')[1]['customer'] is None
print('PASS: authenticated checkout, pickup store, photo, exact status history and safe repeat-order preview')
for page in ['photos.php','customers.php','reviews.php','health.php','orders.php','categories.php','stats.php','loyalty.php']:
    with urllib.request.urlopen(BASE+'admin/'+page,timeout=15) as r:
        assert r.geturl().endswith('/admin/login.php'),page
print('PASS: protected admin pages redirect unauthenticated visitors')
stats=call('server/api.php?action=stats',cookie=cookie)[1]
assert stats['customers']==1 and stats['new_service']==0
assert call('api/loyalty-admin.php')[0]==401
status,loyalty_status,_=call('api/loyalty-admin.php',cookie=cookie);assert status==200
assert loyalty_status['program']['enabled'] is False and loyalty_status['program']['configured'] is False and loyalty_status['live_activation_available'] is False
assert loyalty_status['can_edit'] is True and any(x['name']=='Sport' for x in loyalty_status['category_options'])
draft={'earn_enabled':True,'redeem_enabled':True,'expiration_enabled':True,'review_bonus_enabled':True,'category_exclusions_enabled':True,'earn_percent_bp':500,'max_redeem_percent_bp':3000,'point_value_kopeks':100,'expiration_days':365,'min_order_rub':1000,'review_bonus':50,'excluded_category_prefixes':['Sport']}
assert call('api/loyalty-admin.php',{'action':'save_draft','config':draft},cookie)[0]==403
status,saved,_=call('api/loyalty-admin.php',{'action':'save_draft','config':draft},cookie,csrf);assert status==200
assert saved['program']['configured'] is True and saved['program']['enabled'] is False and saved['program']['stored_enabled'] is False
assert saved['program']['config']['earn_enabled'] is True and saved['program']['config']['redeem_enabled'] is True
status,invalid,_=call('api/loyalty-admin.php',{'action':'save_draft','config':{**draft,'earn_percent_bp':999999}},cookie,csrf);assert status==422
status,draft_order,_=call('api/order-create.php',{**base,'items':[1],'request_key':'d'*64},customer_cookie);assert status==200
draft_order_row=call('api/orders.php?q='+urllib.parse.quote(draft_order['order_number']),cookie=cookie)[1]['items'][0]
assert call('api/orders.php',{'id':draft_order_row['id'],'status':'completed','previous_status':'new'},cookie,csrf)[0]==200
draft_account=call('api/customer.php?action=me',cookie=customer_cookie)[1]
assert draft_account['customer']['bonus_balance']==0 and draft_account['loyalty_program']['configured'] is True and draft_account['loyalty_program']['enabled'] is False
for page in ['categories.php','stats.php']:
    req=urllib.request.Request(BASE+'admin/'+page,headers={'Cookie':cookie})
    with urllib.request.urlopen(req,timeout=15) as r: assert r.status==200 and r.geturl().endswith(page)
print('PASS: category filters, live dashboard counts and reports')

# Account workflows and moderation, using only this disposable database.
assert call('api/customer.php?action=register',{'name':'Duplicate','last_name':'Buyer','email':'buyer@example.test','phone':'+79997654321','birth_date':'1991-02-02','password':'test-only-password','consent':True})[0]==409
assert call('api/customer.php?action=login',{'email':'buyer@example.test','password':'wrong-password'})[0]==401
assert call('api/customer.php?action=favorite',{'product_id':1},customer_cookie)[0]==403
assert call('api/customer.php?action=favorite',{'product_id':999999},customer_cookie,customer_csrf)[0]==404
fav_added=call('api/customer.php?action=favorite',{'product_id':1},customer_cookie,customer_csrf)[1];assert fav_added['active'] is True and fav_added['count']==1
favorite_account=call('api/customer.php?action=me',cookie=customer_cookie)[1]
assert favorite_account['favorites']==['1'] and len(favorite_account['favorite_details'])==1
assert favorite_account['favorite_details'][0]['available'] is True and favorite_account['favorite_details'][0]['price_rub']==200 and favorite_account['favorite_details'][0]['image']=='https://example.test/test-ball.jpg'
live=call('api/product-admin.php?q=Test',cookie=cookie)[1]['items'];p1=next(x for x in live if x['id']==1)
p1['stock_qty']=0;p1['is_active']=0
assert call('api/product-admin.php',p1,cookie,csrf)[0]==200
favorite_account=call('api/customer.php?action=me',cookie=customer_cookie)[1]
assert favorite_account['favorites']==['1'] and favorite_account['favorite_details'][0]['available'] is False
removed=call('api/customer.php?action=favorite_remove',{'product_id':1},customer_cookie,customer_csrf)[1]
assert removed['active'] is False and removed['removed'] is True and removed['count']==0
removed_again=call('api/customer.php?action=favorite_remove',{'product_id':1},customer_cookie,customer_csrf)[1]
assert removed_again['active'] is False and removed_again['removed'] is False and removed_again['count']==0
merged=call('api/customer.php?action=favorites_merge',{'product_ids':[1]},customer_cookie,customer_csrf)[1]
assert merged['merged']==1
favorite_account=call('api/customer.php?action=me',cookie=customer_cookie)[1]
assert favorite_account['favorites']==['1'] and favorite_account['favorite_details'][0]['available'] is False
live=call('api/product-admin.php?q=Test',cookie=cookie)[1]['items'];p1=next(x for x in live if x['id']==1)
p1['price_rub']=200;p1['old_price_rub']=300;p1['stock_qty']=2;p1['is_active']=1
assert call('api/product-admin.php',p1,cookie,csrf)[0]==200
review={'product_id':1,'rating':5,'text':'Useful test review for moderation'}
assert call('api/customer.php?action=review_submit',review,customer_cookie)[0]==403
status,not_eligible,_=call('api/customer.php?action=review_submit',{**review,'product_id':2},customer_cookie,customer_csrf);assert (status,not_eligible['error'])==(403,'review_not_eligible')
assert call('api/customer.php?action=review_submit',{**review,'text':'x'*5001},customer_cookie,customer_csrf)[0]==422
status,submitted,_=call('api/customer.php?action=review_submit',review,customer_cookie,customer_csrf);assert status==200 and submitted['resubmitted'] is False
review_id=submitted['review_id']
status,duplicate,_=call('api/customer.php?action=review_submit',review,customer_cookie,customer_csrf);assert (status,duplicate['error'])==(409,'duplicate_review')
account=call('api/customer.php?action=me',cookie=customer_cookie)[1]
assert account['reviews_count']==1 and account['review_eligible']==[]
assert len(account['review_details'])==1 and account['review_details'][0]['status']=='pending' and account['review_details'][0]['verified_purchase'] is True
assert call('api/customer.php?action=reviews&product_id=1')[1]['count']==0
pending=call('api/review-moderation.php',cookie=cookie)[1]['items'];assert len(pending)==1 and pending[0]['id']==review_id
rejection={'review_id':review_id,'action':'reject','bonus':0}
assert call('api/review-moderation.php',rejection,cookie)[0]==403
assert call('api/review-moderation.php',rejection,cookie,csrf)[0]==200
account=call('api/customer.php?action=me',cookie=customer_cookie)[1]
assert account['review_details'][0]['status']=='rejected'
fixed={**review,'rating':4,'text':'Updated verified purchase review after moderation'}
status,resubmitted,_=call('api/customer.php?action=review_submit',fixed,customer_cookie,customer_csrf);assert status==200 and resubmitted['resubmitted'] is True and resubmitted['review_id']==review_id
pending=call('api/review-moderation.php',cookie=cookie)[1]['items'];assert len(pending)==1 and pending[0]['id']==review_id and pending[0]['rating']==4
approval={'review_id':review_id,'action':'approve','bonus':50}
status,approved,_=call('api/review-moderation.php',approval,cookie,csrf);assert status==200
assert approved['loyalty']['awarded']==0 and approved['loyalty']['reason']=='program_disabled'
assert call('api/review-moderation.php',approval,cookie,csrf)[0]==200
status,approved_duplicate,_=call('api/customer.php?action=review_submit',fixed,customer_cookie,customer_csrf);assert (status,approved_duplicate['error'])==(409,'duplicate_review')
public_review=call('api/customer.php?action=reviews&product_id=1')[1]
assert public_review['count']==1 and public_review['items'][0]['verified_purchase'] is True
account=call('api/customer.php?action=me',cookie=customer_cookie)[1]
assert account['review_details'][0]['status']=='approved' and account['review_details'][0]['rating']==4
assert account['customer']['bonus_balance']==0 and len(account['loyalty'])==0
assert account['loyalty_program']['enabled'] is False and account['loyalty_program']['configured'] is True
manual={'customer_id':account['customer']['id'],'amount':40,'note':'Test ledger adjustment'}
assert call('api/customer-admin.php',manual,cookie)[0]==403
status,adjusted,_=call('api/customer-admin.php',manual,cookie,csrf);assert status==200 and adjusted['bonus_balance']==40 and adjusted['actual_amount']==40
account=call('api/customer.php?action=me',cookie=customer_cookie)[1]
assert account['customer']['bonus_balance']==40 and len(account['loyalty'])==1 and account['loyalty'][0]['kind']=='manual'
status,adjusted,_=call('api/customer-admin.php',{**manual,'amount':-40,'note':'Reset test ledger'},cookie,csrf);assert status==200 and adjusted['bonus_balance']==0
account=call('api/customer.php?action=me',cookie=customer_cookie)[1]
assert account['customer']['bonus_balance']==0 and len(account['loyalty'])==2
profile={'name':'Updated','last_name':'Buyer','email':'buyer@example.test','phone':'+79991112233','birth_date':'1990-02-03','preferred_store':'Проспект Победы, 79','current_password':''}
status,updated,_=call('api/customer.php?action=profile_update',profile,customer_cookie,customer_csrf);assert status==200
assert updated['customer']['name']=='Updated' and updated['customer']['phone']=='+79991112233' and updated['customer']['birth_date']=='1990-02-03' and updated['customer']['preferred_store']=='Проспект Победы, 79'
email_change={**profile,'email':'updated-buyer@example.test'}
status,j,_=call('api/customer.php?action=profile_update',email_change,customer_cookie,customer_csrf);assert (status,j['error'])==(403,'password_required')
status,j,_=call('api/customer.php?action=profile_update',{**email_change,'current_password':'wrong-password'},customer_cookie,customer_csrf);assert (status,j['error'])==(403,'invalid_password')
status,updated,_=call('api/customer.php?action=profile_update',{**email_change,'current_password':'test-only-password'},customer_cookie,customer_csrf);assert status==200
assert updated['customer']['email']=='updated-buyer@example.test' and updated['customer']['preferred_store']=='Проспект Победы, 79'
status,j,_=call('api/customer.php?action=profile_update',{**email_change,'preferred_store':'Неизвестный магазин','current_password':''},customer_cookie,customer_csrf);assert (status,j['error'])==(422,'invalid_input')
assert call('api/customer.php?action=logout',{},customer_cookie,customer_csrf)[0]==200
assert call('api/customer.php?action=me',cookie=customer_cookie)[1]['customer'] is None
assert call('api/customer.php?action=login',{'email':'buyer@example.test','password':'test-only-password'})[0]==401
status,logged,h=call('api/customer.php?action=login',{'email':'updated-buyer@example.test','password':'test-only-password'})
assert status==200 and logged['customer']['bonus_balance']==0 and logged['customer']['preferred_store']=='Проспект Победы, 79'
print('PASS: account, reviews, disabled automatic loyalty, ledger corrections, profile editing and email change')

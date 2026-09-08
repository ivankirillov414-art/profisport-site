import json, urllib.request, urllib.error
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
base={'name':'Test buyer','phone':'+79991234567','items':[1,1],'request_key':'a'*64}
status,j,_=call('api/order-create.php',{**base,'items':[1,1,1]});assert (status,j['error'])==(409,'insufficient_stock')
status,j,_=call('api/order-create.php',{**base,'items':[2]});assert (status,j['error'])==(409,'price_unavailable')
status,j,_=call('api/order-create.php',{**base,'delivery':'orenburg_delivery'});assert (status,j['error'])==(422,'address_required')
status,order,_=call('api/order-create.php',base);assert status==200 and order['total_rub']==300,(status,order)
status,replay,_=call('api/order-create.php',base);assert replay==order
status,j,_=call('api/order-create.php',{**base,'name':'Changed buyer'});assert (status,j['error'])==(409,'request_conflict')
assert call('api/orders.php')[0]==401
status,auth,h=call('server/api.php?action=login',{'username':'Иван Кириллов 414','password':'test-only-password'});assert status==200
cookies=h.get_all('Set-Cookie');cookie=next(c.split(';')[0] for c in reversed(cookies) if c.startswith('PROFISPORT_ADMIN='));csrf=auth['csrf']
status,j,_=call('api/orders.php',cookie=cookie);assert status==200 and j['total']==1
id=j['items'][0]['id']
status,j,_=call('api/orders.php?id='+str(id),cookie=cookie);assert len(j['items'])==1 and j['items'][0]['quantity']==2
payload={'id':id,'status':'confirmed','previous_status':'new'}
assert call('api/orders.php',payload,cookie)[0]==403
assert call('api/orders.php',payload,cookie,csrf)[0]==200
assert call('api/orders.php',payload,cookie,csrf)[0]==409
status,j,_=call('api/orders.php?status=confirmed',cookie=cookie);assert j['total']==1
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
print('PASS: workshop persistence, product editing, stale-write protection, public catalog')
# An authenticated buyer sees only their own order history.
status,customer,h=call('api/customer.php?action=register',{'name':'Account buyer','email':'buyer@example.test','phone':'+79991234567','password':'test-only-password'})
assert status==200
customer_cookie=next(c.split(';')[0] for c in reversed(h.get_all('Set-Cookie')) if c.startswith('PROFISPORT_CUSTOMER='))
status,j,_=call('api/order-create.php',{**base,'items':[1],'request_key':'c'*64},customer_cookie);assert status==200
status,j,_=call('api/customer.php?action=me',cookie=customer_cookie);assert len(j['orders'])==1 and j['orders'][0]['total_rub']==200
assert call('api/customer.php?action=me')[1]['customer'] is None
print('PASS: authenticated checkout and private order history')

import json, urllib.request, urllib.error, secrets, subprocess
BASE='http://127.0.0.1:8080/'
fixture=json.loads(subprocess.check_output(['php','tests/loyalty-tiers-fixture.php']))
def call(path,data=None,cookie=None,csrf=None):
    headers={'Content-Type':'application/json'}
    if cookie: headers['Cookie']=cookie
    if csrf: headers['X-CSRF-Token']=csrf
    req=urllib.request.Request(BASE+path,data=json.dumps(data).encode() if data is not None else None,headers=headers)
    try: r=urllib.request.urlopen(req,timeout=15)
    except urllib.error.HTTPError as e: r=e
    return r.status,json.loads(r.read()),r.headers
status,admin,h=call('server/api.php?action=login',{'username':'Иван Кириллов 414','password':'test-only-password'})
assert status==200
ac=next(c.split(';')[0] for c in reversed(h.get_all('Set-Cookie')) if c.startswith('PROFISPORT_ADMIN='))
status,customer,h=call('api/customer.php?action=login',{'login':'tier-http@example.test','password':'test-only-tier-password'})
assert status==200
cc=next(c.split(';')[0] for c in reversed(h.get_all('Set-Cookie')) if c.startswith('PROFISPORT_CUSTOMER='))
def tier(): return call('api/customer.php?action=me',cookie=cc)[1]['loyalty_tier']['percent_bp']
def order(items):
    status,r,_=call('api/order-create.php',{'name':'Tier HTTP','phone':'+79990006666','items':items,'request_key':secrets.token_hex(32)},cc)
    assert status==200,(status,r)
    listed=call('api/orders.php?q='+r['order_number'],cookie=ac)[1]['items']
    return listed[0]['id'],r

def paid(id):
    s,r,_=call('api/orders.php',{'id':id,'action':'confirm_payment'},ac,admin['csrf'])
    assert s==200,(s,r)
    return r['loyalty_tier']['percent_bp']
first,r=order([88001]);assert r['discount_rub']==0 and tier()==0
assert call('api/orders.php',{'id':first,'action':'confirm_payment'},cc,customer['csrf'])[0]==401
assert call('api/orders.php',{'id':first,'action':'confirm_payment'},ac)[0]==403
assert paid(first)==500 and paid(first)==500
second,r=order([88002,88004]);assert r['discount_rub']==1500 and r['total_rub']==88500,(r)
assert paid(second)==700
third,r=order([88003]);assert r['discount_rub']==3500 and paid(third)==1000
fourth,r=order([88004]);assert r['discount_rub']==0 and paid(fourth)==1000
info=call('api/orders.php?id='+str(third),cookie=ac)[1]
item=int(info['items'][0]['id'])
returns={'id':third,'action':'return_items','items':[{'id':item,'quantity':1,'previous_quantity':0}]}
assert call('api/orders.php',returns,ac,admin['csrf'])[0]==200 and tier()==700
assert call('api/orders.php',returns,ac,admin['csrf'])[0]==422
for id,want in [(second,500),(first,0)]:
    assert call('api/orders.php',{'id':id,'status':'cancelled','previous_status':'new'},ac,admin['csrf'])[0]==200
    assert tier()==want
print('PASS: real checkout pricing, promotion snapshots, payment auth/CSRF, single-level upgrades, repeated payment, returns and cancellation')

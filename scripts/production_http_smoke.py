#!/usr/bin/env python3
import hashlib
import http.cookiejar
import json
import os
import secrets
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE=os.environ.get('PROFISPORT_BASE','https://ferryffggg.infinityfreeapp.com').rstrip('/')
TOKEN=os.environ.get('PROFISPORT_IMPORT_TOKEN','')
if not TOKEN:
    raise SystemExit('PROFISPORT_IMPORT_TOKEN is required')

slug=secrets.token_hex(6)
email=f'smoke-http-{slug}@example.invalid'
phone='+7999'+str(secrets.randbelow(10_000_000)).zfill(7)
password='Smoke-'+secrets.token_hex(8)+'-A1!'
jar=http.cookiejar.CookieJar()
opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
headers={'User-Agent':'ProfiSport-Production-HTTP-Smoke/1.0'}

def request(path, method='GET', payload=None, extra=None, opener_obj=None):
    h=dict(headers)
    if extra: h.update(extra)
    data=None
    if payload is not None:
        data=json.dumps(payload,ensure_ascii=False).encode()
        h['Content-Type']='application/json'
    req=urllib.request.Request(BASE+path,data=data,headers=h,method=method)
    client=opener_obj or opener
    try:
        with client.open(req,timeout=40) as response:
            raw=response.read()
    except urllib.error.HTTPError as e:
        raw=e.read()
        raise RuntimeError(f'HTTP {e.code} {path}: {raw[:500]!r}') from e
    try:
        return json.loads(raw)
    except Exception as e:
        raise RuntimeError(f'Invalid JSON from {path}: {raw[:500]!r}') from e

def helper(action,payload=None):
    return request('/api/live-flow-admin.php?action='+urllib.parse.quote(action),method='POST',payload=payload or {},extra={'X-Import-Token':TOKEN},opener_obj=urllib.request.build_opener())

order_number=None
try:
    product=helper('product')['product']
    pid=int(product['id'])
    register=request('/api/customer.php?action=register','POST',{
        'name':'Smoke','last_name':'HTTP','email':email,'phone':phone,
        'birth_date':'1990-01-01','password':password,'consent':True
    })
    assert register.get('ok') and register.get('customer',{}).get('email')==email
    me=request('/api/customer.php?action=me')
    csrf=me.get('csrf','')
    assert me.get('customer',{}).get('email')==email and csrf

    fav=request('/api/customer.php?action=favorite','POST',{'product_id':pid},{'X-CSRF-Token':csrf})
    assert fav.get('ok') and fav.get('active') is True

    req_key=hashlib.sha256(('profisport-http-smoke|'+slug).encode()).hexdigest()
    order=request('/api/order-create.php','POST',{
        'name':'Smoke HTTP','phone':phone,'email':email,'delivery':'pickup',
        'address':'','comment':'AUTOMATED_PRODUCTION_HTTP_SMOKE',
        'items':[str(pid)],'request_key':req_key
    })
    assert order.get('ok') and order.get('order_number')
    order_number=str(order['order_number'])

    for status in ('confirmed','processing','ready','completed'):
        changed=helper('admin_status',{'email':email,'order_number':order_number,'status':status})
        assert changed.get('ok') and changed.get('to')==status

    logout=request('/api/customer.php?action=logout','POST',{}, {'X-CSRF-Token':csrf})
    assert logout.get('ok')
    login=request('/api/customer.php?action=login','POST',{'email':email,'password':password})
    assert login.get('ok') and login.get('customer',{}).get('email')==email

    profile=request('/api/customer.php?action=me')
    orders=profile.get('orders') or []
    row=next((x for x in orders if x.get('order_number')==order_number),None)
    assert row and row.get('status')=='completed'
    assert str(pid) in set(map(str,profile.get('favorites') or []))

    detail=request('/api/customer.php?action=order&number='+urllib.parse.quote(order_number))
    assert detail.get('ok') and detail.get('order',{}).get('status')=='completed'
    assert any(int(x.get('product_id') or 0)==pid for x in detail.get('items') or [])
    print(json.dumps({
        'ok':True,
        'registration':True,
        'login':True,
        'favorite':True,
        'checkout':True,
        'status_cycle':True,
        'profile_history':True,
        'order_detail':True,
        'cleanup_pending':True
    },ensure_ascii=False))
finally:
    try:
        cleaned=helper('cleanup',{'email':email})
        if not cleaned.get('ok'):
            print('cleanup failed',file=sys.stderr)
    except Exception as e:
        print('cleanup exception: '+str(e),file=sys.stderr)

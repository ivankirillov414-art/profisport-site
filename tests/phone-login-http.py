import json, urllib.request, urllib.error, secrets
BASE='http://127.0.0.1:8080/'
PASSWORD='T9!'+secrets.token_hex(12)

def call(path,data=None,cookie=None,csrf=None):
    headers={'Content-Type':'application/json'}
    if cookie: headers['Cookie']=cookie
    if csrf: headers['X-CSRF-Token']=csrf
    req=urllib.request.Request(BASE+path,data=json.dumps(data).encode() if data is not None else None,headers=headers)
    try: r=urllib.request.urlopen(req,timeout=15)
    except urllib.error.HTTPError as e: r=e
    raw=r.read()
    return r.status,json.loads(raw),r.headers

registration={'name':'Phone','last_name':'Login','email':'phone-login@example.test','phone':'+79998887766','birth_date':'1992-03-04','password':PASSWORD,'consent':True}
status,created,h=call('api/customer.php?action=register',registration)
assert status==200,(status,created)
cookie=next(c.split(';')[0] for c in reversed(h.get_all('Set-Cookie')) if c.startswith('PROFISPORT_CUSTOMER='))
assert call('api/customer.php?action=logout',{},cookie,created['csrf'])[0]==200

status,phone_login,h=call('api/customer.php?action=login',{'login':'+7 (999) 888-77-66','password':PASSWORD})
assert status==200,(status,phone_login)
assert phone_login['customer']['email']=='phone-login@example.test' and phone_login['customer']['phone']=='+79998887766'
phone_cookie=next(c.split(';')[0] for c in reversed(h.get_all('Set-Cookie')) if c.startswith('PROFISPORT_CUSTOMER='))
assert call('api/customer.php?action=logout',{},phone_cookie,phone_login['csrf'])[0]==200

status,email_login,_=call('api/customer.php?action=login',{'email':'phone-login@example.test','password':PASSWORD})
assert status==200 and email_login['customer']['phone']=='+79998887766'
print('PASS: customer login accepts normalized phone and remains compatible with legacy email payload')

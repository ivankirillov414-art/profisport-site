"""Run actual PHP HTTP/session/CSRF/CRUD tests in an isolated SQLite TEST application.

No source config, module-owned paths, live data, Supabase or hosting are modified.
Run with --php PATH. Optional DLLs are loaded only for the child PHP processes.
"""
from __future__ import annotations
import argparse, base64, http.cookiejar, json, os, pathlib, re, secrets, shutil, socket, sqlite3, subprocess, tempfile, time, urllib.error, urllib.parse, urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--php', required=True)
args = parser.parse_args()
php = pathlib.Path(args.php).resolve()
flags = ['-d', f'extension_dir={php.parent / "ext"}', '-d', 'extension=pdo_sqlite', '-d', 'extension=pdo_mysql']
checks = 0

def check(condition, label):
    global checks
    if not condition: raise AssertionError(label)
    checks += 1

class Client:
    def __init__(self, base):
        self.base, self.csrf = base, ''
        self.jar = http.cookiejar.CookieJar()
        self.open = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
    def request(self, route, method='GET', body=None, expected=200, token=True, origin=None, setup=False, form=False, query=None):
        url = self.base + ('setup.php' if setup else 'api/index.php?r=' + urllib.parse.quote(route, safe='/'))
        if query: url += ('&' if '?' in url else '?') + urllib.parse.urlencode(query)
        headers = {'Accept': 'application/json'}
        raw = None
        if body is not None:
            raw = urllib.parse.urlencode(body).encode() if form else json.dumps(body).encode()
            headers['Content-Type'] = 'application/x-www-form-urlencoded' if form else 'application/json'
        if token and self.csrf: headers['X-CSRF-Token'] = self.csrf
        if origin is not None: headers['Origin'] = origin
        req = urllib.request.Request(url, data=raw, headers=headers, method=method)
        try: response = self.open.open(req, timeout=15)
        except urllib.error.HTTPError as error: response = error
        data = response.read()
        check(response.status == expected, f'{method} {route or "setup"}: expected {expected}, got {response.status}: {data[:400]!r}')
        if setup and method == 'GET':
            check('text/html' in response.headers.get('Content-Type',''), 'setup is a protected-key HTML form')
            return {'html':data.decode('utf-8')}, response.headers
        try: result = json.loads(data)
        except Exception: raise AssertionError(f'{route}: non-JSON response {data[:300]!r}')
        check(result.get('ok') is (expected < 400), f'{route}: envelope')
        if expected >= 400:
            check(isinstance(result.get('error', {}).get('code'), str), 'error code')
            check(isinstance(result['error'].get('message'), str), 'error message')
        else:
            value = result['data']
            if isinstance(value, dict) and 'csrf' in value: self.csrf = value['csrf']
        check(response.headers.get('Access-Control-Allow-Origin') is None, 'no wildcard CORS')
        return result.get('data') if expected < 400 else result['error'], response.headers
    def login(self, email, password):
        self.request('auth/me')
        return self.request('auth/login', 'POST', {'email': email, 'password': password})[0]

with tempfile.TemporaryDirectory(prefix='kosmoport-core-test-') as temp:
    root = pathlib.Path(temp) / 'app'
    for directory in ['app/core', 'public/api', 'tests', 'migrations', 'config', 'private', 'app/modules']:
        (root / directory).mkdir(parents=True, exist_ok=True)
    for path in [*ROOT.glob('app/core/*.php'), ROOT/'app/bootstrap.php', ROOT/'public/api/index.php', ROOT/'public/setup.php', ROOT/'tests/core-fixture.sql', ROOT/'tests/core-router.php', ROOT/'tests/core-unit.php', *ROOT.glob('migrations/001*.sql'), *ROOT.glob('migrations/010*.sql')]:
        shutil.copy2(path, root / path.relative_to(ROOT))
    (root/'app/modules/probe.php').write_text('''<?php
return [
 'GET fixture/ping' => static function(): array { return ['user'=>crm_require_roles(['reader','manager','cashier','editor','document_manager']),'base_path'=>crm_config('base_path')]; },
 'GET fixture/path' => static function(): array { crm_require_roles([]); return ['name'=>basename(crm_storage_path($_GET['value']??''))]; },
 'POST fixture/audit' => static function(): array { crm_require_roles(['manager']); crm_audit('fixture.audit','fixture',null,crm_input()); return ['written'=>true]; },
 'GET fixture/error' => static function(): array { throw new RuntimeException('INTERNAL_SECRET_NOT_FOR_BROWSER'); },
];
''', encoding='utf-8')
    setup_key, password = secrets.token_urlsafe(36), secrets.token_urlsafe(18)
    config = {'environment':'test','base_path':'/isolated-event','timezone':'Asia/Yekaterinburg','storage_path':str(root/'private/storage'),'app_key':secrets.token_hex(32),'setup_key_hash':__import__('hashlib').sha256(setup_key.encode()).hexdigest(),'database':{'driver':'sqlite_test','path':str(root/'private/fixture.sqlite'),'allow_test_driver':True}}
    config_path = root/'private/config.php'
    def config_write(value, path=config_path):
        encoded = base64.b64encode(json.dumps(value).encode()).decode()
        path.write_text("<?php return json_decode(base64_decode('"+encoded+"'),true);", encoding='utf-8')
    config_write(config)
    env = {**os.environ, 'KCRM_CONFIG':str(config_path)}
    creation = subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0
    unit = subprocess.run([str(php),*flags,str(root/'tests/core-unit.php')],env=env,capture_output=True,text=True,timeout=30,creationflags=creation)
    check(unit.returncode == 0, 'unit helpers: '+unit.stderr[:400]); unit_result = json.loads(unit.stdout)
    check(unit_result['ok'], 'unit success')
    forbidden = {**config, 'environment':'production'}; config_write(forbidden, root/'private/forbidden.php')
    probe = subprocess.run([str(php),*flags,'-r',"require '"+str(root/'app/bootstrap.php').replace('\\','/')+"'; try { crm_db(); exit(2); } catch (CrmHttpException $e) { echo $e->errorCode; }"],env={**env,'KCRM_CONFIG':str(root/'private/forbidden.php')},capture_output=True,text=True,timeout=15,creationflags=creation)
    check(probe.returncode == 0 and probe.stdout == 'TEST_DRIVER_FORBIDDEN', 'production rejects SQLite')
    with socket.socket() as sock: sock.bind(('127.0.0.1',0)); port = sock.getsockname()[1]
    log_path = root/'private/php-test.log'
    with log_path.open('wb') as log:
        server = subprocess.Popen([str(php),*flags,'-d','display_errors=0','-S',f'127.0.0.1:{port}','-t',str(root/'public'),str(root/'tests/core-router.php')],env=env,stdout=log,stderr=log,creationflags=creation)
        try:
            for _ in range(150):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.1): break
                except OSError: time.sleep(.1)
            else: raise AssertionError('PHP HTTP server did not start')
            base = f'http://127.0.0.1:{port}/isolated-event/'
            owner = Client(base); anonymous = Client(base)
            check(owner.request('health')[0]['ready'] is False, 'fresh database not ready')
            me, headers = owner.request('auth/me'); check(me['user'] is None and len(me['csrf'])==64, 'anonymous CSRF bootstrap')
            check('HttpOnly' in headers.get('Set-Cookie','') and 'SameSite=Lax' in headers.get('Set-Cookie','') and 'path=/isolated-event/' in headers.get('Set-Cookie',''), 'scoped secure session cookie attributes')
            anonymous.request('fixture/ping',expected=401)
            owner.request('auth/login','POST',{'email':'owner@example.invalid','password':password},expected=403,token=False)
            owner.request('auth/login','POST',{'email':'owner@example.invalid','password':password},expected=503)
            owner.request('',setup=True,query={'setup_key':setup_key})
            check(owner.request('health')[0]['ready'] is False, 'URL key never installs owner')
            owner.request('', 'POST', {'name':'Владелец','email':'owner@example.invalid','password':password,'setup_key':'x'*40},setup=True,expected=403)
            installed,_=owner.request('', 'POST', {'name':'Владелец','email':'owner@example.invalid','password':password,'setup_key':setup_key},setup=True,expected=201)
            owner_id=installed['user']['id']; check(installed['user']['role']=='owner','owner role fixed by server')
            check(owner.request('health')[0]['ready'] is True,'installed ready')
            owner.request('', 'POST', {'name':'Другой','email':'other@example.invalid','password':password,'setup_key':setup_key},setup=True,expected=409)
            old_csrf=owner.csrf; before_cookie=list(owner.jar)[0].value
            login=owner.login('owner@example.invalid',password)
            check(login['user']['id']==owner_id and login['csrf']!=old_csrf and list(owner.jar)[0].value!=before_cookie,'login rotates CSRF and session ID')
            check(owner.request('fixture/ping')[0]['base_path']=='/isolated-event','module route uses configured subdirectory')
            for route in ['partners','tasks','users','dashboard','audit','contacts']:
                anonymous.request(route,expected=401)
            partner,_=owner.request('partners','POST',{'name':'Тестовый партнёр','contact_name':'Контакт','phone':'+79990000000','email':'partner@example.invalid','status':'contacted','notes':'Запись только в fixture'})
            updated,_=owner.request('partners','POST',{'id':partner['id'],'expected_revision':1,'status':'agreed'})
            check(updated['phone']==partner['phone'] and updated['revision']==2,'partial edit preserves omitted contact fields')
            owner.request('partners','POST',{'id':partner['id'],'expected_revision':1,'name':'Stale'},expected=409)
            owner.request('partners','POST',{'id':partner['id'],'name':'No revision'},expected=422)
            check(owner.request('partners',query={'q':"%' OR 1=1--"})[0]['total']==0,'search input remains bound data')
            owner.request('partners',query={'limit':'1 OR 1=1'},expected=422)
            owner.request('partners','POST',['invalid object'],expected=422)
            owner.request('partners','POST',{'name':'Too large','notes':'x'*(8*1024*1024)},expected=413)
            contact,_=owner.request('contacts','POST',{'partner_id':partner['id'],'name':'Сотрудник партнёра','role_title':'Руководитель'})
            check(owner.request('contacts',query={'partner_id':partner['id']})[0]['items'][0]['id']==contact['id'],'contact linked to partner')
            owner.request('contacts','POST',{'partner_id':'missing','name':'Invalid'},expected=404)
            clients={}; users={}
            for role in ['admin','manager','cashier','editor','document_manager','reader']:
                user,_=owner.request('users','POST',{'name':role,'email':role+'@example.invalid','role':role,'password':password})
                check('password_hash' not in user and 'session_version' not in user,'user response hides credentials')
                users[role]=user; clients[role]=Client(base); clients[role].login(role+'@example.invalid',password)
            owner.request('users','POST',{'name':'weak','email':'weak@example.invalid','role':'reader','password':'short'},expected=422)
            owner.request('users','POST',{'name':'duplicate','email':'reader@example.invalid','role':'reader','password':password},expected=409)
            owner.request('users','POST',{'id':owner_id,'expected_revision':1,'active':False},expected=409)
            clients['admin'].request('users','POST',{'name':'Escalation','email':'evil@example.invalid','role':'owner','password':password},expected=403)
            clients['admin'].request('users','POST',{'id':owner_id,'expected_revision':1,'role':'admin'},expected=403)
            for role in ['cashier','editor','document_manager','reader']:
                clients[role].request('partners','POST',{'name':'Forbidden'},expected=403)
                clients[role].request('audit',expected=403)
                listed=clients[role].request('users')[0]['items'];check(all('email' not in item and 'password_hash' not in item for item in listed),'assignment list minimises identity data')
            task,_=owner.request('tasks','POST',{'title':'Связаться с партнёром','partner_id':partner['id'],'assignee_id':users['manager']['id'],'due_date':'2026-10-31','description':'Сохранить описание'})
            saved,_=clients['manager'].request('tasks','POST',{'id':task['id'],'expected_revision':1,'status':'done'})
            check(saved['completed_at'].endswith('Z') and saved['description']==task['description'],'task completion uses UTC and retains details')
            cleared,_=owner.request('tasks','POST',{'id':task['id'],'expected_revision':2,'due_date':None,'assignee_id':None})
            check(cleared['due_date'] is None and cleared['assignee_id'] is None,'explicit null clears nullable task fields')
            clients['reader'].request('tasks','POST',{'title':'Forbidden'},expected=403)
            clients['editor'].request('tasks','POST',{'title':'Assign other','assignee_id':users['reader']['id']},expected=403)
            mine,_=clients['editor'].request('tasks','POST',{'title':'Подготовить материал','assignee_id':users['editor']['id']})
            clients['editor'].request('tasks','POST',{'id':task['id'],'expected_revision':3,'status':'todo'},expected=403)
            mine2,_=clients['editor'].request('tasks','POST',{'id':mine['id'],'expected_revision':1,'status':'in_progress'})
            check(mine2['status']=='in_progress','editor manages own task')
            dashboard=owner.request('dashboard')[0]
            check(dashboard['counts']['partners']==1 and dashboard['counts']['open_tasks']==1 and dashboard['counts']['documents'] is None,'dashboard reports real counts/unavailable modules')
            owner.request('fixture/audit','POST',{'password':'REDACT_ME','nested':{'token':'REDACT_TOKEN'},'message':'safe'})
            audit=owner.request('audit',query={'action':'fixture.audit'})[0]['items'][0]
            check(audit['actor_id']==owner_id and audit['details']['password']=='[redacted]' and audit['details']['nested']['token']=='[redacted]' and audit['details']['message']=='safe','audit actor and nested credential redaction')
            for value in ['../secret','a/../secret','/etc/passwd','C:\\private','a\\..\\b']:
                owner.request('fixture/path',query={'value':value},expected=422)
            check(owner.request('fixture/path',query={'value':'documents/version.bin'})[0]['name']=='version.bin','normal private path')
            # Fresh child process: do not assume host OPcache reloads a changed
            # private configuration immediately between HTTP requests.
            unsafe={**config,'storage_path':str(root/'public/private-storage')};config_write(unsafe,root/'private/unsafe.php')
            storage_probe=subprocess.run([str(php),*flags,'-r',"require '"+str(root/'app/bootstrap.php').replace('\\','/')+"'; try { crm_storage_path(); exit(2); } catch (CrmHttpException $e) { echo $e->errorCode; }"],env={**env,'KCRM_CONFIG':str(root/'private/unsafe.php')},capture_output=True,text=True,timeout=15,creationflags=creation)
            check(storage_probe.returncode==0 and storage_probe.stdout=='UNSAFE_STORAGE','webroot storage rejected in fresh configuration')
            error,_=owner.request('fixture/error',expected=500);check('INTERNAL_SECRET' not in error['message'],'exceptions do not leak browser details')
            owner.request('partners','POST',{'name':'Cross origin'},origin='https://evil.invalid',expected=403)
            owner.request('unknown-route',expected=404)
            owner.request('partners','DELETE',{},expected=405)
            owner.request('auth/register','POST',{},expected=404)
            reader=clients['reader']; owner.request('users','POST',{'id':users['reader']['id'],'expected_revision':1,'active':False})
            check(reader.request('auth/me')[0]['user'] is None,'deactivation invalidates existing session immediately')
            stale=clients['cashier'].csrf;clients['cashier'].request('auth/logout','POST',{})
            clients['cashier'].csrf=stale;clients['cashier'].request('auth/login','POST',{'email':'cashier@example.invalid','password':password},expected=403)
            wrong=Client(base);wrong.request('auth/me')
            for _ in range(8): wrong.request('auth/login','POST',{'email':'missing@example.invalid','password':'not-the-password'},expected=401)
            wrong.request('auth/login','POST',{'email':'missing@example.invalid','password':'not-the-password'},expected=429)
            db=sqlite3.connect(config['database']['path']);db.execute('UPDATE kcrm_auth_rate_limits SET window_started_at=?,blocked_until=NULL',('2000-01-01 00:00:00',));db.commit()
            wrong.request('auth/login','POST',{'email':'missing@example.invalid','password':'not-the-password'},expected=401)
            owner.login('owner@example.invalid',password)
            sid=list(owner.jar)[0].value;session_file=root/'private/storage/sessions'/('sess_'+sid)
            state=session_file.read_text();state=re.sub(r'last_seen\|i:\d+;', 'last_seen|i:1;',state);session_file.write_text(state)
            check(owner.request('auth/me')[0]['user'] is None,'idle session expiry')
            check(db.execute('SELECT COUNT(*) FROM kcrm_users WHERE role="owner"').fetchone()[0]==1,'setup and role attacks never create a second owner')
            check(db.execute('SELECT COUNT(*) FROM kcrm_migrations').fetchone()[0]==0,'SQLite does not claim production migrations applied')
            config_write({**config,'environment':'production'},root/'private/production.php')
            https_probe=subprocess.run([str(php),*flags,'-r',"require '"+str(root/'app/bootstrap.php').replace('\\','/')+"'; $_GET['r']='auth/me'; $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['HTTPS']='off'; try { crm_dispatch(); exit(2); } catch (CrmHttpException $e) { echo $e->errorCode; }"],env={**env,'KCRM_CONFIG':str(root/'private/production.php')},capture_output=True,text=True,timeout=15,creationflags=creation)
            check(https_probe.returncode==0 and https_probe.stdout=='HTTPS_REQUIRED','production staff API refuses cleartext HTTP')
            db.close()
            print(json.dumps({'ok':True,'http_checks':checks,'helper_checks':unit_result['checks'],'driver':'sqlite_test','production_mysql_runtime_verified':False,'fixture_disposable':True}))
        finally:
            server.terminate()
            try: server.wait(timeout=5)
            except subprocess.TimeoutExpired: server.kill();server.wait(timeout=5)

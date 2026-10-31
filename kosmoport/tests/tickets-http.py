"""Real PHP HTTP/session/CSRF tests, disposable SQLite TEST database only."""
import argparse,base64,hashlib,http.cookiejar,json,os,pathlib,re,secrets,shutil,socket,sqlite3,subprocess,tempfile,time,urllib.request,urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[1]
p=argparse.ArgumentParser();p.add_argument('--php',required=True);args=p.parse_args();php=pathlib.Path(args.php)
flags=['-d',f'extension_dir={php.parent/"ext"}','-d','extension=pdo_sqlite'];creation=getattr(subprocess,'CREATE_NO_WINDOW',0)
checks=0
(ROOT/'tests/tickets-runtime').mkdir(exist_ok=True)
with tempfile.TemporaryDirectory(prefix='http-',dir=ROOT/'tests/tickets-runtime') as temp:
 root=pathlib.Path(temp)
 for folder in ['app/core','app/modules','app/tickets','public/api','tests','private']:(root/folder).mkdir(parents=True)
 for rel in ['app/bootstrap.php','app/modules/tickets.php','app/tickets/Service.php','public/api/index.php','tests/core-router.php']:
  shutil.copy2(ROOT/rel,root/rel)
 for f in (ROOT/'app/core').glob('*.php'):shutil.copy2(f,root/'app/core'/f.name)
 dbpath=root/'private/test.sqlite';db=sqlite3.connect(dbpath);db.executescript((ROOT/'tests/core-fixture.sql').read_text(encoding='utf-8-sig'))
 sql=(ROOT/'migrations/030_tickets.sql').read_text();sql=re.sub(r' ENGINE=InnoDB(?: DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin)?','',sql).replace('INSERT IGNORE','INSERT OR IGNORE').replace(', INDEX(batch_id), INDEX(used_at)','').replace(', INDEX(admitted_at)','');db.executescript(sql)
 password=secrets.token_urlsafe(20);hashpw=subprocess.check_output([str(php),'-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',password],creationflags=creation).decode()
 for role in ['owner','cashier','reader']:
  db.execute('INSERT INTO kcrm_users(id,name,email,password_hash,role,active,session_version,revision,created_at,updated_at) VALUES(?,?,?,?,?,1,1,1,?,?)',(role,role,role+'@example.invalid',hashpw,role,'2026-10-31 00:00:00','2026-10-31 00:00:00'))
 db.execute("INSERT OR IGNORE INTO kcrm_setup(id) VALUES(1)");db.execute("UPDATE kcrm_setup SET owner_id='owner',completed_at='2026-10-31 00:00:00' WHERE id=1");db.commit()
 cfg={'environment':'test','base_path':'/event','timezone':'Asia/Yekaterinburg','storage_path':str(root/'private/storage'),'app_key':secrets.token_hex(32),'setup_key_hash':hashlib.sha256(secrets.token_bytes(32)).hexdigest(),'database':{'driver':'sqlite_test','path':str(dbpath),'allow_test_driver':True}}
 config=root/'private/config.php';config.write_text("<?php return json_decode(base64_decode('"+base64.b64encode(json.dumps(cfg).encode()).decode()+"'),true);")
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 base=f'http://127.0.0.1:{port}/event/api/index.php?r='
 class Client:
  def __init__(self):self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));self.csrf=''
  def call(self,route,body=None,expected=200,token=True):
   global checks
   headers={'Accept':'application/json'}
   if body is not None:headers['Content-Type']='application/json'
   if token and self.csrf:headers['X-CSRF-Token']=self.csrf
   req=urllib.request.Request(base+route,data=None if body is None else json.dumps(body).encode(),headers=headers)
   try:r=self.opener.open(req,timeout=15)
   except urllib.error.HTTPError as e:r=e
   raw=r.read();assert r.status==expected,(route,r.status,raw[:400]);x=json.loads(raw);assert x['ok']==(expected<400);checks+=1
   data=x.get('data',x.get('error'));self.csrf=data.get('csrf',self.csrf);return data
  def login(self,role):self.call('auth/me');self.call('auth/login',{'email':role+'@example.invalid','password':password})
 log=(root/'private/http.log').open('wb');server=subprocess.Popen([str(php),*flags,'-d','display_errors=0','-S',f'127.0.0.1:{port}','-t',str(root/'public'),str(root/'tests/core-router.php')],env={**os.environ,'KCRM_CONFIG':str(config)},stdout=log,stderr=log,creationflags=creation)
 try:
  anon=Client()
  for _ in range(100):
   try:anon.call('health');break
   except (OSError,urllib.error.URLError):time.sleep(.05)
  anon.call('tickets/sources',expected=401)
  owner=Client();owner.login('owner');cashier=Client();cashier.login('cashier');reader=Client();reader.login('reader');reader.call('tickets/sources',expected=403)
  doc={'format':'kosmoport.tickets','version':1,'sources':[{'id':'source-1','name':'Школа','category':'Школы','address':'Оренбург'}],'batches':[{'id':'batch-1','source_id':'source-1','active':1}],'tickets':[{'code':'999999999000000001','batch_id':'batch-1','used_at':None,'scan_id':None}],'admissions':[],'events':[]}
  owner.call('tickets/import',doc,expected=403,token=False);cashier.call('tickets/import',doc,expected=403);owner.call('tickets/import',doc)
  assert cashier.call('tickets/check',{'code':'999999999000000001'})['consumed'] is False
  assert owner.call('tickets/statistics&period=all')['used_invitations']==0
  request={'code':'999999999000000001','adult_payment_confirmed':True,'request_id':'http-request-1'}
  a=cashier.call('tickets/admit',request);assert cashier.call('tickets/admit',request)==a
  cashier.call('tickets/admit',{**request,'request_id':'http-request-2'},expected=409)
  cashier.call('tickets/reverse',{'admission_id':a['admission_id'],'reason':'Ошибка'},expected=403)
  owner.call('tickets/reverse',{'admission_id':a['admission_id'],'reason':'Ошибка'})
  assert owner.call('tickets/statistics&period=all')['used_invitations']==0
  conflict={**doc,'sources':[{**doc['sources'][0],'name':'Conflict'}]};assert owner.call('tickets/import',conflict,expected=409)['conflicts']
  cashier.call('tickets/export',expected=403);export=owner.call('tickets/export');assert export['tickets'][0]['scan_id'] is None
  assert db.execute("SELECT COUNT(*) FROM kcrm_audit WHERE action='ticket.reverse'").fetchone()[0]==1
  result={'result':'PASS','http_checks':checks,'real_php_sessions_csrf_roles':True,'database':'disposable sqlite_test','production_mysql_tested':False,'live_host_tested':False};(ROOT/'tests/tickets-http-results.json').write_text(json.dumps(result,indent=2));print(json.dumps(result))
 finally:
  server.terminate();server.wait(timeout=5);log.close();db.close()

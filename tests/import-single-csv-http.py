"""Real HTTP upload/import on a disposable local MySQL database only."""
import base64, csv, io, json, subprocess, urllib.request, urllib.error, uuid
BASE='http://127.0.0.1:8080/'

def call(path,data=None,cookie=None,csrf=None,content_type='application/json'):
    headers={'Content-Type':content_type}
    if cookie:headers['Cookie']=cookie
    if csrf:headers['X-CSRF-Token']=csrf
    body=data if isinstance(data,bytes) else (json.dumps(data).encode() if data is not None else None)
    try:r=urllib.request.urlopen(urllib.request.Request(BASE+path,data=body,headers=headers),timeout=30)
    except urllib.error.HTTPError as e:r=e
    raw=r.read()
    try:payload=json.loads(raw)
    except Exception:raise AssertionError((r.status,raw[:500]))
    return r.status,payload,r.headers

def sql(query):
    source='require "server/bootstrap.php";echo json_encode(db()->query('+json.dumps(query)+')->fetchAll());'
    return json.loads(subprocess.check_output(['php','-r',source]))

status,auth,h=call('server/api.php?action=login',{'username':'Иван Кириллов 414','password':'test-only-password'})
assert status==200,(status,auth)
cookie=next(c.split(';')[0] for c in reversed(h.get_all('Set-Cookie')) if c.startswith('PROFISPORT_ADMIN='));csrf=auth['csrf']
endpoint='api/import-upload.php?action='
assert call(endpoint+'start',{})[0]==401
assert call(endpoint+'start',{},cookie)[0]==403
png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a2ioAAAAASUVORK5CYII=')

def upload_files(batch,entries):
    boundary='test-'+uuid.uuid4().hex;body=bytearray()
    for name,path,content,mime in entries:
        body.extend(('--'+boundary+'\r\nContent-Disposition: form-data; name="files[]"; filename="'+name+'"\r\nContent-Type: '+mime+'\r\n\r\n').encode());body.extend(content);body.extend(b'\r\n')
        body.extend(('--'+boundary+'\r\nContent-Disposition: form-data; name="paths[]"\r\n\r\n'+path+'\r\n').encode())
    body.extend(('--'+boundary+'--\r\n').encode())
    return call(endpoint+'upload&batch='+batch,bytes(body),cookie,csrf,'multipart/form-data; boundary='+boundary)

stream=io.StringIO(newline='');w=csv.writer(stream)
w.writerow(['id','name','price','stock','category','image'])
w.writerow(['single-test-1','Тест одиночной таблицы',125,2,'Запчасти / Подшипники','audit-a.png|audit-b.png'])
w.writerow(['single-test-2','Нет в наличии',150,0,'Запчасти / Подшипники',''])
csv_bytes=('\ufeff'+stream.getvalue()).encode('utf-8')
original_counts=sql('SELECT (SELECT COUNT(*) FROM orders) AS orders_count,(SELECT COUNT(*) FROM customers) AS customers_count')[0]

def run_batch(table,photos=True):
    status,start,_=call(endpoint+'start',{},cookie,csrf);assert status==200,(status,start)
    batch=start['batch'];assert start['limits']['max_files']>=1
    entries=[('Произвольное имя.CSV','Произвольное имя.CSV',table,'text/csv')]
    if photos:entries += [('audit-a.png','images/audit-a.png',png,'image/png'),('audit-b.png','images/audit-b.png',png,'image/png')]
    for entry in entries:
        status,result,_=upload_files(batch,[entry]);assert status==200 and result['saved']==1,(status,result)
    # Staging must not leak into the input chosen by the importer.
    status,listing,_=call('api/import-files.php',cookie=cookie);assert status==200
    assert not any('.staging' in f['path'] for f in listing.get('files',[]))
    status,final,_=call(endpoint+'finalize&batch='+batch,{},cookie,csrf);assert status==200,(status,final)
    assert final['files']==len(entries) and final['images']==len(entries)-1
    status,applied,_=call('api/import-apply.php?offset=0&limit=250',{},cookie,csrf)
    assert status==200 and applied['done'] and not applied.get('unchanged'),(status,applied)
    return applied

first=run_batch(csv_bytes)
rows=sql("SELECT id,source_id,category_path,stock_qty,is_active,main_image,images FROM products WHERE source_id LIKE 'single-test-%' ORDER BY source_id")
assert len(rows)==2 and rows[0]['stock_qty']==2 and rows[1]['is_active']==0,rows
assert rows[0]['category_path']=='Запчасти / Подшипники',rows
assert '/manual/' in rows[0]['main_image'] and rows[0]['main_image'].endswith('/images/audit-a.png'),rows
first_main=rows[0]['main_image'];first_id=rows[0]['id']
second=run_batch(csv_bytes)
assert first['snapshot']!=second['snapshot'],'photo update with same CSV not processed'
r=sql("SELECT id,main_image,images FROM products WHERE source_id='single-test-1'")[0]
assert r['id']==first_id and r['main_image']!=first_main,r
assert len(json.loads(r['images']))==2,'old versions of replaced photos must not duplicate gallery'
old_photo=r['main_image']
no_category=b'id;name;price;stock\nsingle-test-1;Updated name;200;3\n'
run_batch(no_category,False)
r=sql("SELECT category_path,stock_qty,main_image FROM products WHERE source_id='single-test-1'")[0]
assert r['category_path']=='Запчасти / Подшипники' and r['stock_qty']==3 and r['main_image']==old_photo,r
assert sql('SELECT (SELECT COUNT(*) FROM orders) AS orders_count,(SELECT COUNT(*) FROM customers) AS customers_count')[0]==original_counts
# An unsupported CSV must not modify product rows.
before=sql('SELECT id,source_id,price_rub,stock_qty,is_active FROM products ORDER BY id')
status,start,_=call(endpoint+'start',{},cookie,csrf)
status,result,_=upload_files(start['batch'],[('bad.csv','bad.csv',b'hello;world\nnot;a;catalog\n','text/csv')]);assert status==200
assert call(endpoint+'finalize&batch='+start['batch'],{},cookie,csrf)[0]==200
status,result,_=call('api/import-apply.php',{},cookie,csrf);assert status>=400
assert sql('SELECT id,source_id,price_rub,stock_qty,is_active FROM products ORDER BY id')==before
print('PASS: authenticated CSV + image files -> import -> stock/categories/photos; second batch; CSV only; malformed input; order/customer preservation')

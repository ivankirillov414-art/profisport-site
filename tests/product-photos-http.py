"""Run only against the disposable CI database and local PHP server."""
import csv, hashlib, json, pathlib, shutil, subprocess, time, urllib.request, urllib.error, urllib.parse, uuid
ROOT=pathlib.Path(__file__).resolve().parents[1]
BASE='http://127.0.0.1:8080/'
PID=910001
prefix='ppe-'+uuid.uuid4().hex[:10]
source_id=prefix+'-main'

def php(code):
    return subprocess.check_output(['php','-r',code],cwd=ROOT)

def call(path,data=None,cookie='',csrf='',multipart=None):
    headers={}
    if cookie:headers['Cookie']=cookie
    if csrf:headers['X-CSRF-Token']=csrf
    if multipart:
        body,content_type=multipart;headers['Content-Type']=content_type
    elif data is not None:
        body=json.dumps(data).encode();headers['Content-Type']='application/json'
    else:body=None
    req=urllib.request.Request(BASE+path,data=body,headers=headers)
    try:r=urllib.request.urlopen(req,timeout=45)
    except urllib.error.HTTPError as e:r=e
    raw=r.read()
    try:j=json.loads(raw)
    except Exception:raise AssertionError((r.status,raw[:300]))
    return r.status,j,r.headers

def multipart(revision,picture,primary='1'):
    boundary='ppe'+uuid.uuid4().hex
    parts=[]
    for name,value in [('id',str(PID)),('revision',revision),('primary',primary)]:
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
    parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="photo"; filename="photo.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+picture+b'\r\n')
    parts.append(f'--{boundary}--\r\n'.encode())
    return b''.join(parts),'multipart/form-data; boundary='+boundary

batch=ROOT/'import'/'manual'/prefix
try:
    php('require "server/bootstrap.php"; $s=db()->prepare("INSERT INTO products(id,source_id,source_hash,name,title,sku,model,price,price_rub,stock_qty,stock_status,availability,is_active,category_path,main_image,images) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");$s->execute('+json.dumps([PID,source_id,hashlib.sha256(('1c|'+source_id).encode()).hexdigest(),'Photo test product','Photo test product','PPE-SKU','PPE-MODEL',123,123,7,'in_stock','in_stock',1,'Test / Photos','','[]'])+');')
    assert call('api/product-photos.php')[0]==401
    status,auth,h=call('server/api.php?action=login',{'username':'Иван Кириллов 414','password':'test-only-password'})
    assert status==200,(status,auth)
    cookie=next(c.split(';')[0] for c in reversed(h.get_all('Set-Cookie')) if c.startswith('PROFISPORT_ADMIN='));csrf=auth['csrf']
    def detail():
        s,j,_=call('api/product-photos.php?id='+str(PID),cookie=cookie);assert s==200,(s,j);return j['product']
    for query in ['Photo test','PPE-SKU','PPE-MODEL',source_id,str(PID)]:
        s,j,_=call('api/product-photos.php?q='+urllib.parse.quote(query),cookie=cookie);assert s==200 and any(p['id']==PID for p in j['items']),(query,s,j)
    q='api/product-photos.php?missing=1&q='+source_id
    assert call(q,cookie=cookie)[1]['total']==1
    p=detail();before=p['revision']
    image=php('$im=imagecreatetruecolor(10,10);imagefill($im,0,0,imagecolorallocate($im,220,100,10));imagepng($im);')
    assert call('api/product-photos.php?action=upload',cookie=cookie,multipart=multipart(before,image))[0]==403
    assert call('api/product-photos.php?action=upload',cookie=cookie,csrf=csrf,multipart=multipart(before,b'<svg></svg>'))[0]==400
    s,j,_=call('api/product-photos.php?action=upload',cookie=cookie,csrf=csrf,multipart=multipart(before,image));assert s==200,(s,j)
    first=j['product']['photos'][0]['url'];assert '/curated-photos/' in first
    assert call(q,cookie=cookie)[1]['total']==0
    assert call('api/product-photos.php?action=primary',{'id':PID,'revision':before,'url':first},cookie,csrf)[0]==409
    p=detail()
    image2=php('$im=imagecreatetruecolor(10,10);imagefill($im,0,0,imagecolorallocate($im,10,30,200));imagepng($im);')
    s,j,_=call('api/product-photos.php?action=upload',cookie=cookie,csrf=csrf,multipart=multipart(p['revision'],image2,'0'));assert s==200,(s,j)
    assert len(j['product']['photos'])==2 and next(x for x in j['product']['photos'] if x['primary'])['url']==first
    second=next(x['url'] for x in j['product']['photos'] if x['url']!=first)
    p=detail();assert call('api/product-photos.php?action=primary',{'id':PID,'revision':p['revision'],'url':'https://example.test/not-this-product.jpg'},cookie,csrf)[0]==400
    s,j,_=call('api/product-photos.php?action=primary',{'id':PID,'revision':p['revision'],'url':second},cookie,csrf);assert s==200,(s,j)
    assert next(x for x in j['product']['photos'] if x['primary'])['url']==second
    public=call('api/catalog.php?id='+str(PID))[1]['items'][0];assert public['image']==second and len(public['images'])==2
    with urllib.request.urlopen(BASE+'api/product-image.php?p='+urllib.parse.quote(second.removeprefix('/import/')),timeout=30) as r:assert r.status==200 and r.headers['Content-Type'].startswith('image/')
    # Run the actual import endpoint without its final archival pass: 100 of 101 rows.
    batch.mkdir(parents=True);(batch/'source-new.png').write_bytes(image)
    with (batch/'Tovary.csv').open('w',newline='') as f:
        w=csv.writer(f,delimiter=';');w.writerow(['id','name','price','stock','category','image']);w.writerow([source_id,'Photo test product',777,11,'Test / Photos','source-new.png'])
        for i in range(100):w.writerow([prefix+'-'+str(i),'PPE generated '+prefix+' '+str(i),1,1,'Test / Photos',''])
    (batch/'.upload.json').write_text(json.dumps({'completed_at':time.time()+10}))
    s,j,_=call('api/import-apply.php?offset=0&limit=100',{},cookie,csrf);assert s==200 and j['done'] is False,(s,j)
    public=call('api/catalog.php?id='+str(PID))[1]['items'][0]
    assert public['image']==second and first in public['images'] and public['stock_qty']==11 and public['price_rub']==777,public
    assert any('source-new.png' in u for u in public['images'])
    print('PASS: authenticated search by name/SKU/model/ID; no-photo filter; CSRF; real multipart upload; invalid file rejection; stale conflict; gallery; primary change; public image; later 1C import preserves manual photos')
finally:
    php('require "server/bootstrap.php";require "server/product-photo-editor.php";ppe_schema(db());db()->exec("DELETE FROM product_photo_overrides WHERE product_id='+str(PID)+'");db()->exec("DELETE FROM products WHERE source_id LIKE \''+prefix+'%\'");')
    shutil.rmtree(batch,ignore_errors=True);shutil.rmtree(ROOT/'import'/'curated-photos'/str(PID),ignore_errors=True)

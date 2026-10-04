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
# Completed snapshots archive unrelated fixtures too; restore their visibility afterward.
visibility=json.loads(php('require "server/bootstrap.php"; echo json_encode(db()->query("SELECT id,is_active,stock_status,availability FROM products")->fetchAll());'))
settings=json.loads(php('require "server/bootstrap.php"; echo json_encode(db()->query("SELECT setting_key,setting_value FROM site_settings WHERE setting_key IN (\'current_1c_snapshot\',\'last_1c_import\')")->fetchAll());'))
try:
    # Exercise upgrading the photo override table installed by the earlier editor.
    php('require "server/bootstrap.php"; db()->exec("CREATE TABLE IF NOT EXISTS product_photo_overrides (product_id BIGINT UNSIGNED PRIMARY KEY,manual_urls LONGTEXT NOT NULL,primary_url TEXT NULL,updated_by INT UNSIGNED NULL,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");')
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
    # Removal must unlink every origin, select a remaining main, and survive import.
    def remove(url, revision=None, token=csrf):
        return call('api/product-photos.php?action=remove',{'id':PID,'revision':revision or detail()['revision'],'url':url},cookie,token)
    p=detail();stale=p['revision']
    assert call('api/product-photos.php?action=remove',{'id':PID,'revision':stale,'url':first})[0]==401
    assert remove(first,token='')[0]==403
    assert remove('https://example.test/other-product.jpg')[0]==400
    s,j,_=remove(first);assert s==200 and all(x['url']!=first for x in j['product']['photos']),(s,j)
    assert next(x['url'] for x in j['product']['photos'] if x['primary'])==second
    assert remove(second,revision=stale)[0]==409
    s,j,_=remove(second);assert s==200,(s,j)
    source_photo=next(x['url'] for x in j['product']['photos'] if x['primary'])
    assert 'source-new.png' in source_photo
    s,j,_=remove(source_photo);assert s==200 and j['product']['photos']==[],(s,j)
    assert call(q,cookie=cookie)[1]['total']==1
    public=call('api/catalog.php?id='+str(PID))[1]['items'][0]
    assert first not in public['images'] and second not in public['images'] and source_photo not in public['images'],public
    s,j,_=call('api/import-apply.php?offset=0&limit=100',{},cookie,csrf);assert s==200,(s,j)
    assert detail()['photos']==[], 'Source import resurrected a deleted photo'
    # Immutable original files remain safe; re-uploading deliberately restores a photo.
    assert (ROOT/first.lstrip('/')).is_file()
    s,j,_=call('api/product-photos.php?action=upload',cookie=cookie,csrf=csrf,multipart=multipart(detail()['revision'],image))
    assert s==200 and len(j['product']['photos'])==1 and j['product']['photos'][0]['url']==first,(s,j)
    # Full real snapshots: no source photo, sold out, missing, then returned/renamed.
    sequence=0
    def complete_snapshot(include_product=True, stock=6, name='Photo test product'):
        global sequence
        sequence+=1
        with (batch/'Tovary.csv').open('w',newline='') as f:
            w=csv.writer(f,delimiter=';');w.writerow(['id','name','price','stock','category','image'])
            if include_product:w.writerow([source_id,name,888,stock,'Test / Updated Photos',''])
            w.writerow([prefix+'-sentinel','Sentinel '+prefix+' '+str(sequence),1,1,'Test / Photos',''])
        (batch/'.upload.json').write_text(json.dumps({'completed_at':time.time()+20+sequence}))
        s,j,_=call('api/import-apply.php?offset=0&limit=100',{},cookie,csrf)
        assert s==200 and j['done'] is True and not j.get('unchanged'),(s,j)
    complete_snapshot()
    p=detail();assert p['id']==PID and p['active'] and len(p['photos'])==1 and p['photos'][0]['url']==first and p['photos'][0]['primary'],p
    complete_snapshot(stock=0)
    p=detail();assert not p['active'] and p['stock_qty']==0 and p['photos'][0]['url']==first,p
    assert not call('api/catalog.php?id='+str(PID))[1]['items']
    complete_snapshot(include_product=False)
    p=detail();assert not p['active'] and p['photos'][0]['url']==first,p
    # Clear the denormalized display fields to prove the saved override restores them.
    php('require "server/bootstrap.php"; db()->exec("UPDATE products SET main_image=NULL,images=\'[]\' WHERE id='+str(PID)+'");')
    complete_snapshot(stock=9,name='Photo test product renamed')
    p=detail();assert p['id']==PID and p['active'] and p['stock_qty']==9 and p['name']=='Photo test product renamed',p
    assert len(p['photos'])==1 and p['photos'][0]['url']==first and p['photos'][0]['primary'],p
    public=call('api/catalog.php?id='+str(PID))[1]['items'][0]
    assert public['image']==first and public['images']==[first],public
    count=php('require "server/bootstrap.php"; echo db()->query("SELECT COUNT(*) FROM products WHERE source_id=\''+source_id+'\'")->fetchColumn();')
    assert count==b'1', 'Returning product was duplicated'
    print('PASS product photos: upload, deletion of manual/source/main/last photo, CSRF and authentication, stale conflicts, import persistence, explicit re-upload, sold-out/missing/renamed return without source photos')
finally:
    php('require "server/bootstrap.php"; $s=db()->prepare("UPDATE products SET is_active=?,stock_status=?,availability=? WHERE id=?"); foreach(json_decode('+json.dumps(json.dumps(visibility))+',true) as $r)$s->execute([$r["is_active"],$r["stock_status"],$r["availability"],$r["id"]]); db()->exec("DELETE FROM site_settings WHERE setting_key IN (\'current_1c_snapshot\',\'last_1c_import\')"); $s=db()->prepare("INSERT INTO site_settings(setting_key,setting_value) VALUES(?,?)");foreach(json_decode('+json.dumps(json.dumps(settings))+',true) as $r)$s->execute([$r["setting_key"],$r["setting_value"]]);')
    php('require "server/bootstrap.php";require "server/product-photo-editor.php";ppe_schema(db());db()->exec("DELETE FROM product_photo_overrides WHERE product_id='+str(PID)+'");db()->exec("DELETE FROM products WHERE source_id LIKE \''+prefix+'%\'");')
    shutil.rmtree(batch,ignore_errors=True);shutil.rmtree(ROOT/'import'/'curated-photos'/str(PID),ignore_errors=True)

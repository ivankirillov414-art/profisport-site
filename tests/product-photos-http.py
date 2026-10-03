import json, pathlib, uuid, urllib.request, urllib.error
f=json.loads(pathlib.Path('/tmp/photo-test-fixtures.json').read_text()); base='http://127.0.0.1:8080/'
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args): return None
opener=urllib.request.build_opener(NoRedirect)
def call(path,method='GET',data=None,ctype='application/json',cookie=None,csrf=True,expected=200,raw=False):
    headers={'Cookie':f['cookie'] if cookie is None else cookie}
    if csrf: headers['X-CSRF-Token']=f['csrf']
    if data is not None:
        headers['Content-Type']=ctype
        if not isinstance(data,bytes): data=json.dumps(data).encode()
    request=urllib.request.Request(base+path,data=data,headers=headers,method=method)
    try: response=opener.open(request,timeout=30)
    except urllib.error.HTTPError as e: response=e
    payload=response.read(); assert response.status==expected,(path,response.status,payload[:800])
    if raw:return payload,response.headers
    return json.loads(payload)
def get(id=f['a']): return call('api/product-photos.php?id='+str(id))['product']
def change(action,product,target='',image=None,rev=None,key=None,expected=200,content=None,filename=None):
    fields={'revision':product['revision'] if rev is None else rev,'request_id':key or str(uuid.uuid4()),'target':target}
    if image or content is not None:
        boundary='PhotoBoundary'+uuid.uuid4().hex; body=b''
        for k,v in fields.items():body+=('--'+boundary+'\r\nContent-Disposition: form-data; name="'+k+'"\r\n\r\n'+str(v)+'\r\n').encode()
        name=filename or image or 'upload.png'; image_bytes=content if content is not None else pathlib.Path('import/photo-test/'+image).read_bytes()
        body+=('--'+boundary+'\r\nContent-Disposition: form-data; name="photo"; filename="'+name+'"\r\nContent-Type: image/png\r\n\r\n').encode()+image_bytes+('\r\n--'+boundary+'--\r\n').encode()
        result=call('api/product-photos.php?id='+str(product['id'])+'&action='+action,'POST',body,'multipart/form-data; boundary='+boundary,expected=expected)
    else: result=call('api/product-photos.php?id='+str(product['id'])+'&action='+action,'POST',fields,expected=expected)
    return result.get('product',result)
call('api/product-photos.php',cookie='',expected=401)
call('api/product-photos.php?id='+str(f['a'])+'&action=reset','POST',{},csrf=False,expected=403)
call('api/product-photos.php?id='+str(f['a'])+'&action=reset','POST',{},cookie=f['viewer_cookie'],expected=403)
call('api/product-photos.php?id=99999999',expected=404)
assert call('api/product-photos.php?q=PHOTO-A')['total']==1
p=get(); assert p['images']==['/import/photo-test/source.png'] and not p['manual']
key=str(uuid.uuid4());original=p.copy();p=change('upload',p,image='upload.png',key=key);assert p['manual'] and len(p['images'])==2
again=change('upload',original,image='upload.png',key=key);assert again['revision']==p['revision'] and again['images']==p['images']
url=p['images'][1];p=change('primary',p,target=url);assert p['images'][0]==url
change('remove',p,target=url,rev=0,expected=409)
p=change('replace',p,target=url,image='replace.png');replacement=p['images'][0];assert replacement!=url
catalog=call('api/catalog.php?id='+str(f['a']))['items'][0]
assert catalog['image']==replacement and catalog['image_source']=='manual'
assert catalog['price_rub']==100 and catalog['stock_qty']==5
assert get(f['b'])['images']==['/import/photo-test/source.png']
assert call('api/photo-health.php')['stats']['products_with_manual_gallery']==1
# The actual importer writes only source images and commerce fields. Override remains.
pathlib.Path('import/photo-test/Tovary.csv').write_text('id;name;price;stock;category;image\nPHOTO-A;Фото товар A;110;7;Тест;source2.png\nPHOTO-B;Фото товар B;120;6;Тест;source.png\n')
r=call('api/import-apply.php?offset=0&limit=250','POST',{});assert r['ok'] and r['done'],r
assert get()['images'][0]==replacement,'1C import overwrote manual gallery'
catalog=call('api/catalog.php?id='+str(f['a']))['items'][0]
assert catalog['image']==replacement and catalog['price_rub']==110 and catalog['stock_qty']==7
p=get();source=p['images'][1];p=change('remove',p,target=source);assert p['images']==[replacement]
# Invalid payload cannot alter the current gallery.
change('upload',p,content=b'<?php echo "bad";?>',filename='fake.png',expected=400)
assert get()['images']==p['images']
# Re-encoding strips trailing executable content from otherwise valid images.
trailing=pathlib.Path('import/photo-test/upload.png').read_bytes()+b'<?php die("bad"); ?>'
p=change('upload',p,content=trailing,filename='photo.png');photo_bytes,_=call(p['images'][-1].lstrip('/'),raw=True)
assert b'<?php' not in photo_bytes
p=change('reset',p);assert not p['manual'] and p['images'][0].endswith('source2.png'),p
assert call('api/catalog.php?id='+str(f['a']))['items'][0]['image'].endswith('source2.png')
p=change('remove',p,target=p['images'][0]);assert p['manual'] and p['images']==[]
assert call('api/catalog.php?id='+str(f['a']))['items'][0]['image'] is None
print('PASS: authenticated upload, preview data, replace, primary, removal, replay, stale revision, true 1C import, reset, image validation and access control')

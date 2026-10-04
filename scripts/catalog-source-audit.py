"""Read-only audit of product export eligibility over the existing deployment channel."""
import csv,io,os,re,json,ssl,ftplib,collections,datetime
ftp=ftplib.FTP_TLS(context=ssl.create_default_context(),timeout=25)
ftp.connect('ftpupload.net',21)
ftp.login('if0_42771076',os.environ['FTP_PASSWORD'])
ftp.prot_p()
files=[];visited=0
def walk(path,depth=0):
    global visited
    visited+=1
    if visited>200 or depth>6: return
    for name,facts in ftp.mlsd(path):
        if name in ('.','..') or name.startswith('.') or name=='curated-photos':continue
        p=path+'/'+name
        if facts.get('type')=='dir':
            if name.lower() not in ('images','photos','foto','img'):walk(p,depth+1)
        elif name.lower().endswith('.csv') and 'tovary' in name.lower():
            files.append({'path':p,'mtime':facts.get('modify',''),'size':int(facts.get('size','0'))})
walk('/htdocs/import')
if not files:raise RuntimeError('No product export found')
files.sort(key=lambda f:(f['mtime'],f['size']),reverse=True)
f=files[0]
if f['size']>33554432:raise RuntimeError('Export exceeds bounded audit size')
buf=io.BytesIO();ftp.retrbinary('RETR '+f['path'],buf.write);ftp.quit()
raw=buf.getvalue()
encoding='utf-16' if raw[:2] in (b'\xff\xfe',b'\xfe\xff') else 'utf-8-sig'
try:text=raw.decode(encoding)
except UnicodeDecodeError:text=raw.decode('cp1251')
delimiter=max([';',',','\t'],key=lambda d:len(next(csv.reader([text.splitlines()[0]],delimiter=d))))
rows=list(csv.reader(io.StringIO(text,newline=''),delimiter=delimiter))
def score(rs):return sum(len(r)==41 and len(r)>4 and r[2].strip()=='Kod_'+r[4].strip() for r in rs)
physical=[]
for line in text.splitlines():
    try:physical.append(next(csv.reader([line],delimiter=delimiter)))
    except csv.Error:continue
if delimiter==';' and score(physical)>score(rows):rows=physical
norm=lambda s:re.sub('[^a-zа-я0-9]','',s.lower())
aliases={'name':['name','title','наименование','название','товар'],'id':['id','guid','uuid','код','кодтовара','ид','идентификатор'],'stock':['stock','stockqty','qty','quantity','остаток','остатки','количество','остатокнаскладе','количествонаскладе'],'price':['price','цена','розничнаяцена','ценарозница'],'category':['category','категория','группа']}
mapping={}
for i,v in enumerate(rows[0]):
    for key,values in aliases.items():
        if norm(v) in [norm(a) for a in values]:mapping[key]=i
if all(k in mapping for k in ('name','id','stock','price')):rows=rows[1:]
else:mapping={'name':7,'id':4,'stock':11,'price':10,'category':3}
counts=collections.Counter(r[mapping['id']].strip() for r in rows if len(r)>mapping['id'])
def field(r,key):return r[mapping[key]].strip() if len(r)>mapping[key] else ''
matches=[]
for r in rows:
    name=field(r,'name') or (r[6].strip() if len(r)>6 else '')
    if re.search('лыж',name,re.I) and re.search('турист|та[её]ж|охот|армей',name,re.I):
        matches.append({'name':name,'source_id':field(r,'id'),'stock':field(r,'stock'),'price':field(r,'price'),'category':field(r,'category'),'duplicate_id_count':counts[field(r,'id')]})
print(json.dumps({'source':f,'rows':len(rows),'mapping':mapping,'matched':len(matches),'products':matches},ensure_ascii=False))

print(json.dumps({'compound_price_rows':sum('&' in field(r,'price') for r in rows),'duplicate_product_ids':sum(v>1 for k,v in counts.items() if k),'examples':[{'columns':r} for r in rows if field(r,'id') in ('49810','18978','27841')]},ensure_ascii=False))

compound=[r for r in rows if '&' in field(r,'price')]
attrs=collections.Counter()
for r in compound:
    for token in field(r,'price').split('|'):
        tail=token.split('&')[2:]
        attrs[','.join(sorted(t.split('=')[0] for t in tail))]+=1
print(json.dumps({'option_attribute_sets':attrs,'other_examples':[{'name':field(r,'name'),'price':field(r,'price'),'stock':field(r,'stock'),'tail':r[13:]} for r in compound[:8]],'duplicate_rows':[{'id':field(r,'id'),'name':field(r,'name'),'price':field(r,'price'),'stock':field(r,'stock')} for r in rows if counts[field(r,'id')]>1]},ensure_ascii=False))

bad_quantity=[];bad_labels=[]
for r in compound:
    options=[t.split('&') for t in field(r,'price').split('|')]
    try:
        if sum(int(o[1]) for o in options)!=int(field(r,'stock')):bad_quantity.append(field(r,'id'))
    except (ValueError,IndexError):bad_quantity.append(field(r,'id'))
    if len(r)<=29 or len(r[29].split('|'))!=len(options):bad_labels.append(field(r,'id'))
print(json.dumps({'option_stock_mismatches':bad_quantity,'option_label_mismatches':bad_labels},ensure_ascii=False))

label_map={};ambiguous=set()
for r in compound:
    options=[t.split('&') for t in field(r,'price').split('|')]
    labels=r[29].split('|') if len(r)>29 else []
    if len(options)!=len(labels):continue
    for o,label in zip(options,labels):
        k=o[2].split('=')[1]
        if k in label_map and label_map[k]!=label:ambiguous.add(k)
        else:label_map[k]=label
for k in ambiguous:label_map.pop(k,None)
unresolved=[]
for r in compound:
    if field(r,'id') not in bad_labels:continue
    for o in field(r,'price').split('|'):
        k=o.split('&')[2].split('=')[1]
        if k not in label_map:unresolved.append({'id':field(r,'id'),'name':field(r,'name'),'option':k,'tail':r[29:]})
print(json.dumps({'ambiguous_size_ids':list(ambiguous),'unresolved_sizes':unresolved},ensure_ascii=False))

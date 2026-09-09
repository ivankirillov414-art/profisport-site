#!/usr/bin/env python3
import glob,json,os,re
from collections import Counter,defaultdict
from datetime import datetime,timezone

ROOT=os.path.abspath(os.path.join(os.path.dirname(__file__),'..'))
DATA=os.path.join(ROOT,'data')
OUT=os.path.join(DATA,'catalog-audit.json')

TYPE_RULES=[
 ('bicycle',re.compile(r'^(?:электровелосипед|велосипед)(?:\s|-)',re.I),('велосип','bmx')),
 ('scooter',re.compile(r'^(?:электросамокат|самокат)(?:\s|-)',re.I),('самокат',)),
 ('snowboard',re.compile(r'^сноуборд(?:\s|-)',re.I),('сноуборд',)),
 ('rollers',re.compile(r'^(?:роликовые коньки|коньки роликовые|коньки для танцев|квады|ролики)(?:\s|-)',re.I),('ролик',)),
 ('skates',re.compile(r'^коньки(?:\s|-)',re.I),('коньк',)),
 ('skis',re.compile(r'^лыжи(?:\s|-)',re.I),('лыж',)),
 ('skateboard',re.compile(r'^скейтборд(?:\s|-)',re.I),('скейт',)),
 ('longboard',re.compile(r'^лонгборд(?:\s|-)',re.I),('лонгборд','скейт')),
 ('treadmill',re.compile(r'^беговая дорожка(?:\s|-)',re.I),('тренаж','фитнес','бегов')),
 ('exercise_bike',re.compile(r'^велотренаж[её]р(?:\s|-)',re.I),('тренаж','фитнес')),
 ('tent',re.compile(r'^палатка(?:\s|-)',re.I),('туризм','палат')),
 ('sleeping_bag',re.compile(r'^(?:спальный мешок|спальник)(?:\s|-)',re.I),('туризм','спаль')),
 ('pool',re.compile(r'^бассейн(?:\s|-)',re.I),('водн','бассейн')),
]

def norm(s):
 s=str(s or '').strip().lower().replace('ё','е')
 s=re.sub(r'[^a-zа-я0-9-]+',' ',s,flags=re.I)
 return re.sub(r'\s+',' ',s).strip()

def title(row):return str(row.get('title') or row.get('name') or '').strip()
def path_list(row):
 p=row.get('category_path') or row.get('breadcrumbs') or []
 if isinstance(p,list):return [str(x).strip() for x in p if str(x).strip()]
 return [x.strip() for x in str(p).split('/') if x.strip()]
def image_values(row):
 values=[]
 raw=row.get('images')
 if isinstance(raw,list):values.extend(str(x).strip() for x in raw if str(x).strip())
 elif isinstance(raw,str) and raw.strip():values.append(raw.strip())
 for key in ('main_image','image'):
  value=str(row.get(key) or '').strip()
  if value:values.append(value)
 return list(dict.fromkeys(values))
def is_cycling_pulley(n):
 return n.startswith('ролики ') and (any(x in n for x in ('переключател','суппорт','подшипник','направляющ','shimano','sram')) or re.search(r'(?:^|\s)rd[- ]?[a-z0-9]',n,re.I))
def classify(name):
 n=norm(name)
 if is_cycling_pulley(n):return ''
 for key,rx,_ in TYPE_RULES:
  if rx.search(n):return key
 return ''
def expected_stems(kind):
 for key,_,stems in TYPE_RULES:
  if key==kind:return stems
 return ()
def spec_value(specs,*names):
 wanted={norm(x) for x in names}
 if isinstance(specs,dict):
  for k,v in specs.items():
   if norm(k) in wanted and str(v or '').strip():return str(v).strip()
 elif isinstance(specs,list):
  for item in specs:
   if not isinstance(item,dict):continue
   k=item.get('name') or item.get('key') or item.get('title')
   v=item.get('value')
   if norm(k) in wanted and str(v or '').strip():return str(v).strip()
 return ''
def load_rows():
 rows=[]
 for fn in sorted(glob.glob(os.path.join(DATA,'catalog-*.json'))):
  try:
   chunk=json.load(open(fn,encoding='utf-8'))
   if isinstance(chunk,list):rows.extend(x for x in chunk if isinstance(x,dict))
  except Exception as e:print('skip',fn,e)
 return rows

def compact_row(row):
 path=path_list(row)
 return {
  'name':title(row),
  'sku':str(row.get('sku') or '').strip(),
  'brand':str(row.get('brand') or '').strip(),
  'model':str(row.get('model') or '').strip(),
  'price_rub':int(row.get('price_rub') or 0),
  'old_price_rub':int(row.get('old_price_rub') or 0),
  'category':path[-1] if path else '',
  'category_path':path,
  'url':str(row.get('url') or '').strip(),
 }

def duplicate_group(rows,idxs):
 items=[compact_row(rows[x]) for x in idxs[:12]]
 skus={x['sku'] for x in items if x['sku']}
 prices={x['price_rub'] for x in items}
 categories={x['category'] for x in items}
 return {
  'name':items[0]['name'],
  'count':len(idxs),
  'same_nonempty_sku':len(skus)==1 and bool(skus),
  'same_price':len(prices)==1,
  'same_category':len(categories)==1,
  'items':items,
 }

def stable_generated_at(report):
 now=datetime.now(timezone.utc).isoformat()
 try:
  previous=json.load(open(OUT,encoding='utf-8'))
 except Exception:
  return now
 old=dict(previous);old.pop('generated_at',None)
 new=dict(report);new.pop('generated_at',None)
 return previous.get('generated_at',now) if old==new else now

def main():
 rows=load_rows();by_name=defaultdict(list);by_url=defaultdict(list);cats=Counter();types=Counter();issues=[]
 missing_images=0;missing_brand=0;old_price_reversed=0;zero_price=0
 zero_price_items=[];invalid_old_price_items=[];missing_image_items=[];missing_brand_items=[]
 for i,row in enumerate(rows):
  name=title(row);n=norm(name);path=path_list(row);ptext=norm(' '.join(path));kind=classify(name)
  if n:by_name[n].append(i)
  u=str(row.get('url') or '').strip()
  if u:by_url[u].append(i)
  leaf=path[-1] if path else 'Без категории';cats[leaf]+=1
  if kind:types[kind]+=1
  if not image_values(row):
   missing_images+=1
   if len(missing_image_items)<120:missing_image_items.append(compact_row(row))
  specs=row.get('specs') or {}
  brand=str(row.get('brand') or '').strip() or spec_value(specs,'Бренд','Производитель')
  if not brand:
   missing_brand+=1
   if len(missing_brand_items)<120:missing_brand_items.append(compact_row(row))
  price=int(row.get('price_rub') or 0);old=int(row.get('old_price_rub') or 0)
  if price<=0:
   zero_price+=1
   if len(zero_price_items)<120:zero_price_items.append(compact_row(row))
  if old>0 and price>old:
   old_price_reversed+=1
   if len(invalid_old_price_items)<120:invalid_old_price_items.append(compact_row(row))
  if kind:
   stems=expected_stems(kind)
   if stems and not any(s in ptext for s in stems):issues.append({'kind':'category_conflict','type':kind,'name':name,'category_path':path,'url':u})
  frame_value=spec_value(specs,'Ростовка рамы')
  if kind!='bicycle' and frame_value:issues.append({'kind':'suspicious_spec','type':kind or 'unknown','name':name,'spec':'Ростовка рамы','value':frame_value,'category_path':path,'url':u})

 duplicates=[duplicate_group(rows,idxs) for _,idxs in by_name.items() if len(idxs)>1]
 duplicate_urls=[{'url':u,'count':len(idxs),'names':[title(rows[x]) for x in idxs[:8]]} for u,idxs in by_url.items() if len(idxs)>1]
 safe_same_sku=sum(1 for x in duplicates if x['same_nonempty_sku'])
 same_price_category=sum(1 for x in duplicates if x['same_price'] and x['same_category'])
 report={
  'catalog_rows':len(rows),
  'summary':{
   'normalized_name_duplicates':len(duplicates),
   'duplicate_groups_same_nonempty_sku':safe_same_sku,
   'duplicate_groups_same_price_and_category':same_price_category,
   'duplicate_urls':len(duplicate_urls),
   'category_conflicts':sum(1 for x in issues if x['kind']=='category_conflict'),
   'suspicious_specs':sum(1 for x in issues if x['kind']=='suspicious_spec'),
   'missing_images':missing_images,
   'missing_brand':missing_brand,
   'zero_or_missing_price':zero_price,
   'old_price_lower_than_current':old_price_reversed
  },
  'primary_product_types':dict(types.most_common()),
  'largest_leaf_categories':[{'category':k,'count':v} for k,v in cats.most_common(80)],
  'issues':issues[:500],
  'zero_price_items':zero_price_items,
  'invalid_old_price_items_sample':invalid_old_price_items,
  'missing_image_items_sample':missing_image_items,
  'missing_brand_items_sample':missing_brand_items,
  'duplicates':sorted(duplicates,key=lambda x:(-x['count'],x['name']))[:300],
  'duplicate_urls':sorted(duplicate_urls,key=lambda x:-x['count'])[:100]
 }
 report['generated_at']=stable_generated_at(report)
 report={'generated_at':report.pop('generated_at'),**report}
 os.makedirs(DATA,exist_ok=True)
 with open(OUT,'w',encoding='utf-8') as f:json.dump(report,f,ensure_ascii=False,indent=2);f.write('\n')
 print(json.dumps(report['summary'],ensure_ascii=False))

if __name__=='__main__':main()

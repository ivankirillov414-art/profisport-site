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
 s=re.sub(r'[^a-zа-я0-9]+',' ',s,flags=re.I)
 return re.sub(r'\s+',' ',s).strip()

def title(row):return str(row.get('title') or row.get('name') or '').strip()
def path_list(row):
 p=row.get('category_path') or row.get('breadcrumbs') or []
 if isinstance(p,list):return [str(x).strip() for x in p if str(x).strip()]
 return [x.strip() for x in str(p).split('/') if x.strip()]
def classify(name):
 n=norm(name)
 if n.startswith('ролики ') and any(x in n for x in ('переключател','суппорт','подшипник','направляющ')):return ''
 for key,rx,_ in TYPE_RULES:
  if rx.search(n):return key
 return ''
def expected_stems(kind):
 for key,_,stems in TYPE_RULES:
  if key==kind:return stems
 return ()
def spec_value(specs,*names):
 wanted={norm(x) for x in names}
 for k,v in specs.items():
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

def main():
 rows=load_rows();by_name=defaultdict(list);by_url=defaultdict(list);cats=Counter();types=Counter();issues=[];missing_images=0;missing_brand=0;old_price_reversed=0;zero_price=0
 for i,row in enumerate(rows):
  name=title(row);n=norm(name);path=path_list(row);ptext=norm(' '.join(path));kind=classify(name)
  if n:by_name[n].append(i)
  u=str(row.get('url') or '').strip()
  if u:by_url[u].append(i)
  leaf=path[-1] if path else 'Без категории';cats[leaf]+=1
  if kind:types[kind]+=1
  images=row.get('images') if isinstance(row.get('images'),list) else []
  if not any(str(x).strip() for x in images):missing_images+=1
  specs=row.get('specs') if isinstance(row.get('specs'),dict) else {}
  brand=str(row.get('brand') or '').strip() or spec_value(specs,'Бренд','Производитель')
  if not brand:missing_brand+=1
  price=int(row.get('price_rub') or 0);old=int(row.get('old_price_rub') or 0)
  if price<=0:zero_price+=1
  if old>0 and price>old:old_price_reversed+=1
  if kind:
   stems=expected_stems(kind)
   if stems and not any(s in ptext for s in stems):issues.append({'kind':'category_conflict','type':kind,'name':name,'category_path':path,'url':u})
  frame_value=spec_value(specs,'Ростовка рамы')
  if kind!='bicycle' and frame_value:issues.append({'kind':'suspicious_spec','type':kind or 'unknown','name':name,'spec':'Ростовка рамы','value':frame_value,'category_path':path,'url':u})
 duplicates=[{'name':title(rows[idxs[0]]),'count':len(idxs),'urls':[str(rows[x].get('url') or '') for x in idxs[:8]]} for _,idxs in by_name.items() if len(idxs)>1]
 duplicate_urls=[{'url':u,'count':len(idxs),'names':[title(rows[x]) for x in idxs[:8]]} for u,idxs in by_url.items() if len(idxs)>1]
 report={
  'generated_at':datetime.now(timezone.utc).isoformat(),
  'catalog_rows':len(rows),
  'summary':{
   'normalized_name_duplicates':len(duplicates),
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
  'duplicates':sorted(duplicates,key=lambda x:(-x['count'],x['name']))[:300],
  'duplicate_urls':sorted(duplicate_urls,key=lambda x:-x['count'])[:100]
 }
 os.makedirs(DATA,exist_ok=True)
 with open(OUT,'w',encoding='utf-8') as f:json.dump(report,f,ensure_ascii=False,indent=2);f.write('\n')
 print(json.dumps(report['summary'],ensure_ascii=False))

if __name__=='__main__':main()

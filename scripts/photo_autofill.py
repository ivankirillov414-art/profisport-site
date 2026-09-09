#!/usr/bin/env python3
import argparse,glob,html,json,os,re,sys,time,urllib.parse,urllib.request
from collections import Counter
from datetime import datetime,timezone
from html.parser import HTMLParser

ROOT=os.path.abspath(os.path.join(os.path.dirname(__file__),'..'));DATA=os.path.join(ROOT,'data');OUT=os.path.join(DATA,'photo-overrides.json')
UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126 Safari/537.36'
GENERIC={'велосипед','велосипеды','самокат','самокаты','трюковой','трюковые','подростковый','подростковые','детский','детские','горный','горные','спортивный','спортивные','модель','размер','черный','черная','черное','чёрный','чёрная','чёрное','белый','белая','серый','серая','синий','синяя','красный','красная','зеленый','зелёный','желтый','жёлтый','black','white','grey','gray','red','blue','green'}
BAD_IMAGE_WORDS=('logo','favicon','sprite','banner','placeholder','no-photo','no_photo','nophoto','blank','pixel','avatar','icon')
BAD_PAGE_WORDS=('фильм','movie','poster','обои','wallpaper','игра','game','lego','раскраска')

def norm(s):
    s=str(s or '').strip().lower().replace('ё','е');s=re.sub(r'[^a-zа-я0-9]+',' ',s,flags=re.I);return re.sub(r'\s+',' ',s).strip()
def tokens(s):return [x for x in norm(s).split() if len(x)>=3]
def distinctive(s,brand=''):
    bt=set(tokens(brand));return [x for x in tokens(s) if x not in GENERIC and x not in bt]
def numbers(s):return {x.lstrip('0') or '0' for x in re.findall(r'(?<![\w])\d+(?:[.,]\d+)?(?![\w])',str(s or ''),flags=re.U)}
def clean_text(s):return html.unescape(re.sub(r'\s+',' ',re.sub(r'<[^>]+>',' ',s or ''))).strip()
def source_url(row):
    u=str(row.get('url') or row.get('external_url') or '').strip()
    if not u:return ''
    if u.startswith('//'):return 'https:'+u
    if u.startswith('/'):return 'https://velo56.ru'+u
    return u if u.startswith(('http://','https://')) else ''
def row_name(row):return str(row.get('title') or row.get('name') or '').strip()
def row_category(row):
    p=row.get('category_path') or row.get('breadcrumbs') or []
    return ' / '.join(str(x) for x in p if str(x).strip()) if isinstance(p,list) else str(p or '')
def row_has_image(row):
    vals=[]
    for k in ('images','image','main_image'):
        v=row.get(k);vals.extend(v if isinstance(v,list) else ([v] if v else []))
    return any(str(x).strip() for x in vals)

def confidence(title,name,brand,model,category):
    tn=norm(title);nn=norm(name);bn=norm(brand);mn=norm(model)
    if not tn or not nn or any(x in tn for x in BAD_PAGE_WORDS):return 0
    target_nums=numbers(name+' '+model);cand_nums=numbers(title)
    if target_nums and cand_nums and not target_nums.intersection(cand_nums):return 0
    brand_match=bool(bn and bn in tn);model_match=bool(mn and len(mn)>=2 and mn in tn)
    if bn and not brand_match and not model_match:return 0
    td=distinctive(name+' '+model,brand);cd=set(distinctive(title,brand));common=sum(1 for x in td if x in cd);cov=common/max(1,len(td))
    score=(100 if tn==nn else 0)+(55 if nn in tn or tn in nn else 0)+(20 if brand_match else 0)+(45 if model_match else 0)+int(cov*45)
    if target_nums:score+=min(20,10*len(target_nums.intersection(cand_nums)))
    if set(distinctive(category)).intersection(set(tokens(title))):score+=5
    if model:
        if not model_match or cov<0.34:return 0
    elif cov<0.68 and tn!=nn and nn not in tn:return 0
    return min(score,100)

class MetaParser(HTMLParser):
    def __init__(self):super().__init__();self.images=[];self.title='';self._in_title=False
    def handle_starttag(self,tag,attrs):
        d={str(k).lower():str(v or '') for k,v in attrs};tag=tag.lower()
        if tag=='title':self._in_title=True
        if tag=='meta':
            key=(d.get('property') or d.get('name') or '').lower()
            if key in ('og:image','og:image:url','twitter:image','twitter:image:src') and d.get('content'):self.images.append(d['content'])
        if tag=='link' and d.get('itemprop','').lower()=='image' and d.get('href'):self.images.append(d['href'])
    def handle_endtag(self,tag):
        if tag.lower()=='title':self._in_title=False
    def handle_data(self,data):
        if self._in_title:self.title+=data

class BingImageParser(HTMLParser):
    def __init__(self):super().__init__();self.items=[]
    def handle_starttag(self,tag,attrs):
        d={str(k).lower():str(v or '') for k,v in attrs};raw=d.get('m','')
        if not raw:return
        try:j=json.loads(html.unescape(raw))
        except Exception:return
        if not isinstance(j,dict):return
        img=str(j.get('murl') or '').strip();src=str(j.get('purl') or '').strip();title=str(j.get('t') or j.get('desc') or '').strip()
        if img.startswith(('http://','https://')):self.items.append((img,src,title))

def request(url,timeout=12,limit=2_000_000):
    req=urllib.request.Request(url,headers={'User-Agent':UA,'Accept-Language':'ru-RU,ru;q=0.9,en;q=0.6','Accept':'text/html,application/xhtml+xml,*/*;q=0.8'})
    with urllib.request.urlopen(req,timeout=timeout) as r:
        raw=r.read(limit);ctype=(r.headers.get('Content-Type') or '').lower();charset='utf-8';m=re.search(r'charset=([\w-]+)',ctype)
        if m:charset=m.group(1)
        try:return raw.decode(charset,'replace'),r.geturl(),ctype
        except LookupError:return raw.decode('utf-8','replace'),r.geturl(),ctype

def image_usable(url):
    if not url.startswith(('http://','https://')) or any(x in url.lower() for x in BAD_IMAGE_WORDS):return False
    try:
        req=urllib.request.Request(url,headers={'User-Agent':UA,'Range':'bytes=0-4095','Accept':'image/avif,image/webp,image/apng,image/*,*/*;q=0.8'})
        with urllib.request.urlopen(req,timeout=10) as r:return (r.headers.get('Content-Type') or '').lower().startswith('image/')
    except Exception:return False

def page_image(url,expected_name='',brand='',model='',category='',trust_source=False):
    try:body,final,_=request(url)
    except Exception:return None
    p=MetaParser()
    try:p.feed(body)
    except Exception:pass
    title=clean_text(p.title);score=100 if trust_source else confidence(title,expected_name,brand,model,category)
    if not trust_source and score<80:return None
    for raw in p.images:
        img=urllib.parse.urljoin(final,html.unescape(raw.strip()))
        if image_usable(img):return {'image':img,'source_url':final,'source_title':title or expected_name,'confidence':score,'method':'source-page' if trust_source else 'web-page'}
    return None

def bing_image_candidate(query,name,brand,model,category):
    url='https://www.bing.com/images/search?'+urllib.parse.urlencode({'q':query,'form':'HDRSC2','first':'1','setlang':'ru'})
    try:body,_,_=request(url,15,3_500_000)
    except Exception:return None,0
    p=BingImageParser()
    try:p.feed(body)
    except Exception:pass
    seen=set();considered=0
    for img,src,title in p.items[:80]:
        if img in seen:continue
        seen.add(img);considered+=1
        score=confidence(title or src,name,brand,model,category)
        if score<82:continue
        if not image_usable(img):continue
        return {'image':img,'source_url':src,'source_title':title or name,'confidence':score,'method':'bing-images'},considered
    return None,considered

def bing_results(query,limit=8):
    url='https://www.bing.com/search?'+urllib.parse.urlencode({'q':query,'count':'12','setlang':'ru'})
    try:body,_,_=request(url)
    except Exception:return []
    out=[]
    for m in re.finditer(r'<li[^>]+class="[^"]*b_algo[^"]*".*?<h2[^>]*>\s*<a[^>]+href="([^"]+)"[^>]*>(.*?)</a>',body,re.I|re.S):
        u=html.unescape(m.group(1));t=clean_text(m.group(2))
        if u.startswith(('http://','https://')):out.append((u,t))
        if len(out)>=limit:break
    return out

def ddg_results(query,limit=8):
    url='https://html.duckduckgo.com/html/?'+urllib.parse.urlencode({'q':query})
    try:body,_,_=request(url)
    except Exception:return []
    out=[]
    for m in re.finditer(r'class="result__a"[^>]+href="([^"]+)"[^>]*>(.*?)</a>',body,re.I|re.S):
        u=html.unescape(m.group(1));t=clean_text(m.group(2));q=urllib.parse.urlparse(u)
        if 'duckduckgo.com' in q.netloc:
            real=urllib.parse.parse_qs(q.query).get('uddg',[''])[0]
            if real:u=urllib.parse.unquote(real)
        if u.startswith(('http://','https://')):out.append((u,t))
        if len(out)>=limit:break
    return out

def web_candidate(name,brand,model,category):
    query=' '.join(str(x).strip() for x in [name,category,brand,model] if str(x or '').strip())
    got,n=bing_image_candidate(query,name,brand,model,category)
    if got:return got,n
    seen=set()
    for engine in (bing_results,ddg_results):
        for u,t in engine(query):
            if u in seen:continue
            seen.add(u)
            if confidence(t,name,brand,model,category)<78:continue
            got=page_image(u,name,brand,model,category,False)
            if got:return got,n
        time.sleep(.5)
    return None,n

def load_rows():
    rows=[]
    for path in sorted(glob.glob(os.path.join(DATA,'catalog-*.json'))):
        try:
            chunk=json.load(open(path,encoding='utf-8'))
            if isinstance(chunk,list):rows.extend(x for x in chunk if isinstance(x,dict))
        except Exception as e:print('skip',path,e,file=sys.stderr)
    return rows

def load_overrides():
    if not os.path.isfile(OUT):return {'version':1,'generated_at':None,'items':{}}
    try:j=json.load(open(OUT,encoding='utf-8'))
    except Exception:return {'version':1,'generated_at':None,'items':{}}
    if not isinstance(j,dict):j={}
    if not isinstance(j.get('items'),dict):j['items']={}
    j['version']=1;return j

def main():
    ap=argparse.ArgumentParser();ap.add_argument('--max-products',type=int,default=60);ap.add_argument('--max-search',type=int,default=20);args=ap.parse_args()
    state=load_overrides();items=state['items'];rows=load_rows();existing_keys={norm(k) for k in items};image_counts=Counter()
    for v in items.values():
        if isinstance(v,dict) and v.get('image'):image_counts[str(v['image'])]+=1
        elif isinstance(v,str):image_counts[v]+=1
    pending=[]
    for r in rows:
        name=row_name(r);key=norm(name)
        if not name or not key or key in existing_keys or row_has_image(r):continue
        stock=str(r.get('availability') or r.get('stock_status') or '');qty=r.get('stock_qty');priority=2 if stock=='in_stock' else (1 if isinstance(qty,(int,float)) and qty>0 else 0)
        pending.append((priority,name,r))
    pending.sort(key=lambda x:(-x[0],x[1]))
    added=searched=source_hits=web_hits=source_attempts=bing_items=0
    for _,name,r in pending[:max(args.max_products,1)]:
        key=norm(name);brand=str(r.get('brand') or '');model=str(r.get('model') or '');cat=row_category(r);got=None;src=source_url(r)
        if src:
            source_attempts+=1;got=page_image(src,name,brand,model,cat,True)
            if got:source_hits+=1
        if not got and searched<args.max_search:
            searched+=1;got,n=web_candidate(name,brand,model,cat);bing_items+=n
            if got:web_hits+=1
        if not got:continue
        img=got['image']
        if image_counts[img]>=3:continue
        image_counts[img]+=1;items[key]={'name':name,'image':img,'source_url':got.get('source_url',''),'source_title':got.get('source_title',''),'method':got.get('method',''),'confidence':int(got.get('confidence',0))};existing_keys.add(key);added+=1
        print(f"ADD {name} -> {img}");time.sleep(.25)
    state['generated_at']=datetime.now(timezone.utc).isoformat();state['items']=dict(sorted(items.items()));state['stats']={'catalog_rows':len(rows),'pending_without_source_image':len(pending),'added_this_run':added,'source_attempts':source_attempts,'source_page_hits':source_hits,'web_search_hits':web_hits,'web_searches':searched,'bing_image_candidates_considered':bing_items,'total_overrides':len(items)}
    os.makedirs(DATA,exist_ok=True)
    with open(OUT,'w',encoding='utf-8') as f:json.dump(state,f,ensure_ascii=False,indent=2);f.write('\n')
    print(json.dumps(state['stats'],ensure_ascii=False))
if __name__=='__main__':main()

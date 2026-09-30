"""Build the reviewed Kosmosfera static copy and CMS snapshot. Requires beautifulsoup4==4.13.4."""
import argparse, json, shutil, copy, re
from pathlib import Path
from bs4 import BeautifulSoup, NavigableString

parser=argparse.ArgumentParser()
parser.add_argument('source',type=Path)
args=parser.parse_args()
root=Path(__file__).resolve().parents[1]
target=root/'sites/kosmosfera'
target.mkdir(parents=True,exist_ok=True)
for name in ['styles.css','script.js','site-config.js','favicon.svg']:
    shutil.copy2(args.source/name,target/name)
asset_sources='\n'.join((args.source/name).read_text(encoding='utf-8') for name in ['index.html','styles.css','script.js','site-config.js'])
assets=set(re.findall(r'assets/[A-Za-z0-9_./-]+',asset_sources))|{'assets/Manrope-OFL.txt','assets/Nunito-OFL.txt'}
(target/'assets').mkdir(exist_ok=True)
for name in sorted(assets):
    shutil.copy2(args.source/name,target/name)
for old in (target/'assets').iterdir():
    if old.is_file() and old.relative_to(target).as_posix() not in assets:old.unlink()
soup=BeautifulSoup((args.source/'index.html').read_text(encoding='utf-8'),'html.parser')
main=soup.select_one('main')
main['data-cms-root']=''
# Keep styled heading fragments and explicit line breaks when editing individual text runs.
for heading in main.select('h1,h2,h3'):
    if heading.find():
        for child in list(heading.children):
            if isinstance(child,NavigableString) and child.strip():
                span=soup.new_tag('span');span.string=str(child);child.replace_with(span)
fields=[]
for node in main.select('h1,h2,h3,h4,p,summary,figcaption,strong,h1 span,h2 span,h3 span,img'):
    if node.name!='img' and (node.find() or not node.get_text(strip=True)):continue
    text=node.get_text(' ',strip=True)
    # Ticket amounts and dates remain coupled to site-config.js and its calculator.
    if node.name!='img' and (re.search(r'\d|₽',text) or node.find_parent(class_='price')):continue
    ident='k'+str(len(fields)+1)
    node['data-id-cms']=ident
    section=node.find_parent('section')
    group=section.get('id','Главная') if section else 'Главная'
    for kind,value in ([('image',node.get('src','')),('alt',node.get('alt',''))] if node.name=='img' else [('text',text)]):
        if kind=='image' and not value:continue
        fid=ident+'-'+kind
        fields.append(dict(id=fid,selector='[data-id-cms="'+ident+'"]',kind=kind,value=value,label=(value[:90] if kind=='text' else ('Изображение' if kind=='image' else 'Описание изображения')),group=group))
        node['data-cms-fields']=','.join(filter(None,[node.get('data-cms-fields'),fid]))
blocks=[];sections=[]
for i,node in enumerate(main.find_all(recursive=False)):
    if not node.get('id'):node['id']='kosmo-section-'+str(i)
    title=node.find(['h1','h2','h3'])
    label=title.get_text(' ',strip=True)[:80] if title else 'Раздел '+str(i+1)
    bid='section'+str(i)
    blocks.append(dict(id=bid,selector='#'+node['id'],label=label,visible=True))
    snapshot=copy.deepcopy(node)
    for bad in snapshot.select('script,iframe,object,embed,style,link'):bad.decompose()
    for el in [snapshot,*snapshot.find_all()]:
        for attr in list(el.attrs):
            if attr.lower().startswith('on') or attr.lower() in ['srcdoc','autofocus']:del el[attr]
    sections.append(dict(id=bid,selector='#'+node['id'],label=label,legacy=bid,html=str(snapshot)))
tag=soup.new_tag('script',src='../../connector.js',defer=True)
tag['data-cms-endpoint']='/cms/api.php';tag['data-cms-site']='kosmosfera';tag['data-cms-page']='index.html'
soup.head.append(tag)
(target/'index.html').write_text(str(soup),encoding='utf-8')
script=(target/'script.js').read_text(encoding='utf-8')
script=script.replace('picture.src = button.dataset.gallery; picture.alt = button.dataset.galleryCaption;',"const current = button.querySelector('img');\n    picture.src = current?.getAttribute('src') || button.dataset.gallery; picture.alt = current?.getAttribute('alt') || button.dataset.galleryCaption;")
script=script.replace("document.querySelector('#gallery-caption').textContent = button.dataset.galleryCaption;","document.querySelector('#gallery-caption').textContent = picture.alt;")
(target/'script.js').write_text(script,encoding='utf-8')
manifest=dict(site='kosmosfera',pages={'index.html':dict(title='Космопорт / Космосфера',fields=fields,blocks=blocks)})
templates={'index.html':dict(styles=['styles.css'],css='',bodyClass=soup.body.get('class','') if isinstance(soup.body.get('class',''),str) else ' '.join(soup.body['class']),mainClass=' '.join(main.get('class',[])),defaults={f['id']:f['value'] for f in fields},sections=sections)}
(root/'private/connectors').mkdir(exist_ok=True)
(root/'private/connectors/kosmosfera.json').write_text(json.dumps(dict(name='Космопорт / Космосфера',manifest=manifest,templates=templates),ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
print(f'Kosmosfera: {len(fields)} fields, {len(sections)} sections')

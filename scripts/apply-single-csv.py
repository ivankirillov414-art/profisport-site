"""Bounded source migration for the single-CSV uploader. No business data access."""
from pathlib import Path

def edit(path, pairs):
    p=Path(path);s=p.read_text()
    for old,new in pairs:
        if old in s:s=s.replace(old,new)
        elif new not in s:raise RuntimeError('Source changed: '+path+' / '+old[:100])
    p.write_text(s)

def line(path,prefix,new):
    p=Path(path);lines=p.read_text().splitlines(True);hits=[i for i,s in enumerate(lines) if s.startswith(prefix)]
    if len(hits)!=1:raise RuntimeError('Source changed: '+path+' '+prefix)
    lines[hits[0]]=new+'\n';p.write_text(''.join(lines))

edit('server/import-upload.php',[
    ("$allowed=['csv','xlsx','xls','jpg','jpeg','png','webp','gif','avif'];", "$allowed=['csv','jpg','jpeg','png','webp','gif','avif'];"),
    ("$safe[]=$part;", "if(preg_match('/\\.(php[0-9]*|phtml|phar|cgi|shtml)(\\.|$)/i',$part))throw new InvalidArgumentException('Недопустимое имя файла.');\n        $safe[]=$part;"),
    ("$files=[];$products=false;$categories=false;$images=0;$bytes=0;", "$files=[];$csv=[];$images=0;$bytes=0;"),
    ("if($base==='tovary.csv')$products=true;\n        if($base==='categories.csv')$categories=true;", "if($ext==='csv')$csv[]=$final;"),
    ("return ['files'=>$files,'products'=>$products,'categories'=>$categories,'images'=>$images,'bytes'=>$bytes];", "return ['files'=>$files,'csv'=>$csv,'images'=>$images,'bytes'=>$bytes];"),
    ("if(!$summary['products']||!$summary['categories'])throw new RuntimeException('Нужны две CSV-таблицы: товары/сводка и категории.');", "if(count($summary['csv'])!==1)throw new RuntimeException('Нужна ровно одна CSV-таблица с товарами. Обновите страницу, если видите два поля таблиц.');"),
    ("$target=$stage.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$file['final']);", "$relative=strtolower(pathinfo($file['final'],PATHINFO_EXTENSION))==='csv'?'Tovary.csv':$file['final'];\n        $target=$stage.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);"),
    ("$manual=rtrim($importRoot,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'manual';", "touch($stage.DIRECTORY_SEPARATOR.'Tovary.csv');\n    $manual=rtrim($importRoot,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'manual';")
])
p=Path('server/import-upload.php');s=p.read_text()
if 'function import_upload_limits' not in s:
    s+='''
/** Respect the hosting limits instead of assuming one large multipart request. */
function import_upload_limits(): array {
    $bytes=static function(string $value): int {
        $value=trim($value);$n=(float)$value;
        if($n<=0)return 64*1024*1024;
        $suffix=strtolower(substr($value,-1));$factor=['k'=>1024,'m'=>1048576,'g'=>1073741824][$suffix]??1;
        return (int)min(64*1024*1024,$n*$factor);
    };
    $post=max(1,min(24*1024*1024,$bytes((string)ini_get('post_max_size')))-65536);
    return ['max_file_bytes'=>min($post,$bytes((string)ini_get('upload_max_filesize'))),'max_batch_bytes'=>$post,'max_files'=>max(1,min(20,(int)ini_get('max_file_uploads')?:20))];
}
'''
    p.write_text(s)
edit('api/import-upload.php',[
    ("upload_out(['ok'=>true,'batch'=>$batch]);", "upload_out(['ok'=>true,'batch'=>$batch,'limits'=>import_upload_limits()]);"),
    ("$limit=import_upload_is_image($relative)?25*1024*1024:64*1024*1024;", "$limit=import_upload_limits()['max_file_bytes'];"),
    ("$chunkBytes>24*1024*1024", "$chunkBytes>import_upload_limits()['max_batch_bytes']")
])
edit('api/import-apply.php',[
    ("require __DIR__.'/../server/import-safety.php';", "require __DIR__.'/../server/import-safety.php';\nrequire __DIR__.'/../server/import-single-csv.php';"),
    ("if(!$pf||!$cf)throw new RuntimeException('1c_csv_missing');", "if(!$pf)throw new RuntimeException('Загрузите CSV-таблицу с товарами.');"),
    ("$latestSourceMtime=max((int)filemtime($pf),(int)filemtime($cf));", "$latestSourceMtime=max((int)filemtime($pf),$cf?(int)filemtime($cf):0);"),
    ("$r=rows($pf);$catsRaw=rows($cf);", "$r=rows($pf);$catsRaw=$cf?rows($cf):[];"),
    ("[$img,$imageFiles]=imageIndex($root,$offset);", "[$img,$imageFiles]=imageIndex($root,$offset,dirname($pf));"),
    ("$category=$catPath((string)($rr[$map['category']]??''));", "$category=single_csv_category((string)($rr[$map['category']??-1]??''),$catPath);"),
    ("'categories_file'=>basename($cf)", "'categories_file'=>$cf?basename($cf):null")
])
line('api/import-apply.php','function rows(',"function rows(string $p):array{return single_csv_rows($p);}")
line('api/import-apply.php','function sourceSnapshot(',"function sourceSnapshot(string $products,?string $categories):string{return single_csv_snapshot($products,$categories);}")
# Preserve unprovided categories of existing products. Numeric IDs are only resolved from an existing category dictionary.
edit('api/import-apply.php',[
    ("$final=array_values(array_unique(array_filter(array_merge($urls,$oldImgs,$oldMain!==''?[$oldMain]:[]),fn($v)=>trim((string)$v)!=='')));$main=$oldMain!==''?$oldMain:($final[0]??null);", "$photoSet=single_csv_merge_photos($urls,$oldImgs,$oldMain);$final=$photoSet['images'];$main=$photoSet['main'];"),
    ("function imageIndex(string $root,int $offset):array", "function imageIndex(string $root,int $offset,string $preferred=''):array"),
    ("if(is_array($j)&&isset($j['map']))return[$j['map']", "if(is_array($j)&&isset($j['map'])&&($j['source_dir']??'')===$preferred)return[$j['map']"),
    ("$map=[];$mtimes=[];$count=0;", "$map=[];$mtimes=[];$priorities=[];$count=0;"),
    ("$mtime=$f->getMTime();if(!isset($mtimes[$key])||$mtime>=$mtimes[$key]){$mtimes[$key]=$mtime;", "$mtime=$f->getMTime();$priority=$preferred!==''&&str_starts_with($normalizedPath,str_replace(DIRECTORY_SEPARATOR,'/',$preferred).'/')?1:0;if(!isset($mtimes[$key])||$priority>$priorities[$key]||($priority===$priorities[$key]&&$mtime>=$mtimes[$key])){$mtimes[$key]=$mtime;$priorities[$key]=$priority;"),
    ("json_encode(['count'=>$count,'map'=>$map]", "json_encode(['count'=>$count,'map'=>$map,'source_dir'=>$preferred]")
])
# Include the uploader directly: it must not depend on the dashboard's dynamic loader.
edit('admin/app.js',[("const uploadScript=document.createElement('script');uploadScript.src='import-upload.js?v=1';uploadScript.defer=true;document.head.appendChild(uploadScript);", "// The single-CSV uploader is included directly by admin/index.php.")])
edit('admin/index.php',[
    ('таблицу товаров, таблицу категорий и папку изображений','одну CSV-таблицу с любым названием и выбранные файлы фотографий'),
    ('<b>Каталог из 1С</b><span>Импорт и обновление товаров</span>','<b>Загрузить обновление</b><span>Одна CSV-таблица и фотографии</span>'),
    ('<script src="app.js?v=16"></script>','<script src="app.js?v=18"></script><script src="import-upload.js?v=single-csv-2"></script>')
])
# The source file may be complete, yet another import still holds the lock: never report that as success.
edit('admin/import-run.js',[("snapshot=d.snapshot||snapshot;", "if(d.busy)throw new Error('Каталог уже обновляется. Дождитесь завершения текущей операции.');if(d.unchanged){msg.className='msg ok';msg.textContent='Эта версия каталога уже применена.';return;}snapshot=d.snapshot||snapshot;")])
# New focused tests run in both the normal PR checks and deployment preflight.
for path in ['.github/workflows/store-tests.yml','.github/workflows/deploy-infinityfree.yml']:
    edit(path,[('node --check admin/orders.js','node --check admin/app.js\n          node --check admin/import-upload.js\n          node --check admin/orders.js'),('php tests/order-validation.php','php tests/import-upload.php\n          php tests/import-single-csv.php\n          php tests/order-validation.php')])
    # Only the disposable database; no production endpoint writes in tests.
    edit(path,[("if [ '${{ matrix.schema }}' = legacy ]; then php tests/legacy-order-writes.php; fi", "if [ '${{ matrix.schema }}' = legacy ]; then php tests/legacy-order-writes.php; fi\n          python3 tests/import-single-csv-http.py")])
# Add new runtime source files to the existing authenticated FTP byte verification list.
edit('.github/workflows/deploy-infinityfree.yml',[("admin/customer-qr.php; do", "admin/customer-qr.php admin/import-upload.js api/import-upload.php server/import-upload.php server/import-single-csv.php; do")])
print('Single CSV + ordinary multiple-image selection applied. No production data changed.')

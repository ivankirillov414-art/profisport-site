from pathlib import Path
p=Path('server/import-upload.php');s=p.read_text()
old="touch($stage.DIRECTORY_SEPARATOR.'Tovary.csv');"
new="""touch($stage.DIRECTORY_SEPARATOR.'Tovary.csv');
    if(file_put_contents($stage.DIRECTORY_SEPARATOR.'.upload.json',json_encode(['completed_at'=>microtime(true)]),LOCK_EX)===false)throw new RuntimeException('Не удалось отметить завершение загрузки.');"""
if new not in s:
    if old not in s:raise RuntimeError('Upload finalizer changed')
    p.write_text(s.replace(old,new))
p=Path('server/import-single-csv.php');s=p.read_text()
if 'function single_csv_source_mtime' not in s:
    s+='''
/** Same-second uploads must not select an older, larger CSV by file size. */
function single_csv_source_mtime(string $path): float {
    $mtime=(float)filemtime($path);
    $dir=str_replace('\\\\','/',dirname($path));
    if(!preg_match('~/manual/[^/]+$~',$dir))return $mtime;
    $marker=$dir.'/.upload.json';
    if(!is_file($marker))return $mtime;
    $data=json_decode((string)file_get_contents($marker),true);
    $completed=$data['completed_at']??0;
    return is_numeric($completed)?max($mtime,(float)$completed):$mtime;
}
'''
    p.write_text(s)
p=Path('api/import-apply.php');s=p.read_text();old="'m'=>$f->getMTime()";new="'m'=>single_csv_source_mtime($f->getPathname())"
if old in s:p.write_text(s.replace(old,new))
elif new not in s:raise RuntimeError('CSV selector changed')
print('Completed batch timestamps applied; older large files cannot shadow a new upload')

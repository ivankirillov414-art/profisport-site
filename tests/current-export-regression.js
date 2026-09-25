const fs=require('fs');
const path=require('path');
const root=path.resolve(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const assert=require('node:assert/strict');

const importer=read('api/import-apply.php');
const catalog=read('api/catalog.php');
const run=read('admin/import-run.js');
const apply=read('admin/import-apply.js');

assert.match(importer,/usort\(\$a,fn\(\$x,\$y\)=>\(\$y\['m'\]<=>\$x\['m'\]\)/,'newest 1C file must be selected first');
assert.match(importer,/foreach\(\['source_id','name','price','stock'\] as \$requiredField\)/,'header import must require identity, name, price and stock');
assert.match(importer,/\$requestedSnapshot=.*snapshot/,'import must accept a pinned snapshot');
assert.match(importer,/function exportSnapshot\(/,'snapshot must represent the whole export, not only the product CSV');
assert.match(importer,/hash_update_file\(\$ctx,\$cf\)/,'category file must participate in snapshot detection');
assert.match(importer,/\$f->getMTime\(\)/,'photo-file metadata must participate in snapshot detection');
assert.match(importer,/hash_equals\(\$requestedSnapshot,\$snapshot\)/,'each batch must verify the same source snapshot');
assert.match(importer,/\$active=\$q>0\?1:0/,'zero stock must be inactive at row import');
assert.match(importer,/catalog_snapshot<>\?/,'products absent from the current export must be hidden');
assert.match(importer,/catalog_snapshot=\? AND COALESCE\(stock_qty,0\)<=0/,'current-export zero stock must be hidden defensively');
assert.match(importer,/\$staleActive!==0\|\|\$zeroActive!==0/,'finalization must verify no stale or zero-stock active rows remain');
assert.match(importer,/current_1c_snapshot/,'successful import must record the authoritative snapshot');
assert.match(catalog,/is_active=1 AND COALESCE\(stock_qty,0\)>0/,'public catalog must directly reject zero-stock rows');
assert.match(run,/snapshotParam=snapshot/,'main admin importer must pin all batches to one snapshot');
assert.match(apply,/snapshotParam=snapshot/,'secondary admin importer must pin all batches to one snapshot');
assert.match(run,/Новая выгрузка стала основной базой каталога/,'admin must report successful source-of-truth activation');

console.log('Current 1C export source-of-truth regression checks passed.');

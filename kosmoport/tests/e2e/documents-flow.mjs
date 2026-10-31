import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import {Session,Blocked} from './harness.mjs';
function pdf(label){
 const content='BT /F1 12 Tf 10 30 Td ('+label+') Tj ET';
 const objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 100] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>','<< /Length '+Buffer.byteLength(content)+' >>\nstream\n'+content+'\nendstream','<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
 let text='%PDF-1.4\n',offsets=[0];for(let n=0;n<objects.length;n++){offsets.push(Buffer.byteLength(text));text+=(n+1)+' 0 obj\n'+objects[n]+'\nendobj\n';}const xref=Buffer.byteLength(text);text+='xref\n0 '+(objects.length+1)+'\n0000000000 65535 f \n'+offsets.slice(1).map(n=>String(n).padStart(10,'0')+' 00000 n \n').join('')+'trailer\n<< /Size '+(objects.length+1)+' /Root 1 0 R >>\nstartxref\n'+xref+'\n%%EOF\n';return Buffer.from(text);
}
function upload(bytes,name,fields={}){const data=new FormData();data.append('file',new Blob([bytes],{type:'application/pdf'}),name);for(const [key,value]of Object.entries(fields))data.append(key,String(value));return data;}
export async function verify(context,report){
 const owner=context.session,first=pdf('QA generated v1'),second=pdf('QA generated v2');let id;
 const created=await report.check('documents: genuine multipart PDF upload and protected exact-byte download',async()=>{
  const result=await owner.ok('documents/upload',{method:'POST',multipart:true,body:upload(first,'qa-v1.pdf',{title:'QA Документ '+context.id,category:'QA'}),expected:201});id=result.document?.id;assert(id,'upload response document id missing');context.document={id,title:result.document.title,version1_sha256:crypto.createHash('sha256').update(first).digest('hex'),version2_sha256:crypto.createHash('sha256').update(second).digest('hex')};
  const list=await owner.ok('documents');assert(list.items.some(x=>x.id===id));assert(!/storage_key|storage_path|\.blob|password_hash/.test(JSON.stringify(list)),'private storage metadata leaked');
  const download=await owner.request('documents/download',{query:{id},expected:200});assert.deepEqual(download.bytes,first);assert.match(download.headers.get('content-type'),/application\/pdf/);assert.match(download.headers.get('content-disposition'),/attachment/i);return {document_id:id,sha256:crypto.createHash('sha256').update(first).digest('hex'),exact_download:true};
 });
 if(!created){await report.check('document dependent version/access scenarios',()=>{throw new Blocked('First upload failed');});return;}
 await report.check('documents: immutable version 1, version 2 and rejected stale version',async()=>{
  await owner.ok('documents/upload',{method:'POST',multipart:true,body:upload(second,'qa-v2.pdf',{document_id:id,expected_version:1}),expected:201});
  const versions=await owner.ok('documents/versions',{query:{id}});assert.equal(versions.items.length,2);assert.deepEqual(versions.items.map(x=>Number(x.version)).sort(),[1,2]);
  assert.deepEqual((await owner.request('documents/download',{query:{id,version:1},expected:200})).bytes,first);
  assert.deepEqual((await owner.request('documents/download',{query:{id,version:2},expected:200})).bytes,second);
  await owner.request('documents/upload',{method:'POST',multipart:true,body:upload(second,'stale.pdf',{document_id:id,expected_version:1}),expected:409});
  assert.equal((await owner.ok('documents/versions',{query:{id}})).items.length,2);return {versions:2,old_bytes_preserved:true};
 });
 await report.check('documents: unauthorized download, traversal, type and size errors',async()=>{
  const anonymous=new Session(context.base_url);await anonymous.request('documents/download',{query:{id},expected:401});
  if(!context.cashier)throw new Blocked('Cashier account unavailable');await context.cashier.request('documents/download',{query:{id},expected:403});
  await owner.request('documents/download',{query:{id:'../config/config.php'},expected:422});await owner.request('documents/download',{query:{id,version:0},expected:422});
  const before=(await owner.ok('documents')).total;
  await owner.request('documents/upload',{method:'POST',multipart:true,body:upload(first,'payload.php',{title:'QA rejected',category:'QA'}),expected:422});
  await owner.request('documents/upload',{method:'POST',multipart:true,body:upload(Buffer.from('not a PDF'),'wrong.pdf',{title:'QA rejected',category:'QA'}),expected:422});
  const end=first.lastIndexOf('%%EOF'),oversize=Buffer.concat([first.subarray(0,end),Buffer.alloc(1024*1024,32),first.subarray(end)]);
  await owner.request('documents/upload',{method:'POST',multipart:true,body:upload(oversize,'large.pdf',{title:'QA rejected',category:'QA'}),expected:413});
  assert.equal((await owner.ok('documents')).total,before);return {anonymous:401,cashier:403,rejected_uploads_leave_no_document:true};
 });
}

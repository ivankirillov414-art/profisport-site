import assert from 'node:assert/strict';
import {Session,Blocked} from './harness.mjs';
export async function verify(context,report){
 const owner=context.session,publicSession=new Session(context.base_url);let saved,marker;
 const ready=await report.check('website: private draft, actual published content and stale-save conflict',async()=>{
  const before=await publicSession.ok('website/content'),draft=await owner.ok('website/draft');assert(draft.content&&Number.isInteger(Number(draft.revision)));
  assert.equal(draft.content.event.opening_date,'2026-11-24');assert.equal(draft.content.event.closing_date,'2027-01-18');
  const content=structuredClone(draft.content);marker='QA Космопорт '+context.id;assert.equal(typeof content.brand,'string');content.brand=marker;
  saved=await owner.ok('website/save',{method:'POST',body:{content,expected_revision:draft.revision}});assert.equal(saved.content.brand,marker);
  assert.equal((await publicSession.ok('website/content')).content.brand,before.content.brand,'draft leaked before publication');
  await owner.request('website/save',{method:'POST',body:{content,expected_revision:draft.revision},expected:409});
  const server=await owner.ok('website/draft');assert.equal(server.revision,saved.revision);context.website={marker,content:saved.content};return {draft_revision:saved.revision,conflict:409,published_unchanged:true};
 });
 if(!ready){await report.check('website dependent publication scenario',()=>{throw new Blocked('Draft save failed');});return;}
 await report.check('website: reviewed publish serves new text; stale publish and HTML rejected',async()=>{
  const published=await owner.ok('website/publish',{method:'POST',body:{expected_revision:saved.revision}});assert(Number(published.revision)>Number(saved.revision));
  const current=await publicSession.ok('website/content');assert.equal(current.content.brand,marker);assert.equal(current.revision,published.revision);
  const html=await publicSession.page('');assert.equal(html.status,200);assert(html.text.includes(marker),'actual public website did not serve published brand');
  await owner.request('website/publish',{method:'POST',body:{expected_revision:saved.revision},expected:409});
  const malicious=structuredClone(saved.content);malicious.brand='<script>alert(1)</script>';await owner.request('website/save',{method:'POST',body:{content:malicious,expected_revision:published.revision},expected:422});
  assert.equal((await publicSession.ok('website/content')).content.brand,marker);return {published_revision:published.revision,public_html_readback:true};
 });
}

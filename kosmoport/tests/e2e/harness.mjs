import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

export function localBase(value) {
  const url = new URL(value);
  assert.equal(url.protocol, 'http:', 'QA only uses an isolated HTTP loopback server');
  assert(['127.0.0.1', 'localhost', '[::1]'].includes(url.hostname), 'Refusing a non-loopback QA target');
  assert(!url.username && !url.password && !url.search && !url.hash, 'Unexpected target credentials or query');
  assert(url.port, 'An explicit disposable-server port is required');
  return new URL(url.href.replace(/\/?$/, '/'));
}

export class Session {
  constructor(base) { this.base = localBase(base); this.cookies = new Map(); this.csrf = ''; this.calls = []; }
  async request(route, {method = 'GET', body, query = {}, csrf = this.csrf, expected, multipart = false, extraHeaders = {}} = {}) {
    const url = new URL('api/index.php', this.base); url.searchParams.set('r', route);
    for (const [key, value] of Object.entries(query)) if (value !== undefined) url.searchParams.set(key, String(value));
    const headers = {Accept: 'application/json', ...extraHeaders};
    if (this.cookies.size) headers.Cookie = [...this.cookies].map(([k,v]) => k+'='+v).join('; ');
    if (method !== 'GET' && csrf) headers['X-CSRF-Token'] = csrf;
    if (body !== undefined && !multipart) headers['Content-Type'] = 'application/json';
    const response = await fetch(url, {method, headers, body: body === undefined ? undefined : multipart ? body : JSON.stringify(body), redirect: 'manual', signal: AbortSignal.timeout(15000)});
    for (const cookie of response.headers.getSetCookie()) {
      const pair = cookie.split(';', 1)[0], index = pair.indexOf('=');
      this.cookies.set(pair.slice(0,index), pair.slice(index+1));
    }
    assert(!response.headers.has('access-control-allow-origin'), 'Same-origin API must not open CORS');
    assert(!response.headers.has('location'), 'Unexpected API redirect; no authenticated cross-origin follow');
    const bytes = Buffer.from(await response.arrayBuffer());
    const jsonType = /application\/json/i.test(response.headers.get('content-type') || '');
    let json = null;
    if (jsonType) { try { json = JSON.parse(bytes.toString()); } catch { throw Error(route+': invalid JSON response'); } }
    this.calls.push({route, method, status: response.status});
    if (json?.data?.csrf) this.csrf = json.data.csrf;
    if (expected !== undefined) assert([expected].flat().includes(response.status), `${method} ${route}: HTTP ${response.status}, expected ${expected}; ${json?.error?.code || ''}`);
    return {status: response.status, headers: response.headers, bytes, json, data: json?.data};
  }
  async ok(route, options = {}) { const r = await this.request(route, {...options, expected: options.expected ?? 200}); assert.equal(r.json?.ok, true, route+': '+r.json?.error?.message); return r.data; }
  async login(email, password) { await this.ok('auth/me'); return this.ok('auth/login', {method: 'POST', body: {email,password}}); }
  async page(relative, {method='GET', form, csrf=this.csrf} = {}) {
    const url=new URL(relative,this.base);
    assert.equal(url.origin,this.base.origin,'No cross-origin page request');
    assert(url.pathname.startsWith(this.base.pathname),'No escape from test mount');
    const headers={Cookie:[...this.cookies].map(([k,v])=>k+'='+v).join('; ')};
    if(method!=='GET'&&csrf)headers['X-CSRF-Token']=csrf;
    const response=await fetch(url,{method,headers,body:form?new URLSearchParams(form):undefined,redirect:'manual',signal:AbortSignal.timeout(20000)});
    for(const cookie of response.headers.getSetCookie()){const pair=cookie.split(';',1)[0],index=pair.indexOf('=');this.cookies.set(pair.slice(0,index),pair.slice(index+1));}
    return {status:response.status,headers:response.headers,text:await response.text()};
  }
}

export class Blocked extends Error {}
export class Report {
  constructor(file) { this.file = file; this.started = new Date().toISOString(); this.cases = []; }
  async check(name, operation) {
    const start = Date.now(); let status = 'pass', detail;
    try { detail = await operation(); } catch (e) { status = e instanceof Blocked ? 'blocked' : 'fail'; detail = String(e.message); }
    this.cases.push({name,status,duration_ms:Date.now()-start,detail}); this.save();
    process.stdout.write(`${status.toUpperCase()} ${name}\n`); return status === 'pass';
  }
  save(extra = {}) {
    fs.mkdirSync(path.dirname(this.file), {recursive:true});
    const report = {started_at:this.started,updated_at:new Date().toISOString(),scope:'Disposable local CRM only. No real scanner, register, paper or production-host evidence.',cases:this.cases,...extra};
    fs.writeFileSync(this.file,JSON.stringify(report,null,2)+'\n');
  }
  get exitCode() { return this.cases.some(x=>x.status==='fail') ? 1 : this.cases.some(x=>x.status==='blocked') ? 2 : 0; }
}

export function fixture(file) {
  const data = JSON.parse(fs.readFileSync(file,'utf8'));
  assert.equal(data.marker,'KCRM_QA_DISPOSABLE_V1','Fixture marker missing');
  localBase(data.base_url);
  assert(/^kcrmqa_[a-z0-9_]+$/.test(data.database.name),'Fixture database must have the kcrmqa_ prefix');
  assert.equal(data.environment,'test');
  return data;
}

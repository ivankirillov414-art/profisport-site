const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const api = fs.readFileSync(path.join(root, 'server', 'api.php'), 'utf8');
const center = fs.readFileSync(path.join(root, 'server', 'loyalty-center.php'), 'utf8');

function assert(condition, message) {
  if (!condition) {
    console.error(message);
    process.exit(1);
  }
}

function walk(dir) {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (['.git', 'assets', 'uploads', 'cms/vendor'].some((skip) => full.includes(skip))) return [];
      return walk(full);
    }
    return /\.(php|js|yml|yaml)$/i.test(entry.name) ? [full] : [];
  });
}

function legacyHelperCallers(helperName) {
  const helperCall = new RegExp(`\\b${helperName}\\s*\\(`);
  return walk(root)
    .map((file) => ({ file, rel: path.relative(root, file).replace(/\\/g, '/'), text: fs.readFileSync(file, 'utf8') }))
    .filter(({ rel }) => !['server/loyalty.php', 'tests/loyalty-settings-route-regression.js'].includes(rel))
    .filter(({ text }) => helperCall.test(text))
    .map(({ rel }) => rel);
}

assert(api.includes('function admin_setting_protected'), 'server/api.php must define a protected-settings guard.');
assert(api.includes("str_starts_with($key, 'loyalty_')"), 'Generic settings API must protect loyalty_* keys.');
assert(api.includes("'protected_settings_hidden'=>true"), 'Generic settings GET must hide protected loyalty settings.');
assert(api.includes("'protected_settings'"), 'Generic settings POST must reject protected loyalty keys.');
assert(api.includes('settings_save_blocked'), 'Blocked settings writes must be audited.');
assert(center.includes('function loyalty_center_publish'), 'The live publish route must stay in server/loyalty-center.php.');
assert(center.includes('loyalty_center_verify_admin_password'), 'Publishing must keep current-password verification.');

const savePosition = api.indexOf("if($action==='settings_save'");
const blockedPosition = api.indexOf('if($blocked)', savePosition);
const writePosition = api.indexOf('INSERT INTO site_settings', savePosition);
assert(savePosition !== -1 && blockedPosition !== -1 && writePosition !== -1, 'settings_save branch, blocked check, and site_settings write must all exist.');
assert(blockedPosition < writePosition, 'Protected setting validation must run before the generic site_settings write.');

for (const helper of ['loyalty_save_draft', 'loyalty_set_program_enabled']) {
  const callers = legacyHelperCallers(helper);
  assert(callers.length === 0, `${helper} must not be used outside server/loyalty.php; use admin/loyalty.php + api/loyalty-admin.php instead. Found: ${callers.join(', ')}`);
}

console.log('Loyalty settings route regression passed');

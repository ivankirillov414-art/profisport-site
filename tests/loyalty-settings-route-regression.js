const fs = require('fs');
const path = require('path');

const api = fs.readFileSync(path.join(__dirname, '..', 'server', 'api.php'), 'utf8');

function assert(condition, message) {
  if (!condition) {
    console.error(message);
    process.exit(1);
  }
}

assert(api.includes('function admin_setting_protected'), 'server/api.php must define a protected-settings guard.');
assert(api.includes("str_starts_with($key, 'loyalty_')"), 'Generic settings API must protect loyalty_* keys.');
assert(api.includes("'protected_settings_hidden'=>true"), 'Generic settings GET must hide protected loyalty settings.');
assert(api.includes("'protected_settings'"), 'Generic settings POST must reject protected loyalty keys.');
assert(api.includes('settings_save_blocked'), 'Blocked settings writes must be audited.');

const savePosition = api.indexOf("if($action==='settings_save'");
const blockedPosition = api.indexOf('if($blocked)', savePosition);
const writePosition = api.indexOf('INSERT INTO site_settings', savePosition);
assert(savePosition !== -1 && blockedPosition !== -1 && writePosition !== -1, 'settings_save branch, blocked check, and site_settings write must all exist.');
assert(blockedPosition < writePosition, 'Protected setting validation must run before the generic site_settings write.');

console.log('Loyalty settings route regression passed');

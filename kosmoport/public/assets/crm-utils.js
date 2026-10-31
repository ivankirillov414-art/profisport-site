export const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
export const number = value => Number.isFinite(Number(value)) && value !== null && value !== '' ? new Intl.NumberFormat('ru-RU').format(Number(value)) : '—';
export function date(value, time = false) {
  if (!value) return '—';
  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value.split('-').reverse().join('.');
  const normalized = /^\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d$/.test(value) ? value.replace(' ', 'T') + 'Z' : value;
  const parsed = new Date(normalized);
  if (Number.isNaN(parsed.valueOf())) return '—';
  return new Intl.DateTimeFormat('ru-RU', {timeZone:'Asia/Yekaterinburg', day:'2-digit',month:'2-digit',year:'numeric', ...(time ? {hour:'2-digit',minute:'2-digit'} : {})}).format(parsed);
}
export function filename(disposition, fallback = 'document') {
  const encoded = disposition.match(/filename\*=UTF-8''([^;]+)/i);
  let name;
  try { name = encoded ? decodeURIComponent(encoded[1]) : disposition.match(/filename="([^"]+)"/i)?.[1]; } catch {}
  return (name || fallback).replace(/[\\/\x00-\x1f]/g, '_');
}
export const items = data => Array.isArray(data?.items) ? data.items : [];
export const sourceUsed = source => source?.used_invitations ?? source?.used ?? source?.count;
export function objectJson(value) {
  const parsed = JSON.parse(value);
  if (!parsed || Array.isArray(parsed) || typeof parsed !== 'object') throw new Error('Контент должен быть JSON-объектом.');
  return parsed;
}
export function canAdmit(data) {
  if (!data) return false;
  const state = data.state ?? data.status ?? data.ticket?.status;
  if (['admitted','used','blocked','cancelled','expired','revoked'].includes(state)) return false;
  if (typeof data.can_admit === 'boolean') return data.can_admit;
  return data.valid === true || ['valid','ready','available'].includes(state);
}

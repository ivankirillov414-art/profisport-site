export class ApiError extends Error {
  constructor(message, status = 0, code = '', details = null) {
    super(message); this.name = 'ApiError'; this.status = status; this.code = code; this.details = details;
  }
}

export function createApi(endpoint, transport = globalThis.fetch.bind(globalThis)) {
  const base = new URL(endpoint); let csrf = '';
  function url(route, query = {}) {
    const target = new URL(base); target.searchParams.set('r', route);
    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== null && value !== '') target.searchParams.set(key, String(value));
    }
    return target.href;
  }
  async function request(route, {method = 'GET', body, query, binary = false, signal} = {}) {
    const headers = {Accept: binary ? '*/*' : 'application/json'};
    const multipart = typeof FormData !== 'undefined' && body instanceof FormData;
    if (method !== 'GET') {
      if (!csrf) throw new ApiError('Сессия не готова. Обновите страницу и войдите снова.', 419, 'CSRF_MISSING');
      headers['X-CSRF-Token'] = csrf;
      if (!multipart) headers['Content-Type'] = 'application/json';
    }
    let response;
    try {
      response = await transport(url(route, query), {
        method, headers, credentials: 'same-origin', cache: 'no-store',
        body: body === undefined ? undefined : multipart ? body : JSON.stringify(body),
        signal: signal ?? AbortSignal.timeout(25000),
      });
    } catch (error) {
      if (signal?.aborted) throw error;
      throw new ApiError('Нет связи с сервером. Проверьте подключение и повторите действие.');
    }
    if (binary && response.ok && /attachment/i.test(response.headers.get('content-disposition') || '')) {
      return {blob: await response.blob(), disposition: response.headers.get('content-disposition') || ''};
    }
    let envelope;
    try { envelope = await response.json(); }
    catch {
      throw new ApiError(response.status === 413 ? 'Файл слишком большой для сервера.' : 'Сервер вернул неожиданный ответ. Проверьте настройку CRM и повторите попытку.', response.status, 'INVALID_RESPONSE');
    }
    if (!response.ok || envelope?.ok !== true) {
      const fallback = response.status === 401 ? 'Сессия истекла. Войдите снова.' : response.status === 403 ? 'У вашей учётной записи нет доступа к этому действию.' : response.status === 409 ? 'Данные уже изменились. Загрузите актуальную версию.' : 'Не удалось выполнить действие.';
      throw new ApiError(envelope?.error?.message || fallback, response.status, envelope?.error?.code || '', envelope?.error?.details ?? envelope?.error?.conflicts ?? null);
    }
    if (binary) throw new ApiError('Сервер не вернул файл для скачивания. Повторите попытку позже.', response.status, 'MISSING_ATTACHMENT');
    const data = envelope.data;
    if (data?.csrf) csrf = data.csrf;
    return data;
  }
  return {url, request, get: (r, q, opts = {}) => request(r, {...opts, query: q}), post: (r, body) => request(r, {method: 'POST', body}),
    download: (r, query) => request(r, {query, binary: true}), clearSession: () => { csrf = ''; }};
}

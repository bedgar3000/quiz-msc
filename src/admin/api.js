const cfg = window.MSC_QUIZ_ADMIN || {};

export const config = cfg;

export async function api(path, { method = 'GET', body } = {}) {
  const response = await fetch(cfg.restUrl + path, {
    method,
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
    credentials: 'same-origin',
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const data = await response.json().catch(() => null);
  if (!response.ok) {
    throw new Error((data && data.message) || `Error ${response.status}`);
  }
  return data;
}

export function quizUrl(slug) {
  return (cfg.quizUrl || '').replace('__slug__', encodeURIComponent(slug || ''));
}

export function formatDate(value) {
  if (!value) return '—';
  const date = new Date(value.replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString('es', { dateStyle: 'medium', timeStyle: 'short' });
}

export function letter(index) {
  let s = '';
  for (let n = index + 1; n > 0; n = Math.floor((n - 1) / 26)) s = String.fromCharCode(65 + ((n - 1) % 26)) + s;
  return s;
}

export function slugify(text) {
  return String(text || '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

export const AVAILABILITY = {
  draft: { label: 'Borrador', cls: 'is-draft' },
  scheduled: { label: 'Programado', cls: 'is-scheduled' },
  expired: { label: 'Expirado', cls: 'is-expired' },
  open: { label: 'Publicado', cls: 'is-open' },
};

export async function copy(text) {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch (e) {
    const area = document.createElement('textarea');
    area.value = text;
    document.body.appendChild(area);
    area.select();
    const ok = document.execCommand('copy');
    area.remove();
    return ok;
  }
}

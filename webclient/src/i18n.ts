/**
 * Translation lookup for the web client.
 *
 * The strings live in Laravel's lang files (lang/<locale>/webclient.php); the
 * console page injects the active language, with English filling any gap, as
 * `window.__RD__.messages`. The session worker has no `window`, so the UI
 * hands it the same table with the connect command (setCatalog).
 *
 * Placeholders use Laravel's ":name" syntax, so the same catalog is checked by
 * `php artisan lang:check`.
 */

import english from './i18n-en.json';

export type Catalog = Record<string, string>;
export type Params = Record<string, string | number>;

let workerCatalog: Catalog | undefined;

/** Use this table instead of window.__RD__.messages (session worker). */
export function setCatalog(catalog: Catalog | undefined): void {
  workerCatalog = catalog;
}

function catalog(): Catalog {
  if (workerCatalog) return workerCatalog;
  const rd = (globalThis as { __RD__?: { messages?: Catalog } }).__RD__;
  return rd?.messages ?? {};
}

function fill(text: string, params?: Params): string {
  if (!params) return text;
  return text.replace(/:([A-Za-z_]+)/g, (whole, name: string) => (name in params ? String(params[name]) : whole));
}

// English, generated from lang/en/webclient.php (php artisan lang:webclient).
// Fills any gap: a page without messages, a key a translation lacks, tests.
const EN: Catalog = english;

function text(key: string): string {
  return catalog()[key] ?? EN[key] ?? key;
}

/** Whether the key has text in the page's language or in English. */
export function hasText(key: string): boolean {
  return key in catalog() || key in EN;
}

/** The translation for a key, falling back to English, then to the key. */
export function tl(key: string, params?: Params): string {
  return fill(text(key), params);
}

function pageLang(): string {
  return (typeof document !== 'undefined' && document.documentElement.lang) || 'en';
}

/**
 * Lower-case a label for use mid-sentence ("Peer enabled audio"). German
 * capitalizes nouns, and Chinese, Japanese and Korean have no case (lowering
 * would only mangle Latin acronyms such as TCP), so those are left alone.
 */
export function lowerLabel(label: string): string {
  return /^(de|zh|ja|ko)\b/i.test(pageLang()) ? label : label.toLowerCase();
}

/**
 * Which "|" form a count takes, the same rule Laravel's trans_choice applies,
 * so one catalog string works on both sides: one form (Chinese, Japanese,
 * Korean, Turkish), one/other (most European languages), 0 and 1 as "one"
 * (French), and three forms for Slavic languages.
 */
export function pluralIndex(lang: string, n: number): number {
  n = Math.abs(n);
  const base = lang.toLowerCase().split(/[-_]/)[0];
  switch (base) {
    case 'zh': case 'ja': case 'ko': case 'tr': case 'id': case 'vi': case 'th':
      return 0;
    case 'fr':
      return n === 0 || n === 1 ? 0 : 1;
    case 'ru': case 'uk': case 'be': case 'sr': case 'hr': case 'bs':
      return n % 10 === 1 && n % 100 !== 11 ? 0 : n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 10 || n % 100 >= 20) ? 1 : 2;
    case 'cs': case 'sk':
      return n === 1 ? 0 : n >= 2 && n <= 4 ? 1 : 2;
    case 'pl':
      return n === 1 ? 0 : n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14) ? 1 : 2;
    default:
      return n === 1 ? 0 : 1;
  }
}

/**
 * tl() escaped for HTML. Use it wherever the text lands in innerHTML or an
 * attribute: a catalog is data, and one "<" or "&" in a translation must not
 * break the markup.
 */
export function htl(key: string, params?: Params): string {
  return tl(key, params).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

/** Like tl(), for a "|"-separated plural message; the form follows pluralIndex(). */
export function tn(key: string, count: number, params?: Params): string {
  const forms = text(key).split('|');
  const form = forms[pluralIndex(pageLang(), count)] ?? forms[0];
  return fill(form, { count, ...params });
}

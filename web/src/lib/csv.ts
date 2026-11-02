// CSV export helpers: build RFC 4180 text and save it as a file.
import { api } from '../api/client';
import type { ListParams, ListResponse } from '../api/types';

/**
 * One cell. Quotes when needed, and prefixes values a spreadsheet would run
 * as a formula (=, +, -, @, tab, CR) with an apostrophe, since notes and
 * TXT records are user-entered.
 */
export function csvCell(v: unknown): string {
  let s = v === null || v === undefined ? '' : String(v);
  if (/^[=+\-@\t\r]/.test(s)) s = `'${s}`;
  return /[",\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

export function toCsv(header: string[], rows: unknown[][]): string {
  return [header, ...rows].map((r) => r.map(csvCell).join(',')).join('\r\n') + '\r\n';
}

export function saveFile(name: string, text: string, type = 'text/csv') {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([text], { type }));
  a.download = name;
  a.click();
  URL.revokeObjectURL(a.href);
}

/** Most rows a list export fetches (50 requests of 200). */
export const EXPORT_LIMIT = 10_000;

/** Every row of a paged list with the given filters and sort, up to EXPORT_LIMIT. */
export async function fetchAll<T>(path: string, params: ListParams): Promise<{ rows: T[]; total: number }> {
  const rows: T[] = [];
  const query = { ...params, page: undefined, perPage: 200 };
  for (let page = 1; ; page++) {
    const r = await api<ListResponse<T>>('GET', path, { query: { ...query, page } });
    rows.push(...r.data);
    if (page >= r.meta.totalPages || rows.length >= EXPORT_LIMIT) return { rows: rows.slice(0, EXPORT_LIMIT), total: r.meta.total };
  }
}

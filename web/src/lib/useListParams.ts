import { useSearchParams } from 'react-router-dom';
import type { ListParams } from '../api/types';

/**
 * Keep list state (page, perPage, sort, q and filters) in the URL so views
 * can be bookmarked and survive reloads.
 */
export function useListParams(defaults: { sort: string; perPage?: number }, filters: string[] = []) {
  const [sp, setSp] = useSearchParams();
  const params: ListParams = {
    page: Number(sp.get('page')) || 1,
    perPage: Number(sp.get('perPage')) || defaults.perPage || 25,
    sort: sp.get('sort') || defaults.sort,
    q: sp.get('q') || undefined,
  };
  for (const f of filters) if (sp.get(f)) params[f] = sp.get(f)!;

  const update = (changes: Partial<Record<string, string | number | undefined>>, resetPage = true) => {
    setSp(
      (prev) => {
        const next = new URLSearchParams(prev);
        for (const [k, v] of Object.entries(changes)) {
          if (v === undefined || v === '') next.delete(k);
          else next.set(k, String(v));
        }
        if (resetPage && !('page' in changes)) next.delete('page');
        return next;
      },
      { replace: true },
    );
  };
  return { params, update };
}

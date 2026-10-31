import { afterEach, describe, expect, it, vi } from 'vitest';
import { api, ApiError, errorMessage, setCsrfToken, setUnauthorizedHandler } from './client';

function mockFetch(status: number, body: unknown) {
  const fn = vi.fn().mockResolvedValue({
    status,
    ok: status >= 200 && status < 300,
    json: () => Promise.resolve(body),
  });
  vi.stubGlobal('fetch', fn);
  return fn;
}

afterEach(() => {
  vi.unstubAllGlobals();
  setCsrfToken(null);
});

describe('api()', () => {
  it('sends JSON bodies with the CSRF token on writes only', async () => {
    const fetch = mockFetch(201, { id: 1 });
    setCsrfToken('tok');
    await api('POST', '/subnets', { body: { name: 'x' } });
    const [url, init] = fetch.mock.calls[0];
    expect(String(url)).toMatch(/\/api\/v1\/subnets$/);
    expect(init.headers['X-CSRF-Token']).toBe('tok');
    expect(init.headers['Content-Type']).toBe('application/json');
    expect(init.body).toBe('{"name":"x"}');

    await api('GET', '/subnets', { query: { q: 'lan', page: 2, sort: undefined } });
    const [url2, init2] = fetch.mock.calls[1];
    expect(String(url2)).toMatch(/\/subnets\?q=lan&page=2$/);
    expect(init2.headers['X-CSRF-Token']).toBeUndefined();
  });

  it('throws ApiError with the API error envelope', async () => {
    mockFetch(403, { error: { code: 'forbidden', message: 'Permission denied', details: { permission: 'subnet_del' } } });
    const err = (await api('DELETE', '/subnets/1').catch((e: unknown) => e)) as ApiError;
    expect(err).toBeInstanceOf(ApiError);
    expect(err.status).toBe(403);
    expect(errorMessage(err)).toBe('Permission denied: you need the "subnet_del" permission.');
  });

  it('reports 401s outside /auth so the app can show the login page', async () => {
    const handler = vi.fn();
    setUnauthorizedHandler(handler);
    mockFetch(401, { error: { code: 'unauthorized', message: 'Authentication required' } });
    await api('GET', '/hosts').catch(() => {});
    expect(handler).toHaveBeenCalledTimes(1);
    await api('GET', '/auth/session').catch(() => {});
    expect(handler).toHaveBeenCalledTimes(1);
  });
});

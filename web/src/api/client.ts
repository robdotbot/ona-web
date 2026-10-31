// Fetch wrapper for /api/v1: JSON in and out, session cookie, CSRF header.

export interface ApiErrorBody {
  code: string;
  message: string;
  details?: {
    fields?: Record<string, string>;
    permission?: string;
    output?: string[];
    [key: string]: unknown;
  };
}

export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly details: ApiErrorBody['details'];

  constructor(status: number, body: ApiErrorBody) {
    super(body.message);
    this.status = status;
    this.code = body.code;
    this.details = body.details;
  }
}

const BASE = '/api/v1';
let csrfToken: string | null = null;
let onUnauthorized: (() => void) | null = null;

export function setCsrfToken(token: string | null) {
  csrfToken = token;
}

/** Called when any request comes back 401, so the app can show the login page. */
export function setUnauthorizedHandler(handler: () => void) {
  onUnauthorized = handler;
}

export async function api<T>(
  method: 'GET' | 'POST' | 'PATCH' | 'DELETE',
  path: string,
  options: { body?: unknown; query?: Record<string, string | number | undefined> } = {},
): Promise<T> {
  const url = new URL(BASE + path, window.location.origin);
  for (const [key, value] of Object.entries(options.query ?? {})) {
    if (value !== undefined && value !== '') url.searchParams.set(key, String(value));
  }
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (options.body !== undefined) headers['Content-Type'] = 'application/json';
  if (method !== 'GET' && csrfToken) headers['X-CSRF-Token'] = csrfToken;

  const res = await fetch(url, {
    method,
    headers,
    credentials: 'same-origin',
    body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
  });

  if (res.status === 204) return undefined as T;
  let data: unknown = null;
  try {
    data = await res.json();
  } catch {
    // fall through with data = null
  }
  if (!res.ok) {
    const body = (data as { error?: ApiErrorBody } | null)?.error ?? {
      code: 'http_error',
      message: `Request failed (${res.status})`,
    };
    if (res.status === 401 && !path.startsWith('/auth/')) onUnauthorized?.();
    throw new ApiError(res.status, body);
  }
  return data as T;
}

/** A human-readable message for any error thrown by a query or mutation. */
export function errorMessage(err: unknown): string {
  if (err instanceof ApiError) {
    if (err.code === 'forbidden' && err.details?.permission) {
      return `Permission denied: you need the "${err.details.permission}" permission.`;
    }
    return err.message;
  }
  if (err instanceof Error) return err.message;
  return 'Something went wrong';
}

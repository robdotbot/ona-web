# REST API integration tests

`run.php` is a zero-dependency PHP 8.3 test runner (curl + mysqli extensions,
no Composer/PHPUnit) that exercises the running API over HTTP.

## Running

With the dev stack up (`docker compose up` in the repo root):

```sh
docker compose exec -T api php /opt/ona/rest/tests/run.php
```

- `ONA_API_URL` — API base URL (default `http://localhost/api/v1`, i.e. from
  inside the `api` container). The legacy UI is assumed at the same host's `/`.
- `ONA_TEST_ONLY` — run only tests whose name contains this substring, e.g.
  `docker compose exec -T -e ONA_TEST_ONLY=dns api php /opt/ona/rest/tests/run.php`.

It prints one `PASS`/`FAIL`/`SKIP` line per test (with the reason under a
failure) and exits non-zero if anything failed. The limited-user test reads DB
credentials from `www/local/config/database_settings.inc.php`, so it is
skipped when run outside the container.

## What it covers

1. Unauthenticated: every protected route (a hand-kept table mirroring
   `routes.php`, including the planned 501 resources) returns 401; `/health`
   and `/openapi.yaml` are public.
2. Login/logout: bad password, LIKE-wildcard usernames, 415 for non-JSON,
   422 for missing fields, session/csrf/permissions on success, logout ends
   the session, a legacy-UI guest session does not authenticate the API.
3. Permissions: `guest` gets 403 `forbidden` (with `details.permission`) on
   every write and nothing changes; a temporary DB user with only
   `subnet_add` can create but not modify or delete a subnet.
4. CSRF: missing, wrong, empty and other-session tokens → 403 `csrf_failed`.
5. CRUD lifecycles for subnets (list/q/pagination/sort, next-ip, usage),
   hosts, interfaces and DNS records (CNAME, MX, TXT containing `=`/`&`).
6. Errors: 404, 409 conflict, 422 with `details.fields`, 400, 415, 405
   (with `Allow`), 501.
7. Search by IP, host name and subnet name (`bestMatch`).
8. Spec: every implemented route and method appears under `paths:` in
   `openapi.yaml`.
9. Output escaping and security headers (`<` as `<`, `nosniff`).

## Test data

Each run uses a random id: objects are named `resttest-<id>-…`
(subnets upper-cased by ONA) in free random `10.250.x.0/24` ranges. Everything
a test creates is registered for cleanup and deleted after the test, pass or
fail; the temporary user and its permission rows are removed from the DB.

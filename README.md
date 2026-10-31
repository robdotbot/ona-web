# ona-next

A modern web interface for [OpenNetAdmin](https://github.com/opennetadmin/ona)
(ONA), backed by a new JSON REST API that wraps ONA's own modules. The
classic ONA interface keeps working alongside it on the same database.

```
ona-next/
├── api/                 ONA (upstream copy) + the REST API in api/rest
├── web/                 React + TypeScript single-page app
└── docker-compose.yml   MariaDB, PHP 8.3/Apache (ONA + API), Vite dev server
```

## Quick start

Requires Docker.

```sh
docker compose up
```

The first start installs ONA into a fresh MariaDB volume, using ONA's own
`install/installcli.php` with the answers fed on stdin. When the `web`
container prints `VITE … ready`:

| What | URL |
| --- | --- |
| New web UI | http://localhost:5173 (log in as `admin` / `admin`) |
| REST API | http://localhost:8080/api/v1/ |
| OpenAPI spec | http://localhost:8080/api/v1/openapi.yaml |
| Classic ONA UI | http://localhost:8080/ |

`guest` / `guest` is a second built-in account with no write permissions,
useful for seeing read-only behaviour.

On a fresh install, adding a PTR record needs a reverse domain
(e.g. `10.in-addr.arpa`). The MVP UI can't create domains yet, so either leave
"Also add a PTR record" unchecked (the default) or add the domain in the
classic UI.

To start over with an empty database: `docker compose down -v`.

## Architecture

```
 browser ──► Vite dev server :5173 ──/api──► Apache + PHP 8.3 :8080 ──► MariaDB
             (React SPA)                     ├─ /api/v1/*  REST API (api/rest)
                                             └─ /*         classic ONA UI
```

**API (`api/`).** `api/` is an unmodified export of upstream ONA `master`
(see `api/UPSTREAM.md`) plus `api/rest/` and the single front controller
`api/www/api/v1/index.php`. The API includes ONA's own `config.inc.php`, so it
shares ONA's DB connection, `sys_config`, users, groups and session store.
Reads use ONA's `functions_db` helpers; every write calls the matching DCM
module through `run_module()` (`subnet_add`, `host_modify`,
`dns_record_del`, …), so ONA's validation, `auth()` permission checks and
schema rules are reused, not reimplemented. Details, the endpoint →
module → permission table and the security notes are in
[`api/rest/README.md`](api/rest/README.md).

API conventions (all in the OpenAPI spec):

- Session login: `POST /api/v1/auth/login` with ONA credentials. No guest
  auto-login. Every write needs the `X-CSRF-Token` from the login response.
- Errors: `{"error": {"code", "message", "details"}}` with proper status
  codes (401, 403 + `details.permission`, 404, 409, 422 + `details.fields`).
- Lists: `?page=&perPage=&sort=` (prefix `-` for descending) →
  `{"data": [...], "meta": {"page", "perPage", "total", "totalPages"}}`.

**Web (`web/`).** React 19 + TypeScript, built with Vite.

- [TanStack Query](https://tanstack.com/query) for server state
  (`src/api/hooks.ts`, one hook per endpoint).
- [TanStack Table](https://tanstack.com/table) for the server-sorted,
  server-paginated tables (`src/components/DataTable.tsx`).
- [Mantine](https://mantine.dev) for components, forms, modals and
  notifications, with light and dark themes.
- React Router.

The app only talks to `/api/v1` on its own origin; in development Vite proxies
that to the API container, so the session cookie stays first-party. List
filters, sort and page live in the URL. Buttons for writes are hidden when the
session lacks the ONA permission, and the API enforces it regardless.

## MVP scope

Done: login/logout; subnets (list, view, add, edit, delete, IP usage, next
free IP); hosts and their interfaces (CRUD); DNS records (CRUD, all ONA
types); global search (IP, MAC, names; jumps straight to an exact match).

Not yet: VLANs, blocks, DHCP, custom attributes, locations/domains editing,
admin screens and plugins. Each has a placeholder:

- **Web:** "Coming soon" entries in the sidebar (`web/src/components/nav.ts`,
  `PLANNED_SECTIONS`) with a page linking to the classic UI.
- **API:** the matching paths answer `501 not_implemented`
  (`api/rest/routes.php`, bottom). `api/rest/README.md` lists the steps to add
  one.

## Development

Edits to `api/` and `web/` are live: the `api` container bind-mounts
`./api`, and Vite hot-reloads `./web`.

Tests:

```sh
# REST API integration tests (run inside the api container, against the live stack)
docker compose exec -T api php /opt/ona/rest/tests/run.php
```

```sh
# Web unit tests and type check (needs Node 20+ on the host)
cd web && npm ci && npm test && npm run typecheck
```

The API tests cover 401 on every protected route, 403 for the
permissionless `guest` user on every write, a user holding only
`subnet_add`, CSRF, CRUD lifecycles, error codes, search, output escaping
and that every route is in the spec. See `api/rest/tests/README.md`.

To run the web app outside Docker against the API container:
`cd web && npm run dev` (it proxies `/api` to `http://localhost:8080`).

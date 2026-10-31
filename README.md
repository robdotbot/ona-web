# ona-web

A modern web interface for [OpenNetAdmin](https://github.com/opennetadmin/ona)
(ONA), backed by a new JSON REST API that wraps ONA's own modules. The
classic ONA interface keeps working alongside it on the same database.

```
ona-web/
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
(e.g. `10.in-addr.arpa`). Add it under **Domains** first, or leave
"Also add a PTR record" unchecked (the default).

To start over with an empty database: `docker compose down -v`.

## Architecture

```
 browser ──► Vite dev server :5173 ──/api──► Apache + PHP 8.3 :8080 ──► MariaDB
             (React SPA)                     ├─ /api/v1/*  REST API (api/rest)
                                             └─ /*         classic ONA UI
```

**API (`api/`).** `api/` is an export of upstream ONA `master` with
robdotbot's pending upstream security fixes applied (see `api/UPSTREAM.md`;
note that `dcm.pl` now needs `-l`/`-p` credentials), plus `api/rest/` and the single front controller
`api/www/api/v1/index.php`. The API includes ONA's own `config.inc.php`, so it
shares ONA's DB connection, `sys_config`, users, groups and session store.
Reads use ONA's `functions_db` helpers; every write calls the matching DCM
module through `run_module()` (`subnet_add`, `host_modify`,
`dns_record_del`, `vlan_add`, `dhcp_pool_modify`, …), so ONA's validation,
`auth()` permission checks and schema rules are reused, not reimplemented.
The only exceptions are the tables ONA has no module for (users, groups,
permissions, system configuration, custom attribute types), which the API
writes directly under the same permissions as the classic admin windows. Details, the endpoint →
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

## Scope

- **Subnets** (list, view, add, edit, delete, IP usage, next free IP, VLAN
  assignment), **hosts** and their **interfaces**, **DNS records** (all ONA
  types), and global search (IP, MAC, names; jumps straight to an exact match).
- **VLANs** and VLAN campuses, with the subnets in each VLAN.
- **Blocks**: named address ranges, with the subnets inside them.
- **DHCP**: servers and the subnets they serve, failover groups, pools (on
  the subnet page too), and options set globally or per subnet, host or server.
- **Custom attributes**: attribute types (with validation rules) and values
  on hosts, subnets and VLANs.
- **Locations** and **domains** (add, edit, delete).
- **Admin**: users, groups and their permissions (`user_admin`), system
  configuration and plugins (`advanced`). Plugin install steps still run in
  the classic UI.

Things the new UI doesn't cover (device types and models, DNS views and DNS
server assignments, config archives, reports, DHCP option definitions) remain
available in the classic UI, linked from the user menu.

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
permissionless `guest` user on every write (and on admin reads), a user
holding only `subnet_add`, CSRF, CRUD lifecycles for every resource, error
codes, search, output escaping and that every route is in the spec. See `api/rest/tests/README.md`.

To run the web app outside Docker against the API container:
`cd web && npm run dev` (it proxies `/api` to `http://localhost:8080`).

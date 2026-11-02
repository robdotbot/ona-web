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
permissions, system configuration, custom attribute types, reference data,
and changing or deleting messages), which the API
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

- An **overview** page with record counts and the active messages from
  every host and subnet (and first steps on an empty install).
- **Subnets** (list, view, add, edit, delete, IP usage, next free IP, VLAN
  assignment, every address with its status, as CSV too), **hosts** and their
  **interfaces** (with the DNS and DHCP services a host provides, and links
  to outside tools from ONA's host actions config), **DNS records** (all ONA
  types), and global search (IP, MAC, names; jumps straight to an exact match).
- **VLANs** and VLAN campuses, with the subnets in each VLAN.
- **Blocks**: named address ranges, with the subnets inside them.
- **DHCP**: servers and the subnets they serve, failover groups, pools (on
  the subnet page too), and options set globally or per subnet, host or server.
- **Custom attributes**: attribute types (with validation rules) and values
  on hosts, subnets and VLANs.
- **Locations** (with a page per location listing its hosts, and a map link)
  and **domains** (add, edit, delete), and the **DNS servers**
  of each domain (master / slave / forward, with their NS records).
- **DNS records** can be turned off and on, scheduled to start later, and
  put in a DNS view (split horizon).
- **Interfaces**: share an address with other hosts (VRRP/HSRP/CARP), add
  external NAT addresses, move an interface to another host, and move a
  range of addresses to another subnet (with a preview).
- **Tags** on hosts and subnets (and a tag filter on the lists),
  **messages** on host and subnet pages, and the **config archive** on the
  host page (view, compare two versions, add, delete one or all).
- **Reports** from plugins, run as text or CSV, and the ONA **change log**
  (filled while the `log_to_db` setting is 1).
- **Tools**: IPv4/IPv6 calculator, an address map on IPv4 subnet pages (up
  to /20), a map of the subnets and free space in each block, advanced
  host and subnet filters (device type, location, IP range, custom
  attribute, tag, VLAN), and keyboard shortcuts (`?` lists them).
- **Reference data**: manufacturers, models, roles, device types, subnet
  types, DHCP option definitions, config types and DNS views.
- **CSV export** of the host, subnet and DNS record lists (the rows matching
  the current filters, up to 10,000), and a **My account** page with your
  groups and permissions.
- **Admin**: users, groups and their permissions (`user_admin`), system
  configuration and plugins (`advanced`). Plugin install steps still run in
  the classic UI.

Plugin windows with their own HTML (for example the nmap scan views) can't be
ported generically and remain in the classic UI, linked from the user menu
and the Reports page.

## Releases (Windows, macOS and Linux)

Each GitHub release has a download per OS: the stack above with the web UI
prebuilt and served by nginx, plus launchers (`start.bat` / `start.command`
to double-click, `start.sh` on Linux) that create `.env` with random
database passwords, run `docker compose up` and open http://localhost:8000.
Users only need Docker Desktop, or on Linux Docker Engine with the Compose
plugin (the Linux bundle is a `.tar.gz` and works on any distribution).
Ports are bound to 127.0.0.1; the bundle's own `README.txt` explains
settings and upgrades. The sources are in `release/`.

- Build locally: `cd web && npm ci && cd .. && scripts/build-release.sh v0.2.0`
  (writes `dist-release/`).
- Release notes: first commit `release/notes/v0.2.0.md` with what changed
  (no Downloads section; `scripts/release-notes.sh` adds it). The release
  description is that file followed by the download instructions; without
  it the release only lists the downloads. To change the notes of a
  published release, edit the file on `main` and run the **Release notes**
  workflow with the version.
- Publish: push a tag such as `v0.2.0`, or run the **Release** workflow by
  hand. It runs the web tests, builds all three bundles, starts the Linux one
  with Docker through its own `start.sh`, runs the REST API test suite inside
  it, checks the Windows and macOS bundles hold the same stack, then creates
  the release with the bundles and `SHA256SUMS`. Pull requests that touch
  the release files run everything except publishing.

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

CI (`.github/workflows/ci.yml`) runs both on every pull request and every
push to `main`: the web unit tests, type check and build, and the API tests
against the Docker stack above.

The API tests cover 401 on every protected route, 403 for the
permissionless `guest` user on every write (and on admin reads), a user
holding only `subnet_add`, CSRF, CRUD lifecycles for every resource, error
codes, search, output escaping and that every route is in the spec. See `api/rest/tests/README.md`.

To run the web app outside Docker against the API container:
`cd web && npm run dev` (it proxies `/api` to `http://localhost:8080`).

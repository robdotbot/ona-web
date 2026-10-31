# ONA REST API (v1)

A JSON API over OpenNetAdmin. It lives next to the unmodified ONA code:

```
api/
├── www/api/v1/index.php   front controller (the only web-reachable file)
├── rest/
│   ├── bootstrap.php      loads ONA's config.inc.php, then dispatches
│   ├── routes.php         the complete, static route table
│   ├── openapi.yaml       OpenAPI 3.1 spec (served at /api/v1/openapi.yaml)
│   ├── src/               Http, Router, Auth, Ona helpers, one class per resource
│   └── tests/             integration tests (see tests/README.md)
└── …                      upstream ONA (see ../UPSTREAM.md)
```

## How a request runs

1. `bootstrap.php` buffers all output, includes ONA's `config/config.inc.php`
   at global scope (DB connection, `sys_config`, ONA session), and discards
   anything ONA printed. Only `Response::json()` writes to the client.
2. `Router` matches the path against `routes.php`. Path parameters only match
   digits. Routes are closures in a fixed table; nothing from the request is
   ever used to choose a file to include or a function to call.
3. Unless a route is public (`/health`, `/openapi.yaml`, `/auth/login`,
   `/auth/logout`), `Auth::requireUser()` runs, and for non-GET requests
   `Auth::checkCsrf()`.
4. Reads use `functions_db` (`db_get_record`, `ona_find_*`,
   `ona_build_domain_name`, `get_subnet_usage`, `ip_mangle`) plus
   parameterised `SelectLimit` queries for lists.
5. Every write goes through `Ona::run($module, $options, $perms)`, which
   checks the permissions with ONA's `auth()` (clean 403), then calls
   `run_module()` so the module does its own validation, its own `auth()`
   check and runs in ONA's transaction. Module failures become JSON errors:
   "Permission denied" → 403, conflicts → 409, anything else → 422 with the
   module's output lines in `details.output`.

| Endpoint | ONA module | Permission(s) |
| --- | --- | --- |
| `POST /subnets` | `subnet_add` | `subnet_add` |
| `PATCH /subnets/{id}` | `subnet_modify` | `subnet_modify` |
| `DELETE /subnets/{id}` | `subnet_del` | `subnet_del` |
| `POST /hosts` | `host_add` | `host_add` |
| `PATCH /hosts/{id}` | `host_modify` (+ `dns_record_modify` to rename) | `host_modify` |
| `DELETE /hosts/{id}` | `host_del` | `host_del` |
| `POST /interfaces` | `interface_add` | `host_add` |
| `PATCH /interfaces/{id}` | `interface_modify` | `interface_modify` |
| `DELETE /interfaces/{id}` | `interface_del` | `interface_del`, `host_del` |
| `POST /dns-records` | `dns_record_add` | `host_add` |
| `PATCH /dns-records/{id}` | `dns_record_modify` | `host_modify` |
| `DELETE /dns-records/{id}` | `dns_record_del` | `host_del` |

The permissions are the ones the ONA modules themselves check (for DNS
records ONA uses the `host_*` permissions, not the `dns_record_*` ones).

## Security notes

These carry the intent of the upstream fixes (window-name file inclusion,
reflected XSS in `html_desktop.inc.php`, `eval` in `dcm.php`, anonymous
`dcm.pl` login, missing `auth()` in DCM write modules) into the API. The
fixes themselves are also applied to the legacy code; see `../UPSTREAM.md`.

- **No client-controlled includes or calls.** Static route table; module
  names are literals in our code; IDs are integers.
- **Escaped output.** Responses are only ever `application/json` built with
  `json_encode` (`JSON_HEX_TAG|AMP|APOS|QUOT`), with `nosniff`, a
  `default-src 'none'` CSP and `Cache-Control: no-store`.
- **Auth on every endpoint**, and **no guest auto-login**: a request counts
  as authenticated only if the session went through `POST /auth/login` and
  the user recorded then is still the ONA session user. A guest session
  from the legacy UI does not authenticate API calls.
- **dcm.pl impersonation blocked.** ONA's `startSession()` treats the
  `console-module-interface` User-Agent plus `options=unix_username=…` as
  logged in; the bootstrap strips both before ONA sees the request.
- **Permission checks on every write**, both in the API and again inside
  the module.
- **Login hardening.** Uniform "Invalid username or password" message with a
  small random delay; exact username match for local users (ONA's local auth
  uses SQL `LIKE`, so `adm_n` would match `admin`); session ID regenerated on
  login.
- **CSRF.** A per-session token is required on every write. Bodies must be
  `application/json`, and the session cookie is `SameSite=Strict`.
- **Option escaping.** Module options are passed as an escaped string, because
  `run_module()` corrupts array values containing `=` or `&`.

Known gaps, unchanged from ONA: local passwords are unsalted MD5, and there
is no login rate limiting.

## Adding a resource

1. Add a class in `src/` (copy `Subnets.php`): `list()` with `Paging`,
   `get()`, and `create()`/`update()`/`delete()` that call `Ona::run()` with
   the module and the permission the module checks.
2. `require` it in `bootstrap.php`, register its routes in `routes.php`
   (replacing its entry in the "planned" 501 list), and document it in
   `openapi.yaml`.
3. Add it to the route table and permission tests in `tests/run.php`.

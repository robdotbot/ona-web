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
   digits, except `{key}` (system configuration names and plugin names),
   which matches `[A-Za-z0-9_.-]` without slashes. Routes are closures in a
   fixed table; nothing from the request is ever used to choose a file to
   include or a function to call.
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
   "Permission denied" → 403, conflicts (duplicates, overlaps, records still
   in use) → 409, anything else → 422 with the module's output lines in
   `details.output`.
6. ONA has no modules for users, groups, permissions, `sys_config` and
   custom attribute types. For those, `Ona::write()` checks the same
   permission the legacy admin window checks (`user_admin` or `advanced`) and
   runs `db_insert_record()` / `db_update_record()` / `db_delete_records()`
   (bound values) in one transaction. Reads of users, groups, permissions,
   system configuration and plugins need that permission too.

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
| `POST /vlan-campuses` | `vlan_campus_add` | `vlan_add` |
| `PATCH /vlan-campuses/{id}` | `vlan_campus_modify` | `vlan_modify` |
| `DELETE /vlan-campuses/{id}` | `vlan_campus_del` | `vlan_del` |
| `POST /vlans` | `vlan_add` | `vlan_add` |
| `PATCH /vlans/{id}` | `vlan_modify` | `vlan_modify` |
| `DELETE /vlans/{id}` | `vlan_del` | `vlan_del` |
| `POST/PATCH/DELETE /blocks…` | `block_add` / `_modify` / `_del` | `advanced` |
| `POST /dhcp-servers` | `dhcp_server_add` | `advanced` |
| `DELETE /dhcp-servers/{id}/subnets/{subnetId}` | `dhcp_server_del` | `advanced` |
| `POST/PATCH/DELETE /dhcp-failover-groups…` | `dhcp_failover_group_add` / `_modify` / `_del` | `advanced` |
| `POST/PATCH/DELETE /dhcp-pools…` | `dhcp_pool_add` / `_modify` / `_del` | `advanced` |
| `POST/PATCH/DELETE /dhcp-entries…` | `dhcp_entry_add` / `_modify` / `_del` | `advanced` |
| `POST /custom-attributes` | `custom_attribute_add` | `custom_attribute_add` |
| `PATCH /custom-attributes/{id}` | `custom_attribute_modify` | `custom_attribute_modify` |
| `DELETE /custom-attributes/{id}` | `custom_attribute_del` | `custom_attribute_del` |
| `POST/PATCH /locations…` | `location_add` / `location_modify` | `location_add` |
| `DELETE /locations/{id}` | `location_del` | `location_del` |
| `POST/PATCH/DELETE /domains…` | `domain_add` / `_modify` / `_del` | `advanced` |
| `/custom-attribute-types…` writes | (direct) | `advanced` |
| `/users…`, `/groups…`, `GET /permissions` | (direct) | `user_admin` |
| `/sys-config…` | (direct) | `advanced` |
| `GET /plugins`, `PATCH /plugins/{key}` | (`plugin_disabled` marker file) | `advanced` |

`PATCH /subnets/{id}` also takes `vlanId` (0 detaches), passed to
`subnet_modify` as the VLAN number plus campus.

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
- **dcm.pl impersonation blocked.** Upstream ONA's `startSession()` treats
  the `console-module-interface` User-Agent plus `options=unix_username=…` as
  logged in (fixed in this repo's legacy copy too); the bootstrap strips both
  before ONA sees the request.
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

- **Names that reached unescaped SQL.** `vlan_modify`, `vlan_campus_modify`
  and `block_modify` used to put the new name into a `LIKE '…'` clause
  without escaping it (now fixed in the legacy code, see `../UPSTREAM.md`).
  As defence in depth the API still only accepts names for VLANs, campuses,
  blocks and locations from a safe character set (`Input::label()`: letters,
  digits, spaces and `. _ : / # ( ) + -`).
- **Plugins** are found by listing the plugin directories; a plugin name
  from the URL is only used if it matches a listed directory, and
  `plugin_info.php` is parsed as text, never included.

Known gaps, unchanged from ONA: local passwords are unsalted MD5 (the user
endpoints write them that way so ONA can check them), and there is no login
rate limiting.

## ONA module quirks the API works around

- `location_modify` reads the new longitude only when `set_logitude` (sic)
  is sent; the API sends both spellings.
- `domain_modify` clears the parent domain and `dhcp_pool_modify` clears the
  failover group unless they are resent; the API always resends them.
- `dhcp_failover_group_modify` writes the old server IDs back when asked to
  change servers, and fails when nothing changes. The API rejects server
  changes (delete and re-create the group) and only sends changed settings.
- `host_modify` can't clear a host's location (in the legacy UI either);
  `PATCH /hosts/{id}` with `locationId: 0` clears it on the device record.
- Several `*_modify` modules print their usage text unless some `set_*`
  option is truthy, or fail when nothing changed; the API only sends real
  changes and adds a no-op companion where needed (see the comments in
  `src/`).

## Adding a resource

1. Add a class in `src/` (copy `Subnets.php` or `Blocks.php`): `list()`
   with `Paging`, `get()`, and `create()`/`update()`/`delete()` that check
   the permission first (`Auth::requirePerm()`), then call `Ona::run()` with
   the module and the permission the module checks.
2. `require` it in `bootstrap.php`, register its routes in `routes.php`,
   and document it in `openapi.yaml`.
3. Add it to the route table, the guest permission test and a lifecycle
   test in `tests/run.php`.

A resource that isn't ready yet can be registered to answer
`501 not_implemented`, so clients can tell "not built yet" from "no such
thing":

```php
$r->add('GET', '/things', function () {
    throw new ApiError(501, 'not_implemented', 'This resource is planned but not implemented yet');
});
```

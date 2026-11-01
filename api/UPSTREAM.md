# Upstream ONA

Everything in `api/` except `rest/`, `docker/`, `www/api/`, `.dockerignore`,
this file and the security fixes listed below is an unmodified export (no git history) of
[opennetadmin/ona](https://github.com/opennetadmin/ona) `master` at commit
`75305184bc4e14bd064da250dd5d70c47b53ae4b` (v19.0.3 plus the window-name
file-inclusion fix, PR #204/#205).

The one upstream file changed is `.gitignore`: upstream ignores `VERSION`,
`.gitignore` itself and all of `www/local`, which would have dropped tracked
files from this repo.

To pull in a newer upstream, export it over this folder and re-check the
REST layer's tests:

```sh
git -C ../ona archive origin/master | tar -x -C api/
```

## Security fixes applied on top of upstream

These are open upstream PRs from robdotbot, replayed here as separate commits
until they are merged:

| Upstream PR | Fix | Files |
| --- | --- | --- |
| [#206](https://github.com/opennetadmin/ona/pull/206) | Reflected XSS via `ws`/`work_space` and `q`/`search` URL parameters | `www/include/html_desktop.inc.php` |
| [#207](https://github.com/opennetadmin/ona/pull/207) | Replace `eval()` in the `dcm.php` output formatter | `www/dcm.php` |
| [#208](https://github.com/opennetadmin/ona/pull/208) | `dcm.php` requires credentials unless `dcm_allow_anonymous` is set to 1 | `www/dcm.php` |
| [#209](https://github.com/opennetadmin/ona/pull/209) | Missing `auth()` checks in DCM modules that change data | `www/modules/…` |

When pulling a newer upstream, drop any of these that upstream has merged.

Behaviour change from #208: `dcm.pl` without `-l`/`-p` no longer works
unless an admin adds the `dcm_allow_anonymous` sys_config option with value
`1` (Admin > System config).

Not yet proposed upstream, fixed only here:

| Fix | Files |
| --- | --- |
| `startSession()` no longer logs in the `unix_username` from `?options=` when the User-Agent is `console-module-interface` (both are client supplied). It still skips the session cookie for `dcm.pl`; `dcm.php` authenticates those requests itself. | `www/include/functions_general.inc.php` |
| SQL injection through names: `vlan_modify`, `vlan_campus_modify` and `block_modify` put the new name (and `vlan_modify` the new VLAN number) into their duplicate checks unescaped, so a name containing `'` broke the query and crafted input changed it. Strings are now quoted with `$onadb->qstr()`, `LIKE` becomes `=` (so `_`/`%` are no longer wildcards; the comparison stays case-insensitive), IDs are cast to integers and a non-numeric VLAN number is refused. | `www/modules/ona/vlan.inc.php`, `vlan_campus.inc.php`, `block.inc.php` |
| Code execution in `tag_add`: it looked up the record by building a PHP `eval()` from the request's `reference` (unescaped, inside quotes), before its permission check, so any logged-in caller could run PHP on the server. It now calls `ona_find_host()` / `ona_find_subnet()` directly. | `www/modules/ona/tag.inc.php` |

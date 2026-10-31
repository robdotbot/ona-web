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

Still present upstream and not fixed here: `startSession()` in
`www/include/functions_general.inc.php` treats the `console-module-interface`
User-Agent plus `options=unix_username=…` as a logged-in user (with no
permissions). The REST API strips both before ONA sees the request.

# Upstream ONA

Everything in `api/` except `rest/`, `docker/`, `www/api/`, `.dockerignore` and
this file is an unmodified export (no git history) of
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

Upstream security fixes that were still open as PRs at export time
(#206 reflected XSS, #207 `eval` in `dcm.php`, #208 anonymous `dcm.pl`
login, #209 missing `auth()` checks in DCM write modules) are **not** applied
to the legacy code here. The REST API does not go through those code paths;
see `rest/README.md` for how it avoids them.

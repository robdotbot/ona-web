<?php
namespace OnaRest;

/**
 * Minimal router. Routes are registered from a fixed table in routes.php;
 * nothing from the request is ever used to pick a file to include or a
 * function name to call.
 */
class Router
{
    /** @var array<int, array{string, string, callable, bool}> */
    private array $routes = [];

    /**
     * @param string $pattern  path with placeholders, e.g. /subnets/{id}. A
     *                         placeholder matches digits and is passed as an
     *                         int, except {key}, which matches a short name
     *                         ([A-Za-z0-9_.-], no slashes) and stays a string.
     * @param bool   $public   true only for login, health and the spec
     */
    public function add(string $method, string $pattern, callable $handler, bool $public = false): void
    {
        $regex = '#^' . preg_replace(['#\{key\}#', '#\{(?!key\})(\w+)\}#'], ['(?P<key>[A-Za-z0-9_][A-Za-z0-9_.-]{0,127})', '(?P<$1>\d+)'], $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler, $public];
    }

    public function dispatch(Request $req): void
    {
        $allowed = [];
        foreach ($this->routes as [$method, $regex, $handler, $public]) {
            if (!preg_match($regex, $req->path, $m)) continue;
            if ($method !== $req->method) {
                $allowed[] = $method;
                continue;
            }
            $params = [];
            foreach (array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY) as $k => $v) $params[$k] = $k === 'key' ? $v : (int)$v;
            if (!$public) {
                Auth::requireUser();
                if (!in_array($req->method, ['GET', 'HEAD', 'OPTIONS'], true)) Auth::checkCsrf($req);
            }
            [$status, $body] = $handler($req, $params);
            Response::json($status, $body);
        }
        if ($allowed) {
            throw new ApiError(405, 'method_not_allowed', "Method {$req->method} not allowed", ['allow' => array_values(array_unique($allowed))]);
        }
        throw new ApiError(404, 'not_found', 'No such endpoint');
    }
}

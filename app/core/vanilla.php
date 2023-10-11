<?php

namespace Vc;

use Vc\Helper as h;

function start($env_file = null) {
    mark($start = hash());
    config('ure', $env_file);
    mark($boot = hash());
    load_routes();
    load_bootstrap();
    mark(compact('boot', 'start'), true);
    dispatch('boot');

    if (handled()) {
        return;
    }

    dispatch('request');

    load(handle(), share());
    mark($start, true);

    load_exists(layout(), share());
}

function hash($str = null) {
    return str_pad(base_convert(substr(sha1($str ?? rand()), -16), 16, 36), 11, '0', STR_PAD_LEFT);
}

function mark($key, $mark = false) {
    h\walk($key, function ($key, $as) use ($mark) {
        $marks = hive('marks', $key);

        if ($mark) {
            $marks['elapsed'] = isset($marks['time']) ? microtime(true) - $marks['time'] : 0;
            $marks['usage'] = isset($marks['mem']) ? memory_get_usage() - $marks['mem'] : 0;

            hive('markers', $as, $marks);
        } else {
            $marks['time'] = microtime(true);
            $marks['mem'] = memory_get_usage();

            hive('marks', $key, $marks);
        }
    });
}

function config_defaults() {
    $dir = h\fixslashes(dirname(__DIR__));
    $pdir = dirname($dir);

    return array(
        'app_dir' => $dir,
        'caseless' => true,
        'data_dir' => $dir . '/data',
        'dev' => false,
        'env' => 'production',
        'error_handler' => null,
        'extension' => 'php',
        'pages_dir' => $dir . '/pages',
        'project_dir' => $pdir,
        'quiet' => false,
        'route_cache' => 'route.cache',
        'var_dir' => $pdir . '/var',
    );
}

function dir($name, $file = null, $create = null, $permission = 0755) {
    $root = config($name) ?? config($name . '_dir');
    $path = $root && $file ? $root . '/' . ltrim($file, '/') : $root;

    if ($path && $create && !file_exists($dir = dirname($path))) {
        mkdir($dir, $permission, true);
    }

    return $path;
}

function cache_file($name) {
    return $name ? dir('var', 'cache/' . $name, true) : null;
}

function hive($root = null, $key = null, $value = null) {
    static $hive;

    if (!$hive) {
        $hive = array(
            'cookie' => $_COOKIE,
            'env' => $_ENV,
            'files' => $_FILES,
            'get' => $_GET,
            'post' => $_POST,
            'server' => $_SERVER,
        );
    }

    if ($root) {
        $var = &$hive[$root];
    } else {
        $var = &$hive;
    }

    if (!$key) {
        return $var;
    }

    $set = func_num_args() > 2;
    $all = is_array($key);

    if ($set) {
        if ($all) {
            $var = $var ? array_merge($var, $key) : $key;
        } else {
            $var[$key] = $value;
        }

        return;
    }

    if ($all) {
        return array_map(fn ($key) => $var[$key] ?? null, $key);
    }

    return $var[$key] ?? null;
}

function hive_set($root, $set, $value) {
    if ($set) {
        hive($root, $value, true);

        return;
    }

    return hive($root);
}

function config($key = null, $value = null, $add = true) {
    if ('ure' === $key) {
        $config = hive('config') ?? config_defaults();

        h\tap(
            h\read($value ?? ($config['project_dir'] . '/.env')),
            function ($content) use ($config, $add) {
                $env = parse_ini_string($content, false, INI_SCANNER_TYPED);

                hive('config', $add ? array_merge($config, $env) : $env, true);
            },
        );

        return;
    }

    return hive('config', ...func_get_args());
}

function dispatch($event, ...$args) {
    $dispatchers = hive('events')[$event] ?? null;

    if (!$dispatchers) {
        return;
    }

    $data = (object) (compact('args') + array('done' => false));
    h\coalesce($dispatchers, function ($dispatcher) use ($data) {
        $dispatcher['handle']($data);

        return $data->done ? true : null;
    });
}

function listen($event, $handle, $priority = 0) {
    $handlers = hive('events')[$event] ?? array();
    $handlers[] = compact('handle', 'priority');

    if (isset($handlers[1])) {
        usort($handlers, fn($a, $b) => $b['priority'] <=> $a['priority']);
    }

    hive('events', $event, $handlers);
}

function routes($cache_store = null) {
    $cache = config('dev') ? false : cache_file(config('route_cache'));
    $cached = $cache && is_file($cache);
    $routes = hive('routes');

    if ($cache && $cache_store && !$cached) {
        h\write($cache, serialize($routes));
    }

    // TODO: fix route cache logic
    if ($cached) {
        hive('routes', $routes = unserialize(h\read($cache)));
    }

    return $routes;
}

function route($path, $handler, $verbs = 'GET', $options = null) {
    $routes = routes();
    $add = &$routes['/' . ltrim($path, '/')];

    $route = (array) $options;
    $route['handler'] = dir('pages', $handler);

    if (empty($route['pattern'])) {
        $route['pattern'] = routify($path);
    }

    h\walk($verbs, function ($verb) use (&$add, $route) {
        $add[strtoupper($verb)] = $route;
    });

    hive('routes', $routes, true);
}

function routify($path) {
    $pattern = str_replace('.', '/', '/' . ltrim($path, '/'));
    $flags = config('caseless') ? 'i' : '';

    if (false !== strpos($pattern, '@')) {
        $pattern = preg_replace_callback(
            '/\/(@)?@([^\/@]+)(@)?(@)?/',
            function ($match) {
                $prefix = '\\/';
                $pattern = '[^\\/]+';
                $name = $match[2];

                // use character classes?
                if (isset($match[1]) && (false !== $pos = strpos($name, '-'))) {
                    $pattern = '[[:' . substr($name, $pos + 1) . ':]]';
                    $name = substr($name, 0, $pos);
                }

                // match everything?
                if (isset($match[4])) {
                    $pattern = '.+';
                }

                // optional?
                if (isset($match[3])) {
                    $prefix = '(?:\\/)?';
                }

                return $prefix . '(<' . $name . '>' . $pattern . ')';
            },
            $pattern,
            -1,
            $count,
            PREG_UNMATCHED_AS_NULL,
        );
    }

    return '/^' . preg_quote($pattern, '/') . '$/' . $flags;
}

function route_register($handler) {
    $base = basename($handler, '.' . config('extension'));

    if ($base === basename($handler)) {
        return;
    }

    $verbs = 'GET';
    $options = null;

    // last dot as verbs
    if (false !== $pos = strrpos($base, '.')) {
        $verbs = substr($base, $pos + 1);
        $base = substr($base, 0, $pos);
    }

    // prev last dot as mime
    if (false !== $pos = strrpos($base, '.')) {
        $options['mime'] = $verbs;

        $verbs = substr($base, $pos + 1);
        $base = substr($base, 0, $pos);
    }

    // remove index
    if (strcasecmp('index', $base) === 0) {
        $base = '';
    }

    $path = '';

    // last path
    if (false !== $pos = strrpos($handler, '/')) {
        $path = substr($handler, 0, $pos);
    }

    // append base
    if ($base) {
        $path .= '/' . $base;
    }

    // append extension
    if ($path && isset($options['mime'])) {
        $path .= '.' . $options['mime'];
    }

    route($path, $handler, $verbs, $options);
}

function load_routes() {
    array_map(fn ($handler) => route_register($handler), h\scandir(dir('pages'), '/^[^_]/'));
}

function share(...$vars) {
    return hive_set(
        'shared',
        $vars && ($shares = array_filter($vars, 'is_array')),
        isset($shares) ? array_merge(...$shares) : null,
    );
}

function layout($file = null) {
    return hive_set('layout', !!$file, $file);
}

function load($file, $args = null, $safe = null) {
    return loader($safe)($file, $args ?? array());
}

function load_exists($file, $args = null) {
    return load($file, $args, true);
}

function load_bootstrap() {
    h\walk(config('loaders'), loader());
}

function loader($safe = null) {
    return function () use ($safe) {
        if ($safe && !is_file(func_get_arg(0))) {
            return null;
        }

        if (func_num_args() > 1) {
            extract(func_get_arg(1));
        }

        $load = require func_get_arg(0);

        if (is_callable($load)) {
            return $load();
        }

        return $load;
    };
}

function handled($handled = null) {
    return hive_set('handled', null !== $handled, $handled);
}

function handle() {
    $path = path();
    $verb = verb();
    $routes = routes(true);
    $match = $routes[$path] ?? $routes[strtolower($path)] ?? route_match($path, $routes) ?? array(
        $verb => error_handler(404),
    );
    $route = $match[$verb] ?? error_handler(403);

    share($route['params'] ?? null, $route['data'] ?? null);

    if (isset($route['mime'])) {
        // TODO: send mime?
    }

    return $route['handler'];
}

function view($file) {
    return h\coalesce('pages', fn ($dir) => is_file($path = dir($dir, $file)) ? $path : null);
}

function error_handler($code) {
    $data = compact('code');
    $handler = config('error_handler') ?? view('_defaults/error.php');

    return compact('handler', 'data');
}

function route_match($path, $routes) {
    $matches = array();
    $match = h\find(
        $routes,
        function ($route) use (&$matches, $path) {
            return isset($route['pattern']) && preg_match($route['pattern'], $path, $matches);
        },
    );

    if (!$match || ($match['params'] && null === ($params = route_match_params($match['params'], $matches)))) {
        return null;
    }

    return array(
        'handler' => $match['handler'],
        'params' => $params ?? null,
    );
}

function route_match_params($params, $matches) {
    return h\reduce(
        $params,
        function ($required, $param, $params) use ($matches) {
            if (null === $params || ($required && !isset($matches[$param]))) {
                return null;
            }

            if (str_ends_with($param, '*')) {
                $params[rtrim($param, '*')] = h\split(end($matches), '/');
            } else {
                $params[$param] = $matches[$param];
            }

            return $params;
        },
        array(),
    );
}

function request($key = null) {
    if (!$key) {
        return hive('request');
    }

    $val = hive('request', $key);

    if (null !== $val) {
        return $val;
    }

    list(
        $acceptable,
        $host,
        $port,
        $method,
        $uri,
        $https,
    ) = hive(
        'server',
        array(
            'HTTP_ACCEPT',
            'HTTP_HOST',
            'SERVER_PORT',
            'REQUEST_METHOD',
            'REQUEST_URI',
            'HTTPS',
        ),
    );

    $use_scheme = 0 === strcasecmp('off', $https ?? 'off') ? 'http' : 'https';
    list($use_host, $use_port) = explode(':', $host . ':' . $port);

    $base_url = $use_scheme . '://' . $use_host;
    $base_path = '';
    $path = $uri;
    $verb = strtoupper($method ?? 'GET');
    $accepts = array_reduce(
        (array) h\split($acceptable, ','),
        function ($accepts, $accept) {
            if (false === $pos = strpos($accept, ';')) {
                $mime = $accept;
                $quality = 1.0;
            } else {
                $mime = substr($accept, 0, $pos);
                $quality = (false === $pos = strpos($accept, 'q=')) ? 1.0 : floatval(substr($accept, $pos + 2));
            }

            $accepts[$mime] = $quality;

            return $accepts;
        },
        array(),
    );

    uasort($accepts, fn($a, $b) => $b <=> $a);

    $accepts = array_keys($accepts);

    if (!in_array($use_port, array(80, 443))) {
        $base_url .= ':' . $use_port;
    }

    if (false !== $pos = strpos($path, '?')) {
        $path = substr($path, 0, $pos);
    }

    $path = rawurldecode($path);

    hive(
        'request',
        compact(
            'accepts',
            'base_path',
            'base_url',
            'path',
            'verb',
        ),
        true,
    );

    return hive('request', $key);
}

function mime_check($mime, $accept) {
    return (
        strcasecmp($accept, $mime) === 0
        || str_starts_with($accept, $mime)
        || str_ends_with($accept, $mime)
        || '*/*' === $accept
    );
}

function accept($mime) {
    return h\some(accepts(), fn ($accept) => mime_check($mime, $accept));
}

function wants($mime) {
    return mime_check($mime, accepts()[0] ?? '');
}

function base_url() {
    return request('base_url');
}

function base_path() {
    return request('base_path');
}

function path() {
    return request('path');
}

function accepts() {
    return request('accepts');
}

function verb($is = null) {
    return $is ? 0 === strcasecmp($is, request('verb')) : request('verb');
}

function response($content = null, $code = 200, $headers = null) {
    if ($code) {
        http_response_code($code);
    }

    if (is_array($headers)) {
        h\walk($headers, fn ($header) => header($header));
    }

    if ($content && !config('quiet')) {
        echo $content;
    }
}

function json($data, $code = 200, $headers = null) {
    $heads = (array) $headers;
    $heads[] = 'Content-Type: application/json';

    response(
        is_string($data) ? $data : json_encode($data),
        $code,
        $heads,
    );
    exit;
}

function redirect($url, $permanent = null, $headers = null) {
    $heads = (array) $headers;
    $heads[] = 'Location: ' . $url;

    response(null, $permanent ? 301 : 302, $heads);
}

function http_status($code) {
    return options('http_status', $code, 'Unknown http status');
}

function options($name, $find = null, $default = null) {
    $options = data($name) ?? array();

    if (null === $find) {
        return $options;
    }

    $found = array_search($find, $options);

    return false === $found ? $default : $found;
}

function data($name, $find = null) {
    $data = load_exists(dir('data', $name . '.php')) ?? null;

    if ($find) {
        return $data[$find] ?? null;
    }

    return $data;
}

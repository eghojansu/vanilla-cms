<?php

function vc_run($env_file = null) {
    vc_mark($start = vc_hash());
    vc_config('ure', $env_file);
    vc_mark($boot = vc_hash());
    vc_load();
    vc_mark(compact('boot', 'start'), true);
    vc_dispatch('boot');

    if (vc_handled()) {
        return;
    }

    vc_dispatch('load');
    vc_handle(fn() => vc_mark($start, true));
}

function vc_hash($str = null) {
    return str_pad(base_convert(substr(sha1($str ?? rand()), -16), 16, 36), 11, '0', STR_PAD_LEFT);
}

function vc_mark($key, $mark = false) {
    static $marks = array();

    if ($key) {
        vc_each($key, function ($key, $as) use (&$marks, $mark) {
            if ($mark) {
                $marker = $marks[$key] ?? array();
                $marker['elapsed'] = isset($marker['time']) ? microtime(true) - $marker['time'] : 0;
                $marker['usage'] = isset($marker['mem']) ? memory_get_usage() - $marker['mem'] : 0;

                vc_globals('markers', $as, $marker);
            } else {
                $marks[$key]['time'] = microtime(true);
                $marks[$key]['mem'] = memory_get_usage();
            }
        }, null, 0);

        return;
    }

    return $marks;
}

function vc_config_defaults() {
    $dir = vc_fixslashes(__DIR__);
    $pdir = dirname($dir);

    return array(
        'app_dir' => $dir,
        'dev' => false,
        'env' => 'production',
        'pages_dir' => $dir . '/pages',
        'project_dir' => $pdir,
        'route_cache' => 'route.cache',
        'var_dir' => $pdir . '/var',
    );
}

function vc_dir($name, $path = null, $create = null, $permission = 0755) {
    $dir = vc_config($name) ?? vc_config($name . '_dir');

    if ($dir && $create && !file_exists($dir)) {
        mkdir($dir, $permission, true);
    }

    return $dir && $path ? $dir . '/' . ltrim($path, '/') : $dir;
}

function vc_cache_file($name) {
    return $name ? vc_dir('var', 'cache/' . $name, true) : null;
}

function vc_globals($root = null, $key = null, $value = null) {
    static $globals;

    if (!$globals) {
        $globals = array(
            'cookie' => $_COOKIE,
            'env' => $_ENV,
            'files' => $_FILES,
            'get' => $_GET,
            'post' => $_POST,
            'server' => $_SERVER,
            'session' => $_SESSION ?? array(),
        );
    }

    if ($root) {
        $hive = &$globals[$root];
    } else {
        $hive = &$globals;
    }

    if (!$key) {
        return $hive;
    }

    $set = func_num_args() > 2;
    $all = is_array($key);

    if ($set) {
        if ($all) {
            $hive = $hive ? array_merge($hive, $key) : $key;
        } else {
            $hive[$key] = $value;
        }

        return;
    }

    if ($all) {
        return array_map(fn ($key) => $hive[$key] ?? null, $key);
    }

    return $hive[$key] ?? null;
}

function vc_config($key = null, $value = null, $add = true) {
    if ('ure' === $key) {
        $config = vc_globals('config') ?? vc_config_defaults();

        vc_tap(
            vc_read($value ?? ($config['project_dir'] . '/.env')),
            function ($content) use ($config, $add) {
                $env = parse_ini_string($content, false, INI_SCANNER_TYPED);

                vc_globals('config', $add ? array_merge($config, $env) : $env, true);
            },
        );

        return;
    }

    return vc_globals('config', ...func_get_args());
}

function vc_dispatch($event, ...$args) {
    $dispatchers = vc_globals('events')[$event] ?? null;

    if (!$dispatchers) {
        return;
    }

    $data = (object) (compact('args') + array('done' => false));
    vc_each($dispatchers, function ($dispatcher, $i, $data) {
        $dispatcher['handle']($data);

        return $data->done;
    }, $data, 5);
}

function vc_listen($event, $handle, $priority = 0) {
    $handlers = vc_globals('events')[$event] ?? array();
    $handlers[] = compact('handle', 'priority');

    if (isset($handlers[1])) {
        usort($handlers, fn($a, $b) => $b['priority'] <=> $a['priority']);
    }

    vc_globals('events', $event, $handlers);
}

function vc_routes($cache_store = null) {
    $cache = vc_config('dev') ? false : vc_cache_file(vc_config('route_cache'));
    $routes = vc_globals('routes');

    if ($routes && $cache && $cache_store) {
        vc_write($cache, serialize($routes));
    }

    if (!$routes && $cache && file_exists($cache)) {
        vc_globals('routes', $routes = unserialize(vc_read($cache)));
    }

    return $routes;
}

function vc_route($path, $handler, $verbs = 'GET', $options = null) {
    $routes = vc_routes();
    $add = &$routes['/' . ltrim($path, '/')];

    $route = (array) $options;
    $route['handler'] = $handler;

    vc_each($verbs, function ($verb) use (&$add, $route) {
        $add[strtoupper($verb)] = $route;
    }, null, 0);

    vc_globals('routes', $routes, true);
}

function vc_share(...$vars) {
    if ($vars && ($shares = array_filter($vars, 'is_array'))) {
        vc_globals('shared', array_merge(...$shares), true);

        return;
    }

    return vc_globals('shared');
}

function vc_load() {
    vc_each(
        vc_config('loaders'),
        fn($loader) => file_exists($loader) && is_callable($load = require $loader) && $load(),
        null,
        0,
    );
}

function vc_handled($handled = null) {
    if (null !== $handled) {
        vc_globals('handled', $handled);

        return;
    }

    return vc_globals('handled') ?? false;
}

function vc_handle($stop_) {
    $handler_ = vc_config('handler') ?? vc_do_handle(vc_path());
    $stop_();

    extract(vc_share() ?? array(), EXTR_PREFIX_SAME, '_');
    include $handler_;
}

function vc_do_handle($path) {
    $verb = vc_verb();
    $routes = vc_routes(true);
    $match = $routes[$path] ?? $routes[strtolower($path)] ?? vc_route_match($path, $routes) ?? array(
        $verb => vc_error_handler(404),
    );
    $route = $match[$verb] ?? vc_error_handler(403);

    vc_share($route['params'] ?? null, $route['data'] ?? null);

    return $route['handler'];
}

function vc_resolve_handler($file) {
    return vc_dir('pages', $file);
}

function vc_error_handler($code) {
    $data = compact('code');
    $handler = vc_config('error_handler') ?? vc_resolve_handler('_defaults/error.php');

    return compact('handler', 'data');
}

function vc_route_match($path, $routes) {
    $matches = array();
    $match = vc_each(
        $routes,
        function ($route) use (&$matches, $path) {
            return isset($route['pattern']) && preg_match($route['pattern'], $path, $matches);
        },
        null,
        7,
    );

    if (!$match || ($match['params'] && null === ($params = vc_route_match_params($match['params'], $matches)))) {
        return null;
    }

    return array(
        'handler' => $match['handler'],
        'params' => $params ?? null,
    );
}

function vc_route_match_params($params, $matches) {
    return vc_each(
        $params,
        function ($required, $param, $params) use ($matches) {
            if (null === $params || ($required && !isset($matches[$param]))) {
                return null;
            }

            if (str_ends_with($param, '*')) {
                $params[rtrim($param, '*')] = vc_split(end($matches), '/');
            } else {
                $params[$param] = $matches[$param];
            }

            return $params;
        },
        array(),
    );
}

function vc_request($key = null) {
    if (!$key) {
        return vc_globals('request');
    }

    $val = vc_globals('request', $key);

    if (null !== $val) {
        return $val;
    }

    list(
        $host,
        $port,
        $method,
        $uri,
        $https,
    ) = vc_globals(
        'server',
        array(
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

    if (!in_array($use_port, array(80, 443))) {
        $base_url .= ':' . $use_port;
    }

    if (false !== $pos = strpos($path, '?')) {
        $path = substr($path, 0, $pos);
    }

    $path = rawurldecode($path);

    vc_globals('request', compact('base_path', 'base_url', 'path', 'verb'), true);

    return vc_globals('request', $key);
}

function vc_base_url() {
    return vc_request('base_url');
}

function vc_base_path() {
    return vc_request('base_path');
}

function vc_path() {
    return vc_request('path');
}

function vc_verb($is = null) {
    return $is ? 0 === strcasecmp($is, vc_request('verb')) : vc_request('verb');
}

function vc_write($file, $content) {
    return file_put_contents($file, $content);
}

function vc_read($file) {
    return file_exists($file) ? file_get_contents($file) : '';
}

<?php

function vc_run($env_file = null) {
    vc_mark($start = vc_hash());
    vc_config('ure', $env_file);
    vc_mark($boot = vc_hash());
    vc_load_routes();
    vc_load_bootstrap();
    vc_mark(compact('boot', 'start'), true);
    vc_dispatch('boot');

    if (vc_handled()) {
        return;
    }

    vc_dispatch('request');

    vc_load(vc_handle(), vc_share());
    vc_mark($start, true);

    vc_load_exists(vc_layout(), vc_share());
}

function vc_hash($str = null) {
    return str_pad(base_convert(substr(sha1($str ?? rand()), -16), 16, 36), 11, '0', STR_PAD_LEFT);
}

function vc_mark($key, $mark = false) {
    static $marks = array();

    if ($key) {
        vc_walk($key, function ($key, $as) use (&$marks, $mark) {
            if ($mark) {
                $marker = $marks[$key] ?? array();
                $marker['elapsed'] = isset($marker['time']) ? microtime(true) - $marker['time'] : 0;
                $marker['usage'] = isset($marker['mem']) ? memory_get_usage() - $marker['mem'] : 0;

                vc_globals('markers', $as, $marker);
            } else {
                $marks[$key]['time'] = microtime(true);
                $marks[$key]['mem'] = memory_get_usage();
            }
        });

        return;
    }

    return $marks;
}

function vc_config_defaults() {
    $dir = vc_fixslashes(__DIR__);
    $pdir = dirname($dir);

    return array(
        'app_dir' => $dir,
        'caseless' => true,
        'dev' => false,
        'env' => 'production',
        'extension' => 'php',
        'pages_dir' => $dir . '/pages',
        'project_dir' => $pdir,
        'quiet' => false,
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

function vc_globals_set($root, $set, $value) {
    if ($set) {
        vc_globals($root, $value, true);

        return;
    }

    return vc_globals($root);
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
    vc_coalesce($dispatchers, function ($dispatcher) use ($data) {
        $dispatcher['handle']($data);

        return $data->done ? true : null;
    });
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

    if (!$routes && $cache && is_file($cache)) {
        vc_globals('routes', $routes = unserialize(vc_read($cache)));
    }

    return $routes;
}

function vc_route($path, $handler, $verbs = 'GET', $options = null) {
    $routes = vc_routes();
    $add = &$routes['/' . ltrim($path, '/')];

    $route = (array) $options;
    $route['handler'] = file_exists($handler) ? $handler : vc_dir('pages', $handler);

    if (empty($route['pattern'])) {
        $route['pattern'] = vc_routify($path);
    }

    vc_walk($verbs, function ($verb) use (&$add, $route) {
        $add[strtoupper($verb)] = $route;
    });

    vc_globals('routes', $routes, true);
}

function vc_routify($path) {
    $normalize = str_replace('.', '/', '/' . ltrim($path, '/'));

    if (str_ends_with($normalize, '/index')) {
        $normalize = substr($normalize, 0, -6);
    }

    if (false === strpos($normalize, '@')) {
        return $normalize;
    }

    return preg_replace_callback(
        '/@(.+)/',
        function ($match) {
            return '-';
        },
        $normalize,
    );
}

function vc_route_register($handler) {
    if (!preg_match('/\.' . preg_quote($ext = vc_config('extension'), '/') . '$/i', $handler)) {
        return;
    }

    $verbs = 'GET';
    $path = substr($handler, 0, -strlen($ext)-1);

    if (false !== $pos = strrpos($path, '.')) {
        $verbs = str_replace('-', ',', substr($path, $pos + 1));
        $path = substr($path, 0, $pos);
    }

    vc_route($path, $handler, $verbs);
}

function vc_load_routes() {
    array_map(fn ($handler) => vc_route_register($handler), vc_scandir(vc_dir('pages'), '/^[^_]/'));
}

function vc_share(...$vars) {
    return vc_globals_set(
        'shared',
        $vars && ($shares = array_filter($vars, 'is_array')),
        isset($shares) ? array_merge(...$shares) : null,
    );
}

function vc_layout($file = null) {
    return vc_globals_set('layout', !!$file, $file);
}

function vc_load($file, $args = null, $safe = null) {
    $path = is_file($file) ? $file : vc_view($file);

    return vc_loader($safe)($path, $args ?? array());
}

function vc_load_exists($file, $args = null) {
    return vc_load($file, $args, true);
}

function vc_load_bootstrap() {
    vc_walk(vc_config('loaders'), vc_loader());
}

function vc_loader($safe = null) {
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

function vc_handled($handled = null) {
    return vc_globals_set('handled', null !== $handled, $handled);
}

function vc_handle() {
    $path = vc_path();
    $verb = vc_verb();
    $routes = vc_routes(true);
    $match = $routes[$path] ?? $routes[strtolower($path)] ?? vc_route_match($path, $routes) ?? array(
        $verb => vc_error_handler(404),
    );
    $route = $match[$verb] ?? vc_error_handler(403);

    vc_share($route['params'] ?? null, $route['data'] ?? null);

    return $route['handler'];
}

function vc_view($file) {
    return vc_coalesce('pages', fn ($dir) => is_file($path = vc_dir($dir, $file)) ? $path : null);
}

function vc_error_handler($code) {
    $data = compact('code');
    $handler = vc_config('error_handler') ?? vc_view('_defaults/error.php');

    return compact('handler', 'data');
}

function vc_route_match($path, $routes) {
    $matches = array();
    $match = vc_find(
        $routes,
        function ($route) use (&$matches, $path) {
            return isset($route['pattern']) && preg_match($route['pattern'], $path, $matches);
        },
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
    return vc_reduce(
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
        $acceptable,
        $host,
        $port,
        $method,
        $uri,
        $https,
    ) = vc_globals(
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
        (array) vc_split($acceptable, ','),
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

    vc_globals(
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

    return vc_globals('request', $key);
}

function vc_mime_check($mime, $accept) {
    return (
        strcasecmp($accept, $mime) === 0
        || str_starts_with($accept, $mime)
        || str_ends_with($accept, $mime)
        || '*/*' === $accept
    );
}

function vc_accept($mime) {
    return vc_some(vc_accepts(), fn ($accept) => vc_mime_check($mime, $accept));
}

function vc_wants($mime) {
    return vc_mime_check($mime, vc_accepts()[0] ?? '');
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

function vc_accepts() {
    return vc_request('accepts');
}

function vc_verb($is = null) {
    return $is ? 0 === strcasecmp($is, vc_request('verb')) : vc_request('verb');
}

function vc_write($file, $content) {
    return file_put_contents($file, $content);
}

function vc_read($file) {
    return is_file($file) ? file_get_contents($file) : '';
}

function vc_scandir($dir, $pattern = null, $root = null) {
    return array_reduce(scandir($dir), function ($items, $item) use ($dir, $pattern, $root) {
        if (
            ('.' === $item || '..' === $item)
            || ($pattern && !preg_match($pattern, $item))
        ) {
            return $items;
        }

        if (is_dir($path = $dir . '/' . $item)) {
            array_push($items, ...vc_scandir($path, $pattern, $dir));
        } else {
            $items[] = substr($path, strlen($root ?? $dir) + 1);
        }

        return $items;
    }, array());
}

function vc_response($content = null, $code = 200, $headers = null) {
    if ($code) {
        http_response_code($code);
    }

    if (is_array($headers)) {
        vc_walk($headers, fn ($header) => header($header));
    }

    if ($content && !vc_config('quiet')) {
        echo $content;
    }
}

function vc_json($data, $code = 200, $headers = null) {
    $heads = (array) $headers;
    $heads[] = 'Content-Type: application/json';

    vc_response(
        is_string($data) ? $data : json_encode($data),
        $code,
        $heads,
    );
    exit;
}

function vc_redirect($url, $permanent = null, $headers = null) {
    $heads = (array) $headers;
    $heads[] = 'Location: ' . $url;

    vc_response(null, $permanent ? 301 : 302, $heads);
}

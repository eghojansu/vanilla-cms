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

                $markers = vc_config('markers');
                $markers[$as] = $marker;
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

    return array(
        'app_dir' => $dir,
        'dev' => false,
        'env' => 'production',
        'project_dir' => dirname($dir),
    );
}

function vc_globals($root = null, $key = null, $value = null, $add = null) {
    static $globals;

    if (!$globals) {
        $globals = array(
            'cookie' => $_COOKIE,
            'env' => $_ENV,
            'files' => $_FILES,
            'get' => $_GET,
            'post' => $_POST,
            'server' => $_SERVER,
            'session' => $_SESSION,
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
        if ($all && null == $add) {
            $hive = $hive ? array_merge($hive, $key) : $key;
        } else if ($all && $add) {
            $hive[$key][$add] = $value;
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

function vc_config($key = null, $value = null, $add = null) {
    if ('ure' === $key) {
        $config = vc_globals('config') ?? vc_config_defaults();

        vc_tap(
            $value ?? $config['project_dir'] . '/.env',
            function ($file) use ($config, $add) {
                $env = parse_ini_file($file, false, INI_SCANNER_TYPED);

                vc_globals('config', $add ? array_merge($config, $env) : $env, null);
            },
            'file_exists',
        );

        return;
    }

    return vc_globals('config', ...func_get_args());
}

function vc_dispatch($event, ...$args) {
    $dispatchers = vc_config('events')[$event] ?? null;

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
    $handlers = vc_config('events')[$event] ?? array();
    $handlers[] = compact('handle', 'priority');

    if (isset($handlers[1])) {
        usort($handlers, fn($a, $b) => $b['priority'] <=> $a['priority']);
    }

    vc_config('events', $handlers, $event);
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
        vc_config('handled', $handled);

        return;
    }

    return vc_config('handled') ?? false;
}

function vc_handle($stop_) {
    $globals = vc_config('globals') ?? array();

    extract($globals, EXTR_PREFIX_SAME, '_');
    include vc_config('handler') ?? vc_resolve_handler();
    $stop_();
}

function vc_resolve_handler() {
    // TODO: resolving request
    vc_base_url();
    vc_dump(vc_globals('server'));
}

function vc_base_url() {
    $svr = vc_globals('server', array('host' => 'HTTP_HOST', 'port' => 'SERVER_PORT'));

    list($host, $port) = explode(':', $svr['host'] . ':' . $svr['port']);

    vc_dump($host, $port);
}

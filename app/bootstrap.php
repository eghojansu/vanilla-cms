<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

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

function vc_config($key = null, $value = null, $add = null) {
    static $config = array(
        'app_dir' => vc_fixslashes(__DIR__),
        'project_dir' => vc_fixslashes(dirname(__DIR__)),
    );

    if (!$key) {
        return $config;
    }

    if ('ure' === $key) {
        vc_tap(
            $value ?? $config['project_dir'] . '/.env',
            function ($file) use (&$config, $add) {
                $env = parse_ini_file($file, false, INI_SCANNER_TYPED);
                $config = $add ? array_merge($config, $env) : $env;
            },
            'file_exists',
        );

        return;
    }

    $set = func_num_args() > 1;
    $all = is_array($key);

    if ($set) {
        if ($all && null == $add) {
            $config = array_merge($config, $key);
        } else if ($all && $add) {
            $config[$key][$add] = $value;
        } else {
            $config[$key] = $value;
        }

        return;
    }

    if ($all) {
        return array_map(fn ($key) => $config[$key] ?? null, $key);
    }

    return $config[$key] ?? null;
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

function vc_handle($_stop) {
    $globals = vc_config('globals');

    extract($globals, EXTR_PREFIX_SAME, '_');
    include vc_config('handler') ?? vc_resolve_handler();
    $_stop();
}

function vc_resolve_handler() {
    // TODO: resolving request
}

/**
* Looper's friend
*
* @param mixed $data   Data to be processed
* @param callable $fn  The data processor
* @param mixed $carry  Initial/fallback data
* @param integer $keep Result processing type
*                      0 = nothing;
*                      1 = carry;
*                      2 = associative;
*                      3 = indexed;
*                      4 = every;
*                      5 = some;
*                      6 = find index;
*                      7 = find value;
*                      8 = filters;
*
* @return mixed The array after being processed, the carry or null if nothing to keep
*/
function vc_each($data, $fn, $carry = null, $keep = 2) {
    $values = vc_split($data);
    $reduce = null !== $carry || 1 === $keep;
    $result = match (true) {
        6 === $keep, 7 === $keep => null,
        5 === $keep, 4 === $keep => false,
        $reduce => $carry,
        default => array(),
    };

    foreach ($values as $key => $value) {
        $stop = false;
        $res = $fn($value, $key, $result, $values, $data);

        if (8 === $keep) {
            if ($res) {
                $result[$key] = $value;
            }
        } elseif (7 === $keep) {
            $stop = !!$res;
            $result = $stop ? $value : null;
        } elseif (6 === $keep) {
            $stop = !!$res;
            $result = $stop ? $key : null;
        } elseif (5 === $keep) {
            $result = $stop = !!$res;
        } elseif (4 === $keep) {
            $stop = !$res;
            $result = !$stop;
        } else if (3 === $keep) {
            $result[] = $res;
        } else if (2 === $keep) {
            $result[$key] = $res;
        } elseif ($reduce) {
            $result = $res;
        }

        if ($stop) {
            break;
        }
    }

    if ($keep > 0) {
        return $result;
    }
}

function vc_tap($value, $fn, $check = null) {
    if (!is_callable($fn) || (null !== $check && !$check) || (is_callable($check) && !$check($value))) {
        return $value;
    }

    return $fn($value);
}

function vc_pick(&$values, $key, $default = null, $keep = 3, $pluck = false) {
    if (!is_array($values)) {
        return $default;
    }

    if (is_array($key)) {
        return vc_each(
            $key,
            function ($fallback, $key) use (&$values, $pluck, $default) {
                if (is_numeric($key)) {
                    $key = $fallback;
                    $fallback = $default;
                }

                $pick = $fallback;

                if (isset($values[$key])) {
                    $pick = $values[$key];

                    if ($pluck) {
                        unset($values[$key]);
                    }
                }

                return $pick;
            },
            null,
            $keep,
        );
    }

    if (isset($values[$key])) {
        $pick = $values[$key];

        if ($pluck) {
            unset($values[$key]);
        }

        return $pick;
    }

    return $default;
}

function vc_pluck(&$values, $key, $default = null, $keep = 3) {
    return vc_pick($values, $key, $default, $keep, true);
}

function vc_split($value, $separator = ',;|') {
    if (is_iterable($value)) {
        return $value;
    }

    return is_string($value) ? preg_split('/['. preg_quote($separator, '/') .']/', $value, -1, PREG_SPLIT_NO_EMPTY) : (array) $value;
}

function vc_fixslashes($str) {
    return strtr($str, '\\', '/');
}

function vc_merge(...$values) {
    return array_merge(...array_filter($values, 'is_array'));
}

function vc_indexed($value) {
    return is_array($value) && (!$value || ctype_digit(implode('', array_keys($value))));
}

function vc_cli() {
    return strncmp(PHP_SAPI, 'cli', 3);
}

function vc_dump(...$values) {
    ob_clean();

    echo '<pre>';
    var_dump(...$values);
    echo '</pre>';
    exit;
}

<?php

namespace Vc\Helper;

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
*                      9 = coalesce;
*
* @return mixed The array after being processed, the carry or null if nothing to keep
*/
function each($data, $fn, $carry = null, $keep = 2) {
    $values = split($data);
    $reduce = null !== $carry || 1 === $keep;
    $result = match (true) {
        6 === $keep, 7 === $keep, 9 === $keep => null,
        5 === $keep, 4 === $keep => false,
        $reduce => $carry,
        default => array(),
    };

    foreach ($values as $key => $value) {
        $stop = false;
        $res = $fn($value, $key, $result, $values, $data);

        if ($reduce) {
            $result = $res;
        } elseif (9 === $keep) {
            $stop = null !== $res;
            $result = $res;
        } elseif (8 === $keep) {
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
        }

        if ($stop) {
            break;
        }
    }

    if ($keep > 0) {
        return $result;
    }
}

function walk($data, $fn): void {
    each($data, $fn, null, 0);
}

function reduce($data, $fn, $carry = null): array {
    return each($data, $fn, $carry, 1);
}

function map($data, $fn): array {
    return each($data, $fn, null, 2);
}

function indexing($data, $fn) {
    return each($data, $fn, null, 3);
}

function every($data, $fn) {
    return each($data, $fn, null, 4);
}

function some($data, $fn) {
    return each($data, $fn, null, 5);
}

function index($data, $fn) {
    return each($data, $fn, null, 6);
}

function find($data, $fn) {
    return each($data, $fn, null, 7);
}

function filters($data, $fn) {
    return each($data, $fn, null, 8);
}

function coalesce($data, $fn) {
    return each($data, $fn, null, 9);
}

function tap($value, $fn, $check = null) {
    if (!is_callable($fn) || (null !== $check && !$check) || (is_callable($check) && !$check($value))) {
        return $value;
    }

    return $fn($value);
}

function pick(&$values, $key, $default = null, $keep = 3, $pluck = false) {
    if (!is_array($values)) {
        return $default;
    }

    if (is_array($key)) {
        return each(
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

function pluck(&$values, $key, $default = null, $keep = 3) {
    return pick($values, $key, $default, $keep, true);
}

function split($value, $separator = ',;|') {
    if (is_iterable($value)) {
        return $value;
    }

    if (is_string($value) && ($parts = preg_split('/['. preg_quote($separator, '/') .']/', $value, -1, PREG_SPLIT_NO_EMPTY))) {
        return $parts;
    }

    return (array) $value;
}

function fixslashes($str) {
    return strtr($str, '\\', '/');
}

function merge(...$values) {
    return array_merge(...array_filter($values, 'is_array'));
}

function indexed($value) {
    return is_array($value) && (!$value || ctype_digit(implode('', array_keys($value))));
}

function cli() {
    return strncmp(PHP_SAPI, 'cli', 3);
}

function dump(...$values) {
    ob_clean();

    echo '<pre>';
    var_dump(...$values);
    echo '</pre>';
}

function dd(...$values) {
    dump(...$values);

    exit;
}

function title($str, ...$pass) {
    return ucwords(strtolower($str), ...$pass);
}

function slug($str) {
    return preg_replace('/\W/', '-', strtolower($str));
}

function rand($len = 8) {
    return substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, $len);
}

function number($value, $fractions = 0) {
    return number_format($value, $fractions, ',', '.');
}

function write($file, $content) {
    return file_put_contents($file, $content);
}

function read($file) {
    return is_file($file) ? file_get_contents($file) : '';
}

function scandir($dir, $pattern = null, $root = null) {
    return array_reduce(\scandir($dir) ?: array(), function ($items, $item) use ($dir, $pattern, $root) {
        if (
            ('.' === $item || '..' === $item)
            || ($pattern && !preg_match($pattern, $item))
        ) {
            return $items;
        }

        if (is_dir($path = $dir . '/' . $item)) {
            array_push($items, ...scandir($path, $pattern, $dir));
        } else {
            $items[] = substr($path, strlen($root ?? $dir) + 1);
        }

        return $items;
    }, array());
}

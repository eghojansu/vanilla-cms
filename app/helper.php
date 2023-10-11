<?php

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
function vc_each($data, $fn, $carry = null, $keep = 2) {
    $values = vc_split($data);
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

function vc_walk($data, $fn) {
    vc_each($data, $fn, null, 0);
}

function vc_reduce($data, $fn, $carry = null) {
    return vc_each($data, $fn, $carry, 1);
}

function vc_map($data, $fn) {
    return vc_each($data, $fn, null, 2);
}

function vc_indexing($data, $fn) {
    return vc_each($data, $fn, null, 3);
}

function vc_every($data, $fn) {
    return vc_each($data, $fn, null, 4);
}

function vc_some($data, $fn) {
    return vc_each($data, $fn, null, 5);
}

function vc_index($data, $fn) {
    return vc_each($data, $fn, null, 6);
}

function vc_find($data, $fn) {
    return vc_each($data, $fn, null, 7);
}

function vc_filters($data, $fn) {
    return vc_each($data, $fn, null, 8);
}

function vc_coalesce($data, $fn) {
    return vc_each($data, $fn, null, 9);
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

    if (is_string($value) && ($parts = preg_split('/['. preg_quote($separator, '/') .']/', $value, -1, PREG_SPLIT_NO_EMPTY))) {
        return $parts;
    }

    return (array) $value;
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
}

function vc_dd(...$values) {
    vc_dump(...$values);

    exit;
}

function vc_title($str, ...$pass) {
    return ucwords(strtolower($str), ...$pass);
}

function vc_slug($str) {
    return preg_replace('/\W/', '-', strtolower($str));
}

function vc_rand($len = 8) {
    return substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, $len);
}

function vc_number($value, $fractions = 0) {
    return number_format($value, $fractions, ',', '.');
}

function vc_options($name, $find = null, $default = null) {
    $options = vc_globals('options');

    if (!$options) {
        vc_globals('options', $options = require __DIR__ . '/options.php', true);
    }

    $group = $options[$name] ?? $default;

    if (null === $find) {
        return $group;
    }

    $found = array_search($find, $group);

    return false === $found ? $default : $found;
}

function vc_data($name, $find = null) {
    $data = vc_globals('data');

    if (!$data) {
        vc_globals('data', $data = require __DIR__ . '/data.php', true);
    }

    if ($find) {
        return $data[$name][$find] ?? null;
    }

    return $data[$name] ?? null;
}

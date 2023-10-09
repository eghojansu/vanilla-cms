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
}

function vc_dd(...$values) {
    vc_dump(...$values);

    exit;
}

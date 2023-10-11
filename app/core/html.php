<?php

namespace Vc\Html;

use Vc\Helper as h;

function shout($value) {
    echo safe($value);
}

function safe($value) {
    return htmlspecialchars($value);
}

function tag($tag, $props = null, $children = null, $close = null) {
    $content = $children;
    $closeTag = $close ?? tag_close($tag);

    if ($children instanceof \Closure) {
        $content = $children();
    }

    if (is_array($children)) {
        $content = implode('', $children);
    }

    return '<' . $tag . attr($props) . '>' . $content . ($closeTag ? '</' . $tag . '>' : '');
}

function attr($props) {
    if (!$props) {
        return '';
    }

    if (is_string($props)) {
        return ' ' . ltrim($props);
    }

    return h\reduce(
        $props,
        fn ($value, $prop, $attr) => $attr . attr_value($prop, $value),
        '',
    );
}

function attr_value($prop, $value) {
    if (is_numeric($prop)) {
        if (null === $value || false === $value) {
            return '';
        }

        return ' ' . safe($value);
    }

    if (null === $value || false === $value || (in_array($prop, array('class')) && !$value)) {
        return '';
    }

    $attr = ' ' . safe($prop);

    if (is_array($value)) {
        if ('class' === $prop) {
            $classes = h\reduce(
                $value,
                function ($name, $show, $classes) {
                    if (is_numeric($show)) {
                        $classes[] = $name;
                    } elseif ($name) {
                        $classes[] = $show;
                    } elseif (false !== $found = array_search($show, $classes)) {
                        unset($classes[$found]);
                    }

                    return $classes;
                },
                array(),
            );

            if ($classes) {
                $attr .= '="' . implode(' ', array_map('Vc\\Html\\safe', array_unique($classes))) . '"';
            }
        }
    } elseif (true !== $value) {
        $attr .= '="' . (prop_safe($prop) ? $value : safe($value)) . '"';
    }

    return $attr;
}

function merge(...$props) {
    return h\reduce(
        $props,
        function ($props, $key, $merged) {
            if (!$props) {
                return $merged;
            }

            if (isset($props['class'])) {
                $merged['class'] = array_merge(
                    h\pick($merged, 'class', array()),
                    h\split($props['class'], ' '),
                );

                unset($props['class']);
            }

            return array_merge($merged, $props);
        },
        array(),
    );
}

function attr_merge(...$props) {
    return attr(merge(...$props));
}

function tag_close($tag) {
    return !in_array($tag, array('img', 'input', 'link'));
}

function prop_safe($prop) {
    return in_array($prop, array('onclick'));
}

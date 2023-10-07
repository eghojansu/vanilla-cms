<?php

function vc_safe($value) {
    return htmlspecialchars($value);
}

function vc_html_tag($tag, $props = null, $children = null, $close = null) {
    $content = $children;
    $closeTag = $close ?? vc_html_tag_close($tag);

    if ($children instanceof \Closure) {
        $content = $children();
    }

    if (is_array($children)) {
        $content = implode('', $children);
    }

    return '<' . $tag . vc_html_attr($props) . '>' . $content . ($closeTag ? '</' . $tag . '>' : '');
}

function vc_html_attr($props) {
    if (!$props) {
        return '';
    }

    if (is_string($props)) {
        return ' ' . ltrim($props);
    }

    return vc_each(
        $props,
        fn ($value, $prop, $attr) => $attr . vc_html_attr_value($prop, $value),
        '',
    );
}

function vc_html_attr_value($prop, $value) {
    if (is_numeric($prop)) {
        if (null === $value || false === $value) {
            return '';
        }

        return ' ' . vc_safe($value);
    }

    if (null === $value || false === $value || (in_array($prop, array('class')) && !$value)) {
        return '';
    }

    $attr = ' ' . vc_safe($prop);

    if (is_array($value)) {
        if ('class' === $prop) {
            $classes = vc_each(
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
                $attr .= '="' . implode(' ', array_map('vc_safe', array_unique($classes))) . '"';
            }
        }
    } elseif (true !== $value) {
        $attr .= '="' . (vc_html_prop_safe($prop) ? $value : vc_safe($value)) . '"';
    }

    return $attr;
}

function vc_html_merge(...$props) {
    return vc_each(
        $props,
        function ($props, $key, $merged) {
            if (!$props) {
                return $merged;
            }

            if (isset($props['class'])) {
                $merged['class'] = array_merge(
                    vc_pick($merged, 'class', array()),
                    vc_split($props['class'], ' '),
                );

                unset($props['class']);
            }

            return array_merge($merged, $props);
        },
        array(),
    );
}

function vc_html_attr_merge(...$props) {
    return vc_html_attr(vc_html_merge(...$props));
}

function vc_html_tag_close($tag) {
    return !in_array($tag, array('img', 'input', 'link'));
}

function vc_html_prop_safe($prop) {
    return in_array($prop, array('onclick'));
}

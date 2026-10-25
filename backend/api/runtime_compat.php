<?php
declare(strict_types=1);

/**
 * Load pinned official Unicode/ctype polyfills when optional PHP extensions are absent.
 * No Composer, network access, configuration reads or output occurs at runtime.
 */
if (defined('DIGGA_RUNTIME_COMPAT_LOADED')) { return; }

$diggaCompatVendor = dirname(__DIR__) . '/data/sdk/vendor';

// Mbstring's official polyfill depends on iconv; install its fallback first.
if (!function_exists('iconv') || !function_exists('iconv_strlen') || !function_exists('iconv_substr') || !function_exists('iconv_strpos')) {
    require_once $diggaCompatVendor . '/symfony-polyfill-iconv/Iconv.php';
    // The PHP8 bootstrap also covers hosts which load iconv but disable selected functions.
    require_once $diggaCompatVendor . '/symfony-polyfill-iconv/bootstrap80.php';
}

if (!function_exists('mb_strlen') || !function_exists('mb_substr') || !function_exists('mb_strtolower') || !function_exists('mb_strpos')) {
    require_once $diggaCompatVendor . '/symfony-polyfill-mbstring/Mbstring.php';
    require_once $diggaCompatVendor . '/symfony-polyfill-mbstring/bootstrap.php';
}

if (!function_exists('ctype_alpha') || !function_exists('ctype_alnum') || !function_exists('ctype_digit') || !function_exists('ctype_space')) {
    require_once $diggaCompatVendor . '/symfony-polyfill-ctype/Ctype.php';
    require_once $diggaCompatVendor . '/symfony-polyfill-ctype/bootstrap.php';
}

unset($diggaCompatVendor);
define('DIGGA_RUNTIME_COMPAT_LOADED', true);

/** Safe capabilities only: never credentials, configuration values, paths or input content. */
if (!function_exists('digga_runtime_compatibility')) {
function digga_runtime_compatibility(): array {
    $capabilities = [];
    foreach ([
        'mbstring' => ['mb_strlen', 'mb_substr', 'mb_strtolower', 'mb_strpos'],
        'iconv' => ['iconv', 'iconv_strlen', 'iconv_substr', 'iconv_strpos'],
        'ctype' => ['ctype_alpha', 'ctype_alnum', 'ctype_digit', 'ctype_space'],
    ] as $extension => $functions) {
        $available = true;
        $internal = true;
        foreach ($functions as $function) {
            if (!function_exists($function)) { $available = false; $internal = false; continue; }
            $internal = $internal && (new ReflectionFunction($function))->isInternal();
        }
        $capabilities[$extension] = [
            'native' => extension_loaded($extension) && $internal,
            'available' => $available,
            'mode' => $available ? ($internal ? 'native' : 'polyfill') : 'unavailable',
        ];
    }
    return $capabilities;
}
}

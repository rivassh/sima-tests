<?php

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\ResponseFactory;

/**
 * Laravel bootstrap for PHPUnit tests
 */
$app = new Container();

$app->singleton('files', function () {
    return new Filesystem();
});

$app->singleton('events', function ($app) {
    return new Dispatcher($app);
});

$app->singleton('response', function ($app) {
    return new ResponseFactory($app);
});

$app->alias('Illuminate\Contracts\Routing\ResponseFactory', 'response');
$app->alias('Illuminate\Contracts\Container\Container', 'app');

/**
 * Global helper functions for Laravel
 */
if (!function_exists('app')) {
    /**
     * Get the available container instance.
     *
     * @param  string|array|null  $key
     * @param  mixed              $default
     * @return \Illuminate\Contracts\Container\Container|mixed
     */
    function app($key = null, $default = null)
    {
        global $app;

        if (is_null($key)) {
            return $app;
        }

        if ($app->bound($key)) {
            return $app->make($key);
        }

        if (is_array($key)) {
            return array_map(function ($value) use ($app, $default) {
                return $app->make($value, $default);
            }, $key);
        }

        return $app->bound($key) ? $app->make($key, $default) : value($default);
    }
}

if (!function_exists('response')) {
    /**
     * Get a response factory instance.
     *
     * @param  mixed  $content
     * @param  int    $status
     * @param  array  $headers
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     */
    function response($content = '', $status = 200, $headers = [])
    {
        if (func_num_args() === 0) {
            return app('response');
        }

        return app('response')->make($content, $status, $headers);
    }
}

if (!function_exists('now')) {
    /**
     * Get a new instance of the Carbon date.
     *
     * @param  string|int|null  $time
     * @param  \DateTimeZone|string|null  $tz
     * @return \Carbon\Carbon|\Carbon\CarbonImmutable
     */
    function now($time = null, $tz = null)
    {
        if (class_exists('Illuminate\Support\Carbon')) {
            return app('Illuminate\Support\Carbon')->now($time, $tz);
        }

        // Fallback to basic DateTime if Carbon is not available
        $dt = new \DateTime($time, $tz ?: new \DateTimeZone(UTC));
        return $dt;
    }
}
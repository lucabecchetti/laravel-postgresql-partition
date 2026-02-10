<?php

namespace Brokenice\LaravelPgsqlPartition\Http;

use Illuminate\Http\Request;

/**
 * Request wrapper that avoids Symfony 7.4+ deprecation of Request::get().
 * Overrides get() to use input() so any code (e.g. in app or other packages)
 * calling $request->get() no longer triggers the deprecation.
 */
class RequestCompat extends Request
{
    /**
     * Get an input item from the request.
     * Uses input() instead of deprecated get() for Symfony 7.4+ compatibility.
     *
     * @param  string|null  $key
     * @param  mixed  $default
     * @return mixed
     */
    public function get($key = null, $default = null)
    {
        return $this->input($key, $default);
    }
}

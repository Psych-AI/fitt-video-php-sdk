<?php

namespace Fitt\Video\Laravel;

use Illuminate\Support\Facades\Facade;

/**
 * The Fitt Video client as a facade. It is never cached, so every call
 * resolves a fresh client and sees the current config and HTTP fakes.
 *
 * @mixin LaravelVideoClient
 */
class FittVideo extends Facade
{
    /** A fresh client on every call (see FittVideoServiceProvider). */
    protected static $cached = false;

    protected static function getFacadeAccessor(): string
    {
        return LaravelVideoClient::class;
    }
}

<?php

namespace Fitt\Video;

/**
 * The SDK's version. It is sent as the User-Agent and must equal the root composer.json's
 * `options.versions`. Bump both together at release (CLAUDE.md, PHP SDK release steps).
 */
final class Version
{
    public const string VERSION = '1.1.0';
}

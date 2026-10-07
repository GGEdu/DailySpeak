<?php

namespace App\Services\News;

use UnexpectedValueException;

/**
 * A URL the harvester refuses to download: not http(s), or a host that is not public.
 */
final class UnsafeUrlException extends UnexpectedValueException
{
    public static function unsupportedScheme(): self
    {
        return new self('Only http and https addresses can be read.');
    }

    public static function nonPublicAddress(): self
    {
        return new self('This URL points to a private or reserved network address.');
    }
}

<?php

namespace App\Support;

/**
 * Where the admin lives. Normally /admin, but the owner can hide it behind
 * a private address by setting ADMIN_PATH (for example "staff-k7q2x") on
 * the server. Everything that needs the admin's address asks here, so the
 * old /admin simply does not exist on the site once a private one is set.
 */
final class AdminPath
{
    public const DEFAULT = 'admin';

    /** The address without slashes, e.g. "admin". Only letters, numbers, - and _ are accepted; anything else falls back to "admin". */
    public static function segment(): string
    {
        $value = trim((string) config('security.admin_path', self::DEFAULT), '/ ');

        return preg_match('/^[A-Za-z0-9_-]{1,60}$/', $value) === 1 ? $value : self::DEFAULT;
    }

    /** True when the admin sits behind a private address. */
    public static function isHidden(): bool
    {
        return self::segment() !== self::DEFAULT;
    }

    /** @return list<string> the request patterns that mean "the admin" (for $request->is(...)) */
    public static function patterns(): array
    {
        $segment = self::segment();

        return [$segment, $segment.'/*'];
    }

    /** A full link into the admin, e.g. url('settings'). */
    public static function url(string $path = ''): string
    {
        return url(self::segment().($path !== '' ? '/'.ltrim($path, '/') : ''));
    }
}

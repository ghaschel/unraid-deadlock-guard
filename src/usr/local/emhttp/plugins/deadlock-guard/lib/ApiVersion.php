<?php
declare(strict_types=1);
namespace DeadlockGuard;

/** The minimum describes the required API operations, not one particular build. */
final class ApiVersion
{
    public const MINIMUM = '4.36.0';

    public static function requirement(): string
    {
        return 'Unraid API ' . self::MINIMUM . ' or newer is required. See Troubleshooting.';
    }

    public static function error(mixed $version): ?string
    {
        $shown = is_string($version) && $version !== '' ? $version : '(missing or invalid)';
        $error = self::requirement() . ' Detected API version: ' . $shown . '.';
        if (
            !is_string($version) ||
            strlen($version) > 255 ||
            !preg_match(
                '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D',
                $version,
                $parts,
            )
        ) {
            return $error;
        }
        $prerelease = $parts[4] ?? '';
        foreach (explode('.', $prerelease) as $identifier) {
            if (ctype_digit($identifier) && strlen($identifier) > 1 && $identifier[0] === '0') {
                return $error;
            }
        }
        // Compare decimal strings without integer overflow; build metadata has no precedence.
        foreach (explode('.', self::MINIMUM) as $index => $minimum) {
            $actual = $parts[$index + 1];
            $comparison = strlen($actual) <=> strlen($minimum) ?: strcmp($actual, $minimum);
            if ($comparison !== 0) {
                return $comparison > 0 ? null : $error;
            }
        }
        return $prerelease === '' ? null : $error;
    }
}

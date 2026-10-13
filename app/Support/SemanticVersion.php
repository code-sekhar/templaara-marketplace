<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class SemanticVersion
{
    public static function newer(string $next, string $base): void
    {
        // Phase 5 deliberately supports stable MAJOR.MINOR.PATCH, not prerelease/build identifiers.
        $pattern = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/';
        if (strlen($next) > 50 || ! preg_match($pattern, $next) || ! preg_match($pattern, $base)) {
            self::invalid();
        }
        foreach (array_map(null, explode('.', $next), explode('.', $base)) as [$a,$b]) {
            $compare = strlen($a) <=> strlen($b);
            if (! $compare) {
                $compare = strcmp($a, $b);
            }
            if ($compare > 0) {
                return;
            }if ($compare < 0) {
                self::invalid();
            }
        }
        self::invalid();
    }

    private static function invalid(): never
    {
        throw ValidationException::withMessages(['version' => 'Use a unique MAJOR.MINOR.PATCH version greater than the current release.']);
    }
}

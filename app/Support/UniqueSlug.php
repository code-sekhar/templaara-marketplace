<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class UniqueSlug
{
    public function generate(Model $record, string $name, string $fallback = 'catalog'): string
    {
        $base = rtrim(substr(Str::slug($name) ?: $fallback, 0, 190), '-');
        $slug = $base;
        $suffix = 2;
        while ($record->newQuery()->where('slug', $slug)->when($record->exists, fn ($query) => $query->whereKeyNot($record->getKey()))->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}

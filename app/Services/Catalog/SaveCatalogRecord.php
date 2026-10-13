<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Support\UniqueSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveCatalogRecord
{
    /** @param array<string, mixed> $data */
    public function save(Model $record, array $data): void
    {
        $automatic = empty($data['slug']);
        // Generate on create or explicit blank input; preserve existing slugs when omitted on update.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                DB::transaction(function () use ($record, $data, $automatic): void {
                    if ($record instanceof Category) {
                        // Serialize hierarchy changes on PostgreSQL to prevent concurrent cycle creation.
                        if (DB::getDriverName() === 'pgsql') {
                            DB::select('SELECT pg_advisory_xact_lock(74201902)');
                        }
                        $this->validateParent($record, $data['parent_id'] ?? null);
                    }
                    if ($automatic) {
                        if ($record->exists && ! array_key_exists('slug', $data)) {
                            $data['slug'] = $record->getAttribute('slug');
                        } else {
                            $data['slug'] = app(UniqueSlug::class)->generate($record, $data['name']);
                        }
                    }
                    $record->fill($data)->save();
                });

                return;
            } catch (UniqueConstraintViolationException $exception) {
                if (! $automatic || $attempt === 4) {
                    throw ValidationException::withMessages(['slug' => 'This slug is already in use. Please choose another.']);
                }
                // The database constraint is authoritative. Retry in a new transaction after a race.
            }
        }
    }

    private function validateParent(Category $category, int|string|null $parentId): void
    {
        $visited = [];
        while ($parentId !== null) {
            if ((string) $parentId === (string) $category->getKey() || isset($visited[$parentId])) {
                throw ValidationException::withMessages(['parent_id' => 'A category cannot be its own parent or a descendant of itself.']);
            }
            $visited[$parentId] = true;
            $parent = Category::whereKey($parentId)->first();
            if (! $parent) {
                throw ValidationException::withMessages(['parent_id' => 'The selected parent no longer exists.']);
            }
            $parentId = $parent->parent_id;
        }
    }
}

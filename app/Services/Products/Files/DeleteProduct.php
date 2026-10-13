<?php

namespace App\Services\Products\Files;

use App\Models\Product;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteProduct
{
    public function delete(User $actor, Product $product): void
    {
        DB::transaction(function () use ($actor, $product): void {
            $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('delete', $locked);
            abort_if($locked->submissions()->exists(), 403, 'Products with moderation history cannot be deleted.');
            abort_if($locked->customerReviews()->exists(), 403, 'Products with customer review history cannot be deleted.');
            abort_if(SupportTicket::where('product_id', $locked->id)->exists(), 403, 'Products with support history cannot be deleted.');
            $locked->load(['media', 'releases.artifacts']);
            $jobs = [];
            foreach ($locked->media->concat($locked->releases->flatMap->artifacts) as $file) {
                abort_if(app(FileRetention::class)->referenced($file), 403);
                $jobs[] = app(FileRetention::class)->schedule($file->disk, $file->path);
            }
            $locked->delete();
            DB::afterCommit(function () use ($jobs): void {
                foreach ($jobs as $job) {
                    app(FileRetention::class)->cleanup($job);
                }
            });
        });
    }
}

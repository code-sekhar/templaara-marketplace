<?php

namespace App\Services\Development;

use App\Enums\AuthorApplicationAction;
use App\Enums\ProductArtifactType;
use App\Enums\ProductMediaType;
use App\Enums\ProductOrigin;
use App\Enums\ProductStatus;
use App\Enums\ProductWorkflowAction;
use App\Models\Category;
use App\Models\Industry;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductRevision;
use App\Models\Technology;
use App\Models\User;
use App\Services\Authors\AuthorWorkflow;
use App\Services\Authors\SaveAuthorProfile;
use App\Services\Marketplace\MarketplaceQuery;
use App\Services\Products\Files\ProductFiles;
use App\Services\Products\ProductUpdates;
use App\Services\Products\SaveProduct;
use App\Services\Products\Workflow\ProductWorkflow;
use App\Services\Settings\MarketplaceSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class DevelopmentDemoCatalog
{
    /** @var array<string,mixed> */
    private array $registry = [];

    public function registryPath(): string
    {
        return storage_path('app/development-demo-catalog/registry.json');
    }

    public function run(bool $remove = false, bool $refreshArtwork = false): int
    {
        if (! app()->environment(['local', 'testing', 'development'])) {
            throw new RuntimeException('Development environment required.');
        }
        if ($remove && $refreshArtwork) {
            throw new RuntimeException('Choose removal or artwork refresh, not both.');
        }
        $directory = dirname($this->registryPath());
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $lock = fopen($directory.'/command.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Unable to open the demo registry lock.');
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Another demo catalog command is running.');
        }
        try {
            $contents = is_file($this->registryPath()) ? file_get_contents($this->registryPath()) : null;
            if ($contents === false) {
                throw new RuntimeException('Unable to read the demo registry.');
            }
            $this->registry = $contents !== null ? json_decode($contents, true, flags: JSON_THROW_ON_ERROR) : ['version' => 1, 'token' => Str::random(24), 'users' => [], 'products' => []];
            if (($this->registry['version'] ?? null) !== 1 || empty($this->registry['token'])) {
                throw new RuntimeException('Unrecognized registry. Refusing to adopt or remove records.');
            }
            $this->saveRegistry();
            if ($remove) {
                return DB::transaction(function (): int {
                    $products = $this->registeredProducts();
                    foreach ($products as $product) {
                        // Publication history cannot be deleted. Removal is deliberately reversible visibility removal.
                        $product->forceFill(['is_active' => false])->save();
                        $this->registry['products'][$product->slug]['hidden_by_command'] = true;
                    }

                    $this->saveRegistry();

                    return count($products);
                });
            }
            if ($refreshArtwork) {
                $registered = $this->registeredProducts();
                if (count($registered) !== 10 || OrderItem::whereIn('product_id', array_map(fn ($p) => $p->id, $registered))->exists()) {
                    throw new RuntimeException('Artwork refresh requires exactly ten registered unpurchased demo products.');
                }
                $admin = $this->user('reviewer', 'Templaara Demo Curator', 'admin');
                foreach ($this->specifications() as [$name, $category, $section]) {
                    $this->refreshArtwork($registered['demo-'.Str::slug($name)], $admin, $name, $section);
                }

                return 10;
            }
            $specs = $this->specifications();
            $categories = Category::active()->whereIn('slug', array_column($specs, 1))->pluck('id', 'slug');
            if ($categories->count() !== count($specs) || ! Industry::active()->exists() || ! Technology::active()->exists()) {
                throw new RuntimeException('Existing active business catalog categories, industries and technologies are required. No catalog records were changed.');
            }
            $currencies = app(MarketplaceSettings::class)->currencies();
            if (! $currencies) {
                throw new RuntimeException('At least one enabled currency is required.');
            }
            $admin = $this->user('reviewer', 'Templaara Demo Curator', 'admin');
            $authors = [];
            foreach (['Northstar Demo Studio', 'PixelWorks Demo Systems', 'CloudHarbor Demo Apps'] as $i => $name) {
                $user = $this->user('seller-'.$i, $name, 'customer');
                $profile = $user->authorProfile;
                if (! $profile) {
                    $profile = app(SaveAuthorProfile::class)->save($user, [
                        'display_name' => $name, 'author_type' => 'individual', 'country_code' => 'IN',
                        'bio' => 'Fictional development seller presenting original demonstration business software. These products are visual fixtures, not commercial software.',
                        'support_email' => $user->email, 'website_url' => 'https://example.com',
                        'professional_headline' => 'Fictional business software studio', 'years_of_experience' => 0,
                        'confidential_work' => 'This is a fictional development portfolio. The demonstration software is illustrative and is not a real commercial deliverable.',
                        'technology_ids' => Technology::active()->limit(3)->pluck('id')->all(),
                    ]);
                }
                $workflow = app(AuthorWorkflow::class);
                if ($profile->status->value === 'draft') {
                    $workflow->transition($user, $profile, AuthorApplicationAction::Submit);
                }
                $profile->refresh();
                if ($profile->status->value === 'submitted') {
                    $workflow->transition($admin, $profile, AuthorApplicationAction::Start, $profile->applications()->firstOrFail()->id);
                }
                $profile->refresh();
                if ($profile->status->value === 'under_review') {
                    $workflow->transition($admin, $profile, AuthorApplicationAction::Approve, $profile->applications()->firstOrFail()->id);
                }
                $authors[] = $user->fresh();
            }
            $files = app(ProductFiles::class);
            foreach ($specs as $i => [$name, $category, $section, $purpose, $audience]) {
                $slug = 'demo-'.Str::slug($name);
                $product = $this->registeredProducts()[$slug] ?? null;
                if (! $product) {
                    if (Product::where('slug', $slug)->exists()) {
                        throw new RuntimeException('A reserved demo slug belongs to an unregistered record. Refusing to modify it.');
                    }
                    $author = $authors[$i % count($authors)];
                    $currency = $currencies[$i % count($currencies)];
                    $product = app(SaveProduct::class)->create($author, [
                        'name' => $name.' (Demo)', 'slug' => $slug, 'category_id' => $categories[$category],
                        'short_description' => $purpose.' Fictional development software preview.',
                        'description' => $purpose.' Bring daily work into a clear shared workspace, with practical tools for teams and business owners. This fictional development product is for visual evaluation only and is not commercial software.',
                        'business_problem' => 'Replace fragmented spreadsheets and disconnected daily processes. '.$purpose,
                        'target_audience' => [$audience, 'Growing business teams'],
                        'key_benefits' => ['Keep daily work organized', 'Give teams a shared view of progress', 'Make operational decisions with clearer context'],
                        'currency' => $currency, 'demo_url' => 'https://example.com/#development-demo',
                        'version_label' => '1.0.0-demo', 'support_period_days' => 90, 'updates_period_days' => 365,
                        'is_active' => true,
                        'meta_title' => $name.' — development preview', 'meta_description' => $purpose,
                        'industry_ids' => Industry::active()->orderBy('id')->limit(2)->pluck('id')->all(),
                        'technology_ids' => Technology::active()->orderBy('id')->limit(3)->pluck('id')->all(),
                        'features' => array_map(fn ($title, $order) => ['title' => $title, 'description' => 'A focused workspace to help your team '.strtolower($title).'.', 'sort_order' => $order], ['Organize daily operations', 'Coordinate your team', 'Review business activity'], [0, 1, 2]),
                        'offers' => array_map(fn ($type, $order) => ['name' => ['Business software', 'Multi-business package', 'Extended package'][$order], 'description' => 'Fictional package for development presentation only.', 'price' => ['4999.00', '8999.00', '19999.00'][$order], 'license_type' => $type, 'activation_limit' => [1, 5, 10][$order], 'support_period_days' => 90, 'updates_period_days' => 365, 'is_active' => true, 'is_default' => $order === 0, 'sort_order' => $order], ['single_business', 'multi_business', 'extended'], [0, 1, 2]),
                    ], ProductOrigin::Author);
                    $this->registry['products'][$slug] = ['id' => $product->id, 'owner_id' => $author->id];
                    $this->saveRegistry();
                }
                if ($this->registry['products'][$slug]['hidden_by_command'] ?? false) {
                    $product->forceFill(['is_active' => true])->save();
                    $this->registry['products'][$slug]['hidden_by_command'] = false;
                    $this->saveRegistry();
                }
                if ($i < 3 && $product->status === ProductStatus::Draft) {
                    app(SaveProduct::class)->update($admin, $product, ['is_featured' => true, 'offers' => $product->offers->map(fn ($offer) => $offer->only(['id', 'name', 'description', 'price', 'license_type', 'activation_limit', 'support_period_days', 'updates_period_days', 'is_default', 'is_active', 'sort_order']))->all()]);
                }
                $actor = $product->owner;
                if ($product->status === ProductStatus::Draft) {
                    if (! $product->cover()->exists()) {
                        $this->uploadImage($files, $actor, $product, ProductMediaType::Cover, $name, $section, 0);
                    }
                    for ($n = $product->screenshots()->count(); $n < 2; $n++) {
                        $this->uploadImage($files, $actor, $product, ProductMediaType::Screenshot, $name, $section, $n + 1);
                    }
                    $release = $product->releases()->first() ?? $files->saveRelease($actor, $product, ['version' => '1.0.0', 'changelog' => 'Fictional development demonstration. No commercial software included.']);
                    if (! $release->artifacts()->exists()) {
                        $path = tempnam(sys_get_temp_dir(), 'templaara-demo-zip-');
                        if ($path === false) {
                            throw new RuntimeException('Unable to create the development package.');
                        }
                        try {
                            $zip = new ZipArchive;
                            $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
                            $zip->addFromString('DEVELOPMENT-ONLY.txt', 'DEMO / DEVELOPMENT ONLY. This package is an inert placeholder, not software. Never sell or deploy it.');
                            $zip->close();
                            $files->uploadArtifact($actor, $product, $release, ProductArtifactType::SourcePackage, new UploadedFile($path, 'development-only.zip', 'application/zip', null, true));
                        } finally {
                            if (is_file($path)) {
                                unlink($path);
                            }
                        }
                    }
                }
                $workflow = app(ProductWorkflow::class);
                foreach ([ProductStatus::Draft->value => ProductWorkflowAction::Submit, ProductStatus::Submitted->value => ProductWorkflowAction::StartReview, ProductStatus::UnderReview->value => ProductWorkflowAction::Approve, ProductStatus::Approved->value => ProductWorkflowAction::Publish] as $status => $action) {
                    if ($product->fresh()->status->value === $status) {
                        $workflow->transition($action === ProductWorkflowAction::Submit ? $actor : $admin, $product, $action);
                    }
                }
                if ($product->fresh()->status !== ProductStatus::Published) {
                    throw new RuntimeException('A registered demo product has an unexpected lifecycle state.');
                }
            }
            $ids = array_column($this->registry['products'], 'id');
            if (app(MarketplaceQuery::class)->visible()->whereIn('products.id', $ids)->count() !== count($specs)) {
                throw new RuntimeException('Demo catalog is not fully visible through the normal public boundary.');
            }

            return count($specs);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function refreshArtwork(Product $product, User $admin, string $name, string $section): void
    {
        $updates = app(ProductUpdates::class);
        $version = '1.0.1';
        $marker = 'Development-only original category artwork refresh. No functional software changes.';
        $revision = ProductRevision::where('product_id', $product->id)->whereHas('release', fn ($q) => $q->where('version', $version))->first();
        if ($revision && $revision->release->changelog !== $marker) {
            throw new RuntimeException('Reserved artwork revision is not owned by this operation.');
        }
        if ($revision?->status === ProductStatus::Published) {
            return;
        }
        $actor = $product->owner;
        $revision ??= $updates->create($actor, $product, $version);
        if ($revision->editable()) {
            $updates->release($actor, $revision, ['version' => $version, 'changelog' => $marker]);
            foreach ($revision->media()->get() as $media) {
                if (! str_contains($media->path, '/updates/'.$revision->id.'/')) {
                    $updates->removeMedia($actor, $revision, $media->id);
                }
            }
            foreach (['cover', 'screenshot', 'screenshot'] as $i => $kind) {
                if ($revision->media()->where('type', $kind)->count() >= ($i === 2 ? 2 : 1)) {
                    continue;
                }
                $file = app(DemoCatalogArtwork::class)->image($name, $section, $i);
                try {
                    $updates->upload($actor, $revision, $kind, $file);
                } finally {
                    @unlink($file->getPathname());
                }
            }
            if (! $revision->release->artifacts()->where('is_current', true)->exists()) {
                $source = $product->releases()->findOrFail($revision->base_release_id)->artifacts()->where('type', 'source_package')->where('is_current', true)->firstOrFail();
                $path = tempnam(sys_get_temp_dir(), 'templaara-demo-revision-');
                if ($path === false) {
                    throw new RuntimeException('Unable to stage development package.');
                }
                try {
                    file_put_contents($path, Storage::disk($source->disk)->get($source->path));
                    $updates->upload($actor, $revision, 'source_package', new UploadedFile($path, 'development-only.zip', 'application/zip', null, true));
                } finally {
                    @unlink($path);
                }
            }
        }
        foreach ([ProductStatus::Draft->value => ProductWorkflowAction::Submit, ProductStatus::Submitted->value => ProductWorkflowAction::StartReview, ProductStatus::UnderReview->value => ProductWorkflowAction::Approve, ProductStatus::Approved->value => ProductWorkflowAction::Publish] as $status => $action) {
            if ($revision->fresh()->status->value === $status) {
                $updates->transition($action === ProductWorkflowAction::Submit ? $actor : $admin, $revision, $action);
            }
        }
        if ($revision->fresh()->status !== ProductStatus::Published) {
            throw new RuntimeException('Artwork revision has an unexpected moderation state.');
        }
    }

    /** @return array<string,Product> */
    private function registeredProducts(): array
    {
        $result = [];
        foreach ($this->registry['users'] as $key => $entry) {
            if (User::whereKey($entry['id'])->value('email') !== $key.'.'.strtolower($this->registry['token']).'@demo.templaara.invalid') {
                throw new RuntimeException('Demo owner identity mismatch.');
            }
        }
        foreach ($this->registry['products'] as $slug => $entry) {
            $product = Product::whereKey($entry['id'])->lockForUpdate()->first();
            if (! $product || $product->slug !== $slug || $product->owner_id !== $entry['owner_id'] || ! in_array($product->owner_id, array_column($this->registry['users'], 'id'), true)) {
                throw new RuntimeException('Registry identity mismatch. No unrelated records will be changed.');
            }
            $result[$slug] = $product;
        }

        return $result;
    }

    private function user(string $key, string $name, string $role): User
    {
        $email = $key.'.'.strtolower($this->registry['token']).'@demo.templaara.invalid';
        if (isset($this->registry['users'][$key])) {
            $user = User::whereKey($this->registry['users'][$key]['id'])->firstOrFail();
            if ($user->email !== $email) {
                throw new RuntimeException('Demo user identity mismatch.');
            }

            return $user;
        }
        $user = User::create(['name' => $name, 'email' => $email, 'password' => Str::random(64), 'email_verified_at' => now()]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole($role);
        $this->registry['users'][$key] = ['id' => $user->id];
        $this->saveRegistry();

        return $user;
    }

    private function saveRegistry(): void
    {
        $path = $this->registryPath();
        file_put_contents($path.'.tmp', json_encode($this->registry, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($path.'.tmp', 0600);
        rename($path.'.tmp', $path);
    }

    private function uploadImage(ProductFiles $files, User $actor, Product $product, ProductMediaType $type, string $name, string $section, int $variant): void
    {
        $image = app(DemoCatalogArtwork::class)->image($name, $section, $variant);
        try {
            $files->uploadMedia($actor, $product, $type, [$image]);
        } finally {
            unlink($image->getPathname());
        }
    }

    /** @return list<array{string,string,string,string,string}> */
    private function specifications(): array
    {
        return [
            ['Templaara Inventory Pro', 'pos-inventory', 'Stock', 'Coordinate stock, billing and purchasing for retail operations.', 'Retail and wholesale businesses'],
            ['PeopleFlow HR', 'hr-payroll', 'People', 'Bring attendance, team records and payroll preparation together.', 'HR and operations teams'],
            ['ClientNest CRM', 'crm', 'Pipeline', 'Track customer conversations and move opportunities forward.', 'Sales and service businesses'],
            ['Ledgerly Business', 'accounting-finance', 'Finance', 'Organize invoices, expenses and daily finance administration.', 'Business owners and accounting teams'],
            ['FieldPilot', 'field-service-management', 'Field visits', 'Plan service visits and coordinate technicians in the field.', 'Field service businesses'],
            ['ShopSphere', 'ecommerce', 'Orders', 'Manage online merchandising and fulfillment in one workspace.', 'Online retailers'],
            ['Bookora', 'booking-reservation', 'Booking', 'Coordinate appointments, availability and customer bookings.', 'Appointment-based businesses'],
            ['ProjectDeck', 'project-management', 'Projects', 'Turn projects into clear plans and coordinated team tasks.', 'Project and delivery teams'],
            ['InsightBoard', 'business-analytics', 'Analytics', 'Bring operational reports into a focused business workspace.', 'Business owners and analysts'],
            ['SupportDesk', 'customer-support', 'Tickets', 'Organize customer requests and coordinate service responses.', 'Customer service teams'],
        ];
    }
}

<?php

use App\Models\Category;
use App\Models\User;
use Database\Seeders\BusinessCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

test('premium guest homepage keeps genuine empty catalog and public navigation', function (): void {
    $this->seed(BusinessCatalogSeeder::class);
    $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->component('marketplace/Home')->has('featured', 0)->has('recent', 0)->has('marketplaceNavigation', 10));
});

test('premium discovery exposes unknown active categories without private fields', function (): void {
    Category::factory()->create(['name' => 'Specialized business tools for growing service teams', 'slug' => 'specialized-business', 'is_active' => true]);
    Category::factory()->create(['is_active' => false]);
    $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->has('marketplaceNavigation', 1)->where('marketplaceNavigation.0.slug', 'specialized-business')->missing('marketplaceNavigation.0.id')->missing('marketplaceNavigation.0.is_active'));
});

test('premium customer dashboard does not invent purchases licenses or invoice totals', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create()->assignRole('customer');
    $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->component('Dashboard')->where('workspace.counts', ['purchases' => 0, 'active_licenses' => 0, 'open_tickets' => 0, 'invoices' => 0]));
});

test('original premium illustrations are self contained passive SVG documents', function (): void {
    foreach (['saas-workspace', 'author-studio', 'catalog-discovery'] as $name) {
        $source = file_get_contents(public_path('illustrations/'.$name.'.svg'));
        expect(strlen($source))->toBeLessThan(12000);
        expect($source)->not->toContain('<script', '<foreignObject', 'href=', 'onload=');
        expect(simplexml_load_string($source))->not->toBeFalse();
    }
});

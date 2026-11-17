<?php

namespace Database\Seeders;

use App\Models\LegalDocument;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class LegalContentSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['edit_legal_content', 'publish_legal_content'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::query()->where('name', 'admin')->where('guard_name', 'web')->first()
            ?->givePermissionTo(['edit_legal_content', 'publish_legal_content']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        LegalDocument::createMissingDefaultDrafts();
    }
}

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

        $templates = [
            'privacy' => ['Privacy Policy', "About this policy\n[Organisation name] operates [service name]. Contact our privacy team at [contact email]. The bracketed names are filled in from Admin → Branding; review this draft for your product before publishing.\n\nInformation we process\nDescribe account details, transactions, device information and any other information collected by your service.\n\nPurposes and legal grounds\nExplain why each category is processed and the applicable legal basis.\n\nSharing, retention and transfers\nIdentify recipients, retention periods and any international transfer arrangements.\n\nYour choices and rights\nExplain applicable access, correction, deletion, objection and complaint procedures. Use Cookie preferences to change optional cookie choices.\n\nUpdates\nPublished versions and the last-updated date appear on this page."],
            'terms' => ['Terms and Conditions', "About this service\nThese terms govern [service name], provided by [organisation name]. The bracketed names are filled in from Admin → Branding; review this draft before publishing.\n\nUsing the service\nDescribe eligibility, acceptable use and account responsibilities.\n\nCharges and cancellations\nSpecify pricing, payment conditions, cancellation and refund arrangements where applicable.\n\nContent and intellectual property\nDescribe ownership, licences and user-submitted content.\n\nAvailability, liability and termination\nSpecify service availability, liability provisions, suspension and termination in terms suitable for your product.\n\nDisputes and contact\nIdentify the applicable governing law, dispute process and contact details."],
            'cookies' => ['Cookie Policy', "About cookies\nCookies help a website remember information between visits. Review this draft and list the cookies actually used by your product before publishing.\n\nNecessary cookies\nOur application uses cookies for sign-in, request protection and remembering your privacy preferences.\n\nOptional cookies\nDescribe any analytics and marketing cookies, their providers, purposes and retention periods. Optional features should run only when the corresponding preference is enabled.\n\nChanging your choice\nOpen Cookie preferences in the footer to accept, reject or change optional categories. Changes apply on the next page load."],
        ];
        foreach ($templates as $slug => [$title, $body]) {
            LegalDocument::query()->firstOrCreate(['slug' => $slug], ['title' => $title, 'body' => $body]);
        }
    }
}

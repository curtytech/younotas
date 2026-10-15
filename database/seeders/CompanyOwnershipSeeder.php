<?php

namespace Database\Seeders;

use App\Models\FocusNfeSetting;
use App\Models\User;
use App\Services\FocusNfeConfigService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Explicit synchronization also works when the parent seeder suppresses model events. */
class CompanyOwnershipSeeder extends Seeder
{
    public function run(): void
    {
        $configuration = app(FocusNfeConfigService::class);

        User::query()->whereIn('email', ['test@example.com', 'nfe-teste@example.com', 'mil.mage.teste@example.com'])
            ->each(function (User $user) use ($configuration): void {
                $company = $configuration->ensureCompanyForUser($user);
                $setting = FocusNfeSetting::query()->where('user_id', $user->id)->first();

                if ($setting) {
                    $setting->company_id ??= $company->id;
                    $setting->settings = FocusNfeConfigService::sanitizeSnapshot($setting->settings ?? []);
                    $setting->legacyOwnerConfiguration = true;
                    $configuration->synchronizeLegacySetting($setting);
                    $setting->save();
                }

                foreach (['focus_nfe_settings', 'clients', 'products', 'services', 'technicians', 'sales',
                    'service_orders', 'stock_movements', 'vehicle', 'drivers', 'fines', 'appeals', 'fiscal_documents'] as $table) {
                    DB::table($table)->where('user_id', $user->id)->whereNull('company_id')->update(['company_id' => $company->id]);
                }
            });
    }
}

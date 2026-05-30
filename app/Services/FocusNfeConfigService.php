<?php

namespace App\Services;

use App\Models\User;

class FocusNfeConfigService
{
    public function forUser(User $user): array
    {
        $user->loadMissing('focusNfeSetting');

        $settings = $user->focusNfeSetting?->settings ?? [];

        return array_replace_recursive(
            config('services.focus_nfe', []),
            is_array($settings) ? $settings : [],
        );
    }
}

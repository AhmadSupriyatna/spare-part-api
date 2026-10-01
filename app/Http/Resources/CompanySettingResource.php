<?php

namespace App\Http\Resources;

use App\Models\CompanySetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CompanySetting
 */
class CompanySettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'logo_url' => $this->logoUrl(),
            // Falls back to the hardcoded default the app shipped with —
            // never blank in the UI just because no one has set it yet.
            'app_name' => $this->app_name ?? 'Sistem Manajemen Spare Part',
            'app_logo_url' => $this->appLogoUrl(),
            'avg_weekly_operating_hours' => $this->avg_weekly_operating_hours,
            'kwh_input_mode' => $this->kwh_input_mode,
        ];
    }
}

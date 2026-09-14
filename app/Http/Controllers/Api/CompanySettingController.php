<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCompanySettingRequest;
use App\Http\Resources\CompanySettingResource;
use App\Models\CompanySetting;
use App\Services\CompanyLogoService;

class CompanySettingController extends Controller
{
    public function __construct(private readonly CompanyLogoService $logos) {}

    public function show(): CompanySettingResource
    {
        return new CompanySettingResource(CompanySetting::current());
    }

    public function update(UpdateCompanySettingRequest $request): CompanySettingResource
    {
        $setting = CompanySetting::current();
        $data = $request->safe()->except('logo');

        if ($request->hasFile('logo')) {
            $this->logos->delete($setting->logo_path);
            $data['logo_path'] = $this->logos->store($request->file('logo'));
        }

        $setting->update($data);

        return new CompanySettingResource($setting);
    }
}

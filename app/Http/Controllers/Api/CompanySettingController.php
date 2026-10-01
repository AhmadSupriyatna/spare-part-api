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
        $data = $request->safe()->except(['logo', 'app_logo', 'app_name']);

        if ($request->hasFile('logo')) {
            $this->logos->delete($setting->logo_path);
            $data['logo_path'] = $this->logos->store($request->file('logo'));
        }

        // "Nama Aplikasi" + its logo are the app's own in-product branding,
        // not the company's printed-paperwork identity above — only ever
        // persisted for a Superadmin, silently dropped otherwise rather
        // than rejecting the whole request (the rest of the form is still
        // valid for Admin Spare Part/Supervisor).
        if ($request->user()->hasRole('superadmin')) {
            if ($request->has('app_name')) {
                $data['app_name'] = $request->input('app_name') ?: null;
            }

            if ($request->hasFile('app_logo')) {
                $this->logos->delete($setting->app_logo_path);
                $data['app_logo_path'] = $this->logos->store($request->file('app_logo'));
            }
        }

        $setting->update($data);

        return new CompanySettingResource($setting);
    }
}

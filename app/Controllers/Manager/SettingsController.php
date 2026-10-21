<?php
/**
 * SARMAD Attendance - manager panel: settings of his OWN customer.
 *
 * Editable here: business name, logo, mobile, email, address, description and
 * the customer level work hints. NOT editable here (owner level, and not
 * reachable through this controller at all): subscription, feature flags,
 * owner settings, device reset, customer status.
 */

declare(strict_types=1);

namespace App\Controllers\Manager;

use App\Core\Redirect;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\TenantContext;
use App\Core\View;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerSetting;
use App\Models\FeatureFlag;
use App\Services\FeatureService;
use App\Services\UploadService;
use App\Support\Capability;
use App\Support\Input;

final class SettingsController
{
    public function index(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();

        $customer = Customer::find($customerId);
        $settings = CustomerSetting::all($customerId);

        return Response::html(View::render('manager/settings/index', [
            'title'    => 'تنظیمات کسب‌وکار',
            'customer' => $customer,
            'settings' => $settings,
            'features' => FeatureService::stateFor($customerId),
            'plan'     => \App\Models\Subscription::currentFor($customerId),
            'money'    => ['IRT' => 'تومان', 'IRR' => 'ریال'],
            'error'    => Session::getFlash('error'),
            'success'  => Session::getFlash('success'),
        ], 'layouts/app'));
    }

    public function update(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();

        if (($denied = Capability::check($request, 'customer.profile.edit')) !== null) {
            return $denied;
        }

        $name    = Input::text($request->input('name', ''), 150);
        $mobile  = Input::mobile($request->input('contact_phone', ''));
        $email   = Input::text($request->input('contact_email', ''), 120);

        if ($name === '') {
            Session::flash('error', 'نام کسب‌وکار الزامی است.');

            return Redirect::response('/manager/settings');
        }

        if ($mobile !== '' && !Input::isMobile($mobile)) {
            Session::flash('error', 'شماره موبایل معتبر نیست.');

            return Redirect::response('/manager/settings');
        }

        if (!Input::isEmail($email)) {
            Session::flash('error', 'ایمیل معتبر نیست.');

            return Redirect::response('/manager/settings');
        }

        $before   = Customer::find($customerId) ?? [];
        $logoPath = (string) ($before['logo_path'] ?? '');

        if ($request->hasFile('logo')) {
            $stored = UploadService::storeLogo(is_array($request->file('logo')) ? $request->file('logo') : [], (string) ($before['uuid'] ?? ''));

            if (!$stored['ok']) {
                Session::flash('error', $stored['message']);

                return Redirect::response('/manager/settings');
            }

            if ($logoPath !== '') {
                UploadService::delete(ltrim($logoPath, '/'));
            }

            $logoPath = $stored['relative'];

            AuditLog::record('customer.logo', 'success', 'لوگوی کسب‌وکار به‌روزرسانی شد.', [
                'file' => $logoPath,
            ], 'app', 'customer', $customerId, $customerId);
        }

        // updateProfile() only accepts the business identity columns, so a
        // subscription / status / feature field smuggled into the POST would
        // be dropped silently.
        Customer::updateProfile($customerId, [
            'name'          => $name,
            'company_name'  => Input::text($request->input('company_name', ''), 150),
            'contact_email' => $email !== '' ? $email : null,
            'contact_phone' => $mobile !== '' ? $mobile : null,
            'address'       => Input::multiline($request->input('address', ''), 255),
            'logo_path'     => $logoPath,
        ]);

        $changed = CustomerSetting::putMany($customerId, [
            'business_name'      => $name,
            'description'        => Input::multiline($request->input('description', ''), 500),
            'work_start_hint'    => Input::time($request->input('work_start_hint', '')) ?? '',
            'work_end_hint'      => Input::time($request->input('work_end_hint', '')) ?? '',
            'late_grace_minutes' => (string) (Input::int($request->input('late_grace_minutes', 0), 0, 600) ?? 0),
            'early_grace_minutes' => (string) (Input::int($request->input('early_grace_minutes', 0), 0, 600) ?? 0),
        ], (int) (Session::userId() ?? 0));

        AuditLog::record('customer.profile.update', 'success', 'اطلاعات کسب‌وکار توسط مدیر مشتری ویرایش شد.', [
            'changed_keys' => $changed,
            'logo'         => $logoPath,
        ], 'app', 'customer', $customerId, $customerId);

        Session::flash('success', 'اطلاعات کسب‌وکار ذخیره شد.');

        return Redirect::response('/manager/settings');
    }

    /**
     * Manager level read of the feature state - editing is owner-only and
     * there is no POST route to do it from this panel.
     */
    public function features(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();

        if (($denied = Capability::check($request, 'feature.manage')) !== null) {
            // A manager is allowed to LOOK at his own flags, so the denial is
            // only about changing them - show the read only page instead.
            return Response::html(View::render('manager/settings/features', [
                'title'    => 'امکانات فعال',
                'features' => FeatureService::stateFor($customerId),
                'customer' => Customer::find($customerId),
                'error'    => Session::getFlash('error'),
                'success'  => Session::getFlash('success'),
            ], 'layouts/app'));
        }

        // Owners who impersonate see the same read only page: changing flags
        // happens in /owner/customers/{uuid}/features only.
        return Response::html(View::render('manager/settings/features', [
            'title'    => 'امکانات فعال',
            'features' => FeatureService::stateFor($customerId),
            'customer' => Customer::find($customerId),
            'error'    => Session::getFlash('error'),
            'success'  => Session::getFlash('success'),
        ], 'layouts/app'));
    }
}

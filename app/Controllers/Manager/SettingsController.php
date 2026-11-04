<?php
/**
 * SARMAD Attendance - manager panel: settings of his OWN customer.
 *
 * Editable here: business name, mobile, email, address, description and the
 * customer level work hints. NOT editable here (owner level, and not
 * reachable through this controller at all): subscription, feature flags,
 * owner settings, device reset, customer status - and, since stage 20, the
 * BRANDING (logo + brand name). Branding is a VIP feature the owner of the
 * platform sets per customer: a manager may read it, never change it.
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

        /* ---- STAGE 20: branding is OWNER ONLY ----------------------------
           The logo and the brand name of a customer are a VIP feature the
           platform owner sells; a manager must not be able to change them -
           not through this form, and not by posting the fields directly.
           Instead of silently ignoring a smuggled `logo` file we refuse the
           whole request and leave an audit trail, so an attempt is visible.
        ------------------------------------------------------------------ */
        if ($request->hasFile('logo') || trim((string) $request->input('brand_name', '')) !== ''
            || trim((string) $request->input('logo_path', '')) !== '') {
            AuditLog::record('customer.brand.denied', 'denied',
                'تلاش مدیر مشتری برای تغییر برند/لوگو رد شد.', [
                    'customer_id'  => $customerId,
                    'has_file'     => $request->hasFile('logo'),
                    'brand_name'   => trim((string) $request->input('brand_name', '')),
                ], 'security', 'customer', $customerId, $customerId);

            Session::flash('error', 'برند و لوگوی این مجموعه فقط توسط مالک سامانه قابل تغییر است.');

            return Redirect::response('/manager/settings');
        }

        // updateProfile() only accepts the contact identity columns, so a
        // subscription / status / feature field smuggled into the POST would
        // be dropped silently - and `logo_path` is not in that list at all.
        Customer::updateProfile($customerId, [
            'name'          => $name,
            'company_name'  => Input::text($request->input('company_name', ''), 150),
            'contact_email' => $email !== '' ? $email : null,
            'contact_phone' => $mobile !== '' ? $mobile : null,
            'address'       => Input::multiline($request->input('address', ''), 255),
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
            // branding is not part of this form any more (owner only)
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

<?php
/**
 * SARMAD Attendance - manager panel: activity log of HIS customer only.
 *
 * The customer id always comes from the session, never from the request, so a
 * manager can not read another customer's activities. The owner's own login
 * rows and other tenants simply do not exist for this page.
 */

declare(strict_types=1);

namespace App\Controllers\Manager;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\TenantContext;
use App\Core\View;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Employee;
use App\Support\Input;

final class ActivityController
{
    public function index(Request $request): Response
    {
        $customerId = TenantContext::requireCustomerId();

        $filters = [
            'customer_id' => $customerId,
            'q'           => Input::text($request->input('q', ''), 60),
            'channel'     => Input::choice($request->input('channel', ''), array_flip(AuditLog::channels())),
            'result'      => Input::choice($request->input('result', ''), ['success' => '1', 'failure' => '1', 'denied' => '1', 'error' => '1']),
            'action'      => Input::text($request->input('action', ''), 60),
            'actor'       => Input::text($request->input('actor', ''), 60),
            'from'        => Input::date($request->input('from', '')) ?? '',
            'to'          => Input::date($request->input('to', '')) ?? '',
        ];

        $listing = AuditLog::paginate($filters, $request->page(), $request->perPage(30));

        return Response::html(View::render('manager/activity/index', [
            'title'    => 'رویدادهای کسب‌وکار',
            'listing'  => $listing,
            'filters'  => $filters,
            'channels' => AuditLog::channels(),
            'summary'  => AuditLog::summary($customerId, 7),
            'customer' => Customer::find($customerId),
            'error'    => Session::getFlash('error'),
            'success'  => Session::getFlash('success'),
        ], 'layouts/app'));
    }
}

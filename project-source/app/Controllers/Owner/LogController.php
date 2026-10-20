<?php
/**
 * SARMAD Attendance - owner panel: audit log browser.
 *
 * Filters (action / actor / customer / date range / result / free text) are
 * all applied as prepared statement parameters; pagination is real SQL
 * LIMIT/OFFSET with a counted total. Nothing is filtered in PHP, so a
 * customer can never see a row that does not belong to them - the owner
 * page itself is owner-only.
 */

declare(strict_types=1);

namespace App\Controllers\Owner;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\AuditLog;
use App\Services\AuditService;

final class LogController
{
    public function index(Request $request): Response
    {
        $filters = [
            'action'      => (string) $request->input('action', ''),
            'actor'       => (string) $request->input('actor', ''),
            'result'      => (string) $request->input('result', ''),
            'channel'     => (string) $request->input('channel', ''),
            'customer_id' => $request->int('customer_id', 0),
            'from'        => (string) $request->input('from', ''),
            'to'          => (string) $request->input('to', ''),
            'q'           => (string) $request->input('q', ''),
        ];

        $listing = AuditLog::paginate($filters, $request->page(), $request->perPage(30));

        return Response::html(View::render('owner/logs/index', [
            'title'    => 'گزارش رویدادهای سامانه',
            'listing'  => $listing,
            'filters'  => $filters,
            'actions'  => AuditService::actions(),
            'counts'   => AuditLog::actionCounts(30),
            'channels' => AuditLog::channels(),
            'customers'=> $this->customerOptions(),
            'active'   => 'logs',
        ], 'layouts/owner'));
    }

    /**
     * CSV export of the CURRENT filter (owner only, streamed as a file).
     */
    public function export(Request $request): Response
    {
        $filters = [
            'action'      => (string) $request->input('action', ''),
            'actor'       => (string) $request->input('actor', ''),
            'result'      => (string) $request->input('result', ''),
            'customer_id' => $request->int('customer_id', 0),
            'from'        => (string) $request->input('from', ''),
            'to'          => (string) $request->input('to', ''),
            'q'           => (string) $request->input('q', ''),
        ];

        $listing = AuditLog::paginate($filters, 1, 200);

        $lines   = [];
        $lines[] = implode(',', ['id', 'created_at', 'action', 'event', 'actor', 'role', 'customer', 'result', 'description', 'ip']);

        foreach ($listing['rows'] as $row) {
            $lines[] = implode(',', array_map(static function ($value): string {
                $value = str_replace('"', '""', (string) $value);

                return '"' . $value . '"';
            }, [
                $row['id'] ?? '',
                $row['created_at'] ?? '',
                $row['action'] ?? $row['event'] ?? '',
                $row['event'] ?? '',
                $row['actor_name'] ?? $row['actor_label'] ?? '',
                $row['actor_type'] ?? '',
                $row['customer_name'] ?? '',
                $row['result'] ?? '',
                $row['description'] ?? '',
                $row['ip'] ?? '',
            ]));
        }

        return Response::make("\xEF\xBB\xBF" . implode("\n", $lines), 200)
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="attendance-logs-' . date('Ymd-His') . '.csv"');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function customerOptions(): array
    {
        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']],
            Database::select(
                'SELECT `id`, `name` FROM `' . Database::table('customers') . '` WHERE `deleted_at` IS NULL ORDER BY `name` ASC LIMIT 500'
            )
        );
    }
}

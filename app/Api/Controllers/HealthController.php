<?php
/**
 * SARMAD Attendance - API health probe.
 */

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiResponse;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class HealthController
{
    public function index(Request $request): Response
    {
        $tables = [];

        try {
            $tables = Database::projectTables();
        } catch (\Throwable $e) {
            return ApiResponse::serverError('اتصال به دیتابیس برقرار نیست.');
        }

        return ApiResponse::success([
            'app'      => (string) Config::get('app.name', 'SARMAD Attendance'),
            'version'  => (string) Config::get('app.version', '1.0.0'),
            'php'      => PHP_VERSION,
            'database' => [
                'driver' => 'mysql',
                'status' => 'connected',
                'prefix' => Database::prefix(),
                'tables' => count($tables),
            ],
            'time'     => date('c'),
        ]);
    }
}

<?php
/**
 * SARMAD Attendance - stage 7 Excel export (REAL .xlsx).
 *
 * The workbook is produced by PhpSpreadsheet (see composer.json / vendor/), not
 * by renaming a CSV or writing an HTML table: the output is a genuine OOXML
 * package and the test suite re-opens every file it produces and reads the
 * cells back.
 *
 * Every sheet carries:
 *   - the report title (merged, RTL sheet, Persian font),
 *   - the customer name, the Jalali AND Gregorian range, the generated-at
 *     stamp and the filters that were applied,
 *   - one header row with a fill, a border and a freeze pane right below it,
 *   - AutoFilter over the whole table,
 *   - the summary of the report under the table,
 *   - tuned column widths.
 *
 * Tenant isolation: the rows come from App\Services\ReportService, which scopes
 * every query by customer_id. Nothing else is read here, so an export can never
 * contain another customer's rows.
 *
 * Memory: the report layer caps the row count (RANGE_LIMIT) and requires a date
 * range; the sheet is written straight to the output stream.
 */

declare(strict_types=1);

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Support\Jalali;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

final class ExcelExporter
{
    /** Hard cap per sheet, independent of the view pagination. */
    public const RANGE_LIMIT = 20000;

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $meta    customer name, actor, filters used
     * @return array{filename:string,bytes:string}
     */
    public function build(int $customerId, string $type, array $filters, array $meta = []): array
    {
        $report = (new ReportService())->build($customerId, $type, $filters);

        $rows = array_slice($report['rows'], 0, self::RANGE_LIMIT);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('SARMAD Attendance')
            ->setTitle($report['title'])
            ->setDescription('گزارش حضور و غیاب - ' . $report['title']);

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->sheetTitle($report['title']));
        $sheet->setRightToLeft(true);

        $columns = $report['columns'];
        $count   = max(1, count($columns));
        $lastCol = $this->columnLetter($count);

        /* ---------------- head block ---------------- */
        $sheet->mergeCells('A1:' . $lastCol . '1');
        $sheet->setCellValue('A1', $report['title']);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15)->setName('Tahoma');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(30);

        $customerName = (string) ($meta['customer_name'] ?? '');
        $from         = (string) ($filters['date_from'] ?? '');
        $to           = (string) ($filters['date_to'] ?? '');

        $sheet->setCellValue('A2', 'مجموعه: ' . $customerName);
        $sheet->setCellValue('A3', 'بازه: ' . ($from !== '' ? Jalali::date($from) : '—') . ' تا ' . ($to !== '' ? Jalali::date($to) : '—')
            . '   (' . $from . ' تا ' . $to . ')');
        $sheet->setCellValue('A4', 'تاریخ تولید گزارش: ' . Jalali::date(date('Y-m-d')) . ' — ' . date('H:i')
            . '   |   تعداد ردیف: ' . count($rows));
        $sheet->setCellValue('A5', 'فیلترها: ' . $this->filtersLabel($filters, $meta) . '   |   کد دوره محاسبه: ' . PayrollCalculator::VERSION);

        foreach (['A2', 'A3', 'A4', 'A5'] as $cell) {
            $sheet->getStyle($cell)->getFont()->setName('Tahoma')->setSize(10)->setBold(false);
        }

        $sheet->getStyle('A5')->getFont()->setItalic(true);

        /* ---------------- header row ---------------- */
        $headerRow = 7;
        $col       = 1;

        foreach ($columns as $column) {
            $sheet->setCellValue($this->columnLetter($col) . $headerRow, (string) $column['label']);
            $col++;
        }

        $headerRange = 'A' . $headerRow . ':' . $lastCol . $headerRow;
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->setName('Tahoma')->setSize(10);
        $sheet->getStyle($headerRange)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('1F2937');
        $sheet->getStyle($headerRange)->getFont()->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension($headerRow)->setRowHeight(24);

        /* ---------------- data rows ---------------- */
        $rowIndex = $headerRow + 1;

        foreach ($rows as $row) {
            $col = 1;

            foreach ($columns as $column) {
                $key   = (string) $column['key'];
                $value = $row[$key] ?? '';
                $cell  = $this->columnLetter($col) . $rowIndex;

                $format = (string) ($column['format'] ?? '');

                if ($format === 'money' || $format === 'int' || $format === 'minutes') {
                    $sheet->setCellValueExplicit(
                        $cell,
                        (float) $value,
                        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
                    );

                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($format === 'money' ? '#,##0' : '#,##0');
                } else {
                    $sheet->setCellValueExplicit($cell, (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }

                $sheet->getStyle($cell)->getFont()->setName('Tahoma')->setSize(10);
                $col++;
            }

            $rowIndex++;
        }

        $lastRow = max($headerRow + 1, $rowIndex - 1);

        $sheet->getStyle('A' . $headerRow . ':' . $lastCol . $lastRow)->getBorders()
            ->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D1D5DB');

        $sheet->getStyle('A' . ($headerRow + 1) . ':' . $lastCol . $lastRow)
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);

        $sheet->freezePane('A' . ($headerRow + 1));
        $sheet->setAutoFilter('A' . $headerRow . ':' . $lastCol . $lastRow);

        /* ---------------- summary block ---------------- */
        $summaryRow = $lastRow + 2;
        $sheet->setCellValue('A' . $summaryRow, 'خلاصه گزارش');
        $sheet->getStyle('A' . $summaryRow)->getFont()->setBold(true)->setName('Tahoma')->setSize(11);
        $summaryRow++;

        foreach ($report['summary'] as $card) {
            $sheet->setCellValue('A' . $summaryRow, (string) $card['label']);

            $value = $card['value'];

            if (is_numeric($value)) {
                $sheet->setCellValueExplicit('B' . $summaryRow, (float) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                $sheet->getStyle('B' . $summaryRow)->getNumberFormat()->setFormatCode('#,##0');
            } else {
                $sheet->setCellValueExplicit('B' . $summaryRow, (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }

            $sheet->getStyle('A' . $summaryRow)->getFont()->setBold(true)->setName('Tahoma')->setSize(10);
            $sheet->getStyle('B' . $summaryRow)->getFont()->setName('Tahoma')->setSize(10);
            $summaryRow++;
        }

        if (($report['note'] ?? '') !== '') {
            $sheet->setCellValue('A' . $summaryRow, (string) $report['note']);
            $sheet->getStyle('A' . $summaryRow)->getFont()->setItalic(true)->setName('Tahoma')->setSize(9);
        }

        /* ---------------- widths ---------------- */
        $col = 1;

        foreach ($columns as $column) {
            $width = max(12, min(38, mb_strlen((string) $column['label'], 'UTF-8') + 6));
            $sheet->getColumnDimension($this->columnLetter($col))->setWidth($width);
            $col++;
        }

        $sheet->getColumnDimension('A')->setWidth(max(18, $sheet->getColumnDimension('A')->getWidth()));

        $filename = $this->filename($type, $from, $to);

        return [
            'spreadsheet' => $spreadsheet,
            'filename'    => $filename,
            'type'        => $type,
            'title'       => $report['title'],
            'rows'        => count($rows),
        ];
    }

    /**
     * Write the workbook to a file inside storage (never to a public path).
     *
     * @return array{path:string,filename:string,bytes:int,rows:int}
     */
    public function toFile(array $built): array
    {
        $dir = \App\Core\Config::path('storage/exports');

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('پوشه خروجی قابل ایجاد نیست.');
        }

        $path = $dir . '/' . $built['filename'];

        (new Xlsx($built['spreadsheet']))->save($path);

        return [
            'path'     => $path,
            'filename' => $built['filename'],
            'bytes'    => (int) filesize($path),
            'rows'     => (int) $built['rows'],
        ];
    }

    /**
     * The binary body of the workbook, ready for an HTTP response.
     */
    public function toBinary(array $built): string
    {
        $file = $this->toFile($built);

        $body = (string) file_get_contents($file['path']);
        @unlink($file['path']);

        return $body;
    }

    public function filename(string $type, string $from, string $to): string
    {
        $type = preg_replace('/[^a-z_]/', '', strtolower($type)) ?: 'report';
        $from = preg_replace('/[^0-9\-]/', '', $from);
        $to   = preg_replace('/[^0-9\-]/', '', $to);

        return 'sarmad-' . $type . ($from !== '' ? '-from-' . $from : '') . ($to !== '' ? '-to-' . $to : '') . '.xlsx';
    }

    private function sheetTitle(string $title): string
    {
        $clean = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', ' ', $title) ?? 'گزارش';

        return mb_substr(trim($clean) === '' ? 'گزارش' : trim($clean), 0, 31, 'UTF-8');
    }

    private function columnLetter(int $index): string
    {
        $letters = '';

        while ($index > 0) {
            $index--;
            $letters = chr(65 + ($index % 26)) . $letters;
            $index   = intdiv($index, 26);
        }

        return $letters;
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $meta
     */
    private function filtersLabel(array $filters, array $meta): string
    {
        $parts = [];

        if (!empty($meta['employee_name'])) {
            $parts[] = 'پرسنل: ' . (string) $meta['employee_name'];
        }

        if (!empty($meta['branch_name'])) {
            $parts[] = 'شعبه: ' . (string) $meta['branch_name'];
        }

        if (!empty($meta['shift_name'])) {
            $parts[] = 'شیفت: ' . (string) $meta['shift_name'];
        }

        if (!empty($filters['status'])) {
            $parts[] = 'وضعیت: ' . AttendanceRecord::statusLabel((string) $filters['status']);
        }

        if (!empty($filters['origin'])) {
            $parts[] = 'منبع: ' . (string) $filters['origin'];
        }

        return $parts === [] ? 'بدون فیلتر اضافه' : implode(' | ', $parts);
    }
}

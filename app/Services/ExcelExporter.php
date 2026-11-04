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
 *   - the customer name, the Jalali range and the generated-at stamp (Jalali
 *     date + a clean HH:MM clock - a raw Gregorian date is never shown to the
 *     user),
 *   - one header row with a fill, a border and a freeze pane right below it,
 *   - AutoFilter over the whole table,
 *   - the summary of the report under the table,
 *   - column widths measured from the longest header/cell of the column plus a
 *     safety margin, so no cell is squeezed into "###",
 *   - a ready-to-print A4 page: landscape, fitted to ONE page wide, the title
 *     row repeated on every printed page, gridlines on and "صفحه X از Y" in the
 *     footer.
 *
 * Dates: `attendance_date` and the `*_at` columns are real Gregorian values in
 * the database. A report column that carries a date/time is tagged by
 * ReportService with a `format` ('date', 'datetime', 'time') and every such
 * cell is written here through Jalali::date()/Jalali::time() - the same rule the
 * web report table uses - so the .xlsx shows 1404/07/14 and 08:30, never
 * 2025-10-06 or 08:30:00.
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
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

final class ExcelExporter
{
    /** Hard cap per sheet, independent of the view pagination. */
    public const RANGE_LIMIT = 20000;

    /** Narrowest data column, in Excel width units (one unit ~ one digit). */
    private const MIN_COLUMN_WIDTH = 11.0;

    /**
     * Widest data column. A longer free text (a leave description, a note) is
     * wrapped inside this width instead of pushing the sheet off the page.
     */
    private const MAX_COLUMN_WIDTH = 42.0;

    /** Safety margin added on top of the longest value of a column. */
    private const COLUMN_PADDING = 4.0;

    /** Number format of every numeric report cell. */
    private const NUMBER_FORMAT = '#,##0';

    /** The row the table header always sits on. */
    private const HEADER_ROW = 7;

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $meta    customer name, actor, filters used
     * @return array{spreadsheet:Spreadsheet,filename:string,type:string,title:string,rows:int}
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

        $from = (string) ($filters['date_from'] ?? '');
        $to   = (string) ($filters['date_to'] ?? '');

        /* ---------------- head block ---------------- */
        $sheet->mergeCells('A1:' . $lastCol . '1');
        $sheet->setCellValue('A1', $report['title']);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15)->setName('Tahoma');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(30);

        $customerName = (string) ($meta['customer_name'] ?? '');
        $brandName    = trim((string) ($meta['brand_name'] ?? ''));

        // STAGE 20 - «مجموعه: برند» on the printed sheet. The brand is what the
        // customer sees on the kiosk and in the panel header, so the sheet says
        // the same thing; the tenant name is still there when they differ.
        $headLine = $brandName !== '' ? $brandName : $customerName;

        if ($brandName !== '' && $customerName !== '' && $brandName !== $customerName) {
            $headLine .= ' (' . $customerName . ')';
        }

        $sheet->setCellValue('A2', 'مجموعه: ' . $headLine);

        // Jalali only: a raw "2025-10-01 تا 2025-10-06" is what the customer
        // complained about, so the Gregorian range stays in the filename and in
        // the database, never in the printed sheet.
        $sheet->setCellValue('A3', 'بازه: ' . ($from !== '' ? Jalali::date($from) : '—') . ' تا ' . ($to !== '' ? Jalali::date($to) : '—'));

        $sheet->setCellValue('A4', 'تاریخ تولید گزارش: ' . Jalali::date(date('Y-m-d')) . ' — ' . Jalali::time(date('H:i'))
            . '   |   تعداد ردیف: ' . count($rows));

        $sheet->setCellValue('A5', 'فیلترها: ' . $this->filtersLabel($filters, $meta) . '   |   کد دوره محاسبه: ' . PayrollCalculator::VERSION);

        foreach (['A2', 'A3', 'A4', 'A5'] as $cell) {
            $sheet->getStyle($cell)->getFont()->setName('Tahoma')->setSize(10)->setBold(false);
        }

        $sheet->getStyle('A5')->getFont()->setItalic(true);

        /* ---------------- header row ---------------- */
        $headerRow = self::HEADER_ROW;
        $col       = 1;

        /** @var array<int,int> $longest longest visible text of each column */
        $longest = [];

        foreach ($columns as $column) {
            $label = (string) ($column['label'] ?? '');

            $sheet->setCellValue($this->columnLetter($col) . $headerRow, $label);

            // The header is bold, so it needs a little more room than a data
            // cell with the same character count.
            $longest[$col] = $this->textLength($label) + 2;

            $col++;
        }

        $headerRange = 'A' . $headerRow . ':' . $lastCol . $headerRow;
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->setName('Tahoma')->setSize(10);
        $sheet->getStyle($headerRange)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('1F2937');
        $sheet->getStyle($headerRange)->getFont()->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle($headerRange)->getAlignment()->setWrapText(true);
        $sheet->getRowDimension($headerRow)->setRowHeight(32);

        /* ---------------- data rows ---------------- */
        $rowIndex = $headerRow + 1;

        foreach ($rows as $row) {
            $col = 1;

            foreach ($columns as $column) {
                $key    = (string) $column['key'];
                $value  = $row[$key] ?? '';
                $cell   = $this->columnLetter($col) . $rowIndex;
                $format = (string) ($column['format'] ?? '');

                if (ReportService::numericFormat($format)) {
                    // A real number cell: the manager can still SUM/filter it.
                    $number = (float) $value;

                    $sheet->setCellValueExplicit($cell, $number, DataType::TYPE_NUMERIC);
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);

                    $text = number_format($number);
                } else {
                    // Jalali date / clean clock / plain text, one rule for all.
                    $text = $this->cellText($column, $value);

                    $sheet->setCellValueExplicit($cell, $text, DataType::TYPE_STRING);
                }

                $sheet->getStyle($cell)->getFont()->setName('Tahoma')->setSize(10);

                $length = $this->textLength($text);

                if ($length > $longest[$col]) {
                    $longest[$col] = $length;
                }

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
        $longest[1] = max($longest[1] ?? 0, $this->textLength('خلاصه گزارش') + 2);
        $summaryRow++;

        foreach ($report['summary'] as $card) {
            $label = (string) $card['label'];
            $value = $card['value'];

            $sheet->setCellValue('A' . $summaryRow, $label);
            $longest[1] = max($longest[1] ?? 0, $this->textLength($label) + 2);

            if (is_numeric($value)) {
                $number = (float) $value;

                $sheet->setCellValueExplicit('B' . $summaryRow, $number, DataType::TYPE_NUMERIC);
                $sheet->getStyle('B' . $summaryRow)->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);

                $longest[2] = max($longest[2] ?? 0, $this->textLength(number_format($number)));
            } else {
                $text = (string) $value;

                $sheet->setCellValueExplicit('B' . $summaryRow, $text, DataType::TYPE_STRING);
                $longest[2] = max($longest[2] ?? 0, $this->textLength($text));
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
        $this->applyColumnWidths($sheet, $longest, $count, $headerRow, $lastRow);

        /* ---------------- print layout (A4, landscape, 1 page wide) ---------- */
        $this->applyPrintSetup($sheet, $report['title'], $from, $to, $headerRow);

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
     * A4 landscape, fitted to one page wide, the header row repeated on every
     * printed page, gridlines on and "صفحه X از Y" in the footer.
     */
    private function applyPrintSetup(
        Worksheet $sheet,
        string $title,
        string $from,
        string $to,
        int $headerRow
    ): void {
        $pageSetup = $sheet->getPageSetup();

        $pageSetup->setPaperSize(PageSetup::PAPERSIZE_A4);
        $pageSetup->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);

        // "Fit to 1 page wide": width is forced to a single A4 page, height is
        // left free (0) so a long report keeps flowing page after page instead
        // of being shrunk into an unreadable strip.
        $pageSetup->setFitToPage(true);
        $pageSetup->setFitToWidth(1);
        $pageSetup->setFitToHeight(0);

        // The title row of the table is printed again on top of every page.
        $pageSetup->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);

        $pageSetup->setHorizontalCentered(true);

        $sheet->setPrintGridlines(true);

        $margins = $sheet->getPageMargins();
        $margins->setTop(0.45);
        $margins->setRight(0.3);
        $margins->setBottom(0.6);
        $margins->setLeft(0.3);
        $margins->setHeader(0.2);
        $margins->setFooter(0.25);

        $stamp = 'تولید: ' . Jalali::date(date('Y-m-d')) . ' ' . Jalali::time(date('H:i'));

        $left = '&L&"Tahoma,Regular"&8' . $this->footerText($title)
            . ($from !== '' || $to !== '' ? ' — بازه ' . Jalali::date($from) . ' تا ' . Jalali::date($to) : '');

        $footer = $left
            . '&C&"Tahoma,Regular"&8صفحه &P از &N'
            . '&R&"Tahoma,Regular"&8' . $this->footerText($stamp);

        $headerFooter = $sheet->getHeaderFooter();
        $headerFooter->setOddFooter($footer);
        $headerFooter->setEvenFooter($footer);
        $headerFooter->setFirstFooter($footer);
        $headerFooter->setAlignWithMargins(true);
    }

    /**
     * Widths are measured, not guessed: the longest visible value of a column
     * (header or cell) plus a safety margin, clamped between MIN and MAX. A
     * column wider than MAX is a free-text column and gets wrap instead of an
     * endless width, so the table still fits one A4 page wide.
     *
     * @param array<int,int> $longest longest visible text length per column
     */
    private function applyColumnWidths(
        Worksheet $sheet,
        array $longest,
        int $columnCount,
        int $headerRow,
        int $lastRow
    ): void {
        for ($col = 1; $col <= $columnCount; $col++) {
            $letter = $this->columnLetter($col);
            $length = $longest[$col] ?? 0;
            $width  = min(self::MAX_COLUMN_WIDTH, max(self::MIN_COLUMN_WIDTH, $length + self::COLUMN_PADDING));

            $sheet->getColumnDimension($letter)->setWidth($width);

            if ($length + self::COLUMN_PADDING > self::MAX_COLUMN_WIDTH) {
                // Long free text (description, note): wrap it inside the width
                // instead of letting the sheet grow horizontally.
                $sheet->getStyle($letter . ($headerRow + 1) . ':' . $letter . $lastRow)
                    ->getAlignment()->setWrapText(true);
                $sheet->getStyle($letter . ($headerRow + 1) . ':' . $letter . $lastRow)
                    ->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            }
        }

        // Column A also holds the summary labels, so it must never look cramped.
        $sheet->getColumnDimension('A')->setWidth(
            max(18.0, (float) $sheet->getColumnDimension('A')->getWidth())
        );
    }

    /**
     * What a text cell of the report shows. Date/clock columns go through
     * Jalali (the same helper the web panel uses), numbers that are not written
     * as numeric cells go through ReportService::present().
     *
     * @param array{key?:string,label?:string,format?:string} $column
     * @param mixed $value
     */
    private function cellText(array $column, $value): string
    {
        $format = (string) ($column['format'] ?? '');
        $raw    = is_scalar($value) || $value === null ? $value : '';

        switch ($format) {
            case 'date':
                return Jalali::date($raw);
            case 'datetime':
                return Jalali::dateTime($raw);
            case 'time':
                return Jalali::time($raw);
            default:
                return ReportService::present($column, $raw);
        }
    }

    /**
     * Visible length of a value in characters. Combining Persian marks and the
     * RTL marks are ignored, so a label is not measured wider than it prints.
     */
    private function textLength(string $text): int
    {
        $clean = preg_replace('/[\x{200e}\x{200f}\x{061c}\x{0619}-\x{061a}\x{064b}-\x{065f}\x{0670}]/u', '', $text);

        return mb_strlen((string) $clean, 'UTF-8');
    }

    /**
     * An ampersand inside a header/footer is a control character in Excel, so a
     * literal one is doubled ("&&") before it is written.
     */
    private function footerText(string $text): string
    {
        return str_replace('&', '&&', $text);
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
        $clean = preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $title) ?? 'گزارش';

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

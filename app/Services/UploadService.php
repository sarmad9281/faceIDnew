<?php
/**
 * SARMAD Attendance - upload layout (structure only in stage 1).
 *
 * The physical layout is decided now so that later stages (employee
 * photo, three face samples, customer logo, report files) never mix data
 * between customers and never need a migration:
 *
 *   uploads/customers/{customer_uuid}/{owner_type}/{owner_uuid}/{category}/{file}
 *
 * Example:
 *   uploads/customers/8f14.../employees/3c2a.../face/sample-1.jpg
 *   uploads/customers/8f14.../branding/logo.png
 *
 * The face recognition pipeline itself is NOT implemented in this stage.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Security;
use RuntimeException;

final class UploadService
{
    public const OWNER_EMPLOYEE = 'employees';
    public const OWNER_DEVICE   = 'devices';
    public const OWNER_BRANCH   = 'branches';
    public const OWNER_BRANDING = 'branding';
    public const OWNER_REPORTS  = 'reports';

    /**
     * Absolute directory for a customer scoped file.
     */
    public static function path(string $customerUuid, string $ownerType, string $ownerUuid = '', string $category = ''): string
    {
        $customerUuid = self::safeSegment($customerUuid);
        $ownerType    = self::safeSegment($ownerType);
        $ownerUuid    = $ownerUuid === '' ? '' : self::safeSegment($ownerUuid);
        $category     = $category === '' ? '' : self::safeSegment($category);

        $relative = 'customers/' . $customerUuid . '/' . $ownerType
            . ($ownerUuid !== '' ? '/' . $ownerUuid : '')
            . ($category !== '' ? '/' . $category : '');

        $absolute = Config::path('uploads/' . $relative);

        if (!is_dir($absolute) && !@mkdir($absolute, 0755, true) && !is_dir($absolute)) {
            throw new RuntimeException('Cannot create upload directory: ' . $relative);
        }

        // Make sure the path never escapes uploads/.
        $base = realpath(Config::path('uploads'));
        $real = realpath($absolute);

        if ($base === false || $real === false || strpos($real, $base) !== 0) {
            throw new RuntimeException('Unsafe upload path.');
        }

        return $absolute;
    }

    public static function relativePath(string $customerUuid, string $ownerType, string $ownerUuid = '', string $category = '', string $file = ''): string
    {
        $relative = 'customers/' . self::safeSegment($customerUuid) . '/' . self::safeSegment($ownerType)
            . ($ownerUuid !== '' ? '/' . self::safeSegment($ownerUuid) : '')
            . ($category !== '' ? '/' . self::safeSegment($category) : '');

        if ($file !== '') {
            $relative .= '/' . self::safeFileName($file);
        }

        return $relative;
    }

    /**
     * Public URL for a stored file (uploads/ is web readable on purpose;
     * PHP execution inside it is blocked by uploads/.htaccess).
     */
    public static function url(string $relativePath): string
    {
        return \App\Support\Url::to('/uploads/' . ltrim($relativePath, '/'));
    }

    /**
     * Validate an uploaded image WITHOUT storing it (used from stage 2).
     *
     * @param array<string,mixed> $file $_FILES entry
     * @return array{ok:bool, message:string, extension:string}
     */
    /** MIME type => canonical extension. Anything else is refused. */
    public const IMAGE_MIME_MAP = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /** Extensions that must never be stored, whatever the MIME says. */
    public const FORBIDDEN_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps',
        'js', 'mjs', 'html', 'htm', 'xhtml', 'svg', 'svgz', 'xml', 'swf', 'exe', 'sh',
        'bash', 'pl', 'py', 'cgi', 'asp', 'aspx', 'jsp', 'htaccess',
    ];

    /**
     * Validate an uploaded image.
     *
     * Checks, in order:
     *   1. the upload really happened (is_uploaded_file / UPLOAD_ERR_OK),
     *   2. size limit from config (uploads.max_image_mb),
     *   3. the extension of the ORIGINAL name is not executable/scriptable,
     *   4. the real MIME type detected by finfo is an allowed image type,
     *   5. getimagesize() agrees that this is a real image.
     *
     * The returned extension always comes from the detected MIME type, never
     * from the user supplied file name.
     *
     * @param array<string,mixed> $file
     * @return array{ok:bool,message:string,extension:string,mime:string}
     */
    public static function validateImage(array $file): array
    {
        $fail = static function (string $message): array {
            return ['ok' => false, 'message' => $message, 'extension' => '', 'mime' => ''];
        };

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            return $fail($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE
                ? 'حجم فایل بیش از حد مجاز سرور است.'
                : 'فایل به درستی آپلود نشد.');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');

        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return $fail('فایل آپلود شده معتبر نیست.');
        }

        $maxMb = (int) Config::get('uploads.max_image_mb', 5);

        if ((int) ($file['size'] ?? 0) > $maxMb * 1024 * 1024) {
            return $fail('حجم فایل باید کمتر از ' . $maxMb . ' مگابایت باشد.');
        }

        $originalExtension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

        if ($originalExtension !== '' && in_array($originalExtension, self::FORBIDDEN_EXTENSIONS, true)) {
            Logger::channel('security')->warning('Blocked upload of an executable file', [
                'extension' => $originalExtension,
                'name'      => substr((string) ($file['name'] ?? ''), 0, 80),
            ]);

            return $fail('این نوع فایل مجاز نیست.');
        }

        $mime = '';

        if (class_exists('finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime  = (string) $finfo->file($tmp);
        }

        if ($mime === '' && function_exists('mime_content_type')) {
            $mime = (string) mime_content_type($tmp);
        }

        if (!isset(self::IMAGE_MIME_MAP[$mime])) {
            Logger::channel('security')->warning('Blocked upload with an unexpected MIME type', ['mime' => $mime]);

            return $fail('فرمت تصویر مجاز نیست (فقط JPG، PNG و WEBP).');
        }

        if (function_exists('getimagesize') && @getimagesize($tmp) === false) {
            return $fail('فایل یک تصویر معتبر نیست.');
        }

        return ['ok' => true, 'message' => 'ok', 'extension' => self::IMAGE_MIME_MAP[$mime], 'mime' => $mime];
    }

    /**
     * Store a customer logo: uploads/customers/{uuid}/branding/logo-*.jpg
     *
     * @param array<string,mixed> $file
     * @return array{ok:bool,message:string,relative:string}
     */
    public static function storeLogo(array $file, string $customerUuid): array
    {
        $stored = self::storeImage($file, $customerUuid, self::OWNER_BRANDING, '', '');

        if (!$stored['ok']) {
            return $stored;
        }

        return $stored;
    }

    /**
     * Delete a previously stored upload (never leaves uploads/).
     */
    public static function delete(string $relative): bool
    {
        $relative = str_replace(['..', '\\'], '', ltrim($relative, '/'));

        if ($relative === '' || strpos($relative, 'customers/') !== 0) {
            return false;
        }

        $base = realpath(Config::path('uploads'));
        $path = realpath(Config::path('uploads/' . $relative));

        if ($base === false || $path === false || strpos($path, $base) !== 0) {
            return false;
        }

        return @unlink($path);
    }

    /**
     * Move a validated file into the tenant layout with a random name.
     *
     * @param array<string,mixed> $file
     * @return array{ok:bool, message:string, relative:string}
     */
    public static function storeImage(array $file, string $customerUuid, string $ownerType, string $ownerUuid = '', string $category = ''): array
    {
        $check = self::validateImage($file);

        if (!$check['ok']) {
            return ['ok' => false, 'message' => $check['message'], 'relative' => ''];
        }

        $directory = self::path($customerUuid, $ownerType, $ownerUuid, $category);
        $fileName  = date('Ymd') . '-' . Security::randomHex(8) . '.' . $check['extension'];

        if (!@move_uploaded_file((string) $file['tmp_name'], $directory . '/' . $fileName)) {
            Logger::channel('app')->error('Upload move failed', ['target' => $directory]);

            return ['ok' => false, 'message' => 'ذخیره فایل ناموفق بود.', 'relative' => ''];
        }

        @chmod($directory . '/' . $fileName, 0644);

        return [
            'ok'       => true,
            'message'  => 'فایل ذخیره شد.',
            'relative' => self::relativePath($customerUuid, $ownerType, $ownerUuid, $category, $fileName),
        ];
    }

    /**
     * Store an image that arrived as a data URL (stage 5 enrolment snapshots).
     *
     * The wizard runs in the browser, so the JPEG is created by a canvas and
     * travels inside the JSON payload instead of a multipart form. The data
     * is therefore validated here with the same rules as an uploaded file:
     * real image type (finfo), decodable, size limit - and it is shrunk to
     * `$maxPx` on the long edge when the GD extension is available (an
     * optional optimisation; without GD the canvas sized image is stored
     * as-is).
     *
     * @return array{ok:bool,message:string,relative:string,mime:string,width:int,height:int}
     */
    public static function storeDataUrlImage(
        string $dataUrl,
        string $customerUuid,
        string $ownerType,
        string $ownerUuid = '',
        string $category = '',
        int $maxPx = 320
    ): array {
        $fail = static function (string $message): array {
            return ['ok' => false, 'message' => $message, 'relative' => '', 'mime' => '', 'width' => 0, 'height' => 0];
        };

        if (strlen($dataUrl) > 4 * 1024 * 1024) {
            return $fail('حجم تصویر ارسالی بیش از حد مجاز است.');
        }

        if (preg_match('#^data:(image/[a-zA-Z0-9.+-]+);base64,(.+)$#s', $dataUrl, $match) !== 1) {
            return $fail('تصویر ارسالی معتبر نیست.');
        }

        $binary = base64_decode(preg_replace('/\s+/', '', $match[2]) ?? '', true);

        if ($binary === false || strlen($binary) < 32) {
            return $fail('تصویر ارسالی معتبر نیست.');
        }

        $maxMb = (int) Config::get('uploads.max_image_mb', 5);

        if (strlen($binary) > $maxMb * 1024 * 1024) {
            return $fail('حجم تصویر باید کمتر از ' . $maxMb . ' مگابایت باشد.');
        }

        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        $mime    = '';
        $image   = null;

        if (class_exists('finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime  = (string) $finfo->buffer($binary);
        }

        if (!in_array($mime, $allowed, true)) {
            Logger::channel('security')->warning('Blocked data URL upload', ['mime' => $mime]);

            return $fail('فرمت تصویر مجاز نیست (فقط JPG، PNG و WEBP).');
        }

        $size = function_exists('getimagesizefromstring') ? @getimagesizefromstring($binary) : false;

        if ($size === false) {
            return $fail('تصویر ارسالی یک فایل تصویری معتبر نیست.');
        }

        $width  = (int) $size[0];
        $height = (int) $size[1];

        if ($width < 32 || $height < 32 || $width > 6000 || $height > 6000) {
            return $fail('ابعاد تصویر مناسب نیست.');
        }

        // Optional server side shrink (only when GD is compiled in).
        if ($maxPx > 0 && max($width, $height) > $maxPx && function_exists('imagecreatetruecolor')) {
            $shrunk = self::shrink($binary, $mime, $maxPx);

            if ($shrunk !== null) {
                $binary = $shrunk['binary'];
                $mime   = 'image/jpeg';
                $width  = $shrunk['width'];
                $height = $shrunk['height'];
            }
        }

        $directory = self::path($customerUuid, $ownerType, $ownerUuid, $category);
        $extension = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
        $fileName  = date('Ymd') . '-' . Security::randomHex(8) . '.' . $extension;

        if (@file_put_contents($directory . '/' . $fileName, $binary) === false) {
            Logger::channel('app')->error('Data URL upload write failed', ['target' => $directory]);

            return $fail('ذخیره تصویر ناموفق بود.');
        }

        @chmod($directory . '/' . $fileName, 0644);

        return [
            'ok'       => true,
            'message'  => 'تصویر ذخیره شد.',
            'relative' => self::relativePath($customerUuid, $ownerType, $ownerUuid, $category, $fileName),
            'mime'     => $mime,
            'width'    => $width,
            'height'   => $height,
        ];
    }

    /**
     * @return array{binary:string,width:int,height:int}|null
     */
    private static function shrink(string $binary, string $mime, int $maxPx): ?array
    {
        $source = @imagecreatefromstring($binary);

        if ($source === false) {
            return null;
        }

        $width  = imagesx($source);
        $height = imagesy($source);
        $ratio  = $maxPx / max($width, $height);
        $newW   = max(1, (int) round($width * $ratio));
        $newH   = max(1, (int) round($height * $ratio));
        $canvas = imagecreatetruecolor($newW, $newH);

        if ($canvas === false) {
            imagedestroy($source);

            return null;
        }

        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newW, $newH, $width, $height);

        ob_start();
        $ok = imagejpeg($canvas, null, 88);
        $out = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($canvas);

        if (!$ok || $out === '') {
            return null;
        }

        return ['binary' => $out, 'width' => $newW, 'height' => $newH];
    }

    /* ================================================================= */

    private static function safeSegment(string $segment): string
    {
        $segment = strtolower(trim($segment));
        $segment = preg_replace('/[^a-z0-9_\-\.]/', '', $segment) ?? $segment;

        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new RuntimeException('Invalid upload path segment.');
        }

        return substr($segment, 0, 64);
    }

    private static function safeFileName(string $file): string
    {
        $file = basename($file);
        $file = preg_replace('/[^A-Za-z0-9_\-\.]/', '', $file) ?? $file;

        return substr($file, 0, 120);
    }
}

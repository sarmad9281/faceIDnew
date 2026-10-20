<?php
/**
 * SARMAD Attendance - server side validation.
 *
 * Usage:
 *   $v = Validator::make($request->all(), [
 *       'email'    => 'required|email|max:190',
 *       'password' => 'required|min:10',
 *   ]);
 *   if ($v->fails()) { ... $v->errors() ... }
 */

declare(strict_types=1);

namespace App\Core;

final class Validator
{
    /** @var array<string,mixed> */
    private array $data;

    /** @var array<string,string> */
    private array $rules;

    /** @var array<string,array<int,string>> */
    private array $errors = [];

    /** @var array<string,mixed> */
    private array $clean = [];

    /** @var array<string,string> */
    private array $labels = [];

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     */
    public function __construct(array $data, array $rules, array $labels = [])
    {
        $this->data   = $data;
        $this->rules  = $rules;
        $this->labels = $labels;

        $this->run();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     */
    public static function make(array $data, array $rules, array $labels = []): self
    {
        return new self($data, $rules, $labels);
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $value    = $this->value($field);
            $rules    = explode('|', $ruleString);
            $required = in_array('required', $rules, true);

            if ($value === null || $value === '') {
                if ($required) {
                    $this->addError($field, 'required');

                    continue;
                }

                // Let optional empty fields pass through as null.
                $this->clean[$field] = $value === '' ? null : $value;

                continue;
            }

            $current = $value;

            foreach ($rules as $rule) {
                if ($rule === '' || $rule === 'required') {
                    continue;
                }

                [$name, $argument] = array_pad(explode(':', $rule, 2), 2, null);

                $result = $this->apply($name, $argument, $current, $field);

                if ($result === false) {
                    break;
                }

                if ($result !== true) {
                    $current = $result;
                }
            }

            if (!isset($this->errors[$field])) {
                $this->clean[$field] = $current;
            }
        }
    }

    /**
     * @param mixed $value
     * @return mixed true = keep, false = failed, other = transformed value
     */
    private function apply(string $name, ?string $argument, $value, string $field)
    {
        switch ($name) {
            case 'email':
                $candidate = trim((string) $value);

                if ($candidate === ''
                    || filter_var($candidate, FILTER_VALIDATE_EMAIL) === false
                    || mb_strlen($candidate) > 190
                ) {
                    $this->addError($field, 'email');

                    return false;
                }

                return mb_strtolower($candidate);

            case 'min':
                $min = (int) $argument;
                if (is_numeric($value) && !is_string($value)) {
                    if ((float) $value < $min) {
                        $this->addError($field, 'min_number', (string) $min);

                        return false;
                    }

                    return $value;
                }

                if (mb_strlen((string) $value) < $min) {
                    $this->addError($field, 'min', (string) $min);

                    return false;
                }

                return $value;

            case 'max':
                $max = (int) $argument;
                if (mb_strlen((string) $value) > $max) {
                    $this->addError($field, 'max', (string) $max);

                    return false;
                }

                return $value;

            case 'numeric':
                if (!is_numeric($value)) {
                    $this->addError($field, 'numeric');

                    return false;
                }

                return $value;

            case 'integer':
                if (!preg_match('/^-?\d+$/', (string) $value)) {
                    $this->addError($field, 'integer');

                    return false;
                }

                return (int) $value;

            case 'in':
                $allowed = array_map('trim', explode(',', (string) $argument));
                if (!in_array((string) $value, $allowed, true)) {
                    $this->addError($field, 'in');

                    return false;
                }

                return $value;

            case 'regex':
                if (!preg_match('#^' . (string) $argument . '$#', (string) $value)) {
                    $this->addError($field, 'regex');

                    return false;
                }

                return $value;

            case 'same':
                if ((string) $value !== (string) $this->value((string) $argument)) {
                    $this->addError($field, 'same', (string) $argument);

                    return false;
                }

                return $value;

            case 'confirmed':
                if ((string) $value !== (string) $this->value($field . '_confirmation')) {
                    $this->addError($field, 'confirmed');

                    return false;
                }

                return $value;

            case 'alpha_dash':
                if (!preg_match('/^[A-Za-z0-9_\-]+$/', (string) $value)) {
                    $this->addError($field, 'alpha_dash');

                    return false;
                }

                return $value;

            case 'string':
                if (!is_scalar($value)) {
                    $this->addError($field, 'string');

                    return false;
                }

                return trim((string) $value);

            case 'phone':
                if (!preg_match('/^[0-9+\-() ]{6,20}$/', (string) $value)) {
                    $this->addError($field, 'phone');

                    return false;
                }

                return trim((string) $value);

            case 'token':
                if (!preg_match('/^[A-Za-z0-9\-_]{16,200}$/', (string) $value)) {
                    $this->addError($field, 'token');

                    return false;
                }

                return $value;

            case 'uuid':
                if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $value)) {
                    $this->addError($field, 'uuid');

                    return false;
                }

                return strtolower((string) $value);

            case 'timezone':
                if (!in_array((string) $value, timezone_identifiers_list(), true)) {
                    $this->addError($field, 'timezone');

                    return false;
                }

                return $value;

            case 'password_strength':
                $problems = Security::passwordProblems((string) $value);
                if ($problems !== []) {
                    foreach ($problems as $problem) {
                        $this->errors[$field][] = $problem;
                    }

                    return false;
                }

                return $value;

            default:
                return $value;
        }
    }

    /**
     * @param mixed $value
     */
    private function addError(string $field, string $rule, ?string $argument = null): void
    {
        $this->errors[$field][] = self::message($field, $rule, $argument, $this->labels[$field] ?? null);
    }

    public static function message(string $field, string $rule, ?string $argument = null, ?string $label = null): string
    {
        $label = $label ?? $field;

        switch ($rule) {
            case 'required':
                return $label . ' الزامی است.';
            case 'email':
                return $label . ' معتبر نیست.';
            case 'min':
                return $label . ' باید حداقل ' . $argument . ' کاراکتر باشد.';
            case 'min_number':
                return $label . ' باید حداقل ' . $argument . ' باشد.';
            case 'max':
                return $label . ' نباید بیشتر از ' . $argument . ' کاراکتر باشد.';
            case 'numeric':
                return $label . ' باید عددی باشد.';
            case 'integer':
                return $label . ' باید یک عدد صحیح باشد.';
            case 'in':
                return $label . ' مقدار مجاز نیست.';
            case 'regex':
                return $label . ' قالب درست را ندارد.';
            case 'same':
                return $label . ' با ' . $argument . ' مطابقت ندارد.';
            case 'confirmed':
                return $label . ' با تکرار آن مطابقت ندارد.';
            case 'alpha_dash':
                return $label . ' فقط می‌تواند شامل حرف، عدد، خط تیره و آندرلاین باشد.';
            case 'string':
                return $label . ' باید متن باشد.';
            case 'phone':
                return $label . ' شماره تماس معتبر نیست.';
            case 'token':
                return $label . ' توکن معتبر نیست.';
            case 'uuid':
                return $label . ' شناسه معتبر نیست.';
            case 'timezone':
                return $label . ' منطقه زمانی معتبر نیست.';
            default:
                return $label . ' مقدار نامعتبر است.';
        }
    }

    /* ================================================================= */

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string,array<int,string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Flat list of all messages.
     *
     * @return array<int,string>
     */
    public function errorList(): array
    {
        $list = [];

        foreach ($this->errors as $messages) {
            foreach ($messages as $message) {
                $list[] = $message;
            }
        }

        return $list;
    }

    public function firstError(): string
    {
        $list = $this->errorList();

        return $list[0] ?? '';
    }

    /**
     * Validated / normalised data.
     *
     * @return array<string,mixed>
     */
    public function validated(): array
    {
        return $this->clean;
    }

    /**
     * @return mixed
     */
    private function value(string $field)
    {
        $value = $this->data[$field] ?? null;

        // Trim plain strings before validating: forms and Persian keyboards
        // often deliver trailing spaces / zero width characters.
        if (is_string($value)) {
            $value = trim($value, " \t\n\r\0\x0B");
            $value = str_replace(["\u{200c}", "\u{200f}", "\u{200e}"], '', $value);
        }

        return $value;
    }
}

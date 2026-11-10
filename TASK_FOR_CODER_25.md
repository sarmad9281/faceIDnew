# TASK FOR CODER - STAGE 25
## True Persian/Jalali Date Inputs & Dark-Themed Shamsi Calendar Picker

**Priority:** Critical  
**Context:** In Stage 24, the coder kept `<input type="date">` and merely appended a small subtitle `معادل شمسی: ...` below the inputs. The client is completely dissatisfied because:
1. Inside the actual input boxes, users still see Gregorian dates (`11/11/2026`, `11/01/2026`, `10/21/2026`, `mm/dd/yyyy`).
2. Clicking the browser date icon opens a native Gregorian calendar (November, December...).
3. Labels and descriptions still explicitly say:
   - `تاریخ استخدام (میلادی)`
   - `تاریخ‌ها میلادی ثبت می‌شوند و در فهرست به‌صورت شمسی نمایش داده می‌شوند.`
   - `تاریخ میلادی؛ معادل شمسی در فهرست نمایش داده می‌شود.`

The system must be **100% Shamsi from the user's perspective**: the text inside the input box must be Shamsi (e.g. `1405/08/20`), clicking it must open an interactive Shamsi calendar, and all "(میلادی)" labels/hints must be eliminated.

---

### 1. Backend: Bidirectional Jalali Support (`app/Support/Jalali.php` & `app/Support/Input.php`)
1. **Add `Jalali::toGregorian(int $jy, int $jm, int $jd): array` to `app/Support/Jalali.php`:**
   ```php
   public static function toGregorian(int $jy, int $jm, int $jd): array
   {
       $jy += 1595;
       $days = -355668 + (365 * $jy) + intdiv($jy, 33) * 8 + intdiv(($jy % 33) + 3, 4) + $jd
           + ($jm < 7 ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);

       $gy = 400 * intdiv($days, 146097);
       $days %= 146097;

       if ($days > 36524) {
           $gy += 100 * intdiv(--$days, 36524);
           $days %= 36524;
           if ($days >= 365) $days++;
       }

       $gy += 4 * intdiv($days, 1461);
       $days %= 1461;

       if ($days > 365) {
           $gy += intdiv(--$days, 365);
           $days %= 365;
       }

       $gd = $days + 1;
       $sal_a = [0, 31, (($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
       $gm = 0;
       while ($gm < 13 && $gd > $sal_a[$gm]) {
           $gd -= $sal_a[$gm];
           $gm++;
       }

       return [$gy, $gm, $gd];
   }
   ```
2. **Update `Input::date($value): ?string` in `app/Support/Input.php`:**
   Support both Gregorian (`YYYY-MM-DD`) and Jalali (`YYYY/MM/DD` or `YYYY-MM-DD` with year between 1300 and 1500).
   If year is in range 1300–1500 (Jalali):
   Automatically convert via `Jalali::toGregorian($y, $m, $d)` and return `sprintf('%04d-%02d-%02d', $gy, $gm, $gd)`.
   This ensures that MySQL and all backend queries receive a standard Gregorian date without breaking any existing database schema or query!

---

### 2. Frontend: Dark-Themed Lightweight Shamsi Date Picker (`assets/js/jalali-picker.js`, `assets/css/manager.css`)
- Provide a zero-dependency, pure vanilla JavaScript Shamsi Date Picker (or lightweight embedded popup) that binds to inputs with `data-jdp` or `class="shamsi-date"`:
  - Input type: `<input type="text" class="input ltr shamsi-date" name="..." value="..." placeholder="1405/08/20" autocomplete="off">`.
  - When clicked or focused: Opens a sleek popup calendar matching the dark theme of the dashboard.
  - Allows selecting Shamsi Year, Month (فروردین تا اسفند), and Day (1 to 30/31).
  - Writing/picking puts the Shamsi date directly into the input: `1405/08/20`.
  - Includes a "امروز" (Today) quick button.

---

### 3. Replace all 29 `<input type="date">` Fields in Views
Convert all native `<input type="date">` inputs in `views/manager/*` (attendance, activity, employees, holidays, leaves, payroll, reports, telegram):
- Replace with `<input type="text" class="input ltr shamsi-date" ...>`.
- Pre-fill values using `\App\Support\Jalali::date($val)` instead of raw Gregorian `YYYY-MM-DD`.
  Example:
  ```php
  <input type="text" name="date_from" class="input ltr shamsi-date" value="<?= $e(\App\Support\Jalali::date($filters['date_from'] ?? date('Y-m-d'))) ?>" placeholder="1405/08/20" autocomplete="off">
  ```
- Remove the temporary `معادل شمسی: ...` subtitle spans added in Task 24, because the input itself now displays the Shamsi date.

---

### 4. Remove all "(میلادی)" Mentions and Apologies
- In `views/manager/employees/form.php`: change `تاریخ استخدام (میلادی)` to `تاریخ استخدام`.
- In `views/manager/holidays/index.php`:
  - Remove subtitle `تاریخ میلادی؛ معادل شمسی در فهرست نمایش داده می‌شود.`.
  - In table header: change columns to `<th>تاریخ (شمسی)</th>` and remove the redundant Gregorian column.
- In `views/manager/leaves/index.php`:
  - Remove notice `تاریخ‌ها میلادی ثبت می‌شوند و در فهرست به‌صورت شمسی نمایش داده می‌شوند.`.
- In `views/owner/logs/index.php`:
  - Change `از تاریخ (میلادی)` and `تا تاریخ (میلادی)` to `از تاریخ` and `تا تاریخ`.

---

### 5. Verification & Tests
- Unit test for `Jalali::toGregorian()` and `Input::date()` covering at least 1,000 round-trip conversions (`fromGregorian` -> `toGregorian` -> matches original date).
- Verification test ensuring zero `<input type="date">` remain in `views/manager/`.
- Verification test ensuring zero occurrences of `(میلادی)` remain in manager view labels.
- Package as `docs/patches/patch-stage25.zip`.

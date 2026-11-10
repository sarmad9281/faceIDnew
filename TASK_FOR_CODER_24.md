# TASK FOR CODER - STAGE 24
## Fix Telegram Preview Dark Theme Contrast & Universal Shamsi Date Enhancements

**Priority:** High  
**Context:** The manager panel is in dark theme. In `/manager/telegram`, the daily report preview `<pre>` box currently uses `background: var(--surface-2, #f6f7f9);` which falls back to bright white, rendering white text on white background (invisible). Additionally, browser native `input[type="date"]` displays Gregorian numbers (`11/11/2026`), causing user confusion; users require full Shamsi date visibility everywhere across the site and reports.

---

### 1. Fix Telegram Daily Report Preview Contrast (`views/manager/telegram/index.php`)
- In `views/manager/telegram/index.php` line 179:
  Update the `<pre>` tag style:
  ```html
  <pre dir="rtl" class="ltr-reset" style="white-space:pre-wrap;background:rgba(255,255,255,0.05);color:#e2e8f0;border:1px solid rgba(255,255,255,0.12);border-radius:10px;padding:14px;margin-top:8px;font-family:inherit;font-size:0.92rem;line-height:2.0"><?= $e((string) ($dailyPreview['message'] ?? '')) ?></pre>
  ```
- In the "تاریخ پیش‌نمایش" field:
  Show the Jalali date prominently:
  ```html
  <span>تاریخ پیش‌نمایش: <strong class="badge"><?= $e(\App\Support\Jalali::date($dailyPreviewDate)) ?></strong></span>
  ```

---

### 2. Universal Shamsi Date Display & Live Picker Badges (`assets/js/manager.js`, `views/manager/*`)
- **Live Shamsi Badge on all `input[type="date"]`:**
  - In `assets/js/manager.js`:
    Add a lightweight client-side Jalali converter / decorator for all `input[type="date"]` inputs in the manager panel:
    - Whenever an `input[type="date"]` is rendered or changed, display a clean badge next to or under the label:
      e.g. `معادل شمسی: ۱۴۰۵/۰۸/۲۱` (using standard Gregorian-to-Jalali conversion algorithm in ~20 lines of JS).
    - When the operator picks or changes a date, the badge updates dynamically in real-time.
- **Audit Views for Any Raw Gregorian Dates:**
  - Check all date and timestamp outputs in `views/manager/*` (attendance, activity, reports, leaves, holidays, shifts, payroll) and ensure all dates pass through `\App\Support\Jalali::date()` or `\App\Support\Jalali::dateTime()`.

---

### Packaging:
- Create `docs/patches/patch-stage24.zip` containing all updated files for direct extraction in DirectAdmin.

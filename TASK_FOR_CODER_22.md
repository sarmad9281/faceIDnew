# TASK FOR CODER - STAGE 22
## Enterprise Perfection: Security Headers A+, Google PageSpeed SEO 100/100 & Accessibility 100/100

**Priority:** High  
**Context:** Recent audit on the live production server (`finger.sarmadfallahzadeh.ir`) achieved extraordinary scores:
- Google PageSpeed Performance: **100 / 100** (0.8s LCP, 0ms TBT)
- Google PageSpeed Best Practices: **100 / 100**
- Qualys SSL Labs: **A+** (highest global rating, TLS 1.3, HSTS)
- GTmetrix: **Grade A** (98% structure, 0ms TBT)

We now want to bring the remaining two metrics to **100 / 100** and **A+**:
1. SecurityHeaders.com rating: **A -> A+** (Add `Content-Security-Policy`).
2. Google PageSpeed SEO: **66 -> 100** (Allow landing page in `robots.txt` and add canonical / meta robots).
3. Google PageSpeed Accessibility: **95 -> 100** (Fix ARIA 1.2 prohibited attributes on generic `div`/`ul` elements).

---

### 1. Security Headers (`.htaccess`): Achieve Grade A+
- In `.htaccess` under `<IfModule mod_headers.c>`:
  Add `Content-Security-Policy`:
  ```apache
  Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self' blob:; media-src 'self' blob:; worker-src 'self' blob:; frame-ancestors 'self';"
  ```
  *(This is tailored to our zero-CDN stack: allows camera video/blob, WebAudio, Service Worker, inline styles, face model JSON/shards, and QR rendering while blocking external script injection and clickjacking).*

---

### 2. SEO Perfection (`robots.txt`, `views/landing.php`): Achieve 100 / 100
- **`robots.txt`:**
  Currently it blocks the entire site (`Disallow: /`), causing Google to score SEO at 66 ("Page is blocked from indexing").
  Update `robots.txt`:
  ```text
  # SARMAD Attendance - public landing page is crawlable, internal surfaces protected
  User-agent: *
  Allow: /$
  Allow: /assets/
  Disallow: /manager/
  Disallow: /owner/
  Disallow: /device
  Disallow: /api/
  Disallow: /login
  Disallow: /logout
  Disallow: /account/
  Disallow: /storage/
  ```
- **`views/landing.php`:**
  - In `<head>`:
    Add `<meta name="robots" content="index, follow">`
    Add `<link rel="canonical" href="<?= $e($url('/')) ?>">`

---

### 3. Accessibility Perfection (`views/landing.php`): Achieve 100 / 100
- Google Lighthouse flagged: *"Elements use prohibited ARIA attributes"*.
  In ARIA 1.2 / HTML5, generic containers (`div`, `ul`, `span`) without a widget/landmark role must not have `aria-label`:
  - In `views/landing.php`:
    - Line 111: `<ul class="hero-proof" aria-label="...">` -> remove `aria-label`
    - Line 118: `<div class="hero-visual" ... aria-label="...">` -> add `role="img"` or remove `aria-label`
    - Line 315: `<ul class="benefit-list" aria-label="...">` -> remove `aria-label`
    - Line 351: `<div class="contact-grid" aria-label="...">` -> remove `aria-label`
  - Ensure all contrast and landmark requirements are completely met.

---

### Packaging:
- Create `docs/patches/patch-stage22.zip` containing the modified files (`.htaccess`, `robots.txt`, `views/landing.php`, and docs) so it can be deployed cleanly to DirectAdmin.

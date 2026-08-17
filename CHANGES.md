# MAster Platform — Developer Change Log

**Date:** 2026-04-05  
**Author:** AI-assisted refactor  
**Purpose:** Feature additions, pricing update, payment flow simplification, documentation

---

## Overview of Changes

| # | Area | Type | Files |
|---|------|------|-------|
| 1 | SMS/Email Overage Payment Popup | New Feature | `includes/usage_tracker.php`, `includes/overage_popup.php`, `admin/system-settings.php`, `dashboard/index.php`, `pages/notifications.php` |
| 2 | Federation (Omospondia) Admin UI | New Feature | `includes/layout.php`, `admin/federations.php` |
| 3 | Remove Automatic Viva Payment Flow | Removal | `pages/create_checkout.php` *(deleted)*, `pages/viva_webhook.php` *(deleted)*, `pages/payment_success.php` *(deleted)*, `pages/upgrade.php` |
| 4 | Pricing Analysis TXT | New File | `pricing-analysis.txt` |
| 5 | Pricing Update €25→€30 | Update | `index.php`, `pages/upgrade.php`, `legal/payments.php`, `legal/terms.php` |

---

## 1. SMS/Email Overage Payment Popup

### Background
When a school exceeds its monthly SMS or email quota, the platform now shows a modal popup with bank transfer / IRIS payment instructions to purchase an additional pack. The admin keeps a commission % (configurable). Everything is controlled from the superadmin panel.

### New File: `includes/overage_popup.php`
- **Purpose:** Renders the overage modal when a school's monthly usage >= limit
- **Function:** `renderOveragePopup(): void`
  - Reads monthly usage via `getMonthlyUsage($schoolId)`
  - Reads superadmin settings: `monthly_sms_limit`, `monthly_email_limit`, `sms_overage_pack_qty`, `sms_overage_pack_price`, `email_overage_pack_qty`, `email_overage_pack_price`, `overage_commission_pct`
  - Displays bank transfer details (IBAN, beneficiary, reference code, amount)
  - Generates mailto: link with pre-filled receipt email
  - Commission display: shows admin keeps X% of pack price
  - "Dismiss" button hides modal until next page load

### Modified: `includes/usage_tracker.php`
- **Lines added: ~120 lines** after existing `checkUsageLimit()` function
- **New functions:**
  - `checkMonthlyUsageLimit(int $schoolId, string $type): bool` (line ~75)
    - Reads `monthly_{type}_limit` from system_settings
    - Counts sent messages for current calendar month
    - Returns false when limit reached
  - `getMonthlyUsage(int $schoolId): array` (line ~100)
    - Returns `['sms' => int, 'email' => int, 'sms_limit' => int, 'email_limit' => int]`
  - `hasPendingOverageRequest(int $schoolId, string $type): bool` (line ~120)
  - `createOverageRequest(int $schoolId, string $type, int $qty, float $amount): int` (line ~135)
  - `ensureOverageRequestsTable(): void` (line ~150)
    - Auto-creates `overage_requests` table on load
- **New DB table auto-created:** `overage_requests`
  - Columns: `id`, `school_id`, `type` (sms/email), `qty`, `amount`, `commission`, `status` (pending/paid/rejected), `notes`, `created_at`, `paid_at`

### Modified: `admin/system-settings.php`
- **Line ~84:** Added new allowed settings keys to `$fields` array:
  ```
  'monthly_sms_limit', 'monthly_email_limit',
  'sms_overage_pack_qty', 'sms_overage_pack_price',
  'email_overage_pack_qty', 'email_overage_pack_price',
  'overage_commission_pct'
  ```
- **Line ~357:** Added new tab button in `<div class="tab-nav">`:
  ```html
  <button class="tab-btn" onclick="switchTab('overage',this)">
    <i class="fa-solid fa-cart-shopping"></i> Πακέτα SMS/Email
  </button>
  ```
- **Lines ~689–750 (new section):** Added `<div id="tab-overage">` panel with:
  - Monthly SMS/email limit inputs (per school, 0 = unlimited)
  - SMS pack: qty input + price input
  - Email pack: qty input + price input
  - Commission % input with live profit preview box

### Modified: `dashboard/index.php`
- **Line 22:** Added `require_once __DIR__ . '/../includes/overage_popup.php';`
- **Line 272 (after `<body>`):** Added `<?php renderOveragePopup(); ?>`

### Modified: `pages/notifications.php`
- **Line 12:** Added `require_once __DIR__ . '/../includes/overage_popup.php';`
- **Line 810 (after `<body>`):** Added `<?php renderOveragePopup(); ?>`

---

## 2. Federation (Omospondia) Admin UI

### Background
Adds a dedicated admin panel to manage federations that bring clubs/schools to the platform. Tracks: which schools came from each federation, commission percentage the federation receives, total commissions paid, net profit per federation, P&L analysis.

### Modified: `includes/layout.php`
- **Line ~321 (inside `$isSA` navItems):** Added sidebar item:
  ```php
  ['href' => APP_URL.'/admin/federations.php', 'icon' => 'fa-solid fa-handshake', 'label' => 'Ομοσπονδίες', 'key' => 'admin_federations'],
  ```
  Inserted between `admin_payments` and `admin_coupons`.

### New File: `admin/federations.php`
- **Total lines:** ~450
- **DB migrations (auto-run on page load):**
  - Creates `federations` table if not exists:
    - Columns: `id`, `name`, `contact_name`, `contact_email`, `contact_phone`, `commission_pct`, `notes`, `active`, `created_at`
  - Adds columns to `schools` table if not exists:
    - `federation_id INT DEFAULT NULL`
    - `federation_ref_code VARCHAR(100) DEFAULT NULL`
- **POST actions handled:**
  - `add_federation` — creates new federation record
  - `edit_federation` — updates federation record + active flag
  - `assign_school` — sets `schools.federation_id` + `federation_ref_code`
  - `unassign_school` — nulls `schools.federation_id`
- **4 tabs:**
  1. **Ομοσπονδίες (list)** — cards per federation showing school count, commission %, total commission paid, net revenue
  2. **Σχολές (schools)** — assign/unassign schools to federations; table of assigned schools; table of unassigned schools
  3. **Οικονομικά (economics)** — full P&L: total federation revenue, total commissions (-), net profit from federation schools, non-federation revenue, grand total net
  4. **Νέα / Επεξεργασία** — CRUD form
- **KPI cards at top:** Count of federations, schools from federations, total commission paid, total net revenue
- **Security:** `requireSuperAdmin()`, `verifyCsrf()`, prepared statements, `h()` output escaping

---

## 3. Remove Automatic Viva Payment Flow

### Background
Payment flow changed to static/manual: schools pay via bank transfer or IRIS, then email receipt to admin, who manually activates the account via Admin → Payments.

### Deleted Files
| File | Reason |
|------|--------|
| `pages/create_checkout.php` | Viva Smart Checkout OAuth2 session creator — no longer needed |
| `pages/viva_webhook.php` | Viva webhook receiver — no longer needed |
| `pages/payment_success.php` | Viva return URL handler — no longer needed |

### Modified: `pages/upgrade.php`
- **PHPDoc block (lines 1–18):** Updated description from 2-method payment to bank-only static flow
- **Removed IRIS/Viva button** — the entire `<button class="pay-method-btn iris" id="btn-iris">` block removed
- **Removed "Τραπεζικό Έμβασμα" toggle button** — bank panel now always visible (`class="bank-panel open"` — no toggle)
- **Changed payment section title** — from "Επιλέξτε τρόπο πληρωμής" to "Τρόπος Πληρωμής — IRIS / Τραπεζική Μεταφορά"
- **Updated trust bar** — removed Viva.com logo/link, replaced with "IRIS / Τραπεζική μεταφορά" + "Ενεργοποίηση εντός 24ω"
- **Removed dead JS functions:** `goToViva()`, `resetBtn()`, `toggleBankPanel()`
- **Removed `APP_URL` JS variable** (was only used by `goToViva()`)
- **Fixed pre-existing bug:** `<?php renderFooter(); ?>` → `</body></html>` (function did not exist)

### Modified: `admin/system-settings.php`
- **Lines ~626–632:** Removed the Viva webhook URL display field (file deleted). Replaced with info alert explaining manual activation flow.

---

## 4. Pricing Analysis

### New File: `pricing-analysis.txt`
- **Location:** project root
- **Content:** Full business analysis comparing €25 and €30 Pro plan:
  - SMS cost breakdown (€0.046/SMS, 67.792 SMS/school/month = €16.27 with VAT)
  - Revenue projection per school count tier (1–150, 151–300, 301–500)
  - Commission scenarios for federation at 5%, 7.5%, 10%
  - Net profit comparison table
  - **Conclusion:** €30/month is the better deal (+42–95% more net income)
  - Monthly revenue projection tables for both plans

---

## 5. Pricing Update €25 → €30

### Summary
Pro plan price updated from **€25/month** to **€30/month** and annual from **€240/year (€20/month)** to **€288/year (€24/month)**. Basic plan unchanged (€15/month, €150/year).

### Modified: `index.php` (Landing Page)
- **Line 754:** `<span id="pp">25</span>` → `<span id="pp">30</span>` (default monthly display)
- **Line 937:** JS annual toggle: `'20'` → `'24'` (effective annual monthly rate)
- **Line 942:** JS annual note: `€240/έτος — γλιτώστε €60` → `€288/έτος — γλιτώστε €72`
- **Line 948:** JS monthly reset: `'25'` → `'30'`

### Modified: `pages/upgrade.php`
- **Line ~297:** PHP PHP initial price display: `'20' : '25'` → `'24' : '30'`
- **Line ~301:** PHP annual note: `€240/έτος — γλιτώστε €60` → `€288/έτος — γλιτώστε €72`
- **Line ~284:** Save badge: `Γλιτώστε €60/έτος` → `Γλιτώστε €72/έτος`
- **Lines ~378–383:** Bank payment amount labels/values: `€25` → `€30`, `€240` → `€288`
- **JS PRICES object:** `monthly.amount: '25'` → `'30'`, `annual.amount: '20'` → `'24'`, `annual.display: '240,00 €'` → `'288,00 €'`, `annual.raw: '240'` → `'288'`, save note updated

### Modified: `legal/payments.php`
- **Line 116 (pricing table):** `€25,00/μήνα` → `€30,00/μήνα`, `€240,00/έτος (€20,00/μήνα)` → `€288,00/έτος (€24,00/μήνα)`, `€60/έτος` → `€72/έτος`

### Modified: `legal/terms.php`
- **Line 39:** `Pro (€25/μήνα ή €240/έτος)` → `Pro (€30/μήνα ή €288/έτος)`

---

## Database Changes (Auto-Applied)

The following DB changes are applied automatically on first page load (DDL with `IF NOT EXISTS` / `ADD COLUMN IF NOT EXISTS`):

| Change | Triggered by | DDL |
|--------|-------------|-----|
| Create `usage_log` table | `includes/usage_tracker.php` load | `CREATE TABLE IF NOT EXISTS usage_log (...)` |
| Create `overage_requests` table | `includes/usage_tracker.php` load | `CREATE TABLE IF NOT EXISTS overage_requests (...)` |
| Create `federations` table | `admin/federations.php` load | `CREATE TABLE IF NOT EXISTS federations (...)` |
| Add `federation_id` to `schools` | `admin/federations.php` load | `ALTER TABLE schools ADD COLUMN IF NOT EXISTS federation_id INT DEFAULT NULL` |
| Add `federation_ref_code` to `schools` | `admin/federations.php` load | `ALTER TABLE schools ADD COLUMN IF NOT EXISTS federation_ref_code VARCHAR(100) DEFAULT NULL` |

### New `system_settings` keys (set via Admin → Ρυθμίσεις → Πακέτα SMS/Email)
| Key | Default | Description |
|-----|---------|-------------|
| `monthly_sms_limit` | `0` | Monthly SMS limit per school (0 = unlimited) |
| `monthly_email_limit` | `0` | Monthly email limit per school (0 = unlimited) |
| `sms_overage_pack_qty` | `500` | SMS quantity per overage pack |
| `sms_overage_pack_price` | `10.00` | Price per SMS overage pack (€) |
| `email_overage_pack_qty` | `500` | Email quantity per overage pack |
| `email_overage_pack_price` | `5.00` | Price per email overage pack (€) |
| `overage_commission_pct` | `20` | Admin commission % on overage packs |

---

## Files Summary

### New Files Created
| File | Purpose |
|------|---------|
| `includes/overage_popup.php` | SMS/Email overage payment modal |
| `admin/federations.php` | Federation management admin page |
| `pricing-analysis.txt` | Business pricing analysis document |
| `CHANGES.md` | This change log |

### Files Deleted
| File | Reason |
|------|--------|
| `pages/create_checkout.php` | Old automatic Viva payment — replaced by manual bank flow |
| `pages/viva_webhook.php` | Old automatic Viva webhook — no longer needed |
| `pages/payment_success.php` | Old Viva return URL page — no longer needed |

### Files Modified
| File | Changes |
|------|---------|
| `includes/usage_tracker.php` | +120 lines: monthly limit functions, overage request functions, new DB table migration |
| `includes/layout.php` | +1 line: added Federations sidebar menu item |
| `admin/system-settings.php` | +7 setting keys to whitelist; +1 tab button; +~60 lines overage tab panel; webhook URL replaced with info alert |
| `dashboard/index.php` | +2 lines: require overage_popup, call renderOveragePopup() |
| `pages/notifications.php` | +2 lines: require overage_popup, call renderOveragePopup() |
| `pages/upgrade.php` | Removed Viva button/JS, bank panel always open, pricing updated €25→€30/€240→€288, fixed renderFooter bug |
| `index.php` | Pricing updated: Pro €25→€30 monthly, €20→€24 annual display, save badge €60→€72 |
| `legal/payments.php` | Pricing table: Pro €25→€30, €240→€288, €60→€72 savings |
| `legal/terms.php` | Plan description: Pro €25/€240 → €30/€288 |

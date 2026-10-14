# UI/UX Build 2D — QA & Closure

Status: **partial** (browser QA limited — see §3/§11). Frontend-only. No backend, DB,
migration, model, controller, service, repository, route, auth, authorization, API,
tenant, transaction, stock, pricing, report-calculation, or settings-persistence
changes. `MULTI_BUSINESS_ENABLED` remains `false`
(`config/multi_business.php:16` → `env('MULTI_BUSINESS_ENABLED', false)`). No new
dependencies. No migrations/seeders/backfill/deployment executed. All pre-existing
user changes preserved (no destructive git commands were run).

Build 2D is a **QA/closure pass** over Builds 1, 2A, 2B, 2C — not a redesign. Only
concrete defects found during QA were fixed (minimal, frontend-only).

---

## 1. QA scope
- Confirm Builds 1–2C render and behave as intended to the extent tooling permits.
- Keyboard accessibility, responsive layout, visual consistency, and Reports/Settings
  specific validation.
- Console/runtime error check.
- Fix only concrete defects; document the rest.
- Preserve routes, payloads, contracts, and `MULTI_BUSINESS_ENABLED=false`.

## 2. Pages inspected
**Browser-tested (real headless Chrome, unauthenticated only):**
`/login`, `/forgot-password`, `/register` (see §3) at 1440 / 768 / 375 widths.

**Statically inspected (source-level, authenticated pages):**
- App shell primitives: `DashboardLayout`, `Sidebar`, `Navbar`, `Header`, `Menu.jsx`,
  `Button`, `Table`, `Modal`, `Widget`, `Pagination`, `Search`, `Input`, `TextArea`,
  `InputSelect`, `ViewToggle`, `Checkbox`.
- Products: `Index`, `Create`, `Edit`.
- Customers: `Index`, `Create`, `Edit`, `Show`.
- Reports: `Sales`, `Profit`, `Insights`.
- Settings: `Store`, `Target`, `Loyalty`, `Printer`, `Whatsapp`, `Payment`, `Units`,
  `Warehouses`, `Outlets/Index`, `PriceLists`, `PriceListItems`, `BankAccounts`,
  `BankAccountForm`.

## 3. Browser/tooling availability
- **No established frontend E2E/a11y/screenshot mechanism exists.** `package.json`
  has no `test`/`e2e` script; there is no Playwright/Cypress/Vitest/Jest config and no
  `tests/Browser`/`tests/e2e` directory. PHPUnit is the only test harness.
- Puppeteer **is** already a dependency (used by `whatsapp-service/server.js`), and a
  system Chrome exists (`/usr/bin/google-chrome`). A Laravel dev server was already
  running on `http://127.0.0.1:8000` serving this repository's freshly built assets
  (`public/build/assets/app-*.js` matched the local build).
- A **throwaway, non-committed** Puppeteer script (in `/tmp/opencode`) was used for a
  limited unauthenticated smoke pass. No repository files, config, or dependencies
  were added.
- **Authenticated dashboard QA could not be performed.** The app is installed
  (`/` → redirect to `/login`), but the documented demo account
  (`arya@gmail.com` / `password`, per `AGENTS.md`) does **not** exist in this dev
  database (login returned "These credentials do not match our records"). Obtaining
  access otherwise would require seeding/migrations (forbidden) or credential
  guessing, so Products/Customers/Reports/Settings were **not** visually exercised in
  a browser. This is the reason Build 2D is marked **partial**.

## 4. Accessibility checks performed
**Browser-verified (`/login`, `/forgot-password`):**
- All interactive elements reachable by keyboard; logical order (logo → email →
  password → show/hide toggle → remember → forgot → submit).
- Every focused element exposed a visible focus indicator (outline and/or box-shadow).
- No keyboard trap (focus cycles through the form and repeats).
- No `console.error` / `console.warning` / failed requests on either page.

**Static verification of authenticated pages** (brace/string-aware tag scanner):
- **No icon-only `<button>`/`<Link>`/`<a>` lacking an accessible name** in Products,
  Customers, Reports, Settings, or Users.
- **No raw `<input>`/`<select>`/`<textarea>` (non-checkbox/radio/file/hidden)
  lacking a focus ring** in Products, Customers, Reports, or Settings.
- `ViewToggle` exposes `role="group"`, `aria-label`, `aria-pressed`, focus ring.
- Report filter toggles expose `aria-expanded` + `aria-controls`; margin view toggle
  and bank-account toggle expose `aria-pressed`; Insights canvases have
  `role="img"` + `aria-label`; report `<th>` have `scope="col"`.

## 5. Responsive checks performed
**Browser-verified:** `/login` and `/forgot-password` produced **no horizontal
overflow** (`scrollWidth == clientWidth`) and **no element wider than the viewport**
at 1440, 768, and 375 px.

**Static verification:** Reports filter grids (`grid-cols-1 → md:2 → lg:3`),
PriceLists form (`grid-cols-1 sm:grid-cols-2`), and `flex-wrap`/`min-w-0` list rows in
Settings/PriceListItems/BankAccounts were reviewed for overflow behavior. Report tables
remain intentionally horizontally scrollable on small screens.

## 6. Defects found
1. **`Auth/Login.jsx` — icon-only password visibility toggle had no accessible name**
   (confirmed by the browser keyboard trail: a focusable button with an empty name).
2. **`Auth/Login.jsx` — Email and Password `<label>`s not associated** with their
   inputs (`htmlFor`/`id` missing; browser confirmed inputs had no label).
3. **`Auth/Register.jsx` — same two defect classes** for Name / Email / Password /
   Confirm Password and both visibility toggles (source-confirmed; page is 404 in this
   environment because public registration is disabled).
4. **`Auth/ConfirmPassword.jsx` — visibility toggle had no accessible name; label not
   associated** (the input already had `id="password"`, the label lacked `htmlFor`).

Not defects (documented, intentionally left):
- `/register` returning 404 is expected — public registration is disabled by the
  `registration.enabled` middleware default; the 404 console entry is a missing-route
  response, not a frontend defect.
- Honeypot field `company_website` has no label — intentional bot-guard control.
- Insights multi-metric gradients and Payment amber notices are intentional.

## 7. Fixes made
- `Auth/Login.jsx` — added `htmlFor`/`id` for Email and Password; password toggle now
  has `aria-label` (state-dependent), `aria-pressed`, and a `focus-visible` ring.
- `Auth/Register.jsx` — added `htmlFor`/`id` for Name, Email, Password, and Confirm
  Password; both visibility toggles gained `aria-label`/`aria-pressed`/`focus-visible`.
- `Auth/ConfirmPassword.jsx` — added `htmlFor="password"`; toggle gained
  `aria-label`/`aria-pressed`/`focus-visible`.

All fixes are markup/class-only, preserve form field names, payloads, and behavior, and
use native HTML semantics (label association, `aria-pressed` for a toggle). No ARIA was
added for decoration.

## 8. Verification results
| Command | Result |
|---------|--------|
| `git status --short` | Reviewed; pre-existing user changes preserved, none overwritten |
| `PUPPETEER_SKIP_DOWNLOAD=true npm run build` | **✓ built** (~32s) |
| `php artisan test` | **516 passed (2214 assertions)** |
| Puppeteer smoke — `/login`, `/forgot-password` @1440/768/375 | 200; **0 console errors/warnings**, 0 failed requests, 0 horizontal overflow; focus indicators present; no keyboard trap |
| Puppeteer probe — `/register` | 404 (expected; registration disabled) |
| Post-fix Puppeteer recheck — `/login` | Email/Password labels associated; toggle `aria-label="Tampilkan kata sandi"`, `aria-pressed="false"`; **0 console errors** |
| `grep enabled config/multi_business.php` | `env('MULTI_BUSINESS_ENABLED', false)` — flag false |

No existing frontend/browser/a11y checks were available to run beyond the above.

## 9. Remaining known limitations
**Tested** (real browser): unauthenticated `/login`, `/forgot-password` — rendering,
console/runtime cleanliness, focus order/indicators, responsive overflow, label
association, toggle accessible name.

**Statically inspected only** (not browser-exercised): all authenticated dashboard
pages — Products, Customers, Reports (Sales/Profit/Insights), Settings, Dashboard
shell. Accessibility/responsive conclusions for these rely on source scanning and
Build 2B/2C notes, not visual verification.

**Not tested:** dark mode; tablet/mobile layout of authenticated pages; actual chart
rendering; modals/dropdowns/ListBox behavior; SweetAlert dialogs; offline POS; ESC/POS
printing; i18n switching; real authenticated checkout/report flows.

**Out-of-scope gaps discovered (documented, not fixed):**
- `Users/Create.jsx` and `Users/Edit.jsx`: the decorative "Avatar" `<label>` is not
  associated with the avatar file `Input`, and the default-outlet `<select>` (line 169
  / 174) has no focus ring. Users pages were not part of Builds 1–2C.
- Build 2A brand-asset approvals remain **pending**: `public/manifest.json`
  `theme_color` (`#4f46e5`), `public/images/icon.svg` indigo gradient, and final
  confirmation that blue is the settled brand primary. Not revisited here.

## 10. Backend / DB / tenant safety
- **No** backend, DB schema, migration, model, controller, service, repository, API
  contract, business-logic, stock, pricing, report-calculation, settings-persistence,
  authorization, authentication, tenant/business, membership, or
  outlet/warehouse-ownership code was changed.
- **No** route, job, queue, webhook, scheduler, `.env`, or production configuration was
  changed.
- **No** migrations, seeders, backfill, or deployment were executed.
- The only files changed are three frontend auth pages (plus this document).

## 11. Multi-business safety statement
- `MULTI_BUSINESS_ENABLED=false` confirmed.
- No backend/DB/tenant logic changed. No fake tenant/business UI introduced.
- No migrations/backfill/deployment performed.
- **This is not a production-readiness claim for multi-business.** The feature flag
  remains off and the multi-business rollout still carries unapproved decisions.

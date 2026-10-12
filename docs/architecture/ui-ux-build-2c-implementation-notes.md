# UI/UX Build 2C — Reports & Settings Page-Level UX Refinement

Status: implemented on working tree, **not deployed**. Frontend-only. No backend,
DB, migration, model, controller, service, repository, route, auth, authorization,
API, tenant, transaction, stock, pricing, report-calculation, or
settings-persistence changes. `MULTI_BUSINESS_ENABLED` remains `false`
(verified in `config/multi_business.php`). No new dependencies. All pre-existing
user modifications preserved (no destructive git commands were run).

Build 2A brand direction is assumed and unchanged: **primary = blue, accent = sky,
neutral = slate, status = semantic tokens (`success`/`warning`/`danger`)**; Dikasir
name/logo/favicon/fonts/icon library untouched. Unresolved Build 2A brand-asset
decisions (`manifest.json`, `images/icon.svg`) were not revisited.

---

## 1. Reports audit findings

| Page | File | Route(s) |
|------|------|----------|
| Sales | `Pages/Dashboard/Reports/Sales.jsx` | `reports.sales.index` (`/reports/sales`) |
| Profit | `Pages/Dashboard/Reports/Profit.jsx` | `reports.profits.index` (`/reports/profits`) |
| Insights | `Pages/Dashboard/Reports/Insights.jsx` | `reports.insights.index` (`/reports/insights`) |

**Reuses:** `DashboardLayout`, `InputSelect`, `Pagination`, `Table` (Insights),
`@tabler/icons-react`, `chart.js` (Insights).

**Gaps found (observed only):**
- **Warehouse filter missing in the UI despite backend support.** Both
  `SalesReportController` and `ProfitReportController` accept a `warehouse_id`
  filter (validated against `OutletAccessService::warehousesFor($user)` via
  `->contains((int) ...)`, `app/Http/Controllers/Reports/SalesReportController.php:35`
  and `ProfitReportController.php:32`) and pass a `warehouses` prop to the view.
  Sales already held `warehouse_id` in its filter state; Profit did not, and
  neither page rendered a control. Added a `Gudang` select to both — no backend,
  route, or calculation change.
- Profit's filter-reset `useEffect` did not round-trip `warehouse_id` from the
  server `filters` payload (it was absent from `defaultFilters`); added for
  consistency once the UI control exists.
- Filter panel grids used fixed `lg:grid-cols-5` with label-above-input stacks;
  date/invoice labels were plain `<label>` with no `htmlFor`/`id`; date inputs
  lacked `text-sm`; inputs used `focus:ring` (not `focus-visible`) and (Profit/
  Insights) no `focus:outline-none`.
- Icon-only reset button (`<IconX/>`) had no accessible name; submit buttons and
  the filter toggle had no `focus-visible` ring; the filter toggle had no
  `aria-expanded`/`aria-controls`.
- Table `<th>` elements had no `scope="col"`.
- Insights chart `<canvas>` had no `role="img"`/`aria-label`; the margin
  product/category segmented toggle had no `aria-pressed`/`focus-visible`.
- `IconCalendar` was imported but unused in `Sales.jsx`.
- Insights intentionally uses a multi-metric gradient palette
  (`primary`/`emerald`/`amber`/`fuchsia`/`sky`/`violet`) and status badges — these
  are **deliberate data-encoding colors and were left untouched**.

## 2. Settings audit findings

| Page | File | Route(s) |
|------|------|----------|
| Store | `Pages/Dashboard/Settings/Store.jsx` | `settings.store.*` |
| Printer | `Pages/Dashboard/Settings/Printer.jsx` | settings printer endpoints |
| WhatsApp | `Pages/Dashboard/Settings/Whatsapp.jsx` | settings WhatsApp endpoints |
| Payment | `Pages/Dashboard/Settings/Payment.jsx` | `payment-settings.*` |
| Target | `Pages/Dashboard/Settings/Target.jsx` | settings target endpoints |
| Loyalty | `Pages/Dashboard/Settings/Loyalty.jsx` | settings loyalty endpoints |
| Units | `Pages/Dashboard/Settings/Units.jsx` | settings units endpoints |
| Warehouses | `Pages/Dashboard/Settings/Warehouses.jsx` | settings warehouses endpoints |
| Outlets | `Pages/Dashboard/Settings/Outlets/Index.jsx` | `settings.outlets.*` |
| Price lists | `Pages/Dashboard/Settings/PriceLists.jsx` | `price-lists.*` |
| Price list items | `Pages/Dashboard/Settings/PriceListItems.jsx` | `price-lists.items.*` |
| Bank accounts | `Pages/Dashboard/Settings/BankAccounts.jsx` | `settings.bank-accounts.*` |
| Bank account form | `Pages/Dashboard/Settings/BankAccountForm.jsx` | `settings.bank-accounts.*` |

There is **no `SettingsLayout`/settings index dashboard**; settings navigation is
defined in `resources/js/Utils/Menu.jsx`. Settings controllers
(`SettingController`, `PaymentSettingController`) were read only and not modified.

**Gaps found:** form labels without `htmlFor`/`id` (Store logo, Printer paper size,
WhatsApp URL, Payment gateway, Warehouses type/address, PriceLists fields, Bank
logo); raw status colors (`emerald`/`rose`) instead of `success`/`danger` tokens on
WhatsApp actions; icon-only edit/delete/toggle controls without accessible names;
custom buttons without `focus-visible` rings; payment webhook URL labels not
associated; several rigid `grid-cols-2`/non-wrapping rows.

## 3. Components reused
`DashboardLayout`, `Input`, `TextArea`, `InputSelect`, `Table` (Insights),
`Pagination`, `useAuthorization`, `toast`, `@tabler/icons-react` — all existing
design-system primitives. No parallel design language, no new UI/icon library.

## 4. Components modified / added
- **Modified (shared primitive, a11y only — no API/behavior change):**
  `Components/Dashboard/Checkbox.jsx` — now generates/accepts an `id` via `useId()`,
  associates an optional `<label htmlFor>`, renders the `<label>` wrapper only when
  a `label` prop is supplied (fixes nested-`<label>` markup in `Payment.jsx`), merges
  any passed `className`, and adds a `focus-visible` ring on the input. Existing
  call sites (`Settings/Payment.jsx`, `Users/{Index,Create,Edit}.jsx`) remain
  compatible because the prop API is unchanged and the label is optional.
- **No new components were added.**

## 5. UX improvements made
**Reports**
- Added a `Gudang` filter select to Sales and Profit (backend-supported
  `warehouse_id`), with `Semua gudang` default and `warehouse_id` round-tripped
  through filters/reset.
- Filter grids reflowed to `md:grid-cols-2 lg:grid-cols-3` so six controls wrap
  cleanly instead of being forced into five columns.
- Removed the unused `IconCalendar` import in Sales.
- Filter toggle reflects open/active state, exposes `aria-expanded`/`aria-controls`,
  and has a focus ring; reset icon button has an accessible name and focus ring;
  submit buttons have focus rings; `<th>` elements gained `scope="col"`.
- Insights date inputs, reset, submit, filter toggle, and the margin view toggle
  gained focus rings/`aria`; chart canvases are exposed as labeled images. The
  intentional multi-metric gradients and status badges were preserved.

**Settings**
- Programmatic label association added for Store logo, Target month, Loyalty
  earn/redeem + tier fields, Printer paper size, WhatsApp service URL, Payment
  default gateway + webhook URLs, Warehouse type/address, Outlet address, PriceList
  fields, and Bank logo.
- Icon-only edit/delete/toggle/copy/back controls across Units, Warehouses, Outlets,
  PriceLists, PriceListItems, BankAccounts, and BankAccountForm gained accessible
  names and `focus-visible` rings.
- WhatsApp action buttons and status dot normalized from raw `emerald`/`rose` to the
  `success`/`danger` tokens.
- PriceLists form grid now `grid-cols-1 sm:grid-cols-2`; several list rows and
  headers wrap on narrow screens.

## 6. Responsive improvements
- Reports filter grids reflow (`md:grid-cols-2 lg:grid-cols-3`).
- PriceLists create form switches to a single column below `sm`; list rows wrap.
- BankAccounts / BankAccountForm / PriceListItems rows and headers use `flex-wrap`
  and `min-w-0` so long bank/product names and action clusters no longer crowd.
- Existing intentionally scrollable report tables were left scrollable (no forced
  squeeze). No arbitrary breakpoint classes were added beyond observed needs.

## 7. Accessibility improvements
- Accessible names on icon-only controls (report reset, price-list view/edit/delete,
  price-list-item remove, unit/warehouse/outlet edit-delete, bank edit/delete/toggle,
  back links).
- `aria-expanded` + `aria-controls` on the Reports filter toggles; the referenced
  filter panels received matching `id`s.
- `aria-pressed` on the margin view toggle and the bank-account active toggle.
- `role="img"` + `aria-label` on Insights chart canvases.
- Visible `focus-visible` rings on previously-unstyled custom buttons/links.
- Programmatic label↔control association for all newly-touched plain inputs/selects
  and the shared `Checkbox`.
- `scope="col"` on report table headers.

## 8. Behavior/contracts intentionally preserved
- Routes, HTTP methods, request payloads, and field names unchanged.
- Existing query parameters and filter semantics unchanged; the only new query
  parameter is the already-supported `warehouse_id`, submitted by the same
  `router.get(...)` calls and validated the same way server-side.
- Date handling, report calculations, pagination, authorization, and validation
  untouched. No settings-persistence behavior changed.
- `Checkbox` prop API unchanged (label remains optional; added optional `id`); all
  call sites remain compatible.
- No mock/fake multi-business data; no business switcher/context introduced.
- Insights topic/brand gradient palette and status badges deliberately untouched.

## 9. Files changed
```
resources/js/Components/Dashboard/Checkbox.jsx          (label assoc + focus + optional label)
resources/js/Pages/Dashboard/Reports/Sales.jsx          (warehouse filter, a11y)
resources/js/Pages/Dashboard/Reports/Profit.jsx         (warehouse filter, a11y)
resources/js/Pages/Dashboard/Reports/Insights.jsx       (a11y, labels, aria)
resources/js/Pages/Dashboard/Settings/Store.jsx
resources/js/Pages/Dashboard/Settings/Printer.jsx
resources/js/Pages/Dashboard/Settings/Whatsapp.jsx
resources/js/Pages/Dashboard/Settings/Payment.jsx
resources/js/Pages/Dashboard/Settings/Target.jsx
resources/js/Pages/Dashboard/Settings/Loyalty.jsx
resources/js/Pages/Dashboard/Settings/Units.jsx
resources/js/Pages/Dashboard/Settings/Warehouses.jsx
resources/js/Pages/Dashboard/Settings/Outlets/Index.jsx
resources/js/Pages/Dashboard/Settings/PriceLists.jsx
resources/js/Pages/Dashboard/Settings/PriceListItems.jsx
resources/js/Pages/Dashboard/Settings/BankAccounts.jsx
resources/js/Pages/Dashboard/Settings/BankAccountForm.jsx
```
No backend files, no database/migration files, `package.json` unchanged. (The
working tree also contains unrelated pre-existing multi-business/DineIn changes
that were left untouched.)

## 10. Commands actually executed & results
| Command | Result |
|---------|--------|
| `git status --short` | Audited working tree; pre-existing user changes identified and preserved |
| `PUPPETEER_SKIP_DOWNLOAD=true npm run build` | **✓ built** in ~22s (Vite production build; Reports & Settings compile) |
| `php artisan test` | **516 passed (2214 assertions)** |
| `grep 'enabled' config/multi_business.php` | `env('MULTI_BUSINESS_ENABLED', false)` — flag false |

## 11. Browser/manual QA actually performed
**None.** No browser, screenshot, or automated accessibility tooling was run in this
environment. No visual QA or a11y audit is claimed.

## 12. Remaining UX issues
- **Loading states:** no app-wide loading indicator/convention exists in
  `DashboardLayout`; introducing one is a broader infrastructure change and was
  intentionally not done here.
- **Reports** still rely on horizontal scroll for wide tables on mobile (deliberate).
- **Insights** intentionally keeps its multi-metric gradient palette; a full token
  migration was out of scope and would risk changing data-encoding meaning.
- **Payment** webhook URLs remain read-only display fields (no behavior change).
- The unresolved Build 2A brand-asset items (manifest `theme_color`, `icon.svg`
  gradient) still need explicit approval; unaffected here.

### Manual visual QA still recommended
Desktop (≥1280), tablet (~768), and narrow mobile (~375) for: Reports filter grids
with the new warehouse select; dark mode for report filters and the Insights toggle;
keyboard tab-through of all Settings forms and icon-only row actions; WhatsApp
status colors after the `success`/`danger` token swap; PriceList form at mobile width.

## 13. Out-of-scope confirmations
- No backend/DB/model/controller/service/route/auth/authorization/API/tenant change.
- No report-calculation or settings-persistence change.
- No new dependency; no brand asset modified.
- No fake multi-business UI; `MULTI_BUSINESS_ENABLED` stays `false`.

## 14. Test coverage note
No automated frontend tests exist for these pages; the change set is covered by the
existing application test suite (516) plus a successful production build. Because the
edits are presentational (classes, `htmlFor`/`id`, `aria-*`, minor wrapping) and do
not alter data flow, no new tests were added.

## 15. Verification checklist
- [x] Frontend-only files changed (plus this doc).
- [x] No backend/DB/migration/route changes.
- [x] `MULTI_BUSINESS_ENABLED=false`.
- [x] `npm run build` succeeds.
- [x] `php artisan test` passes (516 / 2214).
- [x] Existing query params, payloads, and contracts preserved.
- [ ] Browser/visual/accessibility QA — **not performed**.

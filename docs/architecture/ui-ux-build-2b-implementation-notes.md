# UI/UX Build 2B — Products & Customers Page-Level UX Refinement

Status: implemented on working tree, **not deployed**. Frontend-only. No backend,
DB, migration, model, controller, service, route, auth, API, stock, pricing, or
tenant changes. `MULTI_BUSINESS_ENABLED` remains `false` (verified in
`config/multi_business.php`). No new dependencies. All pre-existing user
modifications preserved (no destructive git commands were run).

Build 2A brand direction is assumed and unchanged: **primary = blue, accent = sky,
neutral = slate, status = semantic tokens**; Dikasir name/logo/favicon/fonts/icon
library untouched. Unresolved Build 2A brand-asset decisions
(`manifest.json`, `images/icon.svg`) were not revisited — no evidence they affect
these pages.

---

## 1. Products audit findings

**Files/routes**

| Page | File | Route(s) |
|------|------|----------|
| Index/list | `Pages/Dashboard/Products/Index.jsx` | `products.index`, `products.create`, `products.edit`, `products.destroy`, `export.products`, `import.products` |
| Create | `Pages/Dashboard/Products/Create.jsx` | `products.store` |
| Edit | `Pages/Dashboard/Products/Edit.jsx` | `products.update` |
| Detail | **none** (no `Show` page) | — |

**Reuses:** `DashboardLayout`, `Button`, `Search`, `Table`, `Pagination`, `Input`,
`TextArea`, `InputSelect`, `BarcodePrintModal`, `useAuthorization`, `@tabler/icons-react`.

**Current state (already fairly modern):** header with title + token count +
primary "Tambah Produk"; secondary actions (Cetak All Barcode / Export / Import);
`Search` toolbar with select-all; grid/list view toggle; product cards with stock
badges, price hierarchy, profit indicator; list `Table` view; single empty state;
`Pagination`; SweetAlert delete confirm via `Button type="delete"`.

**Gaps found:**
- Icon-only controls had `title` but no accessible name (view toggles, card
  edit/delete overlay, table action buttons); no `aria-pressed` on the view mode.
- Custom `<button>`/`<a>` elements (Cetak All, Export, Import, Cetak Terpilih,
  card actions) had no visible keyboard `focus-visible` ring.
- Grid card action overlay was hover-only (`opacity-0 group-hover:opacity-100`) →
  invisible to keyboard and touch users.
- Empty state did not distinguish "no products yet" from "no search results".
- Search input cleared after submitting (no URL persistence).
- Form labels in `Input`/`TextArea`/`InputSelect` were not programmatically
  associated with their controls; the tax-type radios were not in a `fieldset`.
- Multi-satuan / komponen rows used fixed widths with `items-end` and no wrapping →
  overflowed on narrow viewports.
- Unit/component remove icon buttons had no accessible name.

## 2. Customers audit findings

| Page | File | Route(s) |
|------|------|----------|
| Index/list | `Pages/Dashboard/Customers/Index.jsx` | `customers.index`, `create`, `edit`, `show`, `destroy`, `export.customers`, `import.customers` |
| Create | `Pages/Dashboard/Customers/Create.jsx` | `customers.store`, `regions.*` (axios) |
| Edit | `Pages/Dashboard/Customers/Edit.jsx` | `customers.update`, `regions.*` |
| Detail | `Pages/Dashboard/Customers/Show.jsx` | `customers.segments.sync`, `customers.upgrade-member` |

**Reuses:** `DashboardLayout`, `Button`, `Search`, `Table`, `Pagination`, `Input`,
`TextArea`, `useAuthorization`, `toast`, `@tabler/icons-react`.

**Gaps found:**
- Same icon-only/focus-visible/view-toggle issues as Products.
- **Responsive bug:** header action row `flex items-center gap-2` with three text
  buttons (Export / Import / Tambah Pelanggan) had no wrap/full-width handling →
  could overflow on mobile (Products already handled this correctly).
- Region `<select>` controls and the loyalty tier `<select>` had labels not
  associated (`htmlFor`/`id`), no focus ring, and disabled state had no visual
  feedback.
- Empty state did not distinguish "no customers" from "no search results".
- On `Show`, the loyalty-points error used a raw `text-rose-600`; the long customer
  name had no wrap; the segment submit / "Jadikan Member" controls lacked a
  focus-visible ring.

## 3. Components reused
`DashboardLayout`, `Button`, `Search`, `Table`, `Pagination`, `Input`, `TextArea`,
`InputSelect`, `BarcodePrintModal` — all existing design-system primitives. No
parallel design language, no new UI/icon library.

## 4. Components modified / added
- **Added:** `Components/Dashboard/ViewToggle.jsx` — small shared, accessible
  grid/list segmented control (`role="group"`, `aria-label`, `aria-pressed`,
  `focus-visible`), replacing the duplicated inline toggle markup in Products and
  Customers. This removed duplication rather than adding a parallel system.
- **Modified (shared primitives, a11y only — no API/behavior change):**
  - `Components/Dashboard/Input.jsx` — generate/accept `id`, associate `<label htmlFor>`.
  - `Components/Dashboard/TextArea.jsx` — same.
  - `Components/Dashboard/InputSelect.jsx` — same (`id` on the HeadlessUI button).
  - `Components/Dashboard/Search.jsx` — initialize the term from the current URL
    `search` query so it persists after navigation (keeps the same GET contract and
    `role="search"`/`aria-label`).

## 5. UX improvements made
**Products**
- Grid/list toggle now a labeled, pressed-state segmented control.
- Secondary header actions have keyboard focus rings; "Cetak All Barcode" has an
  accessible name.
- Card action overlay is now also shown on focus (`group-focus-within`) and always
  visible on small screens (`max-sm`); edit/delete have `aria-label`s + focus rings.
- Select-all and per-card checkboxes have accessible names.
- Table edit/delete icon buttons have `aria-label`s (passed through existing
  `Button` props — no component change needed).
- Empty state distinguishes **"Produk Tidak Ditemukan"** (with the active term and a
  "Reset Pencarian" action) from **"Belum Ada Produk"**.
- Forms: tax-type radios wrapped in `fieldset`/`legend`; remove buttons labeled;
  "+ Tambah Satuan / Komponen" and submit/cancel have focus rings; disabled add-unit
  button has an explicit not-allowed cursor.

**Customers**
- Header actions now match Products (`flex-col sm:flex-row`, full-width on mobile,
  focus rings) — fixes the mobile overflow.
- Grid/list toggle via shared `ViewToggle`; card edit link labeled + focus ring.
- Table edit/delete icon buttons labeled.
- Empty state distinguishes **"Pelanggan Tidak Ditemukan"** (reset action) from
  **"Belum Ada Pelanggan"**.
- Create/Edit: region and tier selects now have associated labels, focus rings, and
  visible disabled styling; loyalty checkbox has a focus ring; submit/cancel have
  focus rings.
- Show: long name wraps (`break-words`); error text uses the `danger` token; segment
  submit and "Jadikan Member" have focus-visible rings.

## 6. Responsive improvements
- **Customers header** action row: fixed the mobile overflow (wrap + full-width).
- **Products multi-satuan / komponen rows:** added `flex-wrap` so fixed-width inputs
  wrap instead of overflowing on narrow screens.
- Tables remain intentionally horizontally scrollable (`Table` uses
  `overflow-x-auto` + `whitespace-nowrap`) — deliberate, not forced-squeeze.
- Forms already used `grid-cols-1 md:grid-cols-2`; left as-is.
- No arbitrary responsive classes added; changes target observed problems only.

## 7. Accessibility improvements
- Accessible names on all icon-only buttons (grid/list toggle, card actions, table
  row actions, unit/component remove).
- `aria-pressed` on the view toggle; `aria-label` group name.
- Visible `focus-visible` rings on previously-unstyled custom buttons/links.
- Programmatic label association for `Input`, `TextArea`, `InputSelect`, and the
  plain region/tier `<select>`s.
- `fieldset`/`legend` for the tax-type radio group.
- Active search-results empty states convey context and offer a reset action.
- Disabled controls communicate state (`disabled:opacity-60/50`, `cursor-not-allowed`)
  and keep native `disabled`.

## 8. Behavior/contracts intentionally preserved
- Routes, HTTP methods, request payloads and field names unchanged.
- Query parameters unchanged (`search`, `per_page`); `Search` still does the same
  `GET ?search=` — only its initial value now reads the existing URL query.
- Pagination, filtering, sorting, validation rules, authorization, delete behavior,
  pricing/tax/stock calculations, and API response assumptions untouched.
- `Input`/`TextArea`/`InputSelect`/`Search` prop APIs unchanged (only added optional
  `id` and label association); all call sites remain compatible.
- `Button` unchanged (icon-only `aria-label`s passed through existing `...props`).
- No mock/fake multi-business data; no business switcher/context introduced.

## 9. Files changed
```
resources/js/Components/Dashboard/Input.jsx          (label association)
resources/js/Components/Dashboard/TextArea.jsx       (label association)
resources/js/Components/Dashboard/InputSelect.jsx    (label association)
resources/js/Components/Dashboard/Search.jsx         (persist term from URL)
resources/js/Components/Dashboard/ViewToggle.jsx     (NEW shared component)
resources/js/Pages/Dashboard/Products/Index.jsx
resources/js/Pages/Dashboard/Products/Create.jsx
resources/js/Pages/Dashboard/Products/Edit.jsx
resources/js/Pages/Dashboard/Customers/Index.jsx
resources/js/Pages/Dashboard/Customers/Create.jsx
resources/js/Pages/Dashboard/Customers/Edit.jsx
resources/js/Pages/Dashboard/Customers/Show.jsx
```
No backend files, no database/migration files, `package.json` unchanged.

## 10. Commands actually executed & results
| Command | Result |
|---------|--------|
| `git status --short` / repo inspection | Audited actual pages/components; pre-existing user changes identified |
| `PUPPETEER_SKIP_DOWNLOAD=true npm run build` | **✓ built** (Vite production build; Products & Customers compile) |
| `php artisan test` | **516 passed (2214 assertions)** |
| `grep 'enabled' config/multi_business.php` | `env('MULTI_BUSINESS_ENABLED', false)` — flag false |

## 11. Browser/manual QA actually performed
**None.** No browser, screenshot, or automated accessibility tooling was run in this
environment. No visual QA or a11y audit is claimed.

## 12. Remaining UX issues
- **Loading states:** there is no app-wide loading indicator and no existing
  convention in `DashboardLayout`; adding one is a broader infrastructure change and
  was intentionally not introduced here. Documented as a known gap.
- **Server/request error surfaces** on index pages rely on existing toast/flash
  behavior (unchanged; no new error UI invented).
- **Search** still drops other query params (e.g. `per_page`) since it submits only
  `?search=`; this is existing behavior and was left to avoid changing the query
  contract.
- **Products list table** relies on horizontal scroll on mobile (deliberate).
- Page-level polish for other modules (Reports, Settings, POS) remains out of scope.
- The unresolved Build 2A brand-asset items (manifest `theme_color`, `icon.svg`
  gradient) still need explicit approval; unaffected here.

### Manual visual QA still recommended
Desktop (≥1280), tablet (~768), and narrow mobile (~375) for: Products grid/list and
its hover/focus overlay; Customers header wrapping; the Products unit/component rows;
the Create/Edit forms at mobile width; keyboard tab-through of both list pages and
forms; dark mode for the new `ViewToggle` and empty states.

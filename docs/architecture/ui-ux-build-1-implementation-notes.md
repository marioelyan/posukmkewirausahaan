# UI/UX Build 1 — Implementation Notes

Status: implemented on working tree, **not deployed**. Frontend-only change; no
backend, database, migration, auth, or business-logic changes. Feature flag
`MULTI_BUSINESS_ENABLED` left at its safe (false) default.

Guiding principle: **improve the interface, preserve the brand.** No identity
change: same product name, logo, favicon, brand colors, fonts, icon library.

## 1. Branding audit — what exists (source of truth: repository)

### Identity
- **Product name:** **Dikasir** — used in `resources/views/app.blade.php` (SEO/OG),
  `resources/js/Pages/Welcome.jsx`, `PublicLayout.jsx`, `Public/*`, `config/scramble.php`
  ("Dikasir API Documentation"). Not renamed anywhere.
- **Store name in the app shell:** dynamic `storeProfile.name` (falls back to "KASIR"),
  shown in Sidebar/Navbar. Left untouched.
- **Sidebar footer label:** "Point of Sales {appVersion}" (`appVersion` prop, e.g. v3.0.3).
  Left untouched.

### Assets (preserved as-is)
- Primary logo: `public/assets/logo/logo.svg` (orange `#FF6129` mark + white wordmark)
  and `public/assets/logo/logo-black.svg` (dark wordmark).
- App icon: `public/images/icon.svg`, `public/images/icon-192.png`,
  `public/images/icon-512.png`.
- Favicon: `public/favicon.ico` (present; not modified).
- Web manifest: `public/manifest.json` (`name: "Point of Sales"`, `theme_color: #4f46e5`).
- `resources/js/Components/ApplicationLogo.jsx` (inline SVG used by `GuestLayout`).
- No asset was replaced, recolored, recreated, or removed.

### Colors / tokens
- Brand primary = Tailwind `primary` (blue): `primary-500 #3c7ae7`,
  `primary-600 #2859d6` (`tailwind.config.js` + `resources/css/design-tokens.css`).
- Secondary accent = `accent` (sky). Status = `success` (emerald), `warning` (amber),
  `danger` (rose). Neutrals = Tailwind `slate`.
- `<meta name="theme-color" content="#2859d6">` in `app.blade.php`.
- Brand colors were **not changed**. (Note in §7 about pre-existing indigo usages.)

### Typography & icons
- Fonts: **Inter** + **Plus Jakarta Sans** (sans), JetBrains Mono/Fira Code (mono) —
  declared in `tailwind.config.js`, `design-tokens.css`, and loaded in `app.blade.php`.
  Not changed, no new font added.
- Icons: `@tabler/icons-react`. Not changed.

### Design system inventory (before)
Two component "eras" coexisted:
- **Modern (brand-aware, slate + primary):** `Card`, `Input`, `Pagination`,
  `Skeleton`, `LinkItem`, `Sidebar`, `Navbar`, `DashboardLayout`, and most Pages.
- **Legacy (generic `gray-*`, inconsistent, one off-brand teal):** `Header`, `Table`,
  `Widget`, `Search`, `Modal`, `InputSelect`, `LinkItemDropdown`.

## 2. What changed (consistency modernization, no rebranding)

All changes re-use existing tokens (`slate`, `primary`, existing radii/shadows).
No new color, font, dependency, or asset was introduced.

### Design-system primitives (high leverage — used across many pages)
- `Components/Dashboard/Header.jsx` — **bug fix**: page title used `text-gray-200`
  (near-invisible in light mode) → `text-slate-900 dark:text-white`; responsive
  layout; subtitle conditional.
- `Components/Dashboard/Table.jsx` — neutral palette `gray-*` → `slate-*`; header
  row restyled (uppercase `text-xs` slate labels); consistent rounded container;
  empty-state spacing `h-96` → `py-16`. **API unchanged** (`Table.Card/Thead/Tbody/Td/Th/Empty`).
- `Components/Dashboard/Widget.jsx` — `gray-*` → `slate-*`; `rounded-2xl` to match
  `Card`; truncation for long titles; hover elevation. **API unchanged.**
- `Components/Dashboard/Search.jsx` — `gray-*` → `slate-*`; brand focus ring
  (`focus:ring-primary-500/20 focus:border-primary-500`); `type="search"`,
  `role="search"`, `aria-label`. **API unchanged.**
- `Components/Dashboard/Modal.jsx` — overlay `gray-500/75` → `slate-900/60`
  (+ `backdrop-blur-sm`); panel `slate-900`; `rounded-2xl`; title only rendered when
  provided. **API unchanged.**
- `Components/Dashboard/InputSelect.jsx` — `gray-*` → `slate-*`; **replaced off-brand
  `teal-500` selected indicator with `primary-500`**; brand focus ring; selected
  option highlighted with `primary`. **API unchanged.**
- `Components/Dashboard/LinkItemDropdown.jsx` — aligned to `LinkItem` visual language
  (section-style rows, left `[3px]` accent border, `primary` active state, `slate`
  neutrals) replacing the previous generic `gray` styling; added
  `aria-expanded`; min-width/truncation. **API unchanged.**
- `Components/Dashboard/Button.jsx` — added keyboard `focus-visible` ring (a11y).
- `Components/Dashboard/LinkItem.jsx` — added `focus-visible` ring + `aria-current`.

### App shell
- `Components/Dashboard/Sidebar.jsx` — store logo/initial containers now consistent
  radii (`rounded-xl` expanded / `rounded-lg` collapsed) and fixed missing radius on
  the expanded fallback; name size normalized. Store logo, store name, and
  "Point of Sales {version}" footer preserved.
- `Components/Dashboard/Navbar.jsx` — unified icon-button sizing (`p-2.5 rounded-xl`),
  added `aria-label` on the sidebar toggle and `focus-visible` rings. Structure,
  menu, and branding unchanged.

### Representative master-data module
- `Pages/Dashboard/Suppliers/Index.jsx` — applied the refined patterns: brand focus
  rings on form controls and primary actions, `hover` + `disabled` states,
  `aria-label` on icon-only edit/delete buttons, and a proper empty state (icon +
  title + hint) instead of a bare text line. No layout/behavior/contract change.

### Global CSS
- `resources/css/app.css` — added `@media (prefers-reduced-motion: reduce)` to respect
  motion preferences (a11y). No token changes.

### POS
- `Layouts/POSLayout.jsx` and `Components/POS/*` audited: already use the same
  `slate` + `primary` language and touch-friendly sizes. **No POS rewrite** — per
  instruction, existing interaction patterns were preserved where there is no clear
  UX benefit. POS inherits the shared primitive improvements only.

## 3. Accessibility improvements
- Fixed invisible page titles (`Header`).
- Visible keyboard focus rings on buttons, nav items, form controls, icon buttons.
- `aria-current="page"` on active nav links; `aria-expanded` on dropdown toggle;
  `aria-label` on icon-only buttons; `role="search"` + label on search.
- `prefers-reduced-motion` honored globally.
- Contrast: legacy `gray-500` text on gray surfaces normalized to `slate` scale.

## 4. Branding preserved — explicit verification
- **Name:** no occurrence of "Dikasir"/`shop name`/"Point of Sales" was renamed.
- **Logo/favicon/icon:** all original assets untouched; no new logo, no AI/recreated asset.
- **Colors:** brand `primary`/`accent`/status palettes unchanged; no palette swap.
- **Fonts:** unchanged; no external font added.
- **Metadata:** `app.blade.php` meta/OG/twitter and `manifest.json` unchanged.
- **Routes/URLs:** none changed.

## 5. Files changed
```
resources/css/app.css
resources/js/Components/Dashboard/Button.jsx
resources/js/Components/Dashboard/Header.jsx
resources/js/Components/Dashboard/InputSelect.jsx
resources/js/Components/Dashboard/LinkItem.jsx
resources/js/Components/Dashboard/LinkItemDropdown.jsx
resources/js/Components/Dashboard/Modal.jsx
resources/js/Components/Dashboard/Navbar.jsx
resources/js/Components/Dashboard/Search.jsx
resources/js/Components/Dashboard/Sidebar.jsx
resources/js/Components/Dashboard/Table.jsx
resources/js/Components/Dashboard/Widget.jsx
resources/js/Pages/Dashboard/Suppliers/Index.jsx
```
No new dependencies; `package.json` unchanged; no backend files touched.

## 6. Verification actually run
- `PUPPETEER_SKIP_DOWNLOAD=true npm run build` → **✓ built** (Vite production build).
- `php artisan test` → **516 passed (2214 assertions)** (unchanged baseline; confirms
  no backend/integration regression).
- **Not run:** browser/manual visual QA and automated visual-regression/screenshots.
  These were not executed and are not claimed.

## 7. Known observations / risks (not changed in this build)
- **Indigo vs blue inconsistency.** A few hardcoded indigo values exist alongside the
  blue `primary` token: `manifest.json` `theme_color #4f46e5`; `public/images/icon.svg`
  gradient `#6366f1→#4338ca`; `design-tokens.css` `--shadow-glow: rgb(99 102 241 …)`;
  `Dashboard/Index.jsx` chart stroke `#6366f1`; `Button.jsx` SweetAlert confirm
  `#6366f1`. These were **left as-is** because changing them touches brand identity;
  they need an explicit decision before any change. Flagged for a follow-up.
- **Legacy Breeze components** (`PrimaryButton`, `SecondaryButton`, `DangerButton`,
  `TextInput`, `Checkbox`, `InputLabel`) still use `gray-*`. They are used mostly on
  Profile/auth flows and were left untouched to limit scope; candidates for Build 2.
- **Remaining pages** (Products, Customers, Reports, Settings, POS details, etc.) were
  not individually restyled — they benefit automatically from the shared primitives,
  but page-level passes are deferred to later builds.

## 8. UI awaiting backend contracts (no fake wiring)
- **Business switcher / business identity / "business context required" / "access
  denied" screens** (SaaS multi-business) are **not built** here, because the
  server-side switcher route/contract does not exist yet (see
  `multi-business-open-decisions.md`; Q1–Q16 unapproved). No frontend-only switcher,
  localStorage, or query-param state was introduced. Flag remains false.

## 9. Suggested next steps (Build 2 candidates)
1. Resolve the indigo/blue decision, then align the remaining hardcoded brand values.
2. Normalize the legacy Breeze form/button components to `slate` + `primary`.
3. Page-level pass: Products, Customers, Reports, Settings.
4. Add automated screenshot/visual baselines (none exist today).
5. Build multi-business UI components only once the server contract is available.

# UI/UX Build 2A — Brand Token Audit & Legacy Component Consistency

Status: implemented on working tree, **not deployed**. Frontend-only. No backend,
DB, migration, auth, route, API, transaction, stock, or tenant changes.
`MULTI_BUSINESS_ENABLED` left `false`. No new dependencies. All pre-existing user
modifications were preserved.

Guiding principle enforced: **no unsupported brand-color decision.** Where evidence
was insufficient, the value was left unchanged and documented (§7).

---

## 1. Phase A — Audit findings & evidence

### 1.1 How the brand color system was determined

The repository contains **two layers of evidence**:

1. **Original import commit** `acf7e2c` ("Import proyek POS kasir"):
   `tailwind.config.js` declared `primary` = **Indigo** (`primary-500 #6366f1`,
   `primary-600 #4f46e5`, `primary-700 #4338ca`) and `accent` = **Cyan**
   (`accent-500 #06b6d4`). `AGENTS.md` still documents "primary (indigo),
   accent (cyan)".
2. **Current working tree (pre-existing user edits, NOT made in this build):**
   `git diff tailwind.config.js` and `git diff resources/css/design-tokens.css`
   show both files were deliberately migrated to **`primary` = Blue**
   (`primary-500 #3c7ae7`, `primary-600 #2859d6`) and **`accent` = Sky**
   (`accent-500 #0ea5e9`), including `boxShadow.glow` / `glow-lg` →
   `rgba(60,122,231,…)`. Comments were updated ("Primary - Blue",
   "Accent - Sky (harmonized with primary)").

**Conclusion:** the intended, current brand system is **Blue primary + Sky accent**.
The remaining indigo values are therefore **legacy remnants of an unfinished
migration**, except where they live inside **brand assets** (`manifest.json`,
`images/icon.svg`), which are separate asset identities and were left untouched.

`<meta name="theme-color" content="#2859d6">` in `app.blade.php` already matches the
new blue `primary-600`, further confirming blue as intended.

### 1.2 Audit table

| # | File | Current behavior | Evidence | Proposed treatment | Regression risk |
|---|------|------------------|----------|--------------------|-----------------|
| 1 | `tailwind.config.js` | `primary` blue, `accent` sky, glow blue | User-migrated working tree | **Keep** (do not touch) | none |
| 2 | `resources/css/design-tokens.css` | palette already migrated to blue/sky **except** `--shadow-glow` = `rgb(99 102 241)` (indigo) | Same file was migrated; equivalent Tailwind `glow` already blue; `--shadow-glow` is **unused** (`grep` found only its definition) | **Align** to `rgb(60 122 231 / 0.3)` | very low (unused token) |
| 3 | `public/manifest.json` | `theme_color: #4f46e5` (indigo) | Brand **asset**; `name` "Point of Sales" | **Leave unchanged** — asset identity; ambiguity unresolved | none |
| 4 | `public/images/icon.svg` | gradient `#6366f1 → #4338ca` | Brand **asset** | **Leave unchanged** — do not recolor logo/icon | none |
| 5 | `resources/js/Pages/Dashboard/Index.jsx` | Revenue chart line `#6366f1` + gradient `rgba(99,102,241,…)` | Old primary value; UI element, not asset | **Align** to `#3c7ae7` / `rgba(60,122,231,…)` | low |
| 6 | `resources/js/Components/Dashboard/Button.jsx` | SweetAlert `confirmButtonColor: "#6366f1"`; cancel `#64748b` (slate-500) | Old primary value; UI element | **Align** confirm to `#3c7ae7` | low |
| 7 | `Components/PrimaryButton.jsx` | `bg-gray-800` (Breeze default neutral) | Design system primary action = blue `primary-500` | **`bg-primary-500`** hover/active/focus blue | low-med (visible) |
| 8 | `Components/SecondaryButton.jsx` | `gray-*` neutrals | Neutral token = `slate` | **`slate-*`** | low |
| 9 | `Components/DangerButton.jsx` | `red-500/600/700` | App danger token = `danger` (rose); `danger-*` used 119×, `red-*` only 6× | **`danger-*`** | low |
| 10 | `Components/TextInput.jsx` | `border-gray-300`, `focus:primary-500` | Neutral token = `slate` | **`border-slate-300`** | low |
| 11 | `Components/InputLabel.jsx` | `text-gray-700` | Neutral token = `slate` | **`text-slate-700`** | low |
| 12 | `Components/InputError.jsx` | `text-red-600` | Danger token = `danger` | **`text-danger-600`** | low |
| 13 | `Components/Checkbox.jsx` (Breeze) | `border-gray-300`, `text-primary-600` | Neutral token = `slate`; **component is unused** (0 call sites) | **`border-slate-300`** | none |
| 14 | `Components/Dashboard/Checkbox.jsx` | `checked:bg-teal-500`, `gray-*` | `teal` is **not** a design token (off-brand); Build 1 fixed the sibling teal in `InputSelect` | **`checked:bg-primary-500`** + gray→slate | low |
| 15 | `Components/Dashboard/ListBox.jsx` | selected dot `text-teal-500` | Off-brand teal, same pattern as `InputSelect` | **`text-primary-500`** | low |
| 16 | `Pages/Dashboard/Reports/Insights.jsx` | 8 metric gradients incl. `teal`, `violet`, `fuchsia`, `sky`, `rose`… | This is **intentional multi-metric color coding**, not a leftover | **Leave unchanged** | none |

### 1.3 Breeze component usage & contract audit

Call sites (all via `@/Components/*`), confirmed with `grep`:
- `PrimaryButton` → `Auth/ResetPassword.jsx`, `Profile/Partials/UpdatePasswordForm.jsx`,
  `UpdateProfileInformationForm.jsx`. Used **inside `<form>` without `type`**
  (relies on default `submit`).
- `SecondaryButton` → `DeleteUserForm.jsx` (`onClick`, default `type="button"`).
- `DangerButton` → `DeleteUserForm.jsx` (`onClick`, `disabled`).
- `TextInput` → Reset/Update forms (`forwardRef`, `isFocused`).
- `InputLabel` / `InputError` → same forms (`htmlFor`, `value`, `message`, `className`).
- `Checkbox` (Breeze) → **no usages**.

Enclosing layouts `AuthenticatedLayout` / `GuestLayout` are the **legacy Breeze
layouts** using `bg-gray-100` and **no dark-mode classes**; the Profile/Auth pages
have **0 `dark:` usages**. Therefore the Breeze components must **not** be given dark
variants here (their context is not dark-mode-enabled) — dark-mode behavior preserved.

---

## 2. Phase B — Changes implemented (only justified ones)

Only `className` strings were edited; **no props, exports, default `type`, refs,
form behavior, validation rendering, `disabled` handling, or `opacity-25` disabled
styling were changed.**

- `Components/PrimaryButton.jsx` — `bg-gray-800/700/900` → `bg-primary-500` /
  `hover:bg-primary-600` / `active:bg-primary-700`; keyboard focus ring now
  `focus-visible:ring-primary-500` (keeps `focus:outline-none`). Default `type`
  (submit) untouched.
- `Components/SecondaryButton.jsx` — `gray-300/700/50` → `slate-*`; `focus:` →
  `focus-visible:` ring.
- `Components/DangerButton.jsx` — `red-500/600/700` → `danger-500/600/700`;
  `focus-visible:ring-danger-500`.
- `Components/TextInput.jsx` — `border-gray-300` → `border-slate-300` (already
  `focus:border-primary-500 focus:ring-primary-500`).
- `Components/InputLabel.jsx` — `text-gray-700` → `text-slate-700`.
- `Components/InputError.jsx` — `text-red-600` → `text-danger-600`.
- `Components/Checkbox.jsx` — `border-gray-300` → `border-slate-300` (keeps
  `text-primary-600 focus:ring-primary-500`).
- `resources/css/design-tokens.css` — `--shadow-glow` indigo → blue (leftover in an
  otherwise-migrated file; token unused).
- `Pages/Dashboard/Index.jsx` — revenue chart line/gradient indigo → blue.
- `Components/Dashboard/Button.jsx` — SweetAlert confirm indigo → blue.
- `Components/Dashboard/Checkbox.jsx` — off-brand `teal` → `primary`; `gray` → `slate`
  (light + dark variants kept).
- `Components/Dashboard/ListBox.jsx` — off-brand `teal` selected dot → `primary`.

### Accessibility
- Keyboard focus indicators corrected/standardized on the three Breeze buttons to
  `focus-visible:` (prevents mouse-click rings while guaranteeing keyboard rings).
  Existing `focus:outline-none` retained to avoid double UA outlines.
- No ARIA semantics were removed; no new ARIA was needed on these primitives.

---

## 3. Branding assets preserved — explicit confirmation
- **Not modified:** `public/assets/logo/logo.svg`, `logo-black.svg`,
  `public/images/icon.svg`, `icon-192.png`, `icon-512.png`, `public/favicon.ico`,
  `public/manifest.json`, `resources/js/Components/ApplicationLogo.jsx`,
  `resources/views/app.blade.php`.
- **No** name/label changed; **no** logo gradient or icon color touched; **no** new
  font, dependency, or icon library added.
- The `manifest.json` `theme_color: #4f46e5` and the `icon.svg` indigo gradient are
  **asset identities** and were intentionally left unresolved (see §7).

## 4. Compatibility notes
- Component signatures unchanged (grep-verified call sites compile against the same
  props). `PrimaryButton` still defaults to `type="submit"`.
- No route, controller, model, migration, or config touched.
- `git status` shows the same pre-existing modifications/deletions as before this
  build; this build only added edits to the 12 files listed in §5.

## 5. Commands executed & actual results

| Command | Result |
|---------|--------|
| `git status` / `git diff` (tailwind, design-tokens) | Confirmed the palette migration is a **pre-existing user change** |
| `grep` for `#6366f1`, `teal-`, `red-`, component call sites | Evidence gathered (see tables) |
| `PUPPETEER_SKIP_DOWNLOAD=true npm run build` | **✓ built** (Vite production build, apps + pages emitted) |
| `php artisan test` | **516 passed (2214 assertions)** |

**Not run / not claimed:** browser visual QA, screenshot/visual-regression comparison,
automated accessibility audit, Lighthouse.

## 6. Known limitations / manual visual QA still required
- **PrimaryButton changing dark-gray → blue** is the most visible change; verify the
  auth (Reset Password) and Profile (Save / Update Password) screens in light mode.
- `--shadow-glow` is unused, so it has no visual effect; change is consistency-only.
- Breeze **layouts** (`AuthenticatedLayout`, `GuestLayout`) still use `gray-100` and
  no dark mode — left untouched (out of the component scope); the auth/profile area
  therefore remains light-only and visually close to `slate`.
- `Pages/Dashboard/Reports/Insights.jsx` still uses `teal` as one of 8 intentional
  multi-metric gradient colors (not a leftover).
- The remaining page-level pass (Products, Customers, Reports, Settings) is still
  deferred (Build 1 §7).

## 7. Unresolved brand decisions needing explicit approval
1. **`public/manifest.json` `theme_color: #4f46e5` (indigo)** vs app `theme-color`
   `#2859d6` (blue) vs blue `primary`. The manifest is a brand asset; changing it
   changes the installed-PWA theme bar. **Left unchanged pending approval.**
2. **`public/images/icon.svg` indigo gradient (`#6366f1→#4338ca`)** coexists with the
   orange logo mark and blue app primary. **Left unchanged** — recoloring a brand icon
   is not justified by repository evidence alone.
3. Confirm whether the intended brand primary is definitively **blue** (this build
   assumes the user's working-tree migration is authoritative). If the indigo palette
   in `manifest.json`/`icon.svg` is the true brand, then items #5–#7, #14–#15 in the
   audit table should be revisited.

## 8. Files changed
```
resources/css/design-tokens.css
resources/js/Components/Checkbox.jsx
resources/js/Components/DangerButton.jsx
resources/js/Components/InputError.jsx
resources/js/Components/InputLabel.jsx
resources/js/Components/PrimaryButton.jsx
resources/js/Components/SecondaryButton.jsx
resources/js/Components/TextInput.jsx
resources/js/Components/Dashboard/Button.jsx
resources/js/Components/Dashboard/Checkbox.jsx
resources/js/Components/Dashboard/ListBox.jsx
resources/js/Pages/Dashboard/Index.jsx
```
(Note: `design-tokens.css` also carries the pre-existing user palette migration; this
build's edit to it is the single `--shadow-glow` line.)

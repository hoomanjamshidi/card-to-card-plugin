---
paths:
  - "admin-src/**"
  - "assets/admin/**"
  - "includes/Admin/App.php"
  - "includes/Rest/**"
---

# Settings panel (React) rules

- Source: `admin-src/` (React 18 + TypeScript + Tailwind 4 + motion, components adapted from Tarhak).
  Output: `assets/admin/app.js` + `app.css` (one IIFE bundle, `vite.config.ts`). WordPress loads the built
  files, so **run `npm run build:admin` after every change in `admin-src/`** and commit both.
- Never edit `assets/admin/app.js` / `app.css` by hand.
- Data comes from `window.ctcAdmin` (localized in `Admin/App.php`) and the REST API `ctc/v1`
  (`admin-src/lib/api.ts`, `wp_rest` nonce header). Settings are saved as one object through
  `POST ctc/v1/settings`. PHP `Settings::sanitize()` is the source of truth, so a value the panel sends that
  PHP does not know is dropped.
- Keep `admin-src/lib/types.ts` in sync with `Settings::defaults()` (same keys, same types).
- The panel is RTL and Persian. Every new label, hint and toast is in Persian, and numbers shown to
  people use Persian digits (`admin-src/lib/utils.ts`).
- REST routes: each one has a `permission_callback` with the right capability (`manage_woocommerce` for
  settings/SMS, `edit_shop_orders` for orders/receipts), and validates its args. Never `__return_true`.

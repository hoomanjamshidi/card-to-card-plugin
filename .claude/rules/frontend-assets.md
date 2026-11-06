---
paths:
  - "assets/css/**"
  - "assets/js/**"
---

# Front-end assets rules

- Edit `assets/css/payment.css` and `assets/js/payment.js` only, then run `npm run build:assets` to regenerate
  `payment.min.css` / `payment.min.js`. Commit the sources and the minified files together. The plugin loads
  `.min` unless `SCRIPT_DEBUG` is on, so a stale min file means the change is not live.
- `assets/js/payment.js` is plain ES2017+ with no build step and no dependencies (no jQuery). It is loaded
  with `defer` on the payment page and on the thank-you / view-order boxes. Strings come from the
  localized `ctcPay` object, never hard-coded.
- The form must still submit without JavaScript (plain multipart POST): JS only adds preview, size check,
  copy buttons, countdown, card tabs and tilt.
- Every rule is scoped under `.ctc` so theme styles do not leak in and ours do not leak out. Colours come
  from CSS variables (`--ctc-accent`, `--ctc-on-accent`, `--ctc-font`, card `--c1/--c2/--c3/--ink/--foil`)
  set by `Payment_Page::inline_css()` and `card_view()`. Respect `data-scheme="light|dark|auto"`.
- Mobile first: the page must not scroll horizontally at 360 px (checked in `tests/e2e/flow.mjs`). Card
  numbers are `dir="ltr"` inside an RTL page.
- `assets/js/blocks.js` registers the block-checkout payment method with `wc.wcBlocksRegistry` and reads its
  data from `Blocks\Payment_Method::get_payment_method_data()`.

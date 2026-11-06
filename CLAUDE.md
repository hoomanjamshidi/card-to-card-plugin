# کارت به کارت حرفه‌ای ووکامرس — Card to Card for WooCommerce

A WooCommerce payment gateway for Iranian card-to-card transfers. The customer is shown a bank card, pays by
bank app, then uploads a receipt. The shop approves or rejects it. Melipayamak SMS covers every step. The
settings panel is a React app. The plugin runs on any theme (classic, block/FSE, or a standalone page).

- PHP ≥ 8.0, WordPress ≥ 6.4, WooCommerce ≥ 8 (dev site runs WC 11.x). HPOS and block checkout are declared
  compatible, so **never read order data via post meta/`get_post_meta`; always use `WC_Order` getters / `get_meta`**.
- Namespace `CardToCard\`, PSR-4 from `includes/` (`CardToCard\Sms\Sender` → `includes/Sms/Sender.php`),
  no Composer autoload at runtime (see `card-to-card.php`).
- Every customer-facing string is Persian, text domain `card-to-card`. Code comments are English; Persian UI
  terms in comments are quoted with «».

## Commands

```bash
composer test                  # PHPUnit unit tests (pure helpers: Banks, Persian, Jalali)
composer lint                  # PHPCS — WordPress Coding Standards (phpcs.xml.dist)
composer analyse               # PHPStan level 6 (phpstan.neon.dist)
npm run build                  # admin panel (Vite → assets/admin/) + minified payment.min.css/js
npm run env                    # Docker dev site http://localhost:8890 (admin / password)
bash bin/dev.sh wp <args>      # WP-CLI inside the dev site
bash bin/dev.sh wp eval-file wp-content/plugins/card-to-card/dev/seed.php               # demo cards, SMS=log
bash bin/dev.sh wp eval-file wp-content/plugins/card-to-card/tests/integration/run.php  # core flow tests
bash bin/dev.sh wp eval-file wp-content/plugins/card-to-card/tests/integration/modules.php
bash tests/integration/http.sh # real HTTP: payment page, headers, upload, nonce, honeypot
node tests/e2e/flow.mjs        # Playwright browser flow (+ README screenshots); NOT `npm run test:e2e`
bash bin/zip.sh                # dist/card-to-card-<version>.zip (runs npm run build first)
```

The plugin folder is bind-mounted into the container (`dev/docker-compose.yml`), so PHP edits are live. Built
assets (`assets/admin/*`, `*.min.*`) are **committed** and are what WordPress loads: rebuild after editing
`admin-src/`, `assets/css/payment.css` or `assets/js/payment.js`.

## Architecture

`Plugin::boot()` (plugins_loaded:20) wires everything; `is_admin()`-only classes live in `includes/Admin/`.

| File | Role |
|---|---|
| `Gateway.php` | `WC_Payment_Gateway`. Only the on/off switch lives in WooCommerce settings; title, texts and cards come from `Settings`. `process_payment()` assigns cards, moves the order to `ctc-receipt` (or `on-hold` when «pro» is off), empties the cart and redirects to the payment page. |
| `Orders.php` | Custom statuses `wc-ctc-receipt` («در انتظار رسید») and `wc-ctc-review` («در انتظار بررسی رسید»). Holds the payment-link key (HMAC), deadline/reminder/expire jobs (Action Scheduler, group `card-to-card`), the strict-mode guard, `approve()` / `reject()`, and `page_state()` → `pay|review|paid|cancelled`. |
| `Payment_Page.php` | Private page `/<slug>/<id>-<16hex>/` (default slug `card-pay`), routed to a real WP page that holds the `[card_to_card_payment]` shortcode. Handles the receipt POST (`handle_post`) and `render_box()` (the compact box on thank-you / view-order). |
| `Receipts.php` | Validates and stores uploads in `uploads/ctc-receipts/` (random names, `.htaccess` deny), `attach()` → status `ctc-review`, streams files to `edit_shop_orders` users only, retention cleanup. |
| `Frontend.php` | WooCommerce e-mails (card + «ارسال رسید» button) and **My Account**: the «ارسال رسید» action in the Orders list and the box on view-order. |
| `Sms/Events.php`, `Sms/Sender.php`, `Sms/Melipayamak.php` | SMS events (`placed`, `received`, `approved`, `rejected`, `remind`, `cancelled`, `admin_new`, `admin_receipt`), tokens (`{link}`, `{key}`, …) and drivers (`off`, `log`, `melipayamak` in pattern or line mode). Jobs are async via Action Scheduler unless `CTC_SMS_SYNC` is defined. |
| `Rest/Controller.php` | `ctc/v1`: settings, receipts, orders/<id>/approve\|reject, stats, sms/test, sms/log. Cookie auth + `wp_rest` nonce, capability per route. |
| `Admin/App.php`, `Admin/Order_Box.php` | Panel page (WooCommerce › کارت به کارت) and the receipt meta box on the order screen (HPOS + legacy). |
| `Modules/*` | Optional features, each switched on in the panel (`ctc_settings['modules'][id]`): `Fee`, `Card_Limits`, `Conditions` (rules), `Fraud` (duplicate receipts, own DB table). They hook in only while on. |
| `Support/*` | Pure helpers (Banks, Persian, Jalali, Colors, Image_Hash). Banks/Persian/Jalali are unit-tested. |
| `Settings.php` | One autoloaded option `ctc_settings`; `defaults()` doubles as the schema and `sanitize()` coerces every key by the default's type. **Add a new setting = add its default (+ choices/range) here; the panel type lives in `admin-src/lib/types.ts`.** |
| `templates/` | Theme-overridable from `yourtheme/card-to-card/<file>`. Data comes in as `$args` (see `Payment_Page::args()` / `render_box()`); keep new keys backward compatible. |

### Order / receipt flow

checkout → `process_payment` → `ctc-receipt` (stock held, deadline set, `placed` SMS with `{link}`) →
customer opens `/card-pay/<key>/` (**no login: the key is the credential**) → POST receipt →
`Receipts::attach` → `ctc-review` → admin approves (`payment_complete`, status `auto|processing|completed`)
or rejects with a reason (→ back to `ctc-receipt` with a new deadline, `rejected` SMS with `{link}`).
No receipt before the deadline → `remind` SMS, then auto `cancelled`/`failed` + stock returned.

Where the customer can (re)open the upload form:
- the redirect right after checkout (`redirect` setting);
- the thank-you box and the My Account › view-order box (`thankyou_box`, `account_box`);
- the «ارسال رسید» action in My Account › Orders (`account_action`);
- the e-mail button, and SMS `{link}` / `{key}` (`placed`, `rejected`, `remind`).

All of these must resolve to `Orders::url()` / `Orders::receipt_url()`. Never build the URL by hand.

## Invariants (do not break)

- **Payment link** = `Orders::key()` = `<order id>-<first 16 hex of HMAC-SHA256(id|order_key, wp_salt('auth'))>`.
  It is stateless (nothing stored), stays valid for the life of the order, needs no login, and is checked
  with `hash_equals`. Only **wrong** keys are counted (`Util::limited/hit('key', 20, <order id>)`, per IP +
  order) and return 404. Never count valid views: many customers share one IP (mobile carrier NAT, a CDN in
  front of the shop), and counting them took every payment link down. Changing the format breaks every
  link already sent by SMS.
- The payment page sends `nocache_headers()`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex` and
  defines `DONOTCACHEPAGE`. It must never be cached (it contains a per-order nonce and personal data).
- Receipt POST: `ctc_nonce` = `Orders::form_token()` (an order-bound HMAC, so it never expires while the
  page is open; a WP nonce `ctc_receipt_<id>` is still accepted) → honeypot `ctc_hp` → status must be
  `ctc-receipt` → field validation → rate limit (10 real upload attempts/hour/IP/order; validation errors do
  not count) → `Receipts::store()` (size ≤ `Payment_Page::max_bytes()` = min(panel, server limit), real MIME
  check via `wp_check_filetype_and_ext`) → `ctc_receipt_check` filter → `attach()` → 303 redirect (PRG).
  A POST larger than `post_max_size` arrives empty and must show the size error, not a blank form.
- Receipt files are never public: no media library, no URL; streamed via `admin-post.php?action=ctc_file`
  with nonce + `edit_shop_orders`, `nosniff`, sandbox CSP.
- Strict mode (`Orders::guard_status`): an unapproved order in our statuses can never become
  processing/completed except through `Orders::approve()` (which sets `Orders::approving(true)`).
- Our statuses count as unpaid (`woocommerce_order_is_pending_statuses`) and are removed from
  `woocommerce_order_is_paid_statuses`.
- SMS events with `$once = true` are sent at most once per order (`_ctc_sms_sent` meta); `remind`,
  `rejected`, `received`, `admin_receipt` may repeat.

Order meta keys: `_ctc_cards`, `_ctc_deadline`, `_ctc_receipts` (list of receipts: path, name, type, size,
time, ref, last4, fields, state, reason, by, at, deleted, flags), `_ctc_reason`, `_ctc_approved`,
`_ctc_expired`, `_ctc_sms_sent`.

## Working rules

- Read `.claude/rules/` (path-scoped) before editing PHP, templates, assets, the panel or tests.
- After a behaviour change, add assertions to `tests/integration/run.php` (core) or `modules.php` (modules)
  and run them on the dev site. Unit tests only cover `Support/` helpers.
- Run `composer lint` and `composer analyse` on touched PHP before calling a change done.
- Other Claude sessions often work in this same checkout at the same time. Re-read a file right before
  editing it, and never revert changes you did not make.
- Project skills: `/dev-site`, `/run-tests`, `/qa-customer-flow`, `/release` (in `.claude/skills/`).

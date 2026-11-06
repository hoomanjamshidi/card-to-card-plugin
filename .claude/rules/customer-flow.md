---
paths:
  - "includes/Frontend.php"
  - "includes/Payment_Page.php"
  - "includes/Orders.php"
  - "includes/Receipts.php"
  - "includes/Gateway.php"
  - "includes/Sms/**"
  - "templates/**"
  - "assets/js/payment.js"
---

# Customer flow rules (checkout → payment page → My Account → SMS)

The customer must always be able to come back and send (or re-send after a rejection) the receipt, from any
of these entry points, without support:

| Entry point | Code | Setting |
|---|---|---|
| Redirect after checkout | `Gateway::process_payment()` → `Orders::url()` | `pro` + `redirect` |
| Thank-you page box | `Gateway::thankyou_page()` → `Payment_Page::render_box()` | `thankyou_box` |
| My Account › Orders «ارسال رسید» button | `Frontend::account_actions()` | `account_action` |
| My Account › View order box | `Frontend::view_order_box()` → `render_box()` | `account_box` |
| E-mail button | `Frontend::email_box()` | always while the receipt is awaited |
| SMS `{link}` / `{key}` | `Sms\Events::tokens()` | `placed`, `rejected`, `remind` events |

Rules:
- Every entry point links to `Orders::url( $order )` (or `Orders::receipt_url()`, which is the same URL and
  only non-empty while the order is `ctc-receipt` and «pro» is on). Do not invent other URLs.
- The link opens without login, on any device, any number of times, until the order is paid or cancelled.
  After that it still opens and shows the state (`review` / `paid` / `cancelled`). It never 404s for a valid
  key.
- The order data (name, phone, address, items) was collected at checkout. The payment page must not ask for
  it again: it shows the card, the amount, the deadline and the receipt form (file + optional
  ref / last4 / panel-defined extra fields).
- The My Account button / box should only show for card-to-card orders (`Orders::is_ours()`) of the
  logged-in owner (WooCommerce already scopes My Account to the owner).
- When the order is `ctc-receipt` the WooCommerce «پرداخت» (pay) action is replaced by «ارسال رسید». A
  card-to-card order must never be sent to the generic `order-pay` page, which would offer other gateways
  and could cause a double payment.
- A customer who comes back (from the SMS, the e-mail or My Account) has usually paid already. The hero
  «واریز کرده‌ام؛ ارسال رسید» button (`data-ctc-jump` → `#ctc-form`) must stay above the fold on mobile.
- Upload size shown and checked = `Payment_Page::max_bytes()` (min of the panel and the server). Never
  show the panel value alone: a 2 MB server limit with «۵ مگابایت» on the page fails every phone photo.
- `payment.js` shrinks JPEG/PNG/WebP over 1.5 MB (or over the limit) to a JPEG of at most 2400 px, and turns
  HEIC into JPEG when the browser can read it, before upload. On any failure it keeps the original file;
  the server check is unchanged. The form submits once (a second tap is ignored) and shows «صفحه را
  نبندید» while sending.
- The amount block comes from `Payment_Page::amount()` (page and box). In toman shops (`IRT`, `IRHT`) it
  carries the rial figure with its own copy button, because many banking apps ask for rial.
- After a rejection the order is back in `ctc-receipt`. The same link shows the rejection reason and a fresh
  form, and the `rejected` SMS carries `{link}`.
- SMS `{link}` is the full URL. Melipayamak patterns reject URLs in variables (error -10), so `{key}` exists:
  the pattern text holds `https://site/<slug>/` and only the key is a variable. If the slug changes, links
  already sent break, so never change the default slug or the key format in code.
- The payment page and the thank-you / view-order boxes load `assets/css/payment(.min).css` and
  `assets/js/payment(.min).js` through `Payment_Page::assets()`. A new place that renders the box must
  call `render_box()` (which enqueues them).

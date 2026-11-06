---
name: qa-customer-flow
description: QA checklist for the customer side of the card-to-card gateway, run as a WordPress plugin tester on the dev site — checkout, private payment page, receipt upload / re-upload after rejection, WooCommerce My Account («ارسال رسید» button and view-order box), e-mail button and the SMS link (no login). Use when asked to test or check the customer experience, My Account, or the SMS / payment link.
---

# Customer flow QA

Start the site and seed it first (`/dev-site`). Turn on the SMS log driver so `{link}` can be read:
`bash bin/dev.sh wp eval '\CardToCard\Settings::save( array( "sms_driver" => "log" ) );'`.

Use the built-in browser for UI checks and curl / WP-CLI for state. For each item record pass/fail and
evidence (status code, status, screenshot), and fix what fails (see `.claude/rules/customer-flow.md`).

## 1. Checkout
- [ ] Classic checkout (Storefront) and block checkout (Twenty Twenty-Five) both list «کارت به کارت» with the
      panel title / description. It is hidden when no active card exists or the total is outside min/max.
- [ ] Placing the order → status `ctc-receipt`, the cart is empty, and the browser lands on `/card-pay/<id>-<key>/`
      (or the thank-you page with the box when `redirect` is off).

## 2. Payment page (guest, logged out, a fresh private window)
- [ ] Opens without login. Response headers: `Cache-Control: no-cache…`, `X-Robots-Tag: noindex`,
      `Referrer-Policy: no-referrer`.
- [ ] It does not ask again for anything collected at checkout (name, phone, address). Only the card(s),
      amount (digits + words), deadline countdown, steps and the receipt form appear.
- [ ] The «واریز کرده‌ام؛ ارسال رسید» button is in the first screen on a phone and jumps to the form.
- [ ] The size hint equals the real limit (`wp_max_upload_size()` when it is lower than the panel's). A
      file over it, and a POST over `post_max_size`, both show «حجم فایل حداکثر … مگابایت».
- [ ] Copy buttons copy bare digits. The image preview shows. A file over the size limit is refused in the
      browser and on the server. A fake image (text renamed `.png`) is refused.
- [ ] A big phone photo (over 1.5 MB, or over the limit) is shrunk to a JPEG before upload: the picker shows
      the new size, and the server stores `image/jpeg`. A long file name stays on one line.
- [ ] Toman shop: the amount shows «به ریال: …» with its own copy button. Rial shop: no second figure.
- [ ] Time left reads «۲ ساعت و ۱۰ دقیقه مانده» and turns red in the last hour. Tapping «ارسال رسید» twice
      posts once.
- [ ] Submitting → 303 → same URL shows «رسید در حال بررسی»; status `ctc-review`. Reload does not resubmit.
- [ ] A wrong key (`…-0000000000000000/`) is a 404. Many reloads of a **valid** link by one IP (and
      different orders from one shared IP, as with mobile carrier NAT) never turn into a 404.
- [ ] Works on a 360 px wide viewport with no horizontal scroll.

## 3. Rejection and re-send
- [ ] Reject from the order screen with a reason → status back to `ctc-receipt` with a new deadline.
- [ ] The **same** link shows the red alert with the reason and a fresh form. Re-upload works.
- [ ] The `rejected` SMS (log) contains the reason and the full link.

## 4. My Account (logged in as the order's customer)
- [ ] My Account › Orders: a `ctc-receipt` order shows «ارسال رسید» linking to the payment page, and no
      generic «پرداخت» (pay) button that would open `order-pay` with other gateways.
- [ ] The customer **closes the tab** right after checkout, logs in later, and can reach the upload form in
      ≤ 2 clicks (Orders → «ارسال رسید», or View → box button).
- [ ] View order: the box shows the card(s), amount, history and the «ارسال رسید واریز» button while
      awaiting; while in review / paid / cancelled it shows the state and «وضعیت پرداخت».
- [ ] «لغو» (cancel) shows for `ctc-receipt` only when `customer_cancel` is on, and cancelling returns
      stock.
- [ ] Another customer cannot see this order in My Account (WooCommerce ownership), and the payment page
      shows «جزئیات سفارش» only to the owner.
- [ ] With `pro` off (status `on-hold`), the account still makes sense: there is no broken link, and the
      box shows the card.

## 5. E-mail
- [ ] The customer «در انتظار» e-mail (HTML and plain text) has the card(s) and the «ارسال رسید واریز» button
      with the same payment URL. The admin «سفارش جدید» e-mail is sent once.

## 6. SMS link
- [ ] `placed` SMS: `{link}` is the full `https://…/card-pay/<id>-<key>/` URL, and `{key}` is only
      `<id>-<key>`. Opening the link from a phone, logged out, reaches the form directly.
- [ ] `remind` SMS before the deadline carries the link. After the deadline the order is cancelled, the link
      still opens and shows «سفارش لغو شد».
- [ ] Changing the slug in the panel breaks links already sent. The panel help next to «مسیر صفحه پرداخت»
      should say so.

## Report
A table of the checklist with pass/fail, what was fixed (file:line), and what was not checked.

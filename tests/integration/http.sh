#!/usr/bin/env bash
# HTTP tests against the running dev site (bin/dev.sh up): the payment page, receipt upload, fake
# files, honeypot, rate of wrong keys and the privacy of stored receipts.
set -uo pipefail
cd "$(dirname "$0")/../.."
BASE="http://localhost:${CTC_PORT:-8890}"
TMP="$(mktemp -d)"
PASS=0; FAIL=0
ok() { if [ "$1" = "0" ]; then PASS=$((PASS+1)); else FAIL=$((FAIL+1)); echo "  ✗ $2"; fi; }
wp() { bash bin/dev.sh wp "$@" 2>/dev/null | grep -v -e Warning -e Container; }

read -r ID URL < <(wp eval-file wp-content/plugins/card-to-card/tests/integration/make-order.php | tail -1)
URL="${URL/localhost:8890/localhost:${CTC_PORT:-8890}}"

# Page.
code=$(curl -s -o "$TMP/page.html" -D "$TMP/h.txt" -w '%{http_code}' "$URL"); ok $([ "$code" = 200 ]; echo $?) "payment page 200 ($code)"
grep -qi 'x-robots-tag: noindex' "$TMP/h.txt"; ok $? "noindex header"
grep -qi 'referrer-policy: no-referrer' "$TMP/h.txt"; ok $? "no-referrer header"
grep -qi 'cache-control: no-cache' "$TMP/h.txt"; ok $? "no-cache header"
grep -q 'class="ctc-plate is-bank bank-mellat' "$TMP/page.html"; ok $? "Mellat card plate with bank colours"
grep -q '۱٬۴۶۳٬۰۰۰' "$TMP/page.html"; ok $? "amount in Toman with Persian digits"
grep -q 'یک میلیون و چهارصد و شصت و سه هزار تومان' "$TMP/page.html"; ok $? "amount in words"
grep -q 'data-ctc-tilt' "$TMP/page.html"; ok $? "tilt enabled"
grep -q 'payment.min.css' "$TMP/page.html"; ok $? "minified CSS enqueued"

# Wrong key → 404.
code=$(curl -s -o /dev/null -w '%{http_code}' "${URL%-*}-0000000000000000/"); ok $([ "$code" = 404 ]; echo $?) "wrong key 404 ($code)"

NONCE=$(grep -o 'name="ctc_nonce" value="[^"]*"' "$TMP/page.html" | sed 's/.*value="//;s/"//')
[ -n "$NONCE" ]; ok $? "nonce on the form"

# Fake image (text with .png) → refused, stays awaiting.
printf 'not an image' > "$TMP/fake.png"
curl -s -o "$TMP/r1.html" -F "ctc_nonce=$NONCE" -F "ctc_file=@$TMP/fake.png;type=image/png" "$URL"
grep -q 'class="ctc-err' "$TMP/r1.html"; ok $? "fake image rejected with an error"
st=$(wp wc shop_order get "$ID" --field=status --user=admin); ok $([ "$st" = ctc-receipt ]; echo $?) "still ctc-receipt after fake file ($st)"

# Honeypot.
printf '\x89PNG\r\n\x1a\n' > /dev/null
base64 -d > "$TMP/r.png" <<< 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
curl -s -o "$TMP/r2.html" -F "ctc_nonce=$NONCE" -F "ctc_hp=bot" -F "ctc_file=@$TMP/r.png;type=image/png" "$URL"
grep -q 'ارسال انجام نشد' "$TMP/r2.html"; ok $? "honeypot blocks bots"

# Bad nonce.
curl -s -o "$TMP/r3.html" -F "ctc_nonce=bad" -F "ctc_file=@$TMP/r.png;type=image/png" "$URL"
grep -q 'صفحه را دوباره باز کنید' "$TMP/r3.html"; ok $? "bad nonce refused"

# Last four with 3 digits.
curl -s -o "$TMP/r4.html" -F "ctc_nonce=$NONCE" -F "ctc_last4=۱۲۳" -F "ctc_file=@$TMP/r.png;type=image/png" "$URL"
grep -q 'چهار رقم آخر' "$TMP/r4.html" && grep -q 'aria-invalid="true"' "$TMP/r4.html"; ok $? "incomplete last four refused"

# Required custom field.
wp eval '\CardToCard\Settings::save(array("fields"=>array(array("id"=>"bank_from","label"=>"بانک مبدأ","type"=>"text","required"=>true))));' >/dev/null
curl -s -o "$TMP/page3.html" "$URL"
grep -q 'name="ctc_f\[bank_from\]"' "$TMP/page3.html"; ok $? "custom field rendered on the page"
curl -s -o "$TMP/r5.html" -F "ctc_nonce=$NONCE" -F "ctc_file=@$TMP/r.png;type=image/png" "$URL"
grep -q '«بانک مبدأ» را وارد کنید' "$TMP/r5.html"; ok $? "required custom field enforced"

# Real upload → 303 → review.
code=$(curl -s -o /dev/null -w '%{http_code}' -F "ctc_nonce=$NONCE" -F "ctc_ref=۱۲۳۴۵۶" -F "ctc_last4=۴۳۲۱" -F "ctc_f[bank_from]=ملت" -F "ctc_file=@$TMP/r.png;type=image/png" "$URL"); ok $([ "$code" = 303 ]; echo $?) "upload redirects 303 ($code)"
st=$(wp wc shop_order get "$ID" --field=status --user=admin); ok $([ "$st" = ctc-review ]; echo $?) "status ctc-review after upload ($st)"
REC=$(wp eval "\$o=wc_get_order($ID);\$r=\CardToCard\Orders::receipts(\$o);echo \$r[0]['path'].'|'.\$r[0]['ref'].'|'.\$r[0]['last4'];")
[ "${REC#*|}" = "123456|4321" ]; ok $? "reference and last four stored in Latin digits ($REC)"
RPATH="${REC%%|*}"
F=$(wp eval "\$o=wc_get_order($ID);\$r=\CardToCard\Orders::receipts(\$o);echo \$r[0]['fields']['بانک مبدأ'] ?? '';")
[ "$F" = "ملت" ]; ok $? "custom field value stored with its label ($F)"
wp eval '\CardToCard\Settings::save(array("fields"=>array()));' >/dev/null
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/wp-content/uploads/ctc-receipts/$RPATH"); ok $([ "$code" = 403 ]; echo $?) "direct receipt URL blocked ($code)"
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/wp-content/uploads/ctc-receipts/"); ok $([ "$code" = 403 ] || [ "$code" = 200 ]; echo $?) "receipts folder not listable ($code)"

# Page now shows review and no form.
curl -s -o "$TMP/page2.html" "$URL"
grep -q 'رسید در حال بررسی' "$TMP/page2.html" && ! grep -q 'name="ctc_file"' "$TMP/page2.html"; ok $? "review state without form"

# Admin stream needs login + nonce.
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/wp-admin/admin-post.php?action=ctc_file&order=$ID&i=0"); ok $([ "$code" != 200 ]; echo $?) "anonymous cannot stream a receipt ($code)"

echo "HTTP: $PASS passed, $FAIL failed."
rm -rf "$TMP"
[ "$FAIL" = 0 ]

/**
 * End-to-end browser test of the whole flow on the dev site (bin/dev.sh up), with screenshots for
 * the README (docs/screenshots/):
 *   1. a customer buys through WooCommerce's block checkout and picks «کارت به کارت»;
 *   2. lands on the private payment page (bank-coloured cards), uploads a receipt;
 *   3. the shop manager approves it in the panel; the page shows «پرداخت تأیید شد».
 * Plus every panel tab (light + dark) and the bank card gallery.
 *
 * Run: node tests/e2e/flow.mjs   (env CTC_URL, default http://localhost:8890)
 */
import { chromium } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import assert from 'node:assert/strict';

const BASE = process.env.CTC_URL || 'http://localhost:8890';
const OUT = 'docs/screenshots';
mkdirSync( OUT, { recursive: true } );
const wp = ( ...args ) => execFileSync( 'bash', [ 'bin/dev.sh', 'wp', ...args ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } ).trim().split( '\n' ).pop();

/** Only the local site is reachable: external fonts / trackers of themes never stall a test. */
const local = async ( ctx ) => {
	await ctx.route( '**/*', ( route ) => ( route.request().url().startsWith( BASE ) || route.request().url().startsWith( 'data:' ) ? route.continue() : route.abort() ) );
	return ctx;
};

let passed = 0;
const step = async ( name, fn ) => {
	try {
		await fn();
		passed++;
		console.log( `  ✓ ${ name }` );
	} catch ( e ) {
		console.error( `  ✗ ${ name }\n    ${ e.message.split( '\n' )[ 0 ] }` );
		process.exitCode = 1;
	}
};

// CTC_CHROME: path to a Chromium/Chrome binary when Playwright's own download is not installed.
const browser = await chromium.launch( process.env.CTC_CHROME ? { executablePath: process.env.CTC_CHROME } : {} );

// A realistic bank receipt image to upload.
const receiptPage = await browser.newPage( { viewport: { width: 420, height: 640 }, deviceScaleFactor: 2 } );
await receiptPage.setContent( `<!doctype html><html dir="rtl"><body style="margin:0;font-family:Tahoma;background:#eef2f7">
	<div style="margin:24px;padding:24px;border-radius:18px;background:#fff;box-shadow:0 10px 30px rgba(0,0,0,.08)">
	<div style="text-align:center;color:#0b8a3e;font-size:46px">✔</div>
	<h2 style="text-align:center;margin:4px 0 18px;color:#0b8a3e;font-size:20px">انتقال وجه با موفقیت انجام شد</h2>
	${ [ [ 'مبلغ', '۴۸۰٬۰۰۰ تومان' ], [ 'کارت مبدأ', '۵۸۵۹-****-****-۴۳۲۱' ], [ 'کارت مقصد', '۶۱۰۴-۳۳۷۹-۱۲۳۴-۵۶۷۳' ], [ 'به نام', 'فروشگاه نمونه' ], [ 'شماره پیگیری', '۸۴۷۲۶۱' ], [ 'تاریخ', '۱۴۰۵/۰۸/۱۴ - ۱۵:۲۴' ] ].map( ( [ k, v ] ) => `<div style="display:flex;justify-content:space-between;padding:12px 0;border-bottom:1px dashed #dde3ea;font-size:15px"><span style="color:#64748b">${ k }</span><b>${ v }</b></div>` ).join( '' ) }
	</div></body></html>` );
await receiptPage.screenshot( { path: '/tmp/ctc-receipt.png' } );
await receiptPage.close();

// Product + settings for the run.
const pid = wp( 'wc', 'product', 'create', '--name=ماگ سرامیکی طاووس', '--type=simple', '--regular_price=480000', '--virtual=true', '--user=admin', '--porcelain' );
console.log( `product ${ pid }` );

// ---------------------------------------------------------------------
// 1. Customer: block checkout.
// ---------------------------------------------------------------------
const customer = await local( await browser.newContext( { viewport: { width: 1440, height: 900 }, locale: 'fa-IR' } ) );
const page = await customer.newPage();
let payUrl = '';

await step( 'block checkout lists «کارت به کارت» and places the order', async () => {
	await page.goto( `${ BASE }/?add-to-cart=${ pid }` );
	await page.goto( `${ BASE }/checkout/` );
	await page.waitForSelector( '.wc-block-checkout', { timeout: 30000 } );
	await page.fill( '#email', 'sara@example.test' );
	const fill = async ( id, v ) => {
		const el = page.locator( `#billing-${ id }, #shipping-${ id }` ).first();
		if ( await el.count() ) await el.fill( v );
	};
	await fill( 'first_name', 'سارا' );
	await fill( 'last_name', 'محمدی' );
	await fill( 'address_1', 'خیابان ولیعصر، پلاک ۱۲' );
	await fill( 'city', 'تهران' );
	await fill( 'postcode', '1234567890' );
	await fill( 'phone', '09123456789' );
	const state = page.locator( '#billing-state, #shipping-state' ).first();
	if ( await state.count() ) await state.selectOption( { index: 1 } ).catch( () => {} );
	const radio = page.locator( 'input[name="radio-control-wc-payment-method-options"][value="card_to_card"]' );
	await radio.check( { force: true } );
	await page.waitForTimeout( 600 );
	await page.screenshot( { path: `${ OUT }/checkout-blocks.png`, fullPage: false } );
	const button = page.locator( '.wc-block-components-checkout-place-order-button' );
	assert.match( await button.innerText(), /ثبت سفارش/ );
	await button.click();
	await page.waitForURL( /\/card-pay\/\d+-[a-f0-9]{16}\/?$/, { timeout: 30000 } );
	payUrl = page.url();
} );

await step( 'payment page shows the bank-coloured card, amount in Toman and the upload form', async () => {
	await page.waitForSelector( '.ctc-plate.bank-mellat' );
	assert.match( await page.locator( '.ctc-fact.is-amount' ).first().innerText(), /۴۸۰٬۰۰۰[\s\S]*تومان/ );
	assert.equal( await page.locator( '.ctc-switch [role=tab]' ).count(), 3 );
	await page.waitForTimeout( 2200 ); // Let the entrance animation finish.
	await page.screenshot( { path: `${ OUT }/payment-desktop.png`, fullPage: true } );
} );

await step( 'card switcher and copy button work', async () => {
	await page.locator( '.ctc-switch [role=tab]' ).nth( 1 ).click();
	assert.equal( await page.locator( '.ctc-cardrow:not([hidden]) .ctc-plate' ).first().getAttribute( 'class' ).then( ( c ) => c.includes( 'bank-melli' ) ), true );
	await customer.grantPermissions( [ 'clipboard-read', 'clipboard-write' ] );
	await page.locator( '.ctc-cardrow:not([hidden]) .ctc-plate .ctc-copy' ).click();
	await page.waitForSelector( '.ctc-cardrow:not([hidden]) .ctc-plate .ctc-copy.is-done' );
	const copied = await page.evaluate( () => navigator.clipboard.readText() );
	assert.match( copied, /^603799\d{10}$/ );
	await page.locator( '.ctc-switch [role=tab]' ).nth( 0 ).click();
} );

await step( 'mobile payment page has no horizontal scroll and the card number fits', async () => {
	const m = await local( await browser.newContext( { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true } ) );
	const mp = await m.newPage();
	await mp.goto( payUrl, { waitUntil: 'domcontentloaded' } );
	await mp.waitForTimeout( 2200 );
	const [ sw, w ] = await mp.evaluate( () => [ document.documentElement.scrollWidth, window.innerWidth ] );
	assert.ok( sw <= w, `scrollWidth ${ sw } > ${ w }` );
	const fits = await mp.locator( '.ctc-plate-no' ).first().evaluate( ( n ) => n.scrollWidth <= n.clientWidth + 1 && n.getBoundingClientRect().right <= n.closest( '.ctc-plate' ).getBoundingClientRect().right );
	assert.ok( fits, 'card number overflows the card' );
	// The theme's fixed bottom bar would land mid-page in a full-page shot.
	await mp.addStyleTag( { content: '.storefront-handheld-footer-bar{display:none!important}' } );
	await mp.screenshot( { path: `${ OUT }/payment-mobile.png`, fullPage: true } );
	await m.close();
} );

await step( 'uploading a receipt moves the order to review', async () => {
	await page.setInputFiles( 'input[name=ctc_file]', '/tmp/ctc-receipt.png' );
	await page.waitForSelector( '[data-ctc-preview]:not([hidden])' );
	await page.fill( 'input[name=ctc_ref]', '۸۴۷۲۶۱' );
	await page.fill( 'input[name=ctc_last4]', '۴۳۲۱' );
	await page.locator( '.ctc-form' ).screenshot( { path: `${ OUT }/receipt-form.png` } );
	await Promise.all( [ page.waitForNavigation(), page.click( '.ctc-submit' ) ] );
	await page.waitForSelector( '.ctc-page.is-review' );
	await page.screenshot( { path: `${ OUT }/payment-review.png`, fullPage: true } );
} );

// ---------------------------------------------------------------------
// 2. Shop manager: panel.
// ---------------------------------------------------------------------
const admin = await local( await browser.newContext( { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 } ) );
const ap = await admin.newPage();
await ap.goto( `${ BASE }/wp-login.php` );
await ap.fill( '#user_login', 'admin' );
await ap.fill( '#user_pass', 'password' );
await Promise.all( [ ap.waitForNavigation(), ap.click( '#wp-submit' ) ] );

const tab = async ( id, file, dark = false ) => {
	await ap.goto( `${ BASE }/wp-admin/admin.php?page=card-to-card#${ id }` );
	await ap.evaluate( ( d ) => localStorage.setItem( 'ctc-dark', d ? '1' : '0' ), dark );
	await ap.reload();
	await ap.waitForSelector( '#ctc-app header' );
	await ap.waitForTimeout( 1600 );
	await ap.screenshot( { path: `${ OUT }/${ file }.png`, fullPage: true } );
};

await step( 'panel dashboard', () => tab( 'dashboard', 'panel-dashboard' ) );
await step( 'panel cards (light + dark)', async () => {
	await tab( 'cards', 'panel-cards' );
	await tab( 'cards', 'panel-cards-dark', true );
} );
await step( 'panel payment, texts, appearance, advanced', async () => {
	await tab( 'payment', 'panel-payment' );
	await tab( 'texts', 'panel-texts' );
	await tab( 'look', 'panel-appearance' );
	await tab( 'advanced', 'panel-advanced' );
	await tab( 'modules', 'panel-modules' );
} );
await step( 'panel SMS: Melipayamak pattern and line modes', async () => {
	await tab( 'sms', 'panel-sms' );
	await ap.getByRole( 'radio', { name: 'ملی‌پیامک' } ).click();
	await ap.waitForTimeout( 400 );
	await ap.locator( 'button', { hasText: 'ثبت سفارش + لینک پرداخت' } ).first().click();
	await ap.waitForTimeout( 600 );
	await ap.screenshot( { path: `${ OUT }/panel-sms-pattern.png`, fullPage: true } );
	await ap.getByRole( 'radio', { name: 'خط اختصاصی / اشتراکی' } ).click();
	await ap.waitForTimeout( 600 );
	await ap.screenshot( { path: `${ OUT }/panel-sms-line.png`, fullPage: true } );
} );

await step( 'approving the receipt in the panel completes the payment', async () => {
	await tab( 'receipts', 'panel-receipts' );
	const card = ap.locator( 'article', { hasText: 'تأیید واریز' } ).first();
	await card.locator( 'button', { hasText: 'تأیید واریز' } ).click();
	await ap.waitForTimeout( 700 );
	await card.screenshot( { path: `${ OUT }/panel-approve-stamp.png` } );
	await page.goto( payUrl );
	await page.waitForSelector( '.ctc-page.is-paid' );
	await page.screenshot( { path: `${ OUT }/payment-paid.png`, fullPage: false } );
} );

await step( 'order screen box shows the receipt', async () => {
	const id = payUrl.match( /card-pay\/(\d+)-/ )[ 1 ];
	await ap.goto( `${ BASE }/wp-admin/admin.php?page=wc-orders&action=edit&id=${ id }` );
	await ap.waitForSelector( '#ctc-receipt' );
	await ap.locator( '#ctc-receipt' ).screenshot( { path: `${ OUT }/order-box.png` } );
} );

await step( 'bank card gallery', async () => {
	const g = await browser.newPage( { viewport: { width: 1520, height: 900 }, deviceScaleFactor: 2 } );
	await g.goto( `${ BASE }/wp-content/plugins/card-to-card/dev/gallery.html` );
	await g.waitForTimeout( 800 );
	await g.locator( '#gallery' ).screenshot( { path: `${ OUT }/banks-gallery.png` } );
	await g.close();
} );

await browser.close();
console.log( `E2E: ${ passed } passed${ process.exitCode ? ', some failed' : '' }.` );

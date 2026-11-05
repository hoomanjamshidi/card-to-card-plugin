/**
 * Advanced: data on uninstall, a summary of the security model and compatibility.
 */
import { HugeiconsIcon, type IconSvgElement } from '@hugeicons/react';
import { SecurityCheckIcon, Database01Icon, CheckmarkCircle02Icon, Alert02Icon, LinkSquare02Icon } from '@hugeicons/core-free-icons';
import { useStore } from '../lib/store';
import { boot, cn } from '../lib/utils';
import { Section, SwitchRow } from '../ui/Field';

function Item( { ok, title, desc, icon }: { ok: boolean; title: string; desc: string; icon?: IconSvgElement } ) {
	return (
		<div className="flex items-start gap-3 rounded-2xl px-3 py-3">
			<HugeiconsIcon icon={ icon ?? ( ok ? CheckmarkCircle02Icon : Alert02Icon ) } size={ 20 } className={ cn( 'mt-0.5 shrink-0', ok ? 'text-[#34C759]' : 'text-[#f59e0b]' ) } />
			<div>
				<p className="text-[13.5px] font-bold">{ title }</p>
				<p className="text-[12.5px] leading-6 text-muted-foreground">{ desc }</p>
			</div>
		</div>
	);
}

export function Advanced() {
	const { s, set } = useStore();
	return (
		<>
			<Section title="امنیت" icon={ SecurityCheckIcon } desc="آنچه افزونه برای محافظت از سفارش‌ها و رسیدها انجام می‌دهد.">
				<Item ok title="لینک پرداخت غیرقابل حدس" desc="هر لینک با HMAC کلید سفارش امضا می‌شود؛ با دانستن شماره سفارش نمی‌توان صفحه پرداخت دیگران را دید. تلاش‌های زیاد محدود می‌شوند." />
				<Item ok title="رسیدهای خصوصی" desc="فایل‌ها خارج از کتابخانه رسانه، با نام تصادفی و دسترسی مستقیم مسدود ذخیره و فقط برای مدیر فروشگاه استریم می‌شوند." />
				<Item ok title="بررسی فایل و فرم" desc="نوع واقعی فایل، حجم، nonce، فیلد تله ضدربات و محدودیت ۵ ارسال در ساعت برای هر سفارش." />
				<Item ok title="بدون کش" desc="صفحه پرداخت no-cache و noindex است و برای WP Rocket، LiteSpeed و W3TC مستثنا می‌شود." />
				<Item ok={ boot().pretty } title="پیوندهای یکتا" desc={ boot().pretty ? 'فعال است؛ آدرس صفحه پرداخت تمیز است.' : 'غیرفعال است؛ آدرس صفحه پرداخت با ?ctc_pay= ساخته می‌شود. از تنظیمات › پیوندهای یکتا فعال کنید.' } />
			</Section>
			<Section title="داده‌ها" icon={ Database01Icon }>
				<SwitchRow label="حذف تنظیمات و رسیدها هنگام پاک کردن افزونه" help="خاموش (پیشنهادی): با حذف افزونه، تنظیمات و فایل رسیدها برای نصب دوباره باقی می‌مانند. اطلاعات سفارش‌ها همیشه می‌مانند." checked={ s.cleanup } onChange={ ( v ) => set( 'cleanup', v ) } />
			</Section>
			<Section title="سازگاری">
				<Item ok title="ذخیره‌سازی سفارش با عملکرد بالا (HPOS)" desc="کاملاً سازگار؛ هم HPOS و هم جدول‌های قدیمی." />
				<Item ok title={ boot().blocks ? 'چک‌اوت بلوکی فعال است' : 'چک‌اوت کلاسیک' } desc="درگاه در چک‌اوت کلاسیک و بلوکی ووکامرس ثبت می‌شود." />
				<Item ok title="هر قالبی" desc="قالب‌های کلاسیک، قالب‌های بلوکی (FSE) و حالت صفحه مستقل. قالب‌ها می‌توانند فایل‌های templates/ افزونه را در پوشه card-to-card/ خود بازنویسی کنند." />
				<a href={ boot().wcUrl } className="mx-3 mt-1 inline-flex items-center gap-1 self-start text-[13px] text-primary hover:underline">
					تنظیمات درگاه در ووکامرس <HugeiconsIcon icon={ LinkSquare02Icon } size={ 14 } />
				</a>
			</Section>
		</>
	);
}

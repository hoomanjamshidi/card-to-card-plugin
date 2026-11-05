/**
 * Payment flow: pro flow, redirect, strict status guard, deadline / reminder, receipt rules, limits.
 */
import { Clock01Icon, Invoice03Icon, Shield01Icon, Link01Icon, TextFontIcon } from '@hugeicons/core-free-icons';
import { FieldsBuilder } from '../components/FieldsBuilder';
import { useStore } from '../lib/store';
import { boot, fa } from '../lib/utils';
import { NumberInput, Row, Section, SwitchRow, TextInput } from '../ui/Field';

export function Payment() {
	const { s, set } = useStore();
	return (
		<>
			<Section title="جریان پرداخت" icon={ Shield01Icon } desc="کارت به کارت حرفه‌ای: صفحه پرداخت اختصاصی، آپلود رسید، وضعیت‌های «در انتظار رسید» و «در انتظار بررسی رسید»، مهلت و لغو خودکار.">
				<SwitchRow label="کارت به کارت حرفه‌ای" help="خاموش = رفتار ساده: سفارش «در انتظار بررسی» می‌شود و کارت در صفحه تشکر نمایش داده می‌شود." checked={ s.pro } onChange={ ( v ) => set( 'pro', v ) } />
				{ s.pro ? (
					<>
						<SwitchRow label="بعد از ثبت سفارش مستقیم به صفحه پرداخت برود" help="خاموش = صفحه تشکر ووکامرس با کارت و دکمه «ارسال رسید»." checked={ s.redirect } onChange={ ( v ) => set( 'redirect', v ) } />
						<SwitchRow label="حالت سخت‌گیرانه" help="تا رسید تأیید نشده، سفارش حتی با تغییر دستی وضعیت، عملیات گروهی یا افزونه‌های دیگر «در حال انجام» یا «تکمیل» نمی‌شود." checked={ s.strict } onChange={ ( v ) => set( 'strict', v ) } />
					</>
				) : null }
			</Section>

			{ s.pro ? (
				<>
					<Section title="مهلت و لغو خودکار" icon={ Clock01Icon } desc="بعد از پایان مهلت، سفارش خودکار لغو و موجودی انبار برگردانده می‌شود. رد شدن رسید، مهلت را از نو شروع می‌کند.">
						<Row label="مهلت ارسال رسید" help="از زمان ثبت سفارش. ۰ = بدون مهلت و بدون لغو خودکار.">
							<NumberInput value={ s.deadline } onChange={ ( v ) => set( 'deadline', v ) } max={ 720 } unit="ساعت" />
						</Row>
						<Row label="یادآوری پیش از پایان مهلت" help={ s.remind >= s.deadline && s.deadline > 0 ? 'باید کمتر از مهلت باشد تا ارسال شود.' : '۰ = بدون یادآوری. با پیامک «یادآوری ارسال رسید».' }>
							<NumberInput value={ s.remind } onChange={ ( v ) => set( 'remind', v ) } max={ 240 } unit="ساعت" />
						</Row>
					</Section>

					<Section title="رسید" icon={ Invoice03Icon } desc="رسیدها خارج از کتابخانه رسانه، با نام تصادفی و دسترسی مستقیم مسدود ذخیره می‌شوند و فقط مدیر فروشگاه آن‌ها را می‌بیند.">
						<Row label="حداکثر حجم فایل">
							<NumberInput value={ s.max_mb } onChange={ ( v ) => set( 'max_mb', v ) } min={ 1 } max={ 20 } unit="مگابایت" />
						</Row>
						<SwitchRow label="پذیرش PDF" help="عکس (JPG، PNG، WEBP، HEIC) همیشه پذیرفته می‌شود." checked={ s.pdf } onChange={ ( v ) => set( 'pdf', v ) } />
					</Section>
					<Section title="فرم ارسال رسید" icon={ TextFontIcon } desc="عنوان و اجباری بودن فیلدها را عوض کنید یا فیلد دلخواه اضافه کنید. مقادیر کنار رسید در پنل و صفحه سفارش نمایش داده می‌شوند.">
						<FieldsBuilder />
					</Section>
				</>
			) : null }

			<Section title="محدودیت مبلغ و آدرس" icon={ Link01Icon }>
				<Row label="حداقل مبلغ سفارش" help={ `برای مبلغ کمتر، کارت به کارت نمایش داده نمی‌شود. ۰ = بدون محدودیت (${ boot().unit }).` }>
					<NumberInput value={ s.min_total } onChange={ ( v ) => set( 'min_total', v ) } max={ 999999999999 } step={ 100000 } />
				</Row>
				<Row label="حداکثر مبلغ سفارش" help={ `مثلاً سقف کارت به کارت روزانه بانک. ۰ = بدون محدودیت (${ boot().unit }).` }>
					<NumberInput value={ s.max_total } onChange={ ( v ) => set( 'max_total', v ) } max={ 999999999999 } step={ 1000000 } />
				</Row>
				<Row label="مسیر صفحه پرداخت" help={ <span dir="ltr" className="inline-block">{ `${ boot().home }${ s.slug || 'card-pay' }/${ fa( 1024 ) }-…` }</span> }>
					<TextInput value={ s.slug } onChange={ ( v ) => set( 'slug', v.toLowerCase().replace( /[^a-z0-9-]/g, '' ) ) } dir="ltr" placeholder="card-pay" aria-label="مسیر صفحه پرداخت" />
				</Row>
			</Section>
		</>
	);
}

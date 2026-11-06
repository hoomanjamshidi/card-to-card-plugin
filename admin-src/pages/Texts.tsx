/**
 * Texts: checkout (gateway title, description, button) and every state of the payment page.
 */
import { ShoppingCart01Icon, TextFontIcon, CustomerSupportIcon } from '@hugeicons/core-free-icons';
import { useStore } from '../lib/store';
import { Row, Section, SwitchRow, TextArea, TextInput, TokenArea } from '../ui/Field';

const PAGE_TOKENS = {
	first_name: 'نام مشتری',
	name: 'نام کامل',
	order: 'شماره سفارش',
	total: 'مبلغ',
	deadline: 'پایان مهلت',
	shop: 'نام فروشگاه',
};

export function Texts() {
	const { s, set } = useStore();
	return (
		<>
			<Section title="صفحه پرداخت (تسویه حساب)" icon={ ShoppingCart01Icon } desc="آنچه مشتری هنگام انتخاب روش پرداخت می‌بیند. در چک‌اوت کلاسیک و بلوکی ووکامرس.">
				<Row label="عنوان روش پرداخت">
					<TextInput value={ s.title } onChange={ ( v ) => set( 'title', v ) } aria-label="عنوان" />
				</Row>
				<Row label="توضیح زیر روش پرداخت" stack>
					<TextArea value={ s.description } onChange={ ( v ) => set( 'description', v ) } rows={ 2 } />
				</Row>
				<Row label="متن دکمه ثبت سفارش">
					<TextInput value={ s.button } onChange={ ( v ) => set( 'button', v ) } aria-label="متن دکمه" />
				</Row>
			</Section>

			<Section title="متن‌های صفحه پرداخت" icon={ TextFontIcon } desc="روی متغیرها بزنید تا در محل نشانگر اضافه شوند.">
				<Row label="تیتر" stack>
					<TokenArea value={ s.page_title } onChange={ ( v ) => set( 'page_title', v ) } tokens={ PAGE_TOKENS } rows={ 1 } />
				</Row>
				<Row label="در انتظار واریز" stack>
					<TokenArea value={ s.text_pay } onChange={ ( v ) => set( 'text_pay', v ) } tokens={ PAGE_TOKENS } />
				</Row>
				<Row label="مراحل (هر خط یک مرحله)" stack>
					<TextArea value={ s.steps } onChange={ ( v ) => set( 'steps', v ) } rows={ 3 } />
				</Row>
				<Row label="رسید در حال بررسی" stack>
					<TokenArea value={ s.text_review } onChange={ ( v ) => set( 'text_review', v ) } tokens={ PAGE_TOKENS } rows={ 2 } />
				</Row>
				<Row label="رسید رد شد" help="دلیل رد زیر همین متن نمایش داده می‌شود." stack>
					<TokenArea value={ s.text_reject } onChange={ ( v ) => set( 'text_reject', v ) } tokens={ PAGE_TOKENS } rows={ 2 } />
				</Row>
				<Row label="پرداخت تأیید شد" stack>
					<TokenArea value={ s.text_paid } onChange={ ( v ) => set( 'text_paid', v ) } tokens={ PAGE_TOKENS } rows={ 2 } />
				</Row>
				<Row label="سفارش لغو شد" stack>
					<TokenArea value={ s.text_expired } onChange={ ( v ) => set( 'text_expired', v ) } tokens={ PAGE_TOKENS } rows={ 2 } />
				</Row>
			</Section>

			<Section title="پشتیبانی" icon={ CustomerSupportIcon } desc="دکمه «گفت‌وگو با پشتیبانی» و متن پاسخ‌گویی زیر ستون خلاصه سفارش در صفحه پرداخت.">
				<SwitchRow label="نمایش بخش پشتیبانی" help="خاموش = دکمه گفت‌وگو و متن پاسخ‌گویی هیچ‌کدام نمایش داده نمی‌شوند (متن‌ها ذخیره می‌مانند)." checked={ s.support_on } onChange={ ( v ) => set( 'support_on', v ) } />
				{ s.support_on ? (
					<>
						<Row label="لینک گفت‌وگو با پشتیبانی" help="تلگرام، واتساپ، بله یا صفحه تماس. خالی = دکمه نمایش داده نمی‌شود.">
							<TextInput value={ s.support_url } onChange={ ( v ) => set( 'support_url', v ) } dir="ltr" placeholder="https://t.me/…" aria-label="لینک پشتیبانی" />
						</Row>
						<Row label="متن پاسخ‌گویی" help="زیر دکمه؛ مثلاً ساعت پاسخ‌گویی یا شماره تماس. خالی = نمایش داده نمی‌شود." stack>
							<TextArea value={ s.support } onChange={ ( v ) => set( 'support', v ) } rows={ 2 } placeholder="پاسخ‌گویی شنبه تا پنجشنبه، ۹ تا ۱۸" />
						</Row>
					</>
				) : null }
			</Section>
		</>
	);
}

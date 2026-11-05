/**
 * Orders & My Account: status after approval / expiry, stock, customer cancel and notes, the
 * thank-you and My Account boxes, and how long receipt files are kept.
 */
import { PackageIcon, UserAccountIcon, Delete02Icon } from '@hugeicons/core-free-icons';
import { useStore } from '../lib/store';
import { fa } from '../lib/utils';
import { NumberInput, Row, Section, SwitchRow } from '../ui/Field';
import { Segmented } from '../ui/Segmented';

const days = ( n: number ) => ( n > 0 ? `${ fa( n ) } روز` : 'برای همیشه نگه داشته می‌شود' );

export function Orders() {
	const { s, set } = useStore();
	return (
		<>
			<Section title="وضعیت سفارش" icon={ PackageIcon } desc="رفتار سفارش‌های کارت به کارت در ووکامرس.">
				<Row label="وضعیت بعد از تأیید رسید" help="«خودکار»: مثل درگاه‌های دیگر؛ محصولات مجازی/دانلودی «تکمیل‌شده» و بقیه «در حال انجام».">
					<Segmented
						value={ s.paid_status }
						onChange={ ( v ) => set( 'paid_status', v ) }
						options={ [
							{ value: 'auto', label: 'خودکار' },
							{ value: 'processing', label: 'در حال انجام' },
							{ value: 'completed', label: 'تکمیل‌شده' },
						] }
					/>
				</Row>
				<Row label="وضعیت بعد از پایان مهلت" help="در هر دو حالت موجودی انبار برگردانده می‌شود.">
					<Segmented
						value={ s.expire_status }
						onChange={ ( v ) => set( 'expire_status', v ) }
						options={ [
							{ value: 'cancelled', label: 'لغو شده' },
							{ value: 'failed', label: 'ناموفق' },
						] }
					/>
				</Row>
				<SwitchRow label="رزرو موجودی تا بررسی رسید" help="روشن: موجودی با ثبت سفارش کم می‌شود تا کالا برای مشتری نگه داشته شود. خاموش: فقط بعد از تأیید پرداخت کم می‌شود." checked={ s.hold_stock } onChange={ ( v ) => set( 'hold_stock', v ) } />
				<SwitchRow label="تأیید و رد رسید به‌عنوان یادداشت برای مشتری" help="ووکامرس ایمیل «یادداشت جدید» را برای مشتری می‌فرستد و در حساب کاربری او دیده می‌شود." checked={ s.customer_note } onChange={ ( v ) => set( 'customer_note', v ) } />
			</Section>

			<Section title="حساب کاربری مشتری" icon={ UserAccountIcon } desc="هماهنگ با بخش «سفارش‌ها» در حساب کاربری ووکامرس.">
				<SwitchRow label="دکمه «ارسال رسید» در لیست سفارش‌ها" help="به‌جای دکمه «پرداخت»، تا وقتی رسید ارسال نشده." checked={ s.account_action } onChange={ ( v ) => set( 'account_action', v ) } />
				<SwitchRow label="کارت و وضعیت رسید در صفحه جزئیات سفارش" help="کارت بانکی، دکمه ارسال رسید و تاریخچه رسیدها بالای جدول سفارش." checked={ s.account_box } onChange={ ( v ) => set( 'account_box', v ) } />
				<SwitchRow label="لغو سفارش توسط مشتری" help="مشتری تا پیش از ارسال رسید می‌تواند سفارش را لغو کند (دکمه «لغو» ووکامرس)." checked={ s.customer_cancel } onChange={ ( v ) => set( 'customer_cancel', v ) } />
				<SwitchRow label="کارت در صفحه تشکر ووکامرس" help="وقتی «انتقال مستقیم به صفحه پرداخت» خاموش است، کارت و دکمه «ارسال رسید» اینجا نمایش داده می‌شود." checked={ s.thankyou_box } onChange={ ( v ) => set( 'thankyou_box', v ) } />
			</Section>

			<Section title="نگهداری فایل رسیدها" icon={ Delete02Icon } desc="فایل‌ها هر روز بررسی و طبق این قوانین حذف می‌شوند. اطلاعات رسید (تاریخ، شماره پیگیری، وضعیت) همیشه روی سفارش می‌ماند؛ فقط فایل پاک می‌شود. ۰ = نگهداری برای همیشه.">
				<Row label="رسیدهای تأییدشده" help={ days( s.keep_approved ) }>
					<NumberInput value={ s.keep_approved } onChange={ ( v ) => set( 'keep_approved', v ) } max={ 3650 } unit="روز" />
				</Row>
				<Row label="رسیدهای ردشده" help={ days( s.keep_rejected ) }>
					<NumberInput value={ s.keep_rejected } onChange={ ( v ) => set( 'keep_rejected', v ) } max={ 3650 } unit="روز" />
				</Row>
				<Row label="سفارش‌های لغو / ناموفق / مسترد" help={ s.keep_cancelled > 0 ? `همه رسیدهای سفارش ${ fa( s.keep_cancelled ) } روز بعد از آخرین تغییر سفارش حذف می‌شوند.` : 'برای همیشه نگه داشته می‌شود' }>
					<NumberInput value={ s.keep_cancelled } onChange={ ( v ) => set( 'keep_cancelled', v ) } max={ 3650 } unit="روز" />
				</Row>
				<SwitchRow label="حذف فایل‌ها با حذف دائمی سفارش" help="وقتی سفارشی از زباله‌دان پاک می‌شود، فایل رسیدهایش هم حذف شود." checked={ s.delete_with_order } onChange={ ( v ) => set( 'delete_with_order', v ) } />
			</Section>
		</>
	);
}

/** Settings of «تشخیص رسید تکراری و مشکوک». */
import { fa } from '../lib/utils';
import { Row, SwitchRow } from '../ui/Field';
import { Segmented } from '../ui/Segmented';
import { moduleData, useModule } from './shared';

type Data = { gd: boolean; heic: boolean; indexed: number };

export function FraudModule() {
	const [ c, update ] = useModule( 'fraud' );
	const data = moduleData<Data>( 'fraud' );
	return (
		<>
			<SwitchRow label="همان فایل" help="فایل بایت‌به‌بایت یکسان با رسید یک سفارش دیگر. قطعی و بدون خطا." checked={ c.file } onChange={ ( v ) => update( { file: v } ) } />
			<SwitchRow
				label="همان تصویر با فایل متفاوت"
				help={ data.gd ? `همان رسید که دوباره ذخیره یا در پیام‌رسان فشرده شده = مشکوک. نسخه کوچک‌شده یا تصویری بسیار شبیه = فقط هشدار تا خودتان مقایسه کنید (دو رسید متفاوت که فقط در یک رقم فرق دارند هم ممکن است شبیه دیده شوند). رسیدهای معمولیِ یک اپ بانکی را یکی حساب نمی‌کند.${ data.heic ? '' : ' (HEIC بدون Imagick بررسی نمی‌شود.)' }` : 'کتابخانه GD روی سرور فعال نیست؛ این بررسی انجام نمی‌شود.' }
				checked={ c.image && data.gd }
				onChange={ ( v ) => update( { image: v } ) }
			/>
			<SwitchRow label="شماره پیگیری تکراری" help="شماره پیگیری + ۴ رقم آخر کارت یکسان، یا شماره مرجع بلند (۱۰ رقم به بالا) = مشکوک. شماره پیگیری کوتاه به‌تنهایی فقط هشدار است، چون بین بانک‌ها تکرار می‌شود." checked={ c.ref } onChange={ ( v ) => update( { ref: v } ) } />
			{ c.image && data.gd ? (
				<Row label="حساسیت «تصویر بسیار مشابه»" help="«زیاد» نسخه‌های کوچک‌تر و فشرده‌تر را هم پیدا می‌کند، با احتمال هشدار بیشتر.">
					<Segmented value={ c.sensitivity } onChange={ ( v ) => update( { sensitivity: v } ) } options={ [ { value: 'low', label: 'کم' }, { value: 'normal', label: 'معمولی' }, { value: 'high', label: 'زیاد' } ] } />
				</Row>
			) : null }
			<Row label="با رسید تکراری قطعی" help={ c.action === 'block' ? 'مشتری پیام «این رسید قبلاً برای سفارش دیگری ارسال شده» می‌بیند و رسید ثبت نمی‌شود؛ تلاش او در یادداشت سفارش ثبت می‌شود.' : 'رسید ثبت می‌شود و با برچسب «⚠️ مشکوک» و لینک سفارش قبلی برای بررسی نمایش داده می‌شود.' }>
				<Segmented value={ c.action } onChange={ ( v ) => update( { action: v } ) } options={ [ { value: 'flag', label: 'فقط برچسب' }, { value: 'block', label: 'نپذیرفتن' } ] } />
			</Row>
			<SwitchRow label="تأیید دوباره برای رسید مشکوک" help="پیش از تأیید رسیدی که برچسب مشکوک دارد، یک بار دیگر پرسیده می‌شود." checked={ c.confirm } onChange={ ( v ) => update( { confirm: v } ) } />
			<p className="px-3 text-[12px] leading-6 text-muted-foreground">
				همان سفارش مستثناست (مشتری می‌تواند رسید ردشده را دوباره بفرستد). اگر سفارش قبلی متعلق به همین مشتری و لغوشده باشد، فقط هشدار داده می‌شود. اثر انگشت رسیدها حتی بعد از حذف فایل‌ها می‌ماند. { data.indexed ? `${ fa( data.indexed ) } رسید ثبت شده.` : 'بررسی از رسیدهای بعد از فعال‌سازی شروع می‌شود.' }
			</p>
		</>
	);
}

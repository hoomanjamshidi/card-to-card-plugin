/** Settings of «تخفیف یا کارمزد» with a live example. */
import { boot, fa, money } from '../lib/utils';
import { Row, SwitchRow, TextInput } from '../ui/Field';
import { MoneyInput } from '../ui/MoneyInput';
import { Segmented } from '../ui/Segmented';
import { latin } from '../lib/utils';
import { useModule } from './shared';
import type { FeeConf } from '../lib/types';

/** Same math as Modules\Fee::amount(). */
export function feeAmount( c: FeeConf, base: number ): number {
	if ( base <= 0 || ( c.min > 0 && base < c.min ) ) return 0;
	let raw = c.mode === 'fixed' ? c.fixed : ( base * c.percent ) / 100;
	if ( c.max > 0 ) raw = Math.min( raw, c.max );
	raw = c.round > 0 ? Math.round( raw / c.round ) * c.round : Math.round( raw );
	return c.type === 'discount' ? -Math.min( raw, base ) : raw;
}

export function FeeModule() {
	const [ c, update ] = useModule( 'fee' );
	const sample = 2_500_000;
	const amount = feeAmount( c, sample );
	const what = c.type === 'discount' ? 'تخفیف' : 'کارمزد';
	const name = c.label.trim() || `${ what } کارت به کارت`;
	return (
		<>
			<Row label="نوع">
				<Segmented value={ c.type } onChange={ ( v ) => update( { type: v } ) } options={ [ { value: 'discount', label: 'تخفیف' }, { value: 'fee', label: 'کارمزد' } ] } />
			</Row>
			<Row label="محاسبه" help={ c.mode === 'percent' ? 'درصدی از جمع محصولات (بعد از کوپن).' : 'مبلغ ثابت برای هر سفارش.' }>
				<div className="flex flex-wrap items-center gap-2">
					<Segmented value={ c.mode } onChange={ ( v ) => update( { mode: v } ) } options={ [ { value: 'percent', label: 'درصد' }, { value: 'fixed', label: 'مبلغ ثابت' } ] } />
					{ c.mode === 'percent' ? (
						<div className="flex w-28 items-center gap-1 rounded-2xl border border-border bg-card px-3 py-2 focus-within:border-primary">
							<input
								value={ fa( c.percent ) }
								inputMode="decimal"
								dir="ltr"
								aria-label="درصد"
								onChange={ ( e ) => {
									const v = Number( latin( e.target.value ).replace( /[^\d.]/g, '' ) || 0 );
									update( { percent: Math.min( 100, Number.isFinite( v ) ? v : 0 ) } );
								} }
								className="w-full bg-transparent text-left text-[14px] font-bold tabular-nums outline-none"
							/>
							<span className="text-xs text-muted-foreground">٪</span>
						</div>
					) : (
						<MoneyInput value={ c.fixed } onChange={ ( v ) => update( { fixed: v } ) } placeholder="۰" className="w-48" label="مبلغ ثابت" />
					) }
				</div>
			</Row>
			{ c.mode === 'percent' ? (
				<>
					<Row label="سقف مبلغ" help={ `حداکثر ${ what } هر سفارش. خالی = بدون سقف.` }>
						<MoneyInput value={ c.max } onChange={ ( v ) => update( { max: v } ) } label="سقف مبلغ" />
					</Row>
					<SwitchRow label="هزینه ارسال هم حساب شود" help="درصد از جمع محصولات + هزینه ارسال." checked={ c.shipping } onChange={ ( v ) => update( { shipping: v } ) } />
				</>
			) : null }
			<Row label="فقط برای سفارش‌های بالای" help="خالی = همه سفارش‌ها.">
				<MoneyInput value={ c.min } onChange={ ( v ) => update( { min: v } ) } placeholder="همه سفارش‌ها" label="حداقل مبلغ" />
			</Row>
			<Row label="گرد کردن" help="مثلاً ۱٬۰۰۰ تا مبلغ‌ها رُند شوند. خالی = بدون گرد کردن.">
				<MoneyInput value={ c.round } onChange={ ( v ) => update( { round: v } ) } placeholder="بدون گرد کردن" label="گرد کردن" />
			</Row>
			<Row label="عنوان ردیف در فاکتور">
				<TextInput value={ c.label } onChange={ ( v ) => update( { label: v } ) } placeholder={ `${ what } کارت به کارت` } aria-label="عنوان ردیف" />
			</Row>
			<SwitchRow label="نمایش کنار نام درگاه" help={ `مثلاً «${ boot().settings.title } (${ c.mode === 'percent' ? `${ fa( c.percent ) }٪` : `${ money( c.fixed ) } ${ boot().unit }` } ${ what })» در صفحه پرداخت.` } checked={ c.badge } onChange={ ( v ) => update( { badge: v } ) } />
			<div className="mx-3 mt-2 rounded-2xl border border-dashed border-border bg-muted/50 p-4 text-[13px] leading-7">
				<p className="mb-1 font-bold">نمونه برای سبد { money( sample ) } { boot().unit }:</p>
				<p className="flex justify-between"><span>{ name }</span><b dir="ltr" className={ amount < 0 ? 'text-[#16a34a]' : 'text-foreground' }>{ amount ? `${ amount < 0 ? '−' : '+' }${ money( Math.abs( amount ) ) }` : '—' }</b></p>
				<p className="flex justify-between text-muted-foreground"><span>مبلغ قابل پرداخت</span><b dir="ltr">{ money( sample + amount ) } { boot().unit }</b></p>
			</div>
		</>
	);
}

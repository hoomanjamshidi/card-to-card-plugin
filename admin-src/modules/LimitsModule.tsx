/** Settings of «سقف دریافت هر کارت»: day / month caps per card with today's usage bars. */
import { useStore } from '../lib/store';
import { boot, cn, fa, money } from '../lib/utils';
import { MoneyInput } from '../ui/MoneyInput';
import { cardLabel, moduleData, useModule } from './shared';

type Data = { usage: Record<string, { day: number; month: number }>; month: string };

function Bar( { used, cap, label }: { used: number; cap: number; label: string } ) {
	const pct = cap > 0 ? Math.min( 100, ( used / cap ) * 100 ) : 0;
	return (
		<div className="text-[11.5px] text-muted-foreground">
			<div className="flex justify-between">
				<span>{ label }</span>
				<span dir="ltr">{ money( used ) }{ cap ? ` / ${ money( cap ) }` : '' }</span>
			</div>
			{ cap ? (
				<div className="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
					<div className={ cn( 'h-full rounded-full', pct >= 100 ? 'bg-[#ef4444]' : pct >= 80 ? 'bg-[#f59e0b]' : 'bg-[#34C759]' ) } style={ { width: `${ pct }%` } } />
				</div>
			) : null }
		</div>
	);
}

export function LimitsModule() {
	const { s, set } = useStore();
	const [ c, update ] = useModule( 'limits' );
	const data = moduleData<Data>( 'limits' );
	const cards = s.cards.filter( ( card ) => card.active );
	const caps = ( id: string ) => c.limits[ id ] ?? { day: 0, month: 0 };
	const setCap = ( id: string, patch: Partial<{ day: number; month: number }> ) => update( { limits: { ...c.limits, [ id ]: { ...caps( id ), ...patch } } } );

	if ( ! cards.length ) return <p className="px-3 text-[13px] text-muted-foreground">ابتدا در تب «کارت‌ها» کارت فعال اضافه کنید.</p>;
	return (
		<>
			<p className="px-3 text-[12.5px] leading-6 text-muted-foreground">
				مبلغ هر سفارش از همان لحظه ثبت روی کارتش حساب می‌شود و اگر سفارش لغو یا ناموفق شود برمی‌گردد. ماه به تقویم شمسی ({ data.month }) است. وقتی همه کارت‌ها پر باشند، کارت به کارت در صفحه پرداخت نمایش داده نمی‌شود.
			</p>
			{ cards.map( ( card ) => {
				const used = data.usage?.[ card.id ] ?? { day: 0, month: 0 };
				return (
					<div key={ card.id } className="grid gap-3 rounded-2xl px-3 py-3 hover:bg-accent/50 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)] md:items-start">
						<div>
							<p className="text-[14px] font-semibold" dir="auto">{ cardLabel( card.number, card.holder ) }</p>
							<p className="text-[12px] text-muted-foreground">{ boot().banks[ card.bank ]?.name ?? '' }</p>
						</div>
						<div className="flex flex-col gap-1.5">
							<MoneyInput value={ caps( card.id ).day } onChange={ ( v ) => setCap( card.id, { day: v } ) } placeholder="سقف روزانه" label="سقف روزانه" />
							<Bar used={ used.day } cap={ caps( card.id ).day } label="امروز" />
						</div>
						<div className="flex flex-col gap-1.5">
							<MoneyInput value={ caps( card.id ).month } onChange={ ( v ) => setCap( card.id, { month: v } ) } placeholder="سقف ماهانه" label="سقف ماهانه" />
							<Bar used={ used.month } cap={ caps( card.id ).month } label="این ماه" />
						</div>
					</div>
				);
			} ) }
			{ s.card_mode === 'all' && cards.length > 1 ? (
				<div className="mx-3 mt-1 flex flex-wrap items-center justify-between gap-2 rounded-2xl bg-[#fff7e0] px-4 py-3 text-[12.5px] leading-6 text-[#7a5200] dark:bg-amber-500/10 dark:text-amber-300">
					<span>در حالت «همه کارت‌ها» مبلغ به اولین کارت نمایش‌داده‌شده نسبت داده می‌شود. برای پر شدن کارت‌ها به ترتیب، حالت «به ترتیب» را انتخاب کنید.</span>
					<button type="button" onClick={ () => set( 'card_mode', 'first' ) } className="rounded-full bg-[#7a5200] px-3 py-1 text-[12px] font-bold text-white">
						حالت «به ترتیب»
					</button>
				</div>
			) : null }
			<p className="px-3 text-[11.5px] text-muted-foreground">{ fa( cards.length ) } کارت فعال — خالی = بدون سقف.</p>
		</>
	);
}

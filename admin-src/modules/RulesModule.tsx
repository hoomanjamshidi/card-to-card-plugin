/** Settings of «شرط نمایش درگاه و کارت». */
import { useStore } from '../lib/store';
import type { Option } from '../lib/types';
import { ChipSelect } from '../ui/ChipSelect';
import { Row } from '../ui/Field';
import { MoneyInput } from '../ui/MoneyInput';
import { Segmented } from '../ui/Segmented';
import { cardLabel, moduleData, useModule } from './shared';

type Data = { cats: Option[]; shipping: Option[]; roles: Option[] };

export function RulesModule() {
	const { s } = useStore();
	const [ c, update ] = useModule( 'rules' );
	const data = moduleData<Data>( 'rules' );
	const cards = s.cards.filter( ( card ) => card.active );
	const rule = ( id: string ) => c.cards[ id ] ?? { roles: [], min: 0, max: 0 };
	const setRule = ( id: string, patch: Partial<{ roles: string[]; min: number; max: number }> ) => update( { cards: { ...c.cards, [ id ]: { ...rule( id ), ...patch } } } );

	return (
		<>
			<p className="px-3 pt-1 text-[13px] font-extrabold">چه زمانی کارت به کارت نمایش داده شود؟</p>
			<Row label="دسته‌بندی محصولات" stack help={ c.cats_mode === 'only' ? 'فقط وقتی همه محصولات سبد در این دسته‌ها (یا زیرشاخه‌هایشان) باشند.' : c.cats_mode === 'except' ? 'اگر حتی یک محصول سبد در این دسته‌ها باشد، نمایش داده نمی‌شود.' : 'برای همه محصولات.' }>
				<div className="flex flex-col gap-3">
					<Segmented value={ c.cats_mode } onChange={ ( v ) => update( { cats_mode: v } ) } options={ [ { value: 'off', label: 'همه' }, { value: 'only', label: 'فقط این دسته‌ها' }, { value: 'except', label: 'به‌جز این دسته‌ها' } ] } />
					{ c.cats_mode !== 'off' ? <ChipSelect options={ data.cats ?? [] } value={ c.cats.map( String ) } onChange={ ( v ) => update( { cats: v.map( Number ) } ) } emptyLabel="دسته‌ای انتخاب نشده (شرط اعمال نمی‌شود)" empty="دسته‌بندی محصولی وجود ندارد." /> : null }
				</div>
			</Row>
			<Row label="روش‌های ارسال" stack help="فقط با این روش‌های ارسال. روش کلی (مثلاً «پیک») همه مناطق را شامل می‌شود.">
				<ChipSelect options={ data.shipping ?? [] } value={ c.shipping } onChange={ ( v ) => update( { shipping: v } ) } emptyLabel="همه روش‌های ارسال" empty="روش ارسالی تعریف نشده است." />
			</Row>
			<Row label="نقش کاربری مشتری" stack help="مثلاً فقط مشتریان واردشده، یا فقط نقش «عمده‌فروش».">
				<ChipSelect options={ data.roles ?? [] } value={ c.roles } onChange={ ( v ) => update( { roles: v } ) } emptyLabel="همه مشتریان" />
			</Row>

			{ cards.length ? (
				<>
					<p className="px-3 pt-4 text-[13px] font-extrabold">کدام کارت به چه کسی داده شود؟</p>
					<p className="px-3 text-[12.5px] leading-6 text-muted-foreground">برای هر کارت نقش و بازه مبلغ تعیین کنید؛ خالی = برای همه. کارتی که شرطش برقرار نباشد به آن سفارش داده نمی‌شود.</p>
					{ cards.map( ( card ) => (
						<div key={ card.id } className="flex flex-col gap-3 rounded-2xl border border-border px-3 py-3 md:mx-3">
							<p className="text-[14px] font-semibold" dir="auto">{ cardLabel( card.number, card.holder ) }</p>
							<ChipSelect options={ data.roles ?? [] } value={ rule( card.id ).roles } onChange={ ( v ) => setRule( card.id, { roles: v } ) } emptyLabel="برای همه نقش‌ها" />
							<div className="grid gap-2 md:grid-cols-2">
								<MoneyInput value={ rule( card.id ).min } onChange={ ( v ) => setRule( card.id, { min: v } ) } placeholder="حداقل مبلغ سفارش" label="حداقل مبلغ" />
								<MoneyInput value={ rule( card.id ).max } onChange={ ( v ) => setRule( card.id, { max: v } ) } placeholder="حداکثر مبلغ سفارش" label="حداکثر مبلغ" />
							</div>
						</div>
					) ) }
				</>
			) : null }
		</>
	);
}

/**
 * Destination cards: drag to reorder (Tarhak sortable-list), edit with a live bank-coloured preview,
 * bank auto-detected from the card number / IBAN, Luhn and mod-97 checks as you type.
 */
import { useState } from 'react';
import { AnimatePresence, motion } from 'motion/react';
import { HugeiconsIcon } from '@hugeicons/react';
import { Add01Icon, CheckmarkCircle02Icon, Alert02Icon, CreditCardIcon, PaintBrush01Icon } from '@hugeicons/core-free-icons';
import { useStore } from '../lib/store';
import type { Card, CardSkin } from '../lib/types';
import { bankOf, cardValid, digits, formatCard, formatSheba, sheba, shebaValid } from '../lib/banks';
import { boot, cn, fa } from '../lib/utils';
import { Section, Row, SwitchRow, TextInput } from '../ui/Field';
import { Segmented } from '../ui/Segmented';
import { SoftSelect } from '../ui/SoftSelect';
import { Sortable } from '../ui/Sortable';
import { DeleteButton } from '../ui/DeleteButton';
import { BankCard } from '../components/BankCard';

const blank = (): Card => ( { id: `c${ Math.random().toString( 36 ).slice( 2, 10 ) }`, number: '', holder: '', sheba: '', account: '', bank: '', skin: 'bank', color1: '#4f46e5', color2: '#0ea5e9', active: true } );

function Check( { ok, text }: { ok: boolean; text: string } ) {
	return (
		<span className={ cn( 'inline-flex items-center gap-1 text-[12px] font-bold', ok ? 'text-[#16a34a]' : 'text-[#e11d48]' ) }>
			<HugeiconsIcon icon={ ok ? CheckmarkCircle02Icon : Alert02Icon } size={ 15 } />
			{ text }
		</span>
	);
}

function Logo( { svg }: { svg: string } ) {
	return svg ? <span className="block size-full [&>svg]:size-full" dangerouslySetInnerHTML={ { __html: svg } } /> : null;
}

export function Cards() {
	const { s, set } = useStore();
	const [ activeId, setActiveId ] = useState( s.cards[ 0 ]?.id ?? '' );
	const card = s.cards.find( ( c ) => c.id === activeId );
	const banks = boot().banks;

	const update = ( patch: Partial<Card> ) => set( 'cards', s.cards.map( ( c ) => ( c.id === activeId ? { ...c, ...patch } : c ) ) );
	const add = () => {
		const c = blank();
		set( 'cards', [ ...s.cards, c ] );
		setActiveId( c.id );
	};
	const remove = () => {
		const rest = s.cards.filter( ( c ) => c.id !== activeId );
		set( 'cards', rest );
		setActiveId( rest[ 0 ]?.id ?? '' );
	};

	const num = card ? digits( card.number ) : '';
	const ib = card ? sheba( card.sheba ) : '';
	const detected = card ? bankOf( { ...card, bank: '' } ).slug : '';

	return (
		<>
			<Section
				title="کارت‌های مقصد"
				desc="مشتری بعد از ثبت سفارش، کارت را با رنگ و لوگوی بانک خودش می‌بیند. ترتیب را با کشیدن عوض کنید."
				icon={ CreditCardIcon }
				actions={
					<button type="button" onClick={ add } className="inline-flex items-center gap-1.5 rounded-full bg-primary px-4 py-2 text-[13px] font-bold text-white shadow-[0_10px_20px_-12px_var(--primary)]">
						<HugeiconsIcon icon={ Add01Icon } size={ 16 } />
						کارت جدید
					</button>
				}
			>
				{ s.cards.length ? (
					<Sortable<Card>
						items={ s.cards }
						onReorder={ ( next ) => set( 'cards', next ) }
						activeId={ activeId }
						onSelect={ setActiveId }
						render={ ( c ) => {
							const { bank } = bankOf( c );
							return (
								<div className="flex items-center gap-3">
									<span className="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-border bg-white p-1">{ bank.logo ? <Logo svg={ bank.logo } /> : <HugeiconsIcon icon={ CreditCardIcon } size={ 18 } className="text-muted-foreground" /> }</span>
									<div className="min-w-0 flex-1">
										<p className="truncate text-[13.5px] font-bold">{ bank.name ? `بانک ${ bank.name }` : 'بانک نامشخص' }{ c.holder ? ` — ${ c.holder }` : '' }</p>
										<p className="truncate text-[12px] tabular-nums text-muted-foreground" dir="ltr">{ formatCard( c.number ) || formatSheba( c.sheba ) || '—' }</p>
									</div>
									<span className="h-6 w-10 shrink-0 rounded-md" style={ { background: `linear-gradient(135deg, ${ c.skin === 'custom' ? c.color1 : bank.colors[ 0 ] }, ${ c.skin === 'custom' ? c.color2 : bank.colors[ 1 ] })` } } />
									{ ! c.active ? <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">غیرفعال</span> : null }
								</div>
							);
						} }
					/>
				) : (
					<button type="button" onClick={ add } className="flex flex-col items-center gap-2 rounded-2xl border-2 border-dashed border-border px-6 py-10 text-muted-foreground hover:border-primary hover:text-primary">
						<HugeiconsIcon icon={ Add01Icon } size={ 26 } />
						<span className="text-[13.5px] font-bold">اولین کارت را اضافه کنید</span>
					</button>
				) }
				{ s.cards.filter( ( c ) => c.active ).length > 1 ? (
					<Row label="نمایش چند کارت" help="«همه»: مشتری بین کارت‌ها انتخاب می‌کند. «تصادفی» و «نوبتی»: به هر سفارش فقط یک کارت داده می‌شود (پخش واریزها بین حساب‌ها).">
						<Segmented
							value={ s.card_mode }
							onChange={ ( v ) => set( 'card_mode', v ) }
							options={ [
								{ value: 'all', label: 'همه کارت‌ها' },
								{ value: 'random', label: 'تصادفی' },
								{ value: 'rotate', label: 'نوبتی' },
							] }
						/>
					</Row>
				) : null }
			</Section>

			<AnimatePresence mode="wait">
				{ card ? (
					<motion.div key={ card.id } initial={ { opacity: 0, y: 10 } } animate={ { opacity: 1, y: 0 } } exit={ { opacity: 0 } } className="grid gap-5 2xl:grid-cols-[minmax(0,1fr)_440px]">
						<Section title="مشخصات کارت" desc="شماره‌ها را با فاصله، خط تیره یا اعداد فارسی هم می‌توانید بنویسید.">
							<Row label="شماره کارت" help={ num ? ( num.length === 16 ? <Check ok={ cardValid( num ) } text={ cardValid( num ) ? 'شماره کارت معتبر است' : 'شماره کارت معتبر نیست (رقم کنترل)' } /> : `${ fa( num.length ) } از ۱۶ رقم` ) : '۱۶ رقم کارت شتاب' }>
								<TextInput value={ formatCard( card.number ) } onChange={ ( v ) => update( { number: digits( v ).slice( 0, 16 ) } ) } dir="ltr" mono inputMode="numeric" placeholder="6037 9900 0000 0000" invalid={ num.length === 16 && ! cardValid( num ) } aria-label="شماره کارت" />
							</Row>
							<Row label="به نام" help="همان نامی که بانک هنگام انتقال نشان می‌دهد.">
								<TextInput value={ card.holder } onChange={ ( v ) => update( { holder: v } ) } placeholder="مثلاً: فروشگاه ..." aria-label="به نام" />
							</Row>
							<Row label="شماره شبا" help={ card.sheba ? ( ib ? <Check ok={ shebaValid( ib ) } text={ shebaValid( ib ) ? 'شبا معتبر است' : 'شبا معتبر نیست (کد کنترل)' } /> : 'باید ۲۴ رقم باشد' ) : 'اختیاری؛ با IR یا بدون آن.' }>
								<TextInput value={ card.sheba } onChange={ ( v ) => update( { sheba: v.toUpperCase() } ) } dir="ltr" mono placeholder="IR00 0000 0000 0000 0000 0000 00" invalid={ !! card.sheba && ( ! ib || ! shebaValid( ib ) ) } aria-label="شماره شبا" />
							</Row>
							<Row label="شماره حساب" help="اختیاری.">
								<TextInput value={ card.account } onChange={ ( v ) => update( { account: v } ) } dir="ltr" mono aria-label="شماره حساب" />
							</Row>
							<Row label="بانک" help={ detected ? `تشخیص خودکار: بانک ${ banks[ detected ].name }` : 'از روی شماره کارت یا شبا تشخیص داده می‌شود.' }>
								<SoftSelect
									value={ card.bank }
									onValueChange={ ( v ) => update( { bank: v } ) }
									options={ [
										{ value: '', label: detected ? `خودکار (${ banks[ detected ].name })` : 'تشخیص خودکار', description: 'پیشنهادی' },
										...Object.entries( banks ).map( ( [ slug, b ] ) => ( { value: slug, label: `بانک ${ b.name }`, badge: <Logo svg={ b.logo } /> } ) ),
									] }
								/>
							</Row>
							<SwitchRow label="فعال" help="کارت غیرفعال به مشتری نشان داده نمی‌شود." checked={ card.active } onChange={ ( v ) => update( { active: v } ) } />
							<div className="flex justify-end px-3 pt-2">
								<DeleteButton onDelete={ remove } label="حذف این کارت" />
							</div>
						</Section>

						<div className="flex flex-col gap-5">
							<Section title="پیش‌نمایش زنده" icon={ PaintBrush01Icon }>
								<div className="flex justify-center rounded-2xl bg-[radial-gradient(circle_at_30%_20%,color-mix(in_srgb,var(--primary)_10%,transparent),transparent_60%)] py-6">
									<BankCard card={ card } tilt={ s.tilt } logo={ s.logo } className="w-full max-w-[380px]" />
								</div>
								<Row label="ظاهر کارت" stack>
									<Segmented<CardSkin>
										full
										value={ card.skin }
										onChange={ ( v ) => update( { skin: v } ) }
										options={ [
											{ value: 'bank', label: 'رنگ بانک' },
											{ value: 'glass', label: 'شیشه‌ای' },
											{ value: 'mono', label: 'مشکی' },
											{ value: 'custom', label: 'دلخواه' },
										] }
									/>
								</Row>
								{ card.skin === 'custom' ? (
									<div className="flex items-center gap-4 px-3 pb-2">
										{ ( [ 'color1', 'color2' ] as const ).map( ( k, i ) => (
											<label key={ k } className="flex items-center gap-2 text-[13px]">
												<input type="color" value={ card[ k ] } onChange={ ( e ) => update( { [ k ]: e.target.value } ) } className="size-10 cursor-pointer rounded-xl border border-border bg-card p-1" />
												{ i === 0 ? 'رنگ شروع' : 'رنگ پایان' }
											</label>
										) ) }
									</div>
								) : null }
							</Section>
						</div>
					</motion.div>
				) : null }
			</AnimatePresence>
		</>
	);
}

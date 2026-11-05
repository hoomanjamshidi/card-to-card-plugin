/**
 * Dashboard: live numbers (Tarhak number-ticker), 14-day bars, setup checklist, card preview.
 */
import { useEffect, useState } from 'react';
import { motion } from 'motion/react';
import { HugeiconsIcon, type IconSvgElement } from '@hugeicons/react';
import { CheckmarkCircle02Icon, Clock01Icon, Invoice03Icon, Money03Icon, CircleIcon, ArrowLeft01Icon } from '@hugeicons/core-free-icons';
import { api } from '../lib/api';
import { useStore } from '../lib/store';
import type { Stats } from '../lib/types';
import { boot, cn, fa, money } from '../lib/utils';
import { NumberTicker } from '../ui/NumberTicker';
import { Section } from '../ui/Field';
import { BankCard } from '../components/BankCard';

function Tile( { label, value, icon, tone, sub, onClick }: { label: string; value: number; icon: IconSvgElement; tone: string; sub?: string; onClick?: () => void } ) {
	return (
		<motion.button
			type="button"
			onClick={ onClick }
			whileHover={ { y: -2 } }
			className="group relative overflow-hidden rounded-[22px] border border-border bg-card p-5 text-start"
		>
			<span className="absolute -top-10 -end-10 size-32 rounded-full opacity-20 blur-2xl" style={ { background: tone } } />
			<span className="flex size-10 items-center justify-center rounded-2xl text-white" style={ { background: tone } }>
				<HugeiconsIcon icon={ icon } size={ 20 } />
			</span>
			<p className="mt-4 text-[12.5px] text-muted-foreground">{ label }</p>
			<p className="mt-1 text-[28px] font-black leading-9 text-foreground">
				<NumberTicker value={ value } />
				{ sub ? <small className="ms-1.5 text-[12px] font-medium text-muted-foreground">{ sub }</small> : null }
			</p>
		</motion.button>
	);
}

export function Dashboard( { go }: { go: ( tab: string ) => void } ) {
	const { s } = useStore();
	const [ st, setSt ] = useState<Stats | null>( null );
	useEffect( () => {
		api<Stats>( 'stats' ).then( setSt ).catch( () => {} );
	}, [] );
	const max = Math.max( 1, ...( st?.days ?? [ 0 ] ) );
	const card = s.cards.find( ( c ) => c.active ) ?? s.cards[ 0 ];
	const checks = [
		{ ok: s.enabled, label: 'درگاه در ووکامرس فعال است', tab: 'dashboard' },
		{ ok: s.cards.some( ( c ) => c.active ), label: 'حداقل یک کارت مقصد اضافه شده', tab: 'cards' },
		{ ok: s.sms_driver === 'melipayamak', label: 'پیامک ملی‌پیامک وصل است', tab: 'sms' },
		{ ok: boot().pretty, label: 'پیوندهای یکتا (Permalink) فعال است', tab: 'advanced' },
		{ ok: s.pro, label: 'جریان حرفه‌ای (صفحه پرداخت و رسید) روشن است', tab: 'payment' },
	];
	const done = checks.filter( ( c ) => c.ok ).length;

	return (
		<>
			<div className="grid grid-cols-2 gap-4 xl:grid-cols-4">
				<Tile label="رسید در انتظار بررسی" value={ st?.review ?? 0 } icon={ Invoice03Icon } tone="linear-gradient(140deg,#f97316,#e11d48)" onClick={ () => go( 'receipts' ) } />
				<Tile label="در انتظار واریز مشتری" value={ st?.awaiting ?? 0 } icon={ Clock01Icon } tone="linear-gradient(140deg,#f59e0b,#d97706)" onClick={ () => go( 'receipts' ) } />
				<Tile label="پرداخت تأییدشده (۳۰ روز)" value={ st?.paid30 ?? 0 } icon={ CheckmarkCircle02Icon } tone="linear-gradient(140deg,#22c55e,#15803d)" />
				<Tile label="مبلغ تأییدشده (۳۰ روز)" value={ st?.sum30 ?? 0 } sub={ st?.unit ?? boot().unit } icon={ Money03Icon } tone="linear-gradient(140deg,#6366f1,#0ea5e9)" />
			</div>

			<div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_400px]">
				<Section title="واریزهای تأییدشده" desc="مبلغ پرداخت‌های کارت به کارت تأییدشده در ۱۴ روز اخیر.">
					<div className="flex h-44 items-end gap-2 px-1" dir="ltr">
						{ ( st?.days ?? Array( 14 ).fill( 0 ) ).map( ( v, i ) => (
							<div key={ i } className="group relative flex h-full flex-1 flex-col justify-end">
								<motion.div
									initial={ { height: 0 } }
									animate={ { height: `${ Math.max( 3, ( v / max ) * 100 ) }%` } }
									transition={ { type: 'spring', stiffness: 160, damping: 20, delay: i * 0.03 } }
									className={ cn( 'w-full rounded-t-xl', v ? 'bg-[linear-gradient(180deg,#6366f1,#0ea5e9)]' : 'bg-muted' ) }
								/>
								<span className="pointer-events-none absolute -top-8 left-1/2 hidden -translate-x-1/2 whitespace-nowrap rounded-lg bg-foreground px-2 py-1 text-[11px] text-background group-hover:block">{ money( v ) }</span>
							</div>
						) ) }
					</div>
					<div className="mt-2 flex justify-between px-1 text-[11px] text-muted-foreground">
						<span>امروز</span>
						<span>{ fa( 14 ) } روز پیش</span>
					</div>
				</Section>

				<Section title="آماده‌سازی" desc={ `${ fa( done ) } از ${ fa( checks.length ) } مورد انجام شده` }>
					<div className="mb-3 h-2 overflow-hidden rounded-full bg-muted">
						<motion.div className="h-full rounded-full bg-[#34C759]" initial={ { width: 0 } } animate={ { width: `${ ( done / checks.length ) * 100 }%` } } transition={ { type: 'spring', stiffness: 120, damping: 20 } } />
					</div>
					{ checks.map( ( c ) => (
						<button key={ c.label } type="button" onClick={ () => go( c.tab ) } className="flex items-center gap-3 rounded-2xl px-2 py-2 text-start hover:bg-accent/60">
							<HugeiconsIcon icon={ c.ok ? CheckmarkCircle02Icon : CircleIcon } size={ 20 } className={ c.ok ? 'text-[#34C759]' : 'text-muted-foreground' } />
							<span className={ cn( 'flex-1 text-[13px]', c.ok ? 'text-foreground' : 'text-muted-foreground' ) }>{ c.label }</span>
							{ ! c.ok ? <HugeiconsIcon icon={ ArrowLeft01Icon } size={ 16 } className="text-muted-foreground" /> : null }
						</button>
					) ) }
				</Section>
			</div>

			{ card ? (
				<Section title="کارتی که مشتری می‌بیند" desc="پیش‌نمایش زنده با رنگ و لوگوی بانک. نشانگر را روی کارت حرکت دهید.">
					<div className="flex justify-center py-2">
						<BankCard card={ card } tilt={ s.tilt } logo={ s.logo } className="w-full max-w-[400px]" />
					</div>
				</Section>
			) : (
				<Section title="هنوز کارتی اضافه نشده" desc="برای فعال شدن درگاه، دست‌کم یک کارت مقصد اضافه کنید.">
					<button type="button" onClick={ () => go( 'cards' ) } className="self-start rounded-full bg-primary px-5 py-2.5 text-[13px] font-bold text-white">
						افزودن کارت
					</button>
				</Section>
			) }
		</>
	);
}

/**
 * Panel shell: header (gateway switch, theme, save), animated sidebar rail and the pages.
 * Saving sends the whole settings object; Ctrl/⌘+S saves too.
 */
import { useCallback, useEffect, useMemo, useState } from 'react';
import { AnimatePresence, motion } from 'motion/react';
import { HugeiconsIcon, type IconSvgElement } from '@hugeicons/react';
import {
	DashboardSquare02Icon,
	Invoice03Icon,
	CreditCardIcon,
	Settings02Icon,
	TextFontIcon,
	PaintBoardIcon,
	Message01Icon,
	SecurityCheckIcon,
	PackageIcon,
	PuzzleIcon,
	Moon02Icon,
	Sun03Icon,
} from '@hugeicons/core-free-icons';
import type { Settings } from './lib/types';
import { StoreContext } from './lib/store';
import { api } from './lib/api';
import { boot, cn, fa } from './lib/utils';
import { SaveButton, type SaveState } from './ui/SaveButton';
import { SoftSwitch } from './ui/SoftSwitch';
import { ToastStack, toast } from './ui/Toasts';
import { Dashboard } from './pages/Dashboard';
import { Receipts } from './pages/Receipts';
import { Cards } from './pages/Cards';
import { Payment } from './pages/Payment';
import { Orders } from './pages/Orders';
import { Texts } from './pages/Texts';
import { Appearance } from './pages/Appearance';
import { Sms } from './pages/Sms';
import { Advanced } from './pages/Advanced';
import { Modules } from './pages/Modules';

type Tab = { id: string; label: string; icon: IconSvgElement; hint: string };

const TABS: Tab[] = [
	{ id: 'dashboard', label: 'داشبورد', icon: DashboardSquare02Icon, hint: 'نمای کلی و وضعیت' },
	{ id: 'receipts', label: 'رسیدها', icon: Invoice03Icon, hint: 'بررسی، تأیید و رد' },
	{ id: 'cards', label: 'کارت‌ها', icon: CreditCardIcon, hint: 'کارت‌های مقصد' },
	{ id: 'payment', label: 'پرداخت و رسید', icon: Settings02Icon, hint: 'مهلت، فرم رسید، قوانین' },
	{ id: 'orders', label: 'سفارش‌ها', icon: PackageIcon, hint: 'وضعیت، حساب کاربری، نگهداری فایل' },
	{ id: 'texts', label: 'متن‌ها', icon: TextFontIcon, hint: 'درگاه و صفحه پرداخت' },
	{ id: 'look', label: 'ظاهر', icon: PaintBoardIcon, hint: 'رنگ، چیدمان، فونت' },
	{ id: 'sms', label: 'پیامک', icon: Message01Icon, hint: 'ملی‌پیامک' },
	{ id: 'modules', label: 'افزونه‌ها', icon: PuzzleIcon, hint: 'تخفیف، سقف کارت، شرط‌ها، ضدتقلب' },
	{ id: 'advanced', label: 'پیشرفته', icon: SecurityCheckIcon, hint: 'امنیت و داده‌ها' },
];

const readHash = () => {
	const id = window.location.hash.replace( '#', '' );
	return TABS.some( ( t ) => t.id === id ) ? id : 'dashboard';
};

export function App() {
	const [ saved, setSaved ] = useState<Settings>( boot().settings );
	const [ s, setS ] = useState<Settings>( boot().settings );
	const [ tab, setTab ] = useState( readHash );
	const [ state, setState ] = useState<SaveState>( 'idle' );
	const [ pending, setPending ] = useState<number | null>( null );
	const [ dark, setDark ] = useState( () => {
		try {
			return localStorage.getItem( 'ctc-dark' ) === '1';
		} catch {
			return false;
		}
	} );
	const dirty = useMemo( () => JSON.stringify( s ) !== JSON.stringify( saved ), [ s, saved ] );

	const store = useMemo(
		() => ( {
			s,
			set: <K extends keyof Settings>( key: K, value: Settings[ K ] ) => setS( ( prev ) => ( { ...prev, [ key ]: value } ) ),
			patch: ( values: Partial<Settings> ) => setS( ( prev ) => ( { ...prev, ...values } ) ),
		} ),
		[ s ]
	);

	const save = useCallback( async () => {
		if ( state !== 'idle' ) return;
		setState( 'loading' );
		try {
			const next = await api<Settings>( 'settings', { method: 'POST', body: s } );
			setSaved( next );
			setS( next );
			setState( 'success' );
			toast( 'success', 'تنظیمات ذخیره شد', 'تغییرات همین حالا روی سایت اعمال شدند.' );
		} catch ( e ) {
			setState( 'idle' );
			toast( 'error', 'ذخیره نشد', ( e as Error ).message );
			return;
		}
		setTimeout( () => setState( 'idle' ), 1600 );
	}, [ s, state ] );

	useEffect( () => {
		const key = ( e: KeyboardEvent ) => {
			if ( ( e.metaKey || e.ctrlKey ) && e.key.toLowerCase() === 's' ) {
				e.preventDefault();
				save();
			}
		};
		const leave = ( e: BeforeUnloadEvent ) => {
			if ( dirty ) e.preventDefault();
		};
		window.addEventListener( 'keydown', key );
		window.addEventListener( 'beforeunload', leave );
		return () => {
			window.removeEventListener( 'keydown', key );
			window.removeEventListener( 'beforeunload', leave );
		};
	}, [ save, dirty ] );

	useEffect( () => {
		window.history.replaceState( null, '', `#${ tab }` );
	}, [ tab ] );

	useEffect( () => {
		const onHash = () => setTab( readHash() );
		window.addEventListener( 'hashchange', onHash );
		return () => window.removeEventListener( 'hashchange', onHash );
	}, [] );

	useEffect( () => {
		const root = document.getElementById( 'ctc-app' );
		root?.classList.toggle( 'ctc-dark', dark );
		try {
			localStorage.setItem( 'ctc-dark', dark ? '1' : '0' );
		} catch {}
	}, [ dark ] );

	useEffect( () => {
		api<{ review: number }>( 'stats' ).then( ( st ) => setPending( st.review ) ).catch( () => {} );
	}, [ tab ] );

	const toggleGateway = async ( on: boolean ) => {
		setS( ( p ) => ( { ...p, enabled: on } ) );
		try {
			const next = await api<Settings>( 'settings', { method: 'POST', body: { enabled: on } } );
			setSaved( ( p ) => ( { ...p, enabled: next.enabled } ) );
			toast( on ? 'success' : 'warning', on ? 'درگاه کارت به کارت فعال شد' : 'درگاه کارت به کارت غیرفعال شد' );
		} catch ( e ) {
			setS( ( p ) => ( { ...p, enabled: ! on } ) );
			toast( 'error', 'تغییر انجام نشد', ( e as Error ).message );
		}
	};

	const Page: React.ComponentType<{ go: ( tab: string ) => void }> = ( { dashboard: Dashboard, receipts: Receipts, cards: Cards, payment: Payment, orders: Orders, texts: Texts, look: Appearance, sms: Sms, modules: Modules, advanced: Advanced } as Record<string, React.ComponentType<{ go: ( tab: string ) => void }>> )[ tab ] ?? Dashboard;

	return (
		<StoreContext.Provider value={ store }>
			<div className="min-h-[calc(100vh-120px)] bg-background pb-16 text-foreground">
				{ /* Header */ }
				<header className="sticky top-8 z-40 -mx-px mb-6 mt-4 flex flex-wrap items-center gap-3 rounded-[22px] border border-border bg-card/90 px-4 py-3 shadow-[0_8px_30px_-20px_rgba(15,23,42,.35)] backdrop-blur-xl md:px-5">
					<div className="flex items-center gap-3">
						<span className="flex size-11 items-center justify-center rounded-2xl bg-[linear-gradient(140deg,#6366f1,#0ea5e9)] text-white shadow-[0_10px_24px_-12px_#4f46e5]">
							<HugeiconsIcon icon={ CreditCardIcon } size={ 22 } />
						</span>
						<div>
							<h1 className="text-[16px] font-black leading-6 text-foreground">کارت به کارت حرفه‌ای</h1>
							<p className="text-[12px] text-muted-foreground">نسخه { fa( boot().version ) } — { boot().site }</p>
						</div>
					</div>
					<div className="ms-auto flex flex-wrap items-center gap-2 md:gap-3">
						<label className="flex items-center gap-2.5 rounded-full border border-border bg-background py-1.5 ps-3.5 pe-1.5">
							<span className={ cn( 'text-[13px] font-bold', s.enabled ? 'text-[#1f9d4a]' : 'text-muted-foreground' ) }>{ s.enabled ? 'درگاه فعال است' : 'درگاه خاموش است' }</span>
							<SoftSwitch checked={ s.enabled } onCheckedChange={ toggleGateway } label="فعال بودن درگاه" />
						</label>
						<button type="button" onClick={ () => setDark( ( d ) => ! d ) } aria-label={ dark ? 'حالت روشن' : 'حالت تیره' } className="flex size-11 items-center justify-center rounded-full border border-border bg-background text-muted-foreground hover:text-foreground">
							<AnimatePresence mode="wait" initial={ false }>
								<motion.span key={ dark ? 'sun' : 'moon' } initial={ { opacity: 0, y: 8, rotate: -24 } } animate={ { opacity: 1, y: 0, rotate: 0 } } exit={ { opacity: 0, y: -8, rotate: 20 } } className={ cn( 'flex items-center justify-center', dark && 'text-amber-400' ) }>
									<HugeiconsIcon icon={ dark ? Sun03Icon : Moon02Icon } size={ 19 } />
								</motion.span>
							</AnimatePresence>
						</button>
						<SaveButton state={ state } dirty={ dirty } onClick={ save } />
					</div>
				</header>

				<div className="grid gap-6 lg:grid-cols-[240px_minmax(0,1fr)]">
					{ /* Sidebar rail — in the language of Tarhak «animated-sidebars». */ }
					<nav aria-label="بخش‌ها" className="lg:sticky lg:top-[120px] lg:self-start">
						<ul className="flex gap-1 overflow-x-auto rounded-[22px] border border-border bg-card p-2 lg:flex-col lg:overflow-visible">
							{ TABS.map( ( t ) => {
								const on = t.id === tab;
								return (
									<li key={ t.id } className="shrink-0">
										<button type="button" onClick={ () => setTab( t.id ) } aria-current={ on ? 'page' : undefined } className={ cn( 'relative flex w-full items-center gap-3 rounded-2xl px-3 py-2.5 text-start outline-none focus-visible:ring-2 focus-visible:ring-ring', on ? 'text-foreground' : 'text-muted-foreground hover:text-foreground' ) }>
											{ on ? <motion.span layoutId="ctc-nav" className="absolute inset-0 rounded-2xl bg-accent" transition={ { type: 'spring', stiffness: 500, damping: 38 } } /> : null }
											{ on ? <motion.span layoutId="ctc-nav-bar" className="absolute inset-y-2.5 start-0 hidden w-1 rounded-full bg-primary lg:block" transition={ { type: 'spring', stiffness: 500, damping: 38 } } /> : null }
											<span className={ cn( 'relative z-1 flex size-9 shrink-0 items-center justify-center rounded-xl', on ? 'bg-primary text-white shadow-[0_8px_18px_-10px_var(--primary)]' : 'bg-muted' ) }>
												<HugeiconsIcon icon={ t.icon } size={ 18 } />
											</span>
											<span className="relative z-1 min-w-0 flex-1">
												<span className="block text-[13.5px] font-bold">{ t.label }</span>
												<span className="hidden text-[11.5px] text-muted-foreground lg:block">{ t.hint }</span>
											</span>
											{ t.id === 'receipts' && pending ? <span className="relative z-1 rounded-full bg-[#FE322A] px-2 py-0.5 text-[11px] font-bold text-white">{ fa( pending ) }</span> : null }
										</button>
									</li>
								);
							} ) }
						</ul>
					</nav>

					<main className="min-w-0">
						<AnimatePresence mode="wait">
							<motion.div key={ tab } initial={ { opacity: 0, y: 12, filter: 'blur(4px)' } } animate={ { opacity: 1, y: 0, filter: 'blur(0px)' } } exit={ { opacity: 0, y: -8, filter: 'blur(4px)' } } transition={ { type: 'spring', stiffness: 420, damping: 36 } } className="flex flex-col gap-5">
								<Page go={ setTab } />
							</motion.div>
						</AnimatePresence>
					</main>
				</div>
				<ToastStack />
			</div>
		</StoreContext.Provider>
	);
}

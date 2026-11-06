/**
 * Receipts inbox: filter by state, see the receipt image, approve (with a stamp animation in the spirit
 * of Tarhak «stamp-approve») or reject with a reason that is texted to the customer.
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import { AnimatePresence, motion } from 'motion/react';
import { HugeiconsIcon } from '@hugeicons/react';
import { CheckmarkBadge01Icon, Cancel01Icon, LinkSquare02Icon, File01Icon, Invoice03Icon, ZoomInAreaIcon, Alert02Icon } from '@hugeicons/core-free-icons';
import { useStore } from '../lib/store';
import { api } from '../lib/api';
import type { OrderRow } from '../lib/types';
import { cn, fa } from '../lib/utils';
import { Segmented } from '../ui/Segmented';
import { toast } from '../ui/Toasts';
import { TextArea } from '../ui/Field';

type Filter = 'review' | 'awaiting' | 'done' | 'all';

const REASONS = [ 'مبلغ واریزی با مبلغ سفارش یکی نیست', 'تصویر رسید خوانا نیست', 'واریزی با این مشخصات در حساب پیدا نشد', 'رسید مربوط به این سفارش نیست' ];

/**
 * Receipt picture: the inline preview from the list answer first, then the thumbnail URL, then the
 * original; a source that fails moves to the next, and when none loads a clear placeholder is shown
 * instead of a broken-image icon.
 */
function Pic( { sources, alt, className }: { sources: string[]; alt: string; className?: string } ) {
	const list = sources.filter( Boolean );
	const [ i, setI ] = useState( 0 );
	if ( i >= list.length ) {
		return (
			<span className="flex size-full flex-col items-center justify-center gap-1 p-2 text-center text-[11.5px] text-muted-foreground">
				<HugeiconsIcon icon={ File01Icon } size={ 24 } />
				تصویر باز نشد
			</span>
		);
	}
	return <img src={ list[ i ] } alt={ alt } loading="lazy" decoding="async" onError={ () => setI( ( n ) => n + 1 ) } className={ className } />;
}

/** The «تأیید شد» ink stamp that lands on the receipt. */
function Stamp() {
	return (
		<motion.div
			initial={ { scale: 2.4, opacity: 0, rotate: -18 } }
			animate={ { scale: 1, opacity: 1, rotate: -12 } }
			transition={ { type: 'spring', stiffness: 520, damping: 18 } }
			className="pointer-events-none absolute inset-0 z-10 flex items-center justify-center"
		>
			<div className="flex size-32 flex-col items-center justify-center rounded-full border-[5px] border-double border-[#16a34a] bg-white/60 text-[#16a34a] backdrop-blur-[1px]">
				<HugeiconsIcon icon={ CheckmarkBadge01Icon } size={ 30 } />
				<span className="mt-1 text-[17px] font-black">تأیید شد</span>
			</div>
		</motion.div>
	);
}

function OrderCard( { row, onChange, onZoom }: { row: OrderRow; onChange: ( row: OrderRow ) => void; onZoom: ( url: string ) => void } ) {
	const [ busy, setBusy ] = useState( false );
	const [ rejecting, setRejecting ] = useState( false );
	const [ reason, setReason ] = useState( '' );
	const [ stamped, setStamped ] = useState( false );
	const [ sure, setSure ] = useState( false );
	const { s } = useStore();
	const last = row.receipts[ row.receipts.length - 1 ];
	const flags = last?.flags ?? [];
	const danger = flags.some( ( f ) => f.level === 'danger' );
	const mustConfirm = danger && last?.state === 'pending' && s.modules?.fraud?.confirm !== false;
	const review = row.status === 'ctc-review';
	const awaiting = row.status === 'ctc-receipt' || row.status === 'on-hold';

	const decide = async ( action: 'approve' | 'reject' ) => {
		// A receipt flagged as a duplicate needs a second click.
		if ( action === 'approve' && mustConfirm && ! sure ) {
			setSure( true );
			return;
		}
		setBusy( true );
		try {
			const next = await api<OrderRow>( `orders/${ row.id }/${ action }`, { method: 'POST', body: { reason } } );
			if ( action === 'approve' ) {
				setStamped( true );
				toast( 'success', `سفارش ${ fa( row.number ) } تأیید شد`, 'پرداخت ثبت شد و سفارش به «در حال انجام» رفت.' );
				setTimeout( () => onChange( next ), 1300 );
			} else {
				toast( 'warning', `رسید سفارش ${ fa( row.number ) } رد شد`, 'مهلت تازه برای مشتری شروع شد و دلیل برایش پیامک می‌شود.' );
				setRejecting( false );
				setReason( '' );
				onChange( next );
			}
		} catch ( e ) {
			toast( 'error', 'انجام نشد', ( e as Error ).message );
		}
		setBusy( false );
	};

	return (
		<motion.article layout initial={ { opacity: 0, y: 12 } } animate={ { opacity: 1, y: 0 } } exit={ { opacity: 0, scale: 0.97 } } className="grid gap-5 rounded-[22px] border border-border bg-card p-4 md:grid-cols-[220px_minmax(0,1fr)] md:p-5">
			<div className="relative aspect-[4/5] overflow-hidden rounded-2xl border border-border bg-muted">
				{ last?.deleted ? (
					<div className="flex size-full flex-col items-center justify-center gap-2 p-4 text-center text-muted-foreground">
						<HugeiconsIcon icon={ File01Icon } size={ 30 } />
						<span className="text-[12px]">فایل طبق تنظیمات نگهداری حذف شده</span>
					</div>
				) : last?.image ? (
					<button type="button" onClick={ () => onZoom( last.url ) } className="group block size-full" aria-label="بزرگ‌نمایی رسید">
						<Pic sources={ [ last.preview, last.thumb, last.url ] } alt={ `رسید سفارش ${ row.number }` } className="size-full object-cover transition-transform duration-500 group-hover:scale-105" />
						<span className="absolute bottom-2 start-2 flex items-center gap-1 rounded-full bg-black/55 px-2.5 py-1 text-[11px] text-white backdrop-blur">
							<HugeiconsIcon icon={ ZoomInAreaIcon } size={ 14 } />
							بزرگ‌نمایی
						</span>
					</button>
				) : last ? (
					<a href={ last.url } target="_blank" rel="noreferrer" className="flex size-full flex-col items-center justify-center gap-2 text-muted-foreground">
						<HugeiconsIcon icon={ File01Icon } size={ 36 } />
						<span className="text-[12px]">دانلود فایل رسید</span>
					</a>
				) : (
					<div className="flex size-full flex-col items-center justify-center gap-2 p-4 text-center text-muted-foreground">
						<HugeiconsIcon icon={ Invoice03Icon } size={ 32 } />
						<span className="text-[12px]">هنوز رسیدی نرسیده</span>
					</div>
				) }
				{ flags.length ? (
					<span className={ cn( 'absolute top-2 start-2 flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-bold text-white shadow', danger ? 'bg-[#e11d48]' : 'bg-[#d97706]' ) }>
						<HugeiconsIcon icon={ Alert02Icon } size={ 14 } />
						{ danger ? 'مشکوک' : 'هشدار' }
					</span>
				) : null }
				{ ( last?.files?.length ?? 0 ) > 1 && ! last?.deleted ? (
					<span className="absolute top-2 end-2 rounded-full bg-black/60 px-2.5 py-1 text-[11px] font-bold text-white backdrop-blur">{ fa( last!.files.length ) } فایل</span>
				) : null }
				<AnimatePresence>{ stamped ? <Stamp /> : null }</AnimatePresence>
			</div>

			<div className="flex min-w-0 flex-col gap-3">
				<div className="flex flex-wrap items-center gap-2">
					<h3 className="text-[16px] font-black">سفارش { fa( row.number ) }</h3>
					<span className={ cn( 'rounded-full px-2.5 py-0.5 text-[11.5px] font-bold', review ? 'bg-[#dbe7ff] text-[#2446a6]' : awaiting ? 'bg-[#fdecc8] text-[#8a5a00]' : 'bg-muted text-muted-foreground' ) }>{ row.label }</span>
					<a href={ row.edit } className="ms-auto inline-flex items-center gap-1 text-[12.5px] text-primary hover:underline">
						صفحه سفارش <HugeiconsIcon icon={ LinkSquare02Icon } size={ 14 } />
					</a>
				</div>
				<dl className="grid grid-cols-2 gap-2 text-[13px] md:grid-cols-3">
					<div className="rounded-2xl bg-muted px-3 py-2">
						<dt className="text-[11.5px] text-muted-foreground">مشتری</dt>
						<dd className="truncate font-bold">{ row.customer || '—' }</dd>
					</div>
					<div className="rounded-2xl bg-muted px-3 py-2">
						<dt className="text-[11.5px] text-muted-foreground">مبلغ</dt>
						<dd className="font-black">
							{ row.total } <small className="font-normal text-muted-foreground">{ row.unit }</small>
						</dd>
					</div>
					<div className="rounded-2xl bg-muted px-3 py-2">
						<dt className="text-[11.5px] text-muted-foreground">موبایل</dt>
						<dd className="font-bold" dir="ltr">{ row.phone ? fa( row.phone ) : '—' }</dd>
					</div>
					{ last?.ref ? (
						<div className="rounded-2xl bg-muted px-3 py-2">
							<dt className="text-[11.5px] text-muted-foreground">شماره پیگیری</dt>
							<dd className="font-bold" dir="ltr">{ fa( last.ref ) }</dd>
						</div>
					) : null }
					{ last?.last4 ? (
						<div className="rounded-2xl bg-muted px-3 py-2">
							<dt className="text-[11.5px] text-muted-foreground">۴ رقم آخر کارت مشتری</dt>
							<dd className="font-bold" dir="ltr">{ fa( last.last4 ) }</dd>
						</div>
					) : null }
					{ Object.entries( last?.fields ?? {} ).map( ( [ label, value ] ) => (
						<div key={ label } className="rounded-2xl bg-muted px-3 py-2">
							<dt className="text-[11.5px] text-muted-foreground">{ label }</dt>
							<dd className="truncate font-bold">{ value }</dd>
						</div>
					) ) }
					<div className="rounded-2xl bg-muted px-3 py-2">
						<dt className="text-[11.5px] text-muted-foreground">{ last ? 'ارسال رسید' : row.deadline ? 'مهلت' : 'ثبت سفارش' }</dt>
						<dd className="font-bold">{ last ? last.time : row.deadline || row.date }</dd>
					</div>
				</dl>
				{ flags.map( ( f, i ) => (
					<div key={ i } className={ cn( 'flex items-start gap-2 rounded-2xl px-3 py-2.5 text-[12.5px] leading-6', f.level === 'danger' ? 'bg-[#e11d48]/8 text-[#9b1c1c] dark:text-rose-300' : 'bg-[#f59e0b]/10 text-[#7a5200] dark:text-amber-300' ) }>
						<HugeiconsIcon icon={ Alert02Icon } size={ 17 } className="mt-1 shrink-0" />
						<span className="min-w-0 flex-1">
							<b>{ f.level === 'danger' ? 'رسید مشکوک: ' : 'هشدار: ' }</b>
							{ f.text }
							{ f.edit ? (
								<a href={ f.edit } target="_blank" rel="noreferrer" className="ms-1 inline-flex items-center gap-0.5 font-bold underline">
									دیدن سفارش { fa( f.number ) } <HugeiconsIcon icon={ LinkSquare02Icon } size={ 13 } />
								</a>
							) : null }
						</span>
					</div>
				) ) }
				{ last && ! last.deleted && last.files.length > 1 ? (
					<div className="flex flex-wrap gap-2">
						{ last.files.map( ( f, i ) =>
							f.image ? (
								<button key={ i } type="button" onClick={ () => onZoom( f.url ) } className="relative size-16 overflow-hidden rounded-xl border border-border bg-muted" aria-label={ `بزرگ‌نمایی فایل ${ fa( i + 1 ) }` }>
									<Pic sources={ [ f.preview, f.thumb, f.url ] } alt="" className="size-full object-cover" />
									<span className="absolute bottom-0.5 start-0.5 rounded-full bg-black/55 px-1.5 text-[10px] text-white">{ fa( i + 1 ) }</span>
								</button>
							) : (
								<a key={ i } href={ f.url } target="_blank" rel="noreferrer" className="flex size-16 flex-col items-center justify-center gap-1 rounded-xl border border-border bg-muted text-[10px] text-muted-foreground" title={ f.name }>
									<HugeiconsIcon icon={ File01Icon } size={ 20 } />
									{ fa( i + 1 ) }
								</a>
							)
						) }
					</div>
				) : null }
				{ row.cards.length ? <p className="text-[12px] text-muted-foreground">واریز به: { row.cards.map( ( c ) => `${ c.bank } ${ fa( c.number ) }` ).join( '، ' ) }</p> : null }
				{ row.receipts.length > 1 ? (
					<p className="text-[12px] text-muted-foreground">
						{ fa( row.receipts.length ) } رسید ارسال شده؛ { fa( row.receipts.filter( ( r ) => r.state === 'rejected' ).length ) } مورد قبلاً رد شده.
					</p>
				) : null }

				{ review || awaiting ? (
					<div className="mt-auto flex flex-wrap items-center gap-2 pt-1">
						<motion.button type="button" disabled={ busy || stamped } onClick={ () => decide( 'approve' ) } whileTap={ { scale: 0.96 } } className={ cn( 'inline-flex items-center gap-2 rounded-full px-5 py-2.5 text-[13.5px] font-bold text-white disabled:opacity-60', sure ? 'bg-[#e11d48] shadow-[0_10px_22px_-12px_#e11d48]' : 'bg-[#16a34a] shadow-[0_10px_22px_-12px_#16a34a]' ) }>
							<HugeiconsIcon icon={ CheckmarkBadge01Icon } size={ 18 } />
							{ sure ? 'با وجود هشدار، تأیید کن' : 'تأیید واریز' }
						</motion.button>
						{ sure ? (
							<button type="button" onClick={ () => setSure( false ) } className="text-[12.5px] text-muted-foreground hover:text-foreground">
								انصراف
							</button>
						) : null }
						{ review ? (
							<button type="button" disabled={ busy } onClick={ () => setRejecting( ( r ) => ! r ) } className="inline-flex items-center gap-2 rounded-full border border-border px-5 py-2.5 text-[13.5px] font-bold text-[#e11d48] hover:bg-[#e11d48]/5">
								<HugeiconsIcon icon={ Cancel01Icon } size={ 16 } />
								رد رسید
							</button>
						) : null }
					</div>
				) : null }
				<AnimatePresence>
					{ rejecting ? (
						<motion.div initial={ { height: 0, opacity: 0 } } animate={ { height: 'auto', opacity: 1 } } exit={ { height: 0, opacity: 0 } } className="overflow-hidden">
							<div className="flex flex-col gap-2 rounded-2xl border border-[#e11d48]/25 bg-[#e11d48]/5 p-3">
								<p className="text-[12.5px] font-bold">دلیل رد (برای مشتری پیامک می‌شود)</p>
								<div className="flex flex-wrap gap-1.5">
									{ REASONS.map( ( r ) => (
										<button key={ r } type="button" onClick={ () => setReason( r ) } className={ cn( 'rounded-full border px-3 py-1 text-[12px]', reason === r ? 'border-[#e11d48] bg-[#e11d48] text-white' : 'border-border bg-card text-muted-foreground hover:text-foreground' ) }>
											{ r }
										</button>
									) ) }
								</div>
								<TextArea value={ reason } onChange={ setReason } rows={ 2 } placeholder="یا دلیل را خودتان بنویسید…" />
								<button type="button" disabled={ busy } onClick={ () => decide( 'reject' ) } className="self-start rounded-full bg-[#e11d48] px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60">
									ثبت رد و اطلاع به مشتری
								</button>
							</div>
						</motion.div>
					) : null }
				</AnimatePresence>
			</div>
		</motion.article>
	);
}

export function Receipts() {
	const [ filter, setFilter ] = useState<Filter>( 'review' );
	const [ rows, setRows ] = useState<OrderRow[] | null>( null );
	const [ error, setError ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ more, setMore ] = useState( false );
	const [ loadingMore, setLoadingMore ] = useState( false );
	const [ zoom, setZoom ] = useState( '' );
	const turn = useRef( 0 );
	const fetchPage = ( p: number ) => api<{ items: OrderRow[]; more: boolean }>( `receipts?status=${ filter }&page=${ p }` );
	const load = useCallback( () => {
		const mine = ++turn.current;
		setRows( null );
		setError( '' );
		setPage( 1 );
		fetchPage( 1 )
			.then( ( r ) => {
				if ( mine !== turn.current ) return; // Another tab was chosen meanwhile.
				setRows( r.items );
				setMore( !! r.more );
			} )
			.catch( ( e ) => {
				if ( mine !== turn.current ) return;
				setRows( [] );
				setError( ( e as Error ).message );
			} );
	}, [ filter ] );
	useEffect( load, [ load ] );
	const loadMore = () => {
		const mine = turn.current;
		setLoadingMore( true );
		fetchPage( page + 1 )
			.then( ( r ) => {
				if ( mine !== turn.current ) return;
				setRows( ( prev ) => [ ...( prev ?? [] ), ...r.items.filter( ( n ) => ! ( prev ?? [] ).some( ( o ) => o.id === n.id ) ) ] );
				setMore( !! r.more );
				setPage( ( p ) => p + 1 );
			} )
			.catch( ( e ) => toast( 'error', 'بارگذاری نشد', ( e as Error ).message ) )
			.finally( () => setLoadingMore( false ) );
	};

	const update = ( next: OrderRow ) => setRows( ( prev ) => ( prev ?? [] ).map( ( r ) => ( r.id === next.id ? next : r ) ).filter( ( r ) => filter === 'all' || filter === 'done' || ( filter === 'review' ? r.status === 'ctc-review' : r.status === 'ctc-receipt' ) ) );

	return (
		<>
			<div className="flex flex-wrap items-center justify-between gap-3">
				<Segmented<Filter>
					value={ filter }
					onChange={ setFilter }
					options={ [
						{ value: 'review', label: 'در انتظار بررسی' },
						{ value: 'awaiting', label: 'در انتظار واریز' },
						{ value: 'done', label: 'انجام‌شده' },
						{ value: 'all', label: 'همه' },
					] }
				/>
				<button type="button" onClick={ load } className="rounded-full border border-border bg-card px-4 py-2 text-[13px] text-muted-foreground hover:text-foreground">
					به‌روزرسانی
				</button>
			</div>
			{ rows === null ? (
				<div className="grid gap-4" aria-busy="true" aria-label="در حال بارگذاری رسیدها">
					{ [ 0, 1 ].map( ( i ) => (
						<div key={ i } className="h-56 animate-pulse rounded-[22px] border border-border bg-card" />
					) ) }
				</div>
			) : error ? (
				<div className="flex flex-col items-center gap-3 rounded-[22px] border border-[#e11d48]/30 bg-[#e11d48]/5 px-6 py-12 text-center" role="alert">
					<span className="flex size-14 items-center justify-center rounded-3xl bg-[#e11d48]/12 text-[#e11d48]">
						<HugeiconsIcon icon={ Alert02Icon } size={ 28 } />
					</span>
					<p className="text-[15px] font-bold">رسیدها بارگذاری نشد</p>
					<p className="max-w-md text-[13px] leading-6 text-muted-foreground">{ error }</p>
					<button type="button" onClick={ load } className="rounded-full bg-primary px-5 py-2 text-[13px] font-bold text-white">
						تلاش دوباره
					</button>
				</div>
			) : rows.length === 0 ? (
				<div className="flex flex-col items-center gap-3 rounded-[22px] border border-dashed border-border bg-card px-6 py-16 text-center">
					<span className="flex size-14 items-center justify-center rounded-3xl bg-[#34C759]/12 text-[#34C759]">
						<HugeiconsIcon icon={ CheckmarkBadge01Icon } size={ 28 } />
					</span>
					<p className="text-[15px] font-bold">چیزی برای نمایش نیست</p>
					<p className="max-w-sm text-[13px] text-muted-foreground">{ filter === 'review' ? 'همه رسیدها بررسی شده‌اند. رسید تازه که برسد، اینجا و در منوی ووکامرس با عدد قرمز نشان داده می‌شود.' : 'سفارشی در این وضعیت نیست.' }</p>
				</div>
			) : (
				<motion.div layout className="grid gap-4">
					<AnimatePresence mode="popLayout">
						{ rows.map( ( row ) => (
							<OrderCard key={ row.id } row={ row } onChange={ update } onZoom={ setZoom } />
						) ) }
					</AnimatePresence>
					{ more ? (
						<button type="button" disabled={ loadingMore } onClick={ loadMore } className="justify-self-center rounded-full border border-border bg-card px-6 py-2.5 text-[13px] font-bold text-muted-foreground hover:text-foreground disabled:opacity-60">
							{ loadingMore ? 'در حال بارگذاری…' : 'نمایش بیشتر' }
						</button>
					) : null }
				</motion.div>
			) }
			<AnimatePresence>
				{ zoom ? (
					<motion.div initial={ { opacity: 0 } } animate={ { opacity: 1 } } exit={ { opacity: 0 } } onClick={ () => setZoom( '' ) } className="fixed inset-0 z-[100001] flex items-center justify-center bg-black/70 p-6 backdrop-blur-sm" role="dialog" aria-label="رسید">
						<motion.img initial={ { scale: 0.92 } } animate={ { scale: 1 } } exit={ { scale: 0.95 } } src={ zoom } alt="رسید" className="max-h-full max-w-full rounded-2xl shadow-2xl" />
					</motion.div>
				) : null }
			</AnimatePresence>
		</>
	);
}

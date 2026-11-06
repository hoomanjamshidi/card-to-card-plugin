/**
 * SMS editors of one event (panel «پیامک»), in the spirit of the Dalan Home theme:
 *  - PatternEditor (pattern mode): the pattern text as registered in Melipayamak ({0} {1} …), each
 *    variable mapped separately in numbered slots (add / reorder / remove, sample value shown), a live
 *    preview with warnings, a «write it with names» composer that turns a free text with {name} {order}
 *    … into the pattern + variables, and the sample to start from;
 *  - TextEditor (line mode): the full text with {tokens}, preview, length in SMS parts, sample reset.
 */
import { useMemo, useState } from 'react';
import { AnimatePresence, motion } from 'motion/react';
import { HugeiconsIcon } from '@hugeicons/react';
import { Alert02Icon, ArrowDown01Icon, ArrowUp01Icon, Cancel01Icon, Copy01Icon, MagicWand01Icon, PlusSignIcon, RefreshIcon } from '@hugeicons/core-free-icons';
import type { EventDef, SmsEventConf } from '../lib/types';
import { boot, cn, fa } from '../lib/utils';
import { TextArea, TextInput, TokenArea } from '../ui/Field';
import { toast } from '../ui/Toasts';

const KEY = '1024-0123456789abcdef';

/** Sample values of the tokens (preview only). */
export const SAMPLE: Record<string, string> = {
	name: 'سارا محمدی',
	first_name: 'سارا',
	order: '1024',
	total: '۱٬۴۶۳٬۰۰۰ تومان',
	link: `${ boot().payBase }${ KEY }${ boot().pretty ? '/' : '' }`,
	key: KEY,
	deadline: '۱۵ آبان، ۱۸:۳۰',
	card: '6104 3379 1234 5678',
	bank: 'بانک ملت',
	holder: boot().site,
	reason: 'مبلغ واریزی کمتر از مبلغ سفارش است',
	shop: boot().site,
	mobile: '09121234567',
};

/** Named tokens of a variable list «{name} {order}». */
export const parseVars = ( vars: string ) => [ ...vars.toLowerCase().matchAll( /\{([a-z0-9_]+)\}/g ) ].map( ( m ) => m[ 1 ] );
const joinVars = ( names: string[] ) => names.map( ( n ) => `{${ n }}` ).join( ' ' );

/** Full text with sample values. */
export const fillNamed = ( text: string ) => text.replace( /\{([a-z0-9_]+)\}/g, ( m, k ) => SAMPLE[ k ] ?? m );

/** Persian SMS: 70 characters in one message, 67 per part after that (UCS-2). Latin only: 160 / 153. */
const smsParts = ( text: string ) => {
	const ucs = /[^\x00-\x7F]/.test( text );
	const len = [ ...text ].length;
	const one = ucs ? 70 : 160;
	const part = ucs ? 67 : 153;
	return { len, parts: len === 0 ? 0 : len <= one ? 1 : Math.ceil( len / part ) };
};

function Counter( { text }: { text: string } ) {
	const { len, parts } = smsParts( text );
	return (
		<span className="text-[11.5px] text-muted-foreground">
			{ fa( len ) } نویسه · { fa( parts ) } پیامک
		</span>
	);
}

const copyText = ( text: string ) =>
	navigator.clipboard
		?.writeText( text )
		.then( () => toast( 'success', 'کپی شد', 'متن الگو را در پنل ملی‌پیامک بگذارید.' ) )
		.catch( () => toast( 'error', 'کپی نشد', 'متن را دستی انتخاب و کپی کنید.' ) );

const smallBtn = 'inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-3 py-1.5 text-[12px] text-muted-foreground transition-colors hover:border-primary/40 hover:text-primary';

/**
 * Turn a free text with named tokens into a Melipayamak pattern: every distinct token becomes the
 * next {n}; {link} becomes the payment page address + the {key} variable (links are refused inside
 * variables).
 */
export const toPattern = ( text: string ) => {
	const names: string[] = [];
	const pattern = text.replace( /\{([a-z0-9_]+)\}/g, ( _m, raw: string ) => {
		const token = raw === 'link' ? 'key' : raw;
		let i = names.indexOf( token );
		if ( i < 0 ) {
			names.push( token );
			i = names.length - 1;
		}
		return raw === 'link' ? `${ boot().payBase }{${ i }}` : `{${ i }}`;
	} );
	return { pattern, vars: joinVars( names ) };
};

/** Numbered variable slots: {0} → token, each sent as its own variable. */
function VarSlots( { names, onChange, used }: { names: string[]; onChange: ( names: string[] ) => void; used: Set<number> | null } ) {
	const [ picking, setPicking ] = useState( false );
	const [ q, setQ ] = useState( '' );
	const tokens = boot().tokens;
	const move = ( i: number, to: number ) => {
		if ( to < 0 || to >= names.length ) return;
		const next = [ ...names ];
		[ next[ i ], next[ to ] ] = [ next[ to ], next[ i ] ];
		onChange( next );
	};
	const list = Object.entries( tokens ).filter( ( [ k, label ] ) => ! q || label.includes( q ) || k.includes( q.toLowerCase() ) );
	return (
		<div className="flex flex-col gap-2">
			<p className="text-[12.5px] font-bold">متغیرها، به ترتیب الگو</p>
			{ names.length ? (
				<ol className="flex flex-col gap-1.5">
					{ names.map( ( name, i ) => {
						const known = name in tokens;
						return (
							<li key={ `${ name }-${ i }` } className={ cn( 'flex items-center gap-2.5 rounded-xl border bg-card px-2.5 py-2', known ? 'border-border' : 'border-[#e11d48]/40 bg-[#e11d48]/5', used && ! used.has( i ) && 'opacity-60' ) }>
								<b dir="ltr" className="flex h-7 min-w-9 items-center justify-center rounded-lg bg-primary/10 px-1.5 text-[12.5px] text-primary">{ `{${ i }}` }</b>
								<span className="min-w-0 flex-1">
									<span className="flex flex-wrap items-center gap-1.5 text-[13px] font-bold">
										{ known ? tokens[ name ] : `{${ name }}` }
										<code dir="ltr" className="text-[11px] font-normal text-muted-foreground">{ `{${ name }}` }</code>
										{ name === 'link' ? (
											<span className="inline-flex items-center gap-1 rounded-full bg-[#f59e0b]/15 px-2 text-[11px] font-normal text-[#92400e] dark:text-amber-300">
												<HugeiconsIcon icon={ Alert02Icon } size={ 12 } /> لینک در متغیر رد می‌شود؛ {`{key}`} بگذارید
											</span>
										) : null }
									</span>
									<span className="block truncate text-[11.5px] text-muted-foreground">{ known ? `نمونه: ${ SAMPLE[ name ] ?? '—' }` : 'این متغیر تعریف نشده و خالی ارسال می‌شود.' }</span>
								</span>
								<span className="flex shrink-0 items-center gap-0.5">
									<button type="button" disabled={ i === 0 } onClick={ () => move( i, i - 1 ) } aria-label="بالا" className="flex size-7 items-center justify-center rounded-lg text-muted-foreground hover:bg-accent disabled:opacity-30">
										<HugeiconsIcon icon={ ArrowUp01Icon } size={ 15 } />
									</button>
									<button type="button" disabled={ i === names.length - 1 } onClick={ () => move( i, i + 1 ) } aria-label="پایین" className="flex size-7 items-center justify-center rounded-lg text-muted-foreground hover:bg-accent disabled:opacity-30">
										<HugeiconsIcon icon={ ArrowDown01Icon } size={ 15 } />
									</button>
									<button type="button" onClick={ () => onChange( names.filter( ( _n, j ) => j !== i ) ) } aria-label="حذف" className="flex size-7 items-center justify-center rounded-lg text-muted-foreground hover:bg-[#e11d48]/10 hover:text-[#e11d48]">
										<HugeiconsIcon icon={ Cancel01Icon } size={ 15 } />
									</button>
								</span>
							</li>
						);
					} ) }
				</ol>
			) : (
				<p className="rounded-xl border border-dashed border-border px-3 py-3 text-[12px] text-muted-foreground">هنوز متغیری انتخاب نشده؛ با «افزودن متغیر» به همان ترتیبی که در متن الگو آمده انتخاب کنید.</p>
			) }
			<button type="button" onClick={ () => setPicking( ( p ) => ! p ) } className={ cn( smallBtn, 'self-start' ) } aria-expanded={ picking }>
				<HugeiconsIcon icon={ picking ? Cancel01Icon : PlusSignIcon } size={ 14 } />
				{ picking ? 'بستن' : 'افزودن متغیر' }
			</button>
			<AnimatePresence initial={ false }>
				{ picking ? (
					<motion.div initial={ { height: 0, opacity: 0 } } animate={ { height: 'auto', opacity: 1 } } exit={ { height: 0, opacity: 0 } } className="overflow-hidden">
						<div className="flex flex-col gap-2 rounded-2xl border border-border bg-muted/50 p-2.5">
							<TextInput value={ q } onChange={ setQ } placeholder="جستجوی متغیر…" aria-label="جستجوی متغیر" />
							<div className="grid gap-1.5 sm:grid-cols-2">
								{ list.map( ( [ k, label ] ) => (
									<button key={ k } type="button" onClick={ () => onChange( [ ...names, k ] ) } className="flex flex-col items-start gap-0.5 rounded-xl border border-border bg-card px-3 py-2 text-start transition-colors hover:border-primary/40 hover:bg-primary/5">
										<span className="flex w-full items-center justify-between gap-2 text-[12.5px] font-bold">
											{ label }
											<code dir="ltr" className="text-[11px] font-normal text-muted-foreground">{ `{${ k }}` }</code>
										</span>
										<span className="w-full truncate text-[11.5px] text-muted-foreground">{ SAMPLE[ k ] ?? '—' }</span>
									</button>
								) ) }
							</div>
						</div>
					</motion.div>
				) : null }
			</AnimatePresence>
		</div>
	);
}

/** Pattern mode: bodyId, pattern text, separate variables, preview + checks. */
export function PatternEditor( { def, conf, update }: { def: EventDef; conf: SmsEventConf; update: ( patch: Partial<SmsEventConf> ) => void } ) {
	const names = parseVars( conf.vars );
	const [ compose, setCompose ] = useState( false );
	const [ draft, setDraft ] = useState( conf.text || def.text );

	const check = useMemo( () => {
		const text = conf.pattern.trim();
		if ( ! text ) return { used: null as Set<number> | null, notes: [] as string[] };
		const used = new Set<number>();
		const notes: string[] = [];
		text.replace( /\{(\d+)\}/g, ( _m, n ) => {
			used.add( Number( n ) );
			return '';
		} );
		[ ...used ].sort( ( a, b ) => a - b ).forEach( ( n ) => {
			if ( names[ n ] === undefined ) notes.push( `متن الگو {${ fa( n ) }} دارد ولی متغیری برایش انتخاب نشده؛ خالی ارسال می‌شود.` );
		} );
		names.forEach( ( name, i ) => {
			if ( ! used.has( i ) ) notes.push( `{${ fa( i ) }} (${ boot().tokens[ name ] ?? name }) انتخاب شده ولی در متن الگو نیامده.` );
		} );
		if ( /\{[a-z_]+\}/i.test( text ) ) notes.push( 'در متن الگوی ملی‌پیامک به‌جای نام متغیر (مثل {name}) شماره‌اش را بنویسید: {0}، {1}، … — یا از «نوشتن با نام متغیرها» استفاده کنید.' );
		return { used, notes };
	}, [ conf.pattern, conf.vars ] );

	const parts = useMemo( () => {
		const out: { t: string; mark?: 'ok' | 'err' }[] = [];
		let last = 0;
		conf.pattern.replace( /\{(\d+)\}/g, ( m, n, offset: number ) => {
			out.push( { t: conf.pattern.slice( last, offset ) } );
			const name = names[ Number( n ) ];
			out.push( name === undefined ? { t: m, mark: 'err' } : { t: SAMPLE[ name ] ?? `{${ name }}`, mark: 'ok' } );
			last = offset + m.length;
			return m;
		} );
		out.push( { t: conf.pattern.slice( last ) } );
		return out;
	}, [ conf.pattern, conf.vars ] );
	const previewText = parts.map( ( p ) => p.t ).join( '' );

	const sample = () => update( { vars: def.vars, pattern: def.pattern } );
	const apply = () => {
		const out = toPattern( draft );
		update( out );
		setCompose( false );
		toast( 'success', 'الگو ساخته شد', 'متن الگو را کپی کنید و در پنل ملی‌پیامک ثبت کنید؛ بعد از تأیید، کد الگو را اینجا بگذارید.' );
	};

	return (
		<div className="flex flex-col gap-4">
			<ol className="grid gap-1 rounded-2xl bg-primary/5 px-4 py-3 text-[12px] leading-6 text-foreground xl:grid-cols-3">
				<li><b>۱.</b> متن الگو را بنویسید (یا نمونه را نگه دارید) و کپی کنید.</li>
				<li><b>۲.</b> در پنل ملی‌پیامک «الگوی خدماتی» بسازید و همین متن را بگذارید.</li>
				<li><b>۳.</b> کد الگوی تأییدشده (bodyId) را اینجا وارد کنید.</li>
			</ol>

			<label className="flex max-w-[240px] flex-col gap-1.5 text-[12.5px] font-bold">
				کد الگو (bodyId)
				<TextInput value={ conf.body } onChange={ ( v ) => update( { body: v.replace( /\D/g, '' ) } ) } dir="ltr" inputMode="numeric" placeholder="123456" />
			</label>

			<div className="flex flex-col gap-1.5">
				<div className="flex flex-wrap items-center justify-between gap-2">
					<p className="text-[12.5px] font-bold">متن الگو</p>
					<span className="flex flex-wrap items-center gap-1.5">
						<button type="button" onClick={ () => copyText( conf.pattern ) } disabled={ ! conf.pattern.trim() } className={ cn( smallBtn, 'disabled:opacity-40' ) }>
							<HugeiconsIcon icon={ Copy01Icon } size={ 14 } /> کپی
						</button>
						<button type="button" onClick={ () => setCompose( ( c ) => ! c ) } className={ smallBtn } aria-expanded={ compose }>
							<HugeiconsIcon icon={ MagicWand01Icon } size={ 14 } /> نوشتن با نام متغیرها
						</button>
						<button type="button" onClick={ sample } className={ smallBtn }>
							<HugeiconsIcon icon={ RefreshIcon } size={ 14 } /> متن نمونه
						</button>
					</span>
				</div>
				<AnimatePresence initial={ false }>
					{ compose ? (
						<motion.div initial={ { height: 0, opacity: 0 } } animate={ { height: 'auto', opacity: 1 } } exit={ { height: 0, opacity: 0 } } className="overflow-hidden">
							<div className="mb-2 flex flex-col gap-2 rounded-2xl border border-primary/25 bg-primary/5 p-3">
								<p className="text-[12px] leading-6 text-muted-foreground">
									پیامک را با نام متغیرها بنویسید (مثل «{`{name}`} عزیز، سفارش {`{order}`} …»). «ساخت الگو» هر متغیر را به شماره الگو ({`{0}`}، {`{1}`} …) تبدیل می‌کند و ترتیب متغیرها را خودش می‌چیند. {`{link}`} به آدرس صفحه پرداخت + {`{key}`} تبدیل می‌شود.
								</p>
								<TokenArea value={ draft } onChange={ setDraft } tokens={ boot().tokens } rows={ 3 } />
								<button type="button" onClick={ apply } disabled={ ! draft.trim() } className="self-start rounded-full bg-primary px-4 py-2 text-[12.5px] font-bold text-white disabled:opacity-50">
									ساخت الگو و متغیرها
								</button>
							</div>
						</motion.div>
					) : null }
				</AnimatePresence>
				<TextArea value={ conf.pattern } onChange={ ( v ) => update( { pattern: v } ) } rows={ 3 } placeholder="{0} عزیز، سفارش {1} ثبت شد." />
				<p className="text-[11.5px] text-muted-foreground">همان متنی که در ملی‌پیامک ثبت می‌کنید؛ اینجا فقط برای پیش‌نمایش و کپی است و خودِ متن ارسال نمی‌شود — فقط متغیرها جداگانه فرستاده می‌شوند.</p>
			</div>

			<VarSlots names={ names } used={ check.used } onChange={ ( next ) => update( { vars: joinVars( next ) } ) } />

			<div className="rounded-2xl bg-muted p-3">
				<div className="mb-1 flex items-center justify-between gap-2">
					<p className="text-[11.5px] font-bold text-muted-foreground">پیش‌نمایش با مقادیر نمونه</p>
					{ conf.pattern.trim() ? <Counter text={ previewText } /> : null }
				</div>
				{ conf.pattern.trim() ? (
					<p className="whitespace-pre-line text-[13px] leading-7">
						{ parts.map( ( p, i ) =>
							p.mark ? (
								<mark key={ i } dir={ p.mark === 'err' ? 'ltr' : undefined } className={ cn( 'rounded px-1', p.mark === 'ok' ? 'bg-primary/15 text-foreground' : 'bg-[#e11d48]/15 text-[#e11d48]' ) }>
									{ p.t }
								</mark>
							) : (
								<span key={ i }>{ p.t }</span>
							)
						) }
					</p>
				) : (
					<p className="text-[12.5px] leading-6">
						{ names.map( ( n, i ) => (
							<span key={ i } className="me-2 inline-block">
								<b dir="ltr" className="text-primary">{ `{${ i }}` }</b> = { SAMPLE[ n ] ?? '—' }
							</span>
						) ) }
						<span className="block text-muted-foreground">برای دیدن متن کامل، متن الگو را بنویسید یا «متن نمونه» را بزنید.</span>
					</p>
				) }
				{ check.notes.length ? (
					<ul className="mt-2 flex flex-col gap-1 border-t border-border pt-2">
						{ check.notes.map( ( n, i ) => (
							<li key={ i } className="flex items-start gap-1.5 text-[12px] leading-6 text-[#92400e] dark:text-amber-300">
								<HugeiconsIcon icon={ Alert02Icon } size={ 14 } className="mt-1 shrink-0" />
								{ n }
							</li>
						) ) }
					</ul>
				) : null }
			</div>
		</div>
	);
}

/** Line mode: the full text, preview and length. */
export function TextEditor( { def, conf, update }: { def: EventDef; conf: SmsEventConf; update: ( patch: Partial<SmsEventConf> ) => void } ) {
	const filled = fillNamed( conf.text );
	return (
		<div className="flex flex-col gap-3">
			<div className="flex flex-wrap items-center justify-between gap-2">
				<p className="text-[12.5px] font-bold">متن پیامک</p>
				<button type="button" onClick={ () => update( { text: def.text } ) } disabled={ conf.text === def.text } className={ cn( smallBtn, 'disabled:opacity-40' ) }>
					<HugeiconsIcon icon={ RefreshIcon } size={ 14 } /> بازگشت به متن نمونه
				</button>
			</div>
			<TokenArea value={ conf.text } onChange={ ( v ) => update( { text: v } ) } tokens={ boot().tokens } rows={ 4 } />
			<div className="rounded-2xl bg-muted p-3">
				<div className="mb-1 flex items-center justify-between gap-2">
					<p className="text-[11.5px] font-bold text-muted-foreground">پیش‌نمایش پیامک</p>
					<Counter text={ filled } />
				</div>
				<p className="whitespace-pre-line text-[13px] leading-7">{ filled }</p>
			</div>
		</div>
	);
}

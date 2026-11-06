/**
 * SMS: Melipayamak in two modes — pattern (shared service line, approved pattern + variables) or
 * line (full text from your own dedicated / shared sender number) — a connection test with credit,
 * a test message, every event and the send log.
 */
import { useEffect, useState } from 'react';
import { AnimatePresence, motion } from 'motion/react';
import { HugeiconsIcon } from '@hugeicons/react';
import { Message01Icon, Wifi01Icon, WifiDisconnected01Icon, SentIcon, Key01Icon, Notification03Icon, Clock01Icon, ArrowDown01Icon } from '@hugeicons/core-free-icons';
import { api } from '../lib/api';
import { useStore } from '../lib/store';
import type { SmsEventConf } from '../lib/types';
import { boot, cn, fa } from '../lib/utils';
import { NumberTicker } from '../ui/NumberTicker';
import { Row, Section, SwitchRow, TextInput } from '../ui/Field';
import { PatternEditor, TextEditor, fillNamed, parseVars } from '../components/SmsPattern';
import { Segmented } from '../ui/Segmented';
import { SoftSelect } from '../ui/SoftSelect';
import { SoftSwitch } from '../ui/SoftSwitch';
import { toast } from '../ui/Toasts';

type Test = { ok: boolean; credit: number | null; message: string; sent: boolean | null };
type LogEntry = { t: number; event: string; to: string; mode: string; text: string; ok: boolean; msg: string };

function EventRow( { id, mode }: { id: string; mode: 'pattern' | 'line' } ) {
	const { s, set } = useStore();
	const def = boot().events[ id ];
	const saved = s.sms_events[ id ] ?? {};
	const savedVars = ( saved.vars ?? '' ).trim();
	const conf: SmsEventConf = {
		on: saved.on ?? def.on,
		body: saved.body ?? '',
		vars: savedVars || def.vars,
		// Same rule as Events::conf(): a pattern configured before pattern texts existed keeps its
		// variables and gets no sample text that would not match them.
		pattern: ( saved.pattern ?? '' ).trim() ? ( saved.pattern as string ) : savedVars ? '' : def.pattern,
		text: ( saved.text ?? '' ).trim() ? ( saved.text as string ) : def.text,
	};
	const [ open, setOpen ] = useState( false );
	const update = ( patch: Partial<SmsEventConf> ) => set( 'sms_events', { ...s.sms_events, [ id ]: { ...conf, ...patch } } );
	const summary = mode === 'pattern' ? ( conf.body ? `الگو ${ fa( conf.body ) } · ${ fa( parseVars( conf.vars ).length ) } متغیر` : 'کد الگو وارد نشده' ) : fillNamed( conf.text ).split( '\n' )[ 0 ];
	return (
		<div className={ cn( 'rounded-2xl border transition-colors', open ? 'border-border bg-background/60' : 'border-transparent' ) }>
			<div className="flex items-center gap-3 px-3 py-3">
				<span className={ cn( 'flex size-9 shrink-0 items-center justify-center rounded-xl', def.to === 'admin' ? 'bg-[#f97316]/12 text-[#f97316]' : 'bg-primary/10 text-primary' ) }>
					<HugeiconsIcon icon={ def.to === 'admin' ? Notification03Icon : Message01Icon } size={ 18 } />
				</span>
				<button type="button" onClick={ () => setOpen( ( o ) => ! o ) } className="min-w-0 flex-1 text-start">
					<p className="text-[14px] font-bold">{ def.label }</p>
					<p className="truncate text-[12px] text-muted-foreground">
						{ def.to === 'admin' ? 'به مدیر' : 'به مشتری' } — { summary }
					</p>
				</button>
				<button type="button" onClick={ () => setOpen( ( o ) => ! o ) } aria-label="ویرایش" className="flex h-8 items-center gap-1 rounded-full px-2.5 text-[12px] text-muted-foreground hover:bg-accent">
					{ open ? 'بستن' : 'ویرایش' }
					<motion.span className="flex items-center justify-center" animate={ { rotate: open ? 180 : 0 } }>
						<HugeiconsIcon icon={ ArrowDown01Icon } size={ 16 } />
					</motion.span>
				</button>
				<SoftSwitch checked={ conf.on } onCheckedChange={ ( v ) => update( { on: v } ) } label={ def.label } />
			</div>
			<AnimatePresence initial={ false }>
				{ open ? (
					<motion.div initial={ { height: 0, opacity: 0 } } animate={ { height: 'auto', opacity: 1 } } exit={ { height: 0, opacity: 0 } } className="overflow-hidden">
						<div className="flex flex-col gap-3 px-3 pb-4">
							{ def.desc ? <p className="rounded-xl bg-[#f59e0b]/10 px-3 py-2 text-[12px] leading-6 text-foreground">{ def.desc }</p> : null }
							{ mode === 'pattern' ? <PatternEditor def={ def } conf={ conf } update={ update } /> : <TextEditor def={ def } conf={ conf } update={ update } /> }
						</div>
					</motion.div>
				) : null }
			</AnimatePresence>
		</div>
	);
}

export function Sms() {
	const { s, set } = useStore();
	const [ test, setTest ] = useState<Test | null>( null );
	const [ testing, setTesting ] = useState( false );
	const [ to, setTo ] = useState( '' );
	const [ event, setEvent ] = useState( 'placed' );
	const [ log, setLog ] = useState<LogEntry[]>( [] );
	const loadLog = () => api<LogEntry[]>( 'sms/log' ).then( setLog ).catch( () => {} );
	useEffect( () => {
		loadLog();
	}, [] );

	const run = async ( send: boolean ) => {
		setTesting( true );
		try {
			// Test with the saved settings: save first so credentials typed here are used.
			await api( 'settings', { method: 'POST', body: s } );
			const r = await api<Test>( 'sms/test', { method: 'POST', body: { to: send ? to : '', event } } );
			setTest( r );
			toast( r.ok ? 'success' : 'error', r.ok ? 'اتصال برقرار است' : 'اتصال برقرار نشد', r.message );
			loadLog();
		} catch ( e ) {
			toast( 'error', 'آزمایش انجام نشد', ( e as Error ).message );
		}
		setTesting( false );
	};

	const mp = s.sms_driver === 'melipayamak';
	return (
		<>
			<Section title="سرویس پیامک" icon={ Message01Icon } desc="پیامک‌های مرحله‌به‌مرحله کارت به کارت: لینک پرداخت، دریافت/تأیید/رد رسید، یادآوری و لغو خودکار.">
				<Row label="سرویس">
					<Segmented
						value={ s.sms_driver }
						onChange={ ( v ) => set( 'sms_driver', v ) }
						options={ [
							{ value: 'off', label: 'خاموش' },
							{ value: 'log', label: 'آزمایشی (لاگ)' },
							{ value: 'melipayamak', label: 'ملی‌پیامک' },
						] }
					/>
				</Row>
				{ mp ? (
					<>
						<Row label="روش ارسال" help={ s.mp_mode === 'pattern' ? 'پترن (خط خدماتی اشتراکی): برای هر پیامک یک الگوی تأییدشده در پنل ملی‌پیامک می‌سازید و فقط متغیرها ارسال می‌شوند. سریع و بدون نیاز به خط اختصاصی.' : 'خط (ارسال ساده): متن کامل از شماره خط شما (اختصاصی یا اشتراکی) فرستاده می‌شود؛ متن‌ها را همین‌جا می‌نویسید. ارسال لینک ممکن است نیاز به هماهنگی با ملی‌پیامک داشته باشد.' }>
							<Segmented
								value={ s.mp_mode }
								onChange={ ( v ) => set( 'mp_mode', v ) }
								options={ [
									{ value: 'pattern', label: 'پترن (الگو)' },
									{ value: 'line', label: 'خط اختصاصی / اشتراکی' },
								] }
							/>
						</Row>
						<Row label="نام کاربری پنل">
							<TextInput value={ s.mp_user } onChange={ ( v ) => set( 'mp_user', v ) } dir="ltr" autoComplete="off" aria-label="نام کاربری" />
						</Row>
						<Row label="رمز یا کلید API" help="اگر برای پنل کلید API ساخته‌اید، همان را وارد کنید. رمز ذخیره‌شده دوباره نمایش داده نمی‌شود.">
							<TextInput value={ s.mp_pass } onChange={ ( v ) => set( 'mp_pass', v ) } dir="ltr" type="password" autoComplete="new-password" aria-label="رمز" />
						</Row>
						{ s.mp_mode === 'line' ? (
							<Row label="شماره خط فرستنده" help="شماره خط اختصاصی یا اشتراکی پنل، مثل 5000xxxx یا 3000xxxx.">
								<TextInput value={ s.mp_from } onChange={ ( v ) => set( 'mp_from', v ) } dir="ltr" inputMode="numeric" aria-label="شماره خط" />
							</Row>
						) : null }
					</>
				) : null }
				{ s.sms_driver !== 'off' ? (
					<Row label="موبایل مدیر" help="برای پیامک‌های «به مدیر»؛ چند شماره را با کاما جدا کنید.">
						<TextInput value={ s.sms_admins } onChange={ ( v ) => set( 'sms_admins', v ) } dir="ltr" placeholder="0912…, 0935…" aria-label="موبایل مدیر" />
					</Row>
				) : null }
			</Section>

			{ s.sms_driver !== 'off' ? (
				<Section title="آزمایش اتصال" icon={ Key01Icon } desc="اتصال به پنل و اعتبار را بررسی کنید یا یک پیامک آزمایشی بفرستید (تنظیمات همین صفحه اول ذخیره می‌شود).">
					<div className="grid gap-4 md:grid-cols-[260px_minmax(0,1fr)]">
						<div className={ cn( 'relative flex flex-col items-center justify-center gap-2 overflow-hidden rounded-2xl border p-5 text-center', test ? ( test.ok ? 'border-[#34C759]/40 bg-[#34C759]/8' : 'border-[#e11d48]/40 bg-[#e11d48]/6' ) : 'border-dashed border-border' ) }>
							<motion.span key={ String( test?.ok ) } initial={ { scale: 0.6, opacity: 0 } } animate={ { scale: 1, opacity: 1 } } className={ cn( 'flex size-14 items-center justify-center rounded-3xl', test ? ( test.ok ? 'bg-[#34C759] text-white' : 'bg-[#e11d48] text-white' ) : 'bg-muted text-muted-foreground' ) }>
								<HugeiconsIcon icon={ test && ! test.ok ? WifiDisconnected01Icon : Wifi01Icon } size={ 26 } />
							</motion.span>
							<p className="text-[14px] font-bold">{ test ? ( test.ok ? 'متصل' : 'قطع' ) : 'هنوز آزمایش نشده' }</p>
							{ test?.credit !== null && test?.credit !== undefined ? (
								<p className="text-[13px] text-muted-foreground">
									اعتبار: <NumberTicker value={ test.credit } decimalPlaces={ test.credit % 1 ? 1 : 0 } className="font-black text-foreground" />
								</p>
							) : null }
							{ test ? <p className="text-[12px] leading-6 text-muted-foreground">{ test.message }</p> : null }
						</div>
						<div className="flex flex-col gap-3">
							<button type="button" disabled={ testing } onClick={ () => run( false ) } className="inline-flex items-center justify-center gap-2 rounded-2xl border border-border bg-card px-4 py-3 text-[13.5px] font-bold hover:bg-accent disabled:opacity-60">
								<HugeiconsIcon icon={ Wifi01Icon } size={ 18 } />
								{ testing ? 'در حال بررسی…' : 'بررسی اتصال و اعتبار' }
							</button>
							<div className="grid gap-2 md:grid-cols-2">
								<TextInput value={ to } onChange={ setTo } dir="ltr" inputMode="tel" placeholder="09…" aria-label="شماره آزمایشی" />
								<SoftSelect value={ event } onValueChange={ setEvent } options={ Object.entries( boot().events ).map( ( [ k, e ] ) => ( { value: k, label: e.label } ) ) } />
							</div>
							<button type="button" disabled={ testing || to.replace( /\D/g, '' ).length < 10 } onClick={ () => run( true ) } className="inline-flex items-center justify-center gap-2 rounded-2xl bg-primary px-4 py-3 text-[13.5px] font-bold text-white disabled:opacity-50">
								<HugeiconsIcon icon={ SentIcon } size={ 18 } />
								ارسال پیامک آزمایشی
							</button>
						</div>
					</div>
				</Section>
			) : null }

			{ s.sms_driver !== 'off' ? (
				<Section title="پیامک‌ها" icon={ Notification03Icon } desc={ s.mp_mode === 'pattern' && mp ? 'هر پیامک: متن الگو (نمونه آماده است)، کد الگوی تأییدشده و متغیرها که هر کدام جداگانه ارسال می‌شوند. روی «ویرایش» بزنید.' : 'متن هر پیامک را خودتان بنویسید (متن نمونه آماده است)؛ متغیرها هنگام ارسال پر می‌شوند. روی «ویرایش» بزنید.' }>
					{ Object.keys( boot().events ).map( ( id ) => (
						<EventRow key={ id } id={ id } mode={ mp ? s.mp_mode : 'line' } />
					) ) }
				</Section>
			) : null }

			{ s.sms_driver !== 'off' ? (
				<Section
					title="گزارش ارسال"
					icon={ Clock01Icon }
					actions={
						log.length ? (
							<button type="button" onClick={ () => api( 'sms/log', { method: 'DELETE' } ).then( () => setLog( [] ) ) } className="rounded-full border border-border px-3 py-1.5 text-[12px] text-muted-foreground hover:text-foreground">
								پاک کردن
							</button>
						) : null
					}
				>
					<SwitchRow label="ثبت گزارش ارسال" checked={ s.sms_log } onChange={ ( v ) => set( 'sms_log', v ) } />
					{ log.length ? (
						<div className="overflow-x-auto">
							<table className="w-full min-w-[620px] text-[12.5px]">
								<thead>
									<tr className="text-muted-foreground">
										<th className="px-3 py-2 text-start font-medium">زمان</th>
										<th className="px-3 py-2 text-start font-medium">رویداد</th>
										<th className="px-3 py-2 text-start font-medium">گیرنده</th>
										<th className="px-3 py-2 text-start font-medium">نتیجه</th>
									</tr>
								</thead>
								<tbody>
									{ log.slice( 0, 30 ).map( ( e, i ) => (
										<tr key={ i } className="border-t border-border">
											<td className="px-3 py-2 whitespace-nowrap">{ new Date( e.t * 1000 ).toLocaleString( 'fa-IR', { dateStyle: 'short', timeStyle: 'short' } ) }</td>
											<td className="px-3 py-2">{ e.event === 'test' ? 'آزمایشی' : boot().events[ e.event ]?.label ?? e.event }</td>
											<td className="px-3 py-2" dir="ltr">{ fa( e.to ) }</td>
											<td className={ cn( 'px-3 py-2', e.ok ? 'text-[#16a34a]' : 'text-[#e11d48]' ) }>{ e.ok ? 'ارسال شد' : e.msg }</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					) : (
						<p className="px-3 text-[12.5px] text-muted-foreground">هنوز پیامکی ارسال نشده.</p>
					) }
				</Section>
			) : null }
		</>
	);
}

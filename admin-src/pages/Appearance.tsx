/**
 * Appearance of the payment page: layout, colour scheme, accent, font, card effects — with a live
 * mini preview.
 */
import { motion } from 'motion/react';
import { HugeiconsIcon } from '@hugeicons/react';
import { LayoutTwoColumnIcon, PaintBoardIcon, Tick02Icon } from '@hugeicons/core-free-icons';
import { useStore } from '../lib/store';
import type { Card } from '../lib/types';
import { boot, cn, ink } from '../lib/utils';
import { ThemeSwatches } from '../ui/ThemeSwatches';
import { Row, Section, SwitchRow } from '../ui/Field';
import { Segmented } from '../ui/Segmented';
import { BankCard } from '../components/BankCard';

const ACCENTS = [ '#4f46e5', '#0ea5e9', '#16a34a', '#e11d48', '#f97316', '#9333ea', '#0f766e', '#96643a', '#111827' ];

const SAMPLE: Card = { id: 's', number: '6104337912345678', holder: 'فروشگاه نمونه', sheba: '', account: '', bank: '', skin: 'bank', color1: '#4f46e5', color2: '#0ea5e9', active: true };

function LayoutOption( { on, title, desc, onClick, standalone }: { on: boolean; title: string; desc: string; onClick: () => void; standalone?: boolean } ) {
	return (
		<button type="button" onClick={ onClick } className={ cn( 'relative flex flex-1 flex-col gap-3 rounded-2xl border p-3 text-start transition-colors', on ? 'border-primary ring-4 ring-primary/10' : 'border-border hover:border-foreground/20' ) }>
			<div className="flex h-24 flex-col gap-1.5 overflow-hidden rounded-xl border border-border bg-muted p-2">
				{ ! standalone ? <div className="h-3 rounded bg-foreground/15" /> : <div className="h-2 w-10 rounded bg-foreground/20" /> }
				<div className="flex flex-1 gap-1.5">
					<div className="flex-1 rounded-md bg-card" />
					<div className="w-1/3 rounded-md bg-card" />
				</div>
				{ ! standalone ? <div className="h-3 rounded bg-foreground/15" /> : null }
			</div>
			<div>
				<p className="text-[13.5px] font-bold">{ title }</p>
				<p className="text-[12px] text-muted-foreground">{ desc }</p>
			</div>
			{ on ? (
				<span className="absolute top-2 end-2 flex size-6 items-center justify-center rounded-full bg-primary text-white">
					<HugeiconsIcon icon={ Tick02Icon } size={ 14 } />
				</span>
			) : null }
		</button>
	);
}

export function Appearance() {
	const { s, set, patch } = useStore();
	const theme = s.accent_source === 'theme' ? boot().themeColors.find( ( c ) => c.slug === s.accent_theme ) : undefined;
	const accent = theme?.color ?? s.accent;
	const preview = s.cards.find( ( c ) => c.active ) ?? SAMPLE;
	return (
		<>
			<Section title="چیدمان صفحه پرداخت" icon={ LayoutTwoColumnIcon } desc="صفحه پرداخت روی هر قالبی کار می‌کند؛ قالب‌های کلاسیک و قالب‌های بلوکی (FSE).">
				<div className="flex flex-col gap-3 px-3 py-2 md:flex-row">
					<LayoutOption on={ s.layout === 'theme' } title="داخل قالب سایت" desc="با سربرگ و پانویس قالب فعلی" onClick={ () => set( 'layout', 'theme' ) } />
					<LayoutOption on={ s.layout === 'standalone' } standalone title="صفحه مستقل" desc="صفحه تمیز و متمرکز، فقط لوگوی فروشگاه" onClick={ () => set( 'layout', 'standalone' ) } />
				</div>
			</Section>

			<div className="grid gap-5 2xl:grid-cols-[minmax(0,1fr)_460px]">
				<Section title="رنگ و حالت" icon={ PaintBoardIcon }>
					<Row label="حالت رنگ">
						<Segmented
							value={ s.scheme }
							onChange={ ( v ) => set( 'scheme', v ) }
							options={ [
								{ value: 'light', label: 'روشن' },
								{ value: 'dark', label: 'تیره' },
								{ value: 'auto', label: 'خودکار' },
							] }
						/>
					</Row>
					<Row label="رنگ اصلی" help={ s.accent_source === 'theme' ? `از رنگ‌های قالب «${ boot().themeName }»؛ اگر رنگ را در قالب عوض کنید، صفحه پرداخت هم عوض می‌شود.` : 'دکمه‌ها، مراحل و نشانگرها. رنگ متن روی دکمه خودکار (سفید یا تیره) انتخاب می‌شود.' }>
						<div className="flex flex-col items-start gap-3 md:items-end">
							<Segmented
								size="sm"
								value={ s.accent_source }
								onChange={ ( v ) => set( 'accent_source', v ) }
								options={ [
									{ value: 'custom', label: 'رنگ دلخواه' },
									{ value: 'theme', label: 'رنگ قالب' },
								] }
							/>
							{ s.accent_source === 'theme' ? (
								<ThemeSwatches
									selected={ s.accent_theme }
									onPick={ ( slug, hex ) => patch( { accent_theme: slug, accent: hex } ) }
									empty="رنگی از قالب فعلی پیدا نشد (theme.json یا تنظیمات رنگ سفارشی‌ساز). «رنگ دلخواه» را انتخاب کنید."
								/>
							) : (
								<div className="flex flex-wrap items-center gap-2">
									{ ACCENTS.map( ( c ) => (
										<motion.button key={ c } type="button" whileTap={ { scale: 0.9 } } onClick={ () => set( 'accent', c ) } aria-label={ c } className={ cn( 'size-8 rounded-full ring-offset-2 ring-offset-card', s.accent === c && 'ring-2 ring-foreground' ) } style={ { background: c } } />
									) ) }
									<input type="color" value={ s.accent } onChange={ ( e ) => set( 'accent', e.target.value ) } className="size-8 cursor-pointer rounded-full border border-border bg-card p-0.5" aria-label="رنگ دلخواه" />
								</div>
							) }
						</div>
					</Row>
					<Row label="فونت" help="وزیرمتن همراه افزونه است؛ «فونت قالب» از فونت سایت استفاده می‌کند.">
						<Segmented
							value={ s.font }
							onChange={ ( v ) => set( 'font', v ) }
							options={ [
								{ value: 'vazirmatn', label: 'وزیرمتن' },
								{ value: 'theme', label: 'فونت قالب' },
							] }
						/>
					</Row>
					<SwitchRow label="جلوه سه‌بعدی کارت" help="کارت با حرکت نشانگر کج می‌شود و برق می‌زند (در موبایل و حالت کاهش حرکت غیرفعال است)." checked={ s.tilt } onChange={ ( v ) => set( 'tilt', v ) } />
					<SwitchRow label="لوگوی بانک روی کارت" checked={ s.logo } onChange={ ( v ) => set( 'logo', v ) } />
				</Section>

				<Section title="پیش‌نمایش">
					<div className={ cn( 'ctc rounded-2xl p-5', s.scheme === 'dark' ? 'bg-[#0f1320]' : 'bg-[#f2f3f8]' ) } data-scheme={ s.scheme } style={ { '--ctc-accent': accent, '--ctc-on-accent': ink( accent ) } as React.CSSProperties }>
						<BankCard card={ preview } tilt={ s.tilt } logo={ s.logo } className="mx-auto w-full max-w-[360px]" />
						<div className="mt-4 flex flex-col gap-2">
							<div className="ctc-fact is-amount">
								<dt>مبلغ قابل واریز</dt>
								<dd>
									<b>۱٬۴۶۳٬۰۰۰</b> <small>تومان</small>
								</dd>
							</div>
							<button type="button" className="ctc-btn ctc-submit">ارسال رسید</button>
						</div>
					</div>
				</Section>
			</div>
		</>
	);
}

/**
 * «افزونه‌ها»: optional feature modules. Each one has a switch; its settings open when it is on.
 * Modules registered by other plugins (ctc_modules) show with their switch only.
 */
import { AnimatePresence, motion } from 'motion/react';
import { HugeiconsIcon, type IconSvgElement } from '@hugeicons/react';
import { DiscountTag01Icon, ChartBarLineIcon, FilterHorizontalIcon, ShieldEnergyIcon, PuzzleIcon } from '@hugeicons/core-free-icons';
import type { ComponentType } from 'react';
import { useStore } from '../lib/store';
import type { ModuleMeta, Modules as ModulesConf } from '../lib/types';
import { boot, cn, fa } from '../lib/utils';
import { SoftSwitch } from '../ui/SoftSwitch';
import { FeeModule } from '../modules/FeeModule';
import { LimitsModule } from '../modules/LimitsModule';
import { RulesModule } from '../modules/RulesModule';
import { FraudModule } from '../modules/FraudModule';

const ICONS: Record<string, IconSvgElement> = { fee: DiscountTag01Icon, limit: ChartBarLineIcon, filter: FilterHorizontalIcon, shield: ShieldEnergyIcon, puzzle: PuzzleIcon };
const UI: Record<string, ComponentType> = { fee: FeeModule, limits: LimitsModule, rules: RulesModule, fraud: FraudModule };

function ModuleCard( { meta }: { meta: ModuleMeta } ) {
	const { s, set } = useStore();
	const conf = s.modules?.[ meta.id ] ?? { on: false };
	const Body = UI[ meta.id ];
	const toggle = ( on: boolean ) => set( 'modules', { ...s.modules, [ meta.id ]: { ...conf, on } } as ModulesConf );
	return (
		<motion.section layout className={ cn( 'overflow-hidden rounded-[22px] border bg-card shadow-[0_1px_2px_rgba(15,23,42,.04)] transition-colors', conf.on ? 'border-primary/40' : 'border-border' ) }>
			<header className="flex items-start gap-3 p-5 md:p-6">
				<span className={ cn( 'flex size-11 shrink-0 items-center justify-center rounded-2xl transition-colors', conf.on ? 'bg-primary text-white shadow-[0_8px_18px_-10px_var(--primary)]' : 'bg-muted text-muted-foreground' ) }>
					<HugeiconsIcon icon={ ICONS[ meta.icon ] ?? PuzzleIcon } size={ 21 } />
				</span>
				<div className="min-w-0 flex-1">
					<h2 className="text-[15px] font-extrabold text-foreground">{ meta.label }</h2>
					<p className="mt-0.5 max-w-2xl text-[13px] leading-6 text-muted-foreground">{ meta.desc }</p>
				</div>
				<SoftSwitch checked={ !! conf.on } onCheckedChange={ toggle } label={ meta.label } />
			</header>
			<AnimatePresence initial={ false }>
				{ conf.on && Body ? (
					<motion.div key="body" initial={ { height: 0, opacity: 0 } } animate={ { height: 'auto', opacity: 1 } } exit={ { height: 0, opacity: 0 } } transition={ { type: 'spring', stiffness: 380, damping: 36 } }>
						<div className="flex flex-col gap-1 border-t border-border px-2 pb-5 pt-3 md:px-3">
							<Body />
						</div>
					</motion.div>
				) : null }
			</AnimatePresence>
		</motion.section>
	);
}

export function Modules() {
	const { s } = useStore();
	const list = boot().modules;
	const on = list.filter( ( m ) => s.modules?.[ m.id ]?.on ).length;
	return (
		<>
			<div className="rounded-[22px] border border-border bg-card px-5 py-4">
				<p className="text-[15px] font-extrabold">افزونه‌ها</p>
				<p className="mt-0.5 text-[13px] leading-6 text-muted-foreground">
					امکانات اختیاری؛ هر کدام را که لازم دارید روشن کنید و بعد ذخیره کنید. افزونه خاموش هیچ اثری روی سایت ندارد. { fa( on ) } از { fa( list.length ) } فعال است.
				</p>
			</div>
			{ list.map( ( meta ) => (
				<ModuleCard key={ meta.id } meta={ meta } />
			) ) }
		</>
	);
}

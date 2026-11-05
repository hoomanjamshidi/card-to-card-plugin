/**
 * Form building blocks: section card, labelled row, text / number / textarea inputs, token chips.
 */
import { useRef, type ReactNode } from 'react';
import { motion } from 'motion/react';
import { HugeiconsIcon, type IconSvgElement } from '@hugeicons/react';
import { MinusSignIcon, PlusSignIcon } from '@hugeicons/core-free-icons';
import { cn, fa, latin } from '../lib/utils';
import { SoftSwitch } from './SoftSwitch';

export function Section( { title, desc, icon, children, actions, className }: { title: string; desc?: string; icon?: IconSvgElement; children: ReactNode; actions?: ReactNode; className?: string } ) {
	return (
		<motion.section
			initial={ { opacity: 0, y: 10 } }
			animate={ { opacity: 1, y: 0 } }
			transition={ { type: 'spring', stiffness: 380, damping: 34 } }
			className={ cn( 'rounded-[22px] border border-border bg-card p-5 shadow-[0_1px_2px_rgba(15,23,42,.04)] md:p-6', className ) }
		>
			<header className="mb-5 flex flex-wrap items-start justify-between gap-3">
				<div className="flex items-start gap-3">
					{ icon ? (
						<span className="flex size-10 shrink-0 items-center justify-center rounded-2xl bg-primary/10 text-primary">
							<HugeiconsIcon icon={ icon } size={ 20 } />
						</span>
					) : null }
					<div>
						<h2 className="text-[15px] font-extrabold text-foreground">{ title }</h2>
						{ desc ? <p className="mt-0.5 max-w-2xl text-[13px] text-muted-foreground">{ desc }</p> : null }
					</div>
				</div>
				{ actions }
			</header>
			<div className="flex flex-col gap-1">{ children }</div>
		</motion.section>
	);
}

/** A settings row: label + help on the right, control on the left. */
export function Row( { label, help, children, stack }: { label: string; help?: ReactNode; children: ReactNode; stack?: boolean } ) {
	return (
		<div className={ cn( 'flex gap-3 rounded-2xl px-3 py-3 transition-colors hover:bg-accent/50', stack ? 'flex-col' : 'flex-col md:flex-row md:items-center md:justify-between' ) }>
			<div className="min-w-0 md:max-w-[52%]">
				<p className="text-[14px] font-semibold text-foreground">{ label }</p>
				{ help ? <p className="mt-0.5 text-[12.5px] leading-6 text-muted-foreground">{ help }</p> : null }
			</div>
			<div className={ cn( 'shrink-0', stack ? 'w-full' : 'md:min-w-[260px] md:max-w-[420px] md:flex-1 md:flex md:justify-end' ) }>{ children }</div>
		</div>
	);
}

export function SwitchRow( { label, help, checked, onChange }: { label: string; help?: ReactNode; checked: boolean; onChange: ( v: boolean ) => void } ) {
	return (
		<div className="flex items-center justify-between gap-4 rounded-2xl px-3 py-3 transition-colors hover:bg-accent/50">
			<div className="min-w-0">
				<p className="text-[14px] font-semibold text-foreground">{ label }</p>
				{ help ? <p className="mt-0.5 text-[12.5px] leading-6 text-muted-foreground">{ help }</p> : null }
			</div>
			<SoftSwitch checked={ checked } onCheckedChange={ onChange } label={ label } />
		</div>
	);
}

const inputBase = 'w-full rounded-2xl border border-border bg-card px-3.5 py-2.5 text-[14px] text-foreground outline-none transition-[border,box-shadow] placeholder:text-muted-foreground/70 focus:border-primary focus:ring-4 focus:ring-primary/15';

export function TextInput( { value, onChange, placeholder, dir, mono, invalid, type = 'text', ...rest }: { value: string; onChange: ( v: string ) => void; placeholder?: string; dir?: 'ltr' | 'rtl'; mono?: boolean; invalid?: boolean; type?: string; inputMode?: 'numeric' | 'text' | 'tel' | 'url'; maxLength?: number; autoComplete?: string; 'aria-label'?: string } ) {
	return (
		<input
			type={ type }
			value={ value }
			dir={ dir }
			placeholder={ placeholder }
			onChange={ ( e ) => onChange( e.target.value ) }
			className={ cn( inputBase, mono && 'tabular-nums tracking-wider', dir === 'ltr' && 'text-left', invalid && 'border-rose-400 focus:border-rose-500 focus:ring-rose-500/15' ) }
			{ ...rest }
		/>
	);
}

export function TextArea( { value, onChange, rows = 3, placeholder, id }: { value: string; onChange: ( v: string ) => void; rows?: number; placeholder?: string; id?: string } ) {
	return <textarea id={ id } rows={ rows } value={ value } placeholder={ placeholder } onChange={ ( e ) => onChange( e.target.value ) } className={ cn( inputBase, 'resize-y leading-7' ) } />;
}

/** Number with − / + steppers and a unit. */
export function NumberInput( { value, onChange, min = 0, max = 9999, step = 1, unit }: { value: number; onChange: ( v: number ) => void; min?: number; max?: number; step?: number; unit?: string } ) {
	const clamp = ( v: number ) => Math.max( min, Math.min( max, v ) );
	return (
		<div className="inline-flex items-center gap-1 rounded-2xl border border-border bg-card p-1">
			<button type="button" aria-label="کمتر" onClick={ () => onChange( clamp( value - step ) ) } className="flex size-9 items-center justify-center rounded-xl text-muted-foreground hover:bg-accent hover:text-foreground">
				<HugeiconsIcon icon={ MinusSignIcon } size={ 16 } />
			</button>
			<input
				value={ fa( value ) }
				inputMode="numeric"
				onChange={ ( e ) => onChange( clamp( Number( latin( e.target.value ).replace( /\D/g, '' ) || 0 ) ) ) }
				className="w-16 bg-transparent text-center text-[15px] font-bold tabular-nums outline-none"
				aria-label={ unit }
			/>
			{ unit ? <span className="pe-1 text-xs text-muted-foreground">{ unit }</span> : null }
			<button type="button" aria-label="بیشتر" onClick={ () => onChange( clamp( value + step ) ) } className="flex size-9 items-center justify-center rounded-xl text-muted-foreground hover:bg-accent hover:text-foreground">
				<HugeiconsIcon icon={ PlusSignIcon } size={ 16 } />
			</button>
		</div>
	);
}

/** Clickable {token} chips that insert into a textarea / input. */
export function TokenChips( { tokens, onInsert }: { tokens: Record<string, string>; onInsert: ( token: string ) => void } ) {
	return (
		<div className="mt-2 flex flex-wrap gap-1.5">
			{ Object.entries( tokens ).map( ( [ key, label ] ) => (
				<button key={ key } type="button" title={ label } onClick={ () => onInsert( `{${ key }}` ) } className="rounded-full border border-border bg-muted px-2.5 py-0.5 text-[11.5px] text-muted-foreground transition-colors hover:border-primary/40 hover:bg-primary/10 hover:text-primary" dir="ltr">
					{ `{${ key }}` }
				</button>
			) ) }
		</div>
	);
}

/** Textarea with token chips that insert at the caret. */
export function TokenArea( { value, onChange, tokens, rows = 3 }: { value: string; onChange: ( v: string ) => void; tokens: Record<string, string>; rows?: number } ) {
	const ref = useRef<HTMLTextAreaElement>( null );
	const insert = ( token: string ) => {
		const el = ref.current;
		if ( ! el ) return onChange( value + token );
		const start = el.selectionStart ?? value.length;
		const end = el.selectionEnd ?? value.length;
		onChange( value.slice( 0, start ) + token + value.slice( end ) );
		requestAnimationFrame( () => {
			el.focus();
			el.setSelectionRange( start + token.length, start + token.length );
		} );
	};
	return (
		<div className="w-full">
			<textarea ref={ ref } rows={ rows } value={ value } onChange={ ( e ) => onChange( e.target.value ) } className={ cn( inputBase, 'resize-y leading-7' ) } />
			<TokenChips tokens={ tokens } onInsert={ insert } />
		</div>
	);
}

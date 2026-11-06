/** Amount input with thousands separators and Persian digits; 0 shows as empty («بدون محدودیت»). */
import { boot, cn, latin, money } from '../lib/utils';

export function MoneyInput( { value, onChange, placeholder = 'بدون محدودیت', className, label }: { value: number; onChange: ( v: number ) => void; placeholder?: string; className?: string; label?: string } ) {
	return (
		<div className={ cn( 'flex items-center gap-2 rounded-2xl border border-border bg-card px-3.5 py-2 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15', className ) }>
			<input
				value={ value ? money( value ) : '' }
				inputMode="numeric"
				dir="ltr"
				placeholder={ placeholder }
				aria-label={ label }
				onChange={ ( e ) => onChange( Number( latin( e.target.value ).replace( /\D/g, '' ) || 0 ) ) }
				className="w-full min-w-0 bg-transparent text-left text-[14px] font-bold tabular-nums outline-none placeholder:font-normal placeholder:text-muted-foreground/70"
			/>
			<span className="shrink-0 text-xs text-muted-foreground">{ boot().unit }</span>
		</div>
	);
}

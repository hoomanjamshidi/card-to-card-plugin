/**
 * Multi-select as toggle chips (with a search box for long lists). Empty selection usually means
 * «all» — the caller says so in `emptyLabel`.
 */
import { useState } from 'react';
import { HugeiconsIcon } from '@hugeicons/react';
import { Search01Icon, Tick02Icon } from '@hugeicons/core-free-icons';
import type { Option } from '../lib/types';
import { cn, fa } from '../lib/utils';

export function ChipSelect( { options, value, onChange, emptyLabel, empty = 'موردی وجود ندارد.' }: { options: Option[]; value: string[]; onChange: ( v: string[] ) => void; emptyLabel?: string; empty?: string } ) {
	const [ q, setQ ] = useState( '' );
	if ( ! options.length ) return <p className="text-[12.5px] text-muted-foreground">{ empty }</p>;
	const shown = q ? options.filter( ( o ) => o.label.includes( q ) || o.value.includes( q ) ) : options;
	const toggle = ( v: string ) => onChange( value.includes( v ) ? value.filter( ( x ) => x !== v ) : [ ...value, v ] );
	return (
		<div className="flex w-full flex-col gap-2">
			{ options.length > 10 ? (
				<label className="flex items-center gap-2 rounded-2xl border border-border bg-card px-3 py-2 text-muted-foreground focus-within:border-primary">
					<HugeiconsIcon icon={ Search01Icon } size={ 16 } />
					<input value={ q } onChange={ ( e ) => setQ( e.target.value ) } placeholder="جستجو…" className="w-full bg-transparent text-[13px] text-foreground outline-none" />
				</label>
			) : null }
			<div className="flex max-h-56 flex-wrap gap-1.5 overflow-y-auto">
				{ shown.map( ( o ) => {
					const on = value.includes( o.value );
					return (
						<button key={ o.value } type="button" aria-pressed={ on } onClick={ () => toggle( o.value ) } className={ cn( 'inline-flex items-center gap-1 rounded-full border px-3 py-1 text-[12.5px] transition-colors', on ? 'border-primary bg-primary/10 font-bold text-primary' : 'border-border bg-muted text-muted-foreground hover:text-foreground' ) }>
							{ on ? <HugeiconsIcon icon={ Tick02Icon } size={ 13 } /> : null }
							{ o.label }
						</button>
					);
				} ) }
			</div>
			<p className="text-[11.5px] text-muted-foreground">{ value.length ? `${ fa( value.length ) } مورد انتخاب شده` : emptyLabel }</p>
		</div>
	);
}

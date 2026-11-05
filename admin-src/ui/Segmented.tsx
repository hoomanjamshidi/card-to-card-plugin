/**
 * Segmented control with a sliding spring pill — in the visual language of Tarhak «animated-tabs».
 */
import { useId, type ReactNode } from 'react';
import { motion } from 'motion/react';
import { cn } from '../lib/utils';

export type Segment<T extends string> = { value: T; label: string; icon?: ReactNode };

export function Segmented<T extends string>( { value, onChange, options, size = 'md', full }: { value: T; onChange: ( v: T ) => void; options: Segment<T>[]; size?: 'sm' | 'md'; full?: boolean } ) {
	const id = useId().replace( /:/g, '' );
	return (
		<div role="radiogroup" className={ cn( 'inline-flex gap-1 rounded-2xl border border-border bg-muted p-1', full && 'flex w-full' ) }>
			{ options.map( ( o ) => {
				const on = o.value === value;
				return (
					<button
						key={ o.value }
						type="button"
						role="radio"
						aria-checked={ on }
						onClick={ () => onChange( o.value ) }
						className={ cn(
							'relative flex flex-1 items-center justify-center gap-1.5 rounded-xl font-medium outline-none transition-colors focus-visible:ring-2 focus-visible:ring-ring',
							size === 'sm' ? 'px-3 py-1.5 text-xs' : 'px-4 py-2 text-[13px]',
							on ? 'text-foreground' : 'text-muted-foreground hover:text-foreground'
						) }
					>
						{ on ? <motion.span layoutId={ `seg-${ id }` } className="absolute inset-0 rounded-xl bg-card shadow-[0_2px_10px_-4px_rgba(15,23,42,.35)]" transition={ { type: 'spring', stiffness: 500, damping: 36 } } /> : null }
						<span className="relative z-1 inline-flex items-center gap-1.5 whitespace-nowrap">{ o.icon }{ o.label }</span>
					</button>
				);
			} ) }
		</div>
	);
}

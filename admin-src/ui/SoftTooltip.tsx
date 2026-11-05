/**
 * Soft card tooltip — adapted from Tarhak «tooltip».
 */
import { useId, useState, type ReactNode } from 'react';
import { AnimatePresence, motion } from 'motion/react';
import { cn } from '../lib/utils';

export function SoftTooltip( { label, side = 'top', children, className }: { label: string; side?: 'top' | 'bottom'; children: ReactNode; className?: string } ) {
	const id = useId();
	const [ open, setOpen ] = useState( false );
	const top = side === 'top';
	return (
		<div
			className={ cn( 'relative inline-flex', className ) }
			onMouseEnter={ () => setOpen( true ) }
			onMouseLeave={ () => setOpen( false ) }
			onFocusCapture={ () => setOpen( true ) }
			onBlurCapture={ ( e ) => ! e.currentTarget.contains( e.relatedTarget as Node | null ) && setOpen( false ) }
		>
			<div aria-describedby={ open ? id : undefined }>{ children }</div>
			<AnimatePresence>
				{ open ? (
					<motion.div
						id={ id }
						role="tooltip"
						initial={ { opacity: 0, y: top ? 8 : -8, scale: 0.92 } }
						animate={ { opacity: 1, y: 0, scale: 1 } }
						exit={ { opacity: 0, y: top ? 6 : -6, scale: 0.94 } }
						transition={ { type: 'spring', bounce: 0.2, duration: 0.35 } }
						className={ cn( 'pointer-events-none absolute left-1/2 z-40 -translate-x-1/2', top ? 'bottom-[calc(100%+10px)]' : 'top-[calc(100%+10px)]' ) }
					>
						<div className="relative max-w-[260px] whitespace-normal border border-border bg-card px-3 py-2 text-center text-xs text-foreground shadow-lg" style={ { borderRadius: 14, borderWidth: 1, width: 'max-content' } }>
							{ label }
							<span aria-hidden className={ cn( 'absolute left-1/2 size-2.5 -translate-x-1/2 rotate-45 border-border bg-card', top ? 'top-full -mt-[5px] border-e border-b' : 'bottom-full -mb-[5px] border-s border-t' ) } />
						</div>
					</motion.div>
				) : null }
			</AnimatePresence>
		</div>
	);
}

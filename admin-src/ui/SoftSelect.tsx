/**
 * Animated select — adapted from Tarhak «animated-select» (SoftSelect): morphing trigger, spring list,
 * sliding highlight, drawn tick. Options may carry an SVG/HTML badge (bank logos).
 */
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import { HugeiconsIcon } from '@hugeicons/react';
import { ArrowDown01Icon, Tick02Icon } from '@hugeicons/core-free-icons';
import { cn } from '../lib/utils';

const ON = '#34C759';
const panelSpring = { type: 'spring' as const, stiffness: 520, damping: 38, mass: 0.7 };
const rowSpring = { type: 'spring' as const, stiffness: 600, damping: 34, mass: 0.6 };

export type SelectOption = { value: string; label: string; description?: string; badge?: ReactNode };

type Props = { value: string; onValueChange: ( value: string ) => void; options: SelectOption[]; placeholder?: string; label?: string };

export function SoftSelect( { value, onValueChange, options, placeholder = 'انتخاب کنید', label }: Props ) {
	const reduce = useReducedMotion() ?? false;
	const [ open, setOpen ] = useState( false );
	const [ hovered, setHovered ] = useState<string | null>( null );
	const rootRef = useRef<HTMLDivElement>( null );
	const listId = useId();
	const hl = useId().replace( /:/g, '' );
	const selected = options.find( ( o ) => o.value === value );

	useEffect( () => {
		if ( ! open ) return;
		const down = ( e: MouseEvent ) => {
			if ( rootRef.current && ! rootRef.current.contains( e.target as Node ) ) setOpen( false );
		};
		const key = ( e: KeyboardEvent ) => e.key === 'Escape' && setOpen( false );
		document.addEventListener( 'mousedown', down );
		document.addEventListener( 'keydown', key );
		return () => {
			document.removeEventListener( 'mousedown', down );
			document.removeEventListener( 'keydown', key );
		};
	}, [ open ] );

	const pick = ( next: string ) => {
		onValueChange( next );
		window.setTimeout( () => setOpen( false ), 140 );
	};

	return (
		<div ref={ rootRef } className="relative w-full">
			{ label ? <p className="mb-2 text-sm font-medium text-foreground">{ label }</p> : null }
			<motion.button
				type="button"
				aria-haspopup="listbox"
				aria-expanded={ open }
				aria-controls={ listId }
				aria-label={ label ?? placeholder }
				onClick={ () => setOpen( ( v ) => ! v ) }
				whileTap={ reduce ? undefined : { scale: 0.985 } }
				className={ cn( 'relative flex w-full cursor-pointer items-center gap-3 overflow-hidden border border-border bg-card px-3.5 py-2.5 text-start outline-none focus-visible:ring-2 focus-visible:ring-ring', open && 'border-foreground/20' ) }
				style={ { borderRadius: 16, borderWidth: 1 } }
			>
				{ selected?.badge ? <span className="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-border bg-white p-1">{ selected.badge }</span> : null }
				<div className="min-w-0 flex-1">
					<AnimatePresence mode="wait" initial={ false }>
						<motion.span
							key={ selected?.value ?? 'empty' }
							initial={ reduce ? false : { opacity: 0, y: 6, filter: 'blur(3px)' } }
							animate={ { opacity: 1, y: 0, filter: 'blur(0px)' } }
							exit={ reduce ? undefined : { opacity: 0, y: -6, filter: 'blur(3px)' } }
							transition={ rowSpring }
							className={ cn( 'block truncate text-[14px] font-medium', selected ? 'text-foreground' : 'text-muted-foreground' ) }
						>
							{ selected?.label ?? placeholder }
						</motion.span>
					</AnimatePresence>
				</div>
				<motion.span className="flex size-7 shrink-0 items-center justify-center rounded-full border border-border bg-background text-muted-foreground" animate={ { rotate: open ? 180 : 0 } } transition={ panelSpring }>
					<HugeiconsIcon icon={ ArrowDown01Icon } size={ 16 } />
				</motion.span>
			</motion.button>

			<AnimatePresence>
				{ open ? (
					<motion.div
						id={ listId }
						role="listbox"
						initial={ reduce ? { opacity: 1 } : { opacity: 0, y: -8, scale: 0.97, filter: 'blur(4px)' } }
						animate={ { opacity: 1, y: 0, scale: 1, filter: 'blur(0px)' } }
						exit={ reduce ? { opacity: 0 } : { opacity: 0, y: -6, scale: 0.98, filter: 'blur(3px)' } }
						transition={ panelSpring }
						className="absolute inset-x-0 top-[calc(100%+8px)] z-30 max-h-80 overflow-y-auto border border-border bg-card shadow-[0_12px_40px_-18px_rgba(0,0,0,0.45)]"
						style={ { borderRadius: 18, borderWidth: 1 } }
						onMouseLeave={ () => setHovered( null ) }
					>
						<ul className="flex flex-col gap-0.5 p-1.5">
							{ options.map( ( option, index ) => {
								const active = option.value === value;
								const show = hovered === option.value || ( active && ! hovered );
								return (
									<motion.li key={ option.value } role="option" aria-selected={ active } initial={ reduce ? false : { opacity: 0, y: 14 } } animate={ { opacity: 1, y: 0 } } transition={ { ...rowSpring, delay: reduce ? 0 : Math.min( 0.03 + index * 0.025, 0.3 ) } }>
										<button type="button" onClick={ () => pick( option.value ) } onMouseEnter={ () => setHovered( option.value ) } className="relative flex w-full cursor-pointer items-center gap-3 rounded-2xl px-3 py-2 text-start outline-none focus-visible:ring-2 focus-visible:ring-ring">
											{ show ? <motion.span layoutId={ `sel-${ hl }` } className="absolute inset-0 rounded-2xl bg-accent" transition={ panelSpring } /> : null }
											{ option.badge ? <span className="relative z-1 flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-border bg-white p-1">{ option.badge }</span> : null }
											<div className="relative z-1 min-w-0 flex-1">
												<p className="truncate text-[14px] font-medium text-foreground">{ option.label }</p>
												{ option.description ? <p className="truncate text-xs text-muted-foreground">{ option.description }</p> : null }
											</div>
											<span className="relative z-1 flex size-6 shrink-0 items-center justify-center">
												<AnimatePresence initial={ false }>
													{ active ? (
														<motion.span key="tick" initial={ reduce ? false : { opacity: 0, scale: 0.4, rotate: -40 } } animate={ { opacity: 1, scale: 1, rotate: 0 } } exit={ { opacity: 0, scale: 0.5 } } transition={ rowSpring } className="flex size-6 items-center justify-center rounded-full" style={ { backgroundColor: ON } }>
															<HugeiconsIcon icon={ Tick02Icon } size={ 14 } className="text-white" />
														</motion.span>
													) : null }
												</AnimatePresence>
											</span>
										</button>
									</motion.li>
								);
							} ) }
						</ul>
					</motion.div>
				) : null }
			</AnimatePresence>
		</div>
	);
}

/**
 * Toast stack — adapted from Tarhak «toast-stack»: spring in/out, progress bar, auto-dismiss.
 * Use the `toast()` function from anywhere.
 */
import { useCallback, useEffect, useState } from 'react';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import { HugeiconsIcon, type IconSvgElement } from '@hugeicons/react';
import { Alert02Icon, Cancel01Icon, CancelCircleIcon, CheckmarkCircle02Icon } from '@hugeicons/core-free-icons';

export type ToastKind = 'success' | 'error' | 'warning';
type Item = { id: number; kind: ToastKind; title: string; description?: string };

const KIND: Record<ToastKind, { icon: IconSvgElement; accent: string; soft: string }> = {
	success: { icon: CheckmarkCircle02Icon, accent: '#34C759', soft: 'color-mix(in oklab, #34C759 14%, transparent)' },
	error: { icon: CancelCircleIcon, accent: '#E11D48', soft: 'color-mix(in oklab, #E11D48 14%, transparent)' },
	warning: { icon: Alert02Icon, accent: '#D97706', soft: 'color-mix(in oklab, #D97706 16%, transparent)' },
};
const AUTO = 4200;
let seq = 0;
let push: ( item: Item ) => void = () => {};

/** Show a toast. */
export function toast( kind: ToastKind, title: string, description?: string ) {
	push( { id: ++seq, kind, title, description } );
}

function ToastCard( { item, onDismiss, reduce }: { item: Item; onDismiss: ( id: number ) => void; reduce: boolean } ) {
	const meta = KIND[ item.kind ];
	const [ progress, setProgress ] = useState( 1 );
	useEffect( () => {
		const started = performance.now();
		let frame = 0;
		const tick = ( now: number ) => {
			const t = Math.min( 1, ( now - started ) / AUTO );
			setProgress( 1 - t );
			if ( t < 1 ) frame = requestAnimationFrame( tick );
			else onDismiss( item.id );
		};
		frame = requestAnimationFrame( tick );
		return () => cancelAnimationFrame( frame );
	}, [ item.id, onDismiss ] );
	return (
		<motion.div
			layout
			role="status"
			aria-live="polite"
			initial={ reduce ? false : { opacity: 0, y: -18, scale: 0.96, filter: 'blur(4px)' } }
			animate={ { opacity: 1, y: 0, scale: 1, filter: 'blur(0px)' } }
			exit={ reduce ? { opacity: 0 } : { opacity: 0, y: -12, scale: 0.96, filter: 'blur(4px)' } }
			transition={ { type: 'spring', stiffness: 420, damping: 30, mass: 0.75 } }
			className="pointer-events-auto relative w-[340px] max-w-[calc(100vw-32px)] overflow-hidden rounded-2xl border border-border bg-card shadow-[0_18px_40px_-20px_rgba(0,0,0,.45)]"
			style={ { borderInlineStartWidth: 3, borderInlineStartColor: meta.accent } }
		>
			<div className="flex items-start gap-3 px-3.5 py-3" style={ { background: meta.soft } }>
				<span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full" style={ { background: meta.soft, color: meta.accent } }>
					<HugeiconsIcon icon={ meta.icon } size={ 20 } />
				</span>
				<div className="min-w-0 flex-1 pt-0.5">
					<p className="text-sm font-bold text-foreground">{ item.title }</p>
					{ item.description ? <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">{ item.description }</p> : null }
				</div>
				<button type="button" aria-label="بستن اعلان" onClick={ () => onDismiss( item.id ) } className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-xl text-muted-foreground hover:bg-accent hover:text-foreground">
					<HugeiconsIcon icon={ Cancel01Icon } size={ 16 } />
				</button>
			</div>
			<div className="h-0.5 w-full bg-border/60">
				<div className="h-full" style={ { width: `${ progress * 100 }%`, background: meta.accent } } />
			</div>
		</motion.div>
	);
}

/** Mount once. */
export function ToastStack() {
	const reduce = useReducedMotion() ?? false;
	const [ items, setItems ] = useState<Item[]>( [] );
	const dismiss = useCallback( ( id: number ) => setItems( ( prev ) => prev.filter( ( t ) => t.id !== id ) ), [] );
	useEffect( () => {
		push = ( item ) => setItems( ( prev ) => [ item, ...prev ].slice( 0, 4 ) );
	}, [] );
	return (
		<div className="pointer-events-none fixed bottom-6 left-6 z-[100000] flex flex-col gap-2">
			<AnimatePresence mode="popLayout" initial={ false }>
				{ items.map( ( item ) => <ToastCard key={ item.id } item={ item } onDismiss={ dismiss } reduce={ reduce } /> ) }
			</AnimatePresence>
		</div>
	);
}

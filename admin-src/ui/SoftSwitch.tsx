/**
 * Pill switch — adapted from Tarhak «animated-switches» (SoftSwitch): spring thumb, iOS green.
 */
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import { HugeiconsIcon } from '@hugeicons/react';
import { Tick02Icon } from '@hugeicons/core-free-icons';
import { cn } from '../lib/utils';

const ON = '#34C759';
const TRACK = { w: 52, h: 32, thumb: 26, pad: 3 } as const;
const TRAVEL = TRACK.w - TRACK.thumb - TRACK.pad * 2;
const thumbSpring = { type: 'spring' as const, stiffness: 500, damping: 32, mass: 0.75 };
const softSpring = { type: 'spring' as const, stiffness: 420, damping: 28, mass: 0.65 };

type Props = { checked: boolean; onCheckedChange: ( next: boolean ) => void; label?: string; disabled?: boolean };

export function SoftSwitch( { checked, onCheckedChange, label, disabled }: Props ) {
	const reduce = useReducedMotion() ?? false;
	return (
		<motion.button
			type="button"
			role="switch"
			aria-checked={ checked }
			aria-label={ label }
			disabled={ disabled }
			onClick={ () => onCheckedChange( ! checked ) }
			whileTap={ reduce ? undefined : { scale: 0.97 } }
			className={ cn(
				'relative inline-flex shrink-0 cursor-pointer items-center overflow-hidden border outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
				checked ? 'border-transparent' : 'border-border bg-muted'
			) }
			style={ { width: TRACK.w, height: TRACK.h, borderRadius: 999, borderWidth: 1, backgroundColor: checked ? ON : undefined } }
		>
			<motion.span
				aria-hidden
				className="pointer-events-none absolute inset-0 rounded-full"
				style={ { backgroundColor: ON } }
				initial={ false }
				animate={ { opacity: checked ? 1 : 0 } }
				transition={ { duration: reduce ? 0.1 : 0.22 } }
			/>
			{ /* The track is drawn LTR like iOS; in RTL «on» sits on the left. */ }
			<motion.span
				className="absolute top-1/2 z-1 flex -translate-y-1/2 items-center justify-center border border-black/5 bg-white shadow-sm"
				style={ { width: TRACK.thumb, height: TRACK.thumb, borderRadius: 999, right: TRACK.pad } }
				initial={ false }
				animate={ { x: checked ? -TRAVEL : 0, scaleX: reduce ? 1 : [ 1.14, 1 ], scaleY: reduce ? 1 : [ 0.9, 1 ] } }
				transition={ reduce ? { duration: 0.12 } : thumbSpring }
			>
				<AnimatePresence mode="wait" initial={ false }>
					{ checked ? (
						<motion.span key="on" initial={ { opacity: 0, scale: 0.4, rotate: -40 } } animate={ { opacity: 1, scale: 1, rotate: 0 } } exit={ { opacity: 0, scale: 0.5 } } transition={ softSpring } className="flex items-center justify-center">
							<HugeiconsIcon icon={ Tick02Icon } size={ 14 } style={ { color: ON } } />
						</motion.span>
					) : (
						<motion.span key="off" initial={ { opacity: 0, scale: 0.5 } } animate={ { opacity: 0.35, scale: 1 } } exit={ { opacity: 0, scale: 0.5 } } className="size-1.5 rounded-full bg-muted-foreground" />
					) }
				</AnimatePresence>
			</motion.span>
		</motion.button>
	);
}

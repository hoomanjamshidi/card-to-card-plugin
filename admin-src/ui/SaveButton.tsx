/**
 * Save button with animated idle / saving / saved states — adapted from Tarhak «save-button».
 */
import { AnimatePresence, motion } from 'motion/react';
import { HugeiconsIcon } from '@hugeicons/react';
import { Tick02Icon } from '@hugeicons/core-free-icons';
import { cn } from '../lib/utils';

export type SaveState = 'idle' | 'loading' | 'success';

export function SaveButton( { state, dirty, onClick }: { state: SaveState; dirty: boolean; onClick: () => void } ) {
	const text = state === 'loading' ? 'در حال ذخیره…' : state === 'success' ? 'ذخیره شد' : dirty ? 'ذخیره تغییرات' : 'ذخیره';
	const busy = state !== 'idle';
	return (
		<div className="relative inline-flex">
			<motion.button
				type="button"
				onClick={ onClick }
				disabled={ busy }
				whileTap={ busy ? undefined : { scale: 0.97 } }
				className={ cn(
					'relative h-11 min-w-[150px] rounded-full px-6 text-[14px] font-bold transition-all duration-300',
					busy ? 'cursor-not-allowed border border-border bg-muted text-foreground' : dirty ? 'bg-primary text-white shadow-[0_12px_24px_-12px_var(--primary)] hover:brightness-110' : 'border border-border bg-card text-muted-foreground'
				) }
			>
				<span className="relative flex min-h-[1.25em] items-center justify-center overflow-hidden">
					<AnimatePresence mode="popLayout" initial={ false }>
						<motion.span
							key={ text }
							initial={ { opacity: 0, y: 8, filter: 'blur(4px)' } }
							animate={ { opacity: 1, y: 0, filter: 'blur(0px)' } }
							exit={ { opacity: 0, y: -8, filter: 'blur(4px)' } }
							transition={ { type: 'spring', stiffness: 500, damping: 30 } }
							className="inline-block whitespace-nowrap"
						>
							{ text }
						</motion.span>
					</AnimatePresence>
				</span>
			</motion.button>
			<div className="pointer-events-none absolute -top-1 -end-1 z-10">
				<AnimatePresence mode="wait">
					{ busy && (
						<motion.div
							initial={ { opacity: 0, scale: 0, x: -8, filter: 'blur(4px)' } }
							animate={ { opacity: 1, scale: 1, x: 0, filter: 'blur(0px)' } }
							exit={ { opacity: 0, scale: 0, x: -8, filter: 'blur(4px)' } }
							transition={ { type: 'spring', stiffness: 300, damping: 20 } }
							className={ cn( 'flex size-6 items-center justify-center rounded-full ring-3 ring-background', state === 'success' ? 'bg-[#34C759] text-white' : 'bg-muted text-foreground' ) }
						>
							{ state === 'loading' ? (
								<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden>
									<path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5" />
									<path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z">
										<animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate" />
									</path>
								</svg>
							) : (
								<HugeiconsIcon icon={ Tick02Icon } className="size-4" />
							) }
						</motion.div>
					) }
				</AnimatePresence>
			</div>
		</div>
	);
}

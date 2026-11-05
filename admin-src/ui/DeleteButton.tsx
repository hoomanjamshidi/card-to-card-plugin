/**
 * Delete with a cancellable countdown — adapted from Tarhak «delete-button» (without dialkit).
 */
import { useEffect, useState } from 'react';
import { AnimatePresence, motion } from 'motion/react';
import { HugeiconsIcon } from '@hugeicons/react';
import { Delete02Icon, Undo03Icon } from '@hugeicons/core-free-icons';
import { fa } from '../lib/utils';

const RED = '#FE322A';

export function DeleteButton( { onDelete, label = 'حذف', seconds = 3 }: { onDelete: () => void; label?: string; seconds?: number } ) {
	const [ deleting, setDeleting ] = useState( false );
	const [ count, setCount ] = useState( seconds );
	useEffect( () => {
		if ( ! deleting ) return;
		if ( count === 0 ) {
			onDelete();
			setDeleting( false );
			return;
		}
		const t = setTimeout( () => setCount( ( c ) => c - 1 ), 1000 );
		return () => clearTimeout( t );
	}, [ deleting, count, onDelete ] );
	const layout = { duration: 0.45, ease: [ 0.785, 0.135, 0.15, 0.86 ] as [ number, number, number, number ] };
	return (
		<AnimatePresence mode="popLayout" initial={ false }>
			{ ! deleting ? (
				<motion.button
					key="del"
					type="button"
					layout
					onClick={ () => {
						setCount( seconds );
						setDeleting( true );
					} }
					whileTap={ { scale: 0.95 } }
					initial={ { opacity: 0 } }
					animate={ { opacity: 1 } }
					exit={ { opacity: 0 } }
					transition={ { layout } }
					className="inline-flex items-center gap-1.5 rounded-full bg-[#FE322A]/10 px-4 py-2 text-[13px] font-bold text-[#FE322A] hover:bg-[#FE322A]/15"
				>
					<HugeiconsIcon icon={ Delete02Icon } size={ 16 } />
					{ label }
				</motion.button>
			) : (
				<motion.button
					key="undo"
					type="button"
					layout
					onClick={ () => setDeleting( false ) }
					whileTap={ { scale: 0.95 } }
					initial={ { opacity: 0 } }
					animate={ { opacity: 1 } }
					exit={ { opacity: 0 } }
					transition={ { layout } }
					className="inline-flex items-center gap-2 rounded-full bg-muted py-1.5 ps-1.5 pe-1.5 ring-1 ring-black/5"
				>
					<span className="flex size-7 items-center justify-center rounded-full" style={ { background: RED } }>
						<HugeiconsIcon icon={ Undo03Icon } className="size-4 text-white" />
					</span>
					<span className="text-[13px] font-bold" style={ { color: RED } }>
						انصراف
					</span>
					<span className="relative flex size-7 items-center justify-center overflow-hidden rounded-full text-xs font-bold text-white" style={ { background: RED } }>
						<AnimatePresence mode="popLayout">
							<motion.span key={ count } initial={ { opacity: 0, y: 10, scale: 0.8 } } animate={ { opacity: 1, y: 0, scale: 1 } } exit={ { opacity: 0, y: -10, scale: 0.8 } } transition={ { duration: 0.2 } } className="absolute">
								{ fa( count ) }
							</motion.span>
						</AnimatePresence>
					</span>
				</motion.button>
			) }
		</AnimatePresence>
	);
}

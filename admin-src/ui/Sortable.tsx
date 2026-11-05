/**
 * Drag-to-reorder list — adapted from Tarhak «sortable-list» (Reorder + drag handle + spring).
 */
import { useState, type ReactNode } from 'react';
import { Reorder, motion, useDragControls, useReducedMotion } from 'motion/react';
import { HugeiconsIcon } from '@hugeicons/react';
import { DragDropVerticalIcon } from '@hugeicons/core-free-icons';
import { cn, fa } from '../lib/utils';

const spring = { type: 'spring' as const, stiffness: 420, damping: 32, mass: 0.75 };

function Row<T>( { item, index, render, active, onSelect }: { item: T; index: number; render: ( item: T ) => ReactNode; active: boolean; onSelect: () => void } ) {
	const controls = useDragControls();
	const reduce = useReducedMotion() ?? false;
	const [ dragging, setDragging ] = useState( false );
	return (
		<Reorder.Item
			value={ item }
			dragListener={ false }
			dragControls={ controls }
			onDragStart={ () => setDragging( true ) }
			onDragEnd={ () => setDragging( false ) }
			whileDrag={ reduce ? undefined : { scale: 1.02, boxShadow: '0 16px 40px -18px rgba(0,0,0,0.45)', zIndex: 20 } }
			transition={ spring }
			className={ cn( 'relative list-none rounded-2xl border bg-card', active ? 'border-primary ring-4 ring-primary/10' : 'border-border', dragging ? 'cursor-grabbing' : 'cursor-pointer' ) }
			style={ { borderWidth: 1 } }
			onClick={ onSelect }
		>
			<div className="flex items-center gap-2.5 px-2.5 py-2.5">
				<button
					type="button"
					aria-label="جابه‌جایی"
					className="flex size-9 shrink-0 cursor-grab touch-none select-none items-center justify-center rounded-xl border border-border bg-background text-muted-foreground active:cursor-grabbing"
					onPointerDown={ ( e ) => controls.start( e ) }
					onClick={ ( e ) => e.stopPropagation() }
				>
					<HugeiconsIcon icon={ DragDropVerticalIcon } size={ 18 } />
				</button>
				<motion.span layout className="flex size-7 shrink-0 items-center justify-center rounded-full border border-border bg-background text-xs font-bold tabular-nums text-muted-foreground" transition={ spring }>
					{ fa( index + 1 ) }
				</motion.span>
				<div className="min-w-0 flex-1">{ render( item ) }</div>
			</div>
		</Reorder.Item>
	);
}

export function Sortable<T extends { id: string }>( { items, onReorder, render, activeId, onSelect }: { items: T[]; onReorder: ( next: T[] ) => void; render: ( item: T ) => ReactNode; activeId?: string; onSelect: ( id: string ) => void } ) {
	return (
		<Reorder.Group axis="y" values={ items } onReorder={ onReorder } as="ul" className="flex flex-col gap-2 p-0">
			{ items.map( ( item, i ) => (
				<Row key={ item.id } item={ item } index={ i } render={ render } active={ item.id === activeId } onSelect={ () => onSelect( item.id ) } />
			) ) }
		</Reorder.Group>
	);
}

/**
 * Spring number counter with Persian digits — adapted from Tarhak «number-ticker».
 */
import { useEffect, useRef } from 'react';
import { useInView, useMotionValue, useSpring } from 'motion/react';
import { cn } from '../lib/utils';

const format = ( v: number, places: number ) =>
	new Intl.NumberFormat( 'fa-IR', { minimumFractionDigits: places, maximumFractionDigits: places } ).format( Number( v.toFixed( places ) ) );

export function NumberTicker( { value, className, decimalPlaces = 0, delay = 0 }: { value: number; className?: string; decimalPlaces?: number; delay?: number } ) {
	const ref = useRef<HTMLSpanElement>( null );
	const mv = useMotionValue( 0 );
	const spring = useSpring( mv, { damping: 60, stiffness: 100 } );
	const inView = useInView( ref, { once: true } );
	useEffect( () => {
		if ( ! inView ) return;
		if ( window.matchMedia?.( '(prefers-reduced-motion: reduce)' ).matches ) {
			if ( ref.current ) ref.current.textContent = format( value, decimalPlaces );
			return;
		}
		const t = setTimeout( () => mv.set( value ), delay * 1000 );
		return () => clearTimeout( t );
	}, [ mv, inView, value, delay, decimalPlaces ] );
	useEffect( () => spring.on( 'change', ( v ) => {
		if ( ref.current ) ref.current.textContent = format( v, decimalPlaces );
	} ), [ spring, decimalPlaces ] );
	return (
		<span ref={ ref } className={ cn( 'inline-block tabular-nums tracking-normal', className ) }>
			{ format( 0, decimalPlaces ) }
		</span>
	);
}

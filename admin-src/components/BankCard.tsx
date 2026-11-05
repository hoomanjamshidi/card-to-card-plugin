/**
 * Live bank card plate — the same markup and classes as templates/card.php, so the panel preview is
 * exactly what customers see (payment.css is loaded on the panel page). Pointer tilt + glare.
 */
import { useRef } from 'react';
import type { Card } from '../lib/types';
import { bankOf, formatCard, formatSheba, sheba as toSheba } from '../lib/banks';
import { cn } from '../lib/utils';

export function BankCard( { card, tilt = true, logo = true, className, compact }: { card: Card; tilt?: boolean; logo?: boolean; className?: string; compact?: boolean } ) {
	const ref = useRef<HTMLDivElement>( null );
	const { slug, bank } = bankOf( card );
	const custom = card.skin === 'custom';
	const colors = custom ? [ card.color1, card.color2, card.color1 ] : bank.colors;
	const style = {
		'--c1': colors[ 0 ],
		'--c2': colors[ 1 ],
		'--c3': colors[ 2 ],
		'--ink': custom ? '#ffffff' : bank.ink,
		'--foil': custom ? '#ffffff' : bank.accent,
	} as React.CSSProperties;
	const move = ( e: React.PointerEvent ) => {
		const el = ref.current;
		if ( ! tilt || ! el ) return;
		const r = el.getBoundingClientRect();
		const x = ( e.clientX - r.left ) / r.width;
		const y = ( e.clientY - r.top ) / r.height;
		el.classList.add( 'is-tilting' );
		el.style.setProperty( '--ry', `${ ( x - 0.5 ) * 14 }deg` );
		el.style.setProperty( '--rx', `${ ( 0.5 - y ) * 12 }deg` );
		el.style.setProperty( '--mx', `${ x * 100 }%` );
		el.style.setProperty( '--my', `${ y * 100 }%` );
	};
	const leave = () => {
		const el = ref.current;
		if ( ! el ) return;
		el.classList.remove( 'is-tilting' );
		el.style.setProperty( '--rx', '0deg' );
		el.style.setProperty( '--ry', '0deg' );
	};
	const number = formatCard( card.number );
	const ibanOnly = ! number && toSheba( card.sheba );
	return (
		<div className={ cn( 'ctc', className ) } dir="rtl">
			<div ref={ ref } className={ cn( 'ctc-plate', `is-${ card.skin }`, slug ? `bank-${ slug }` : 'bank-unknown', compact && 'is-compact' ) } style={ style } onPointerMove={ move } onPointerLeave={ leave }>
				<span className="ctc-plate-pattern" aria-hidden="true" />
				<span className="ctc-plate-glare" aria-hidden="true" />
				<div className="ctc-plate-top">
					{ logo && bank.logo ? <span className="ctc-plate-logo" aria-hidden="true" dangerouslySetInnerHTML={ { __html: bank.logo } } /> : null }
					<span className="ctc-plate-bank">{ bank.name ? `بانک ${ bank.name }` : 'کارت بانکی' }</span>
					<svg className="ctc-plate-nfc" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" aria-hidden="true">
						<path d="M8.5 8.5a5 5 0 0 1 0 7M12 6a8.5 8.5 0 0 1 0 12M15.5 3.5a12 12 0 0 1 0 17" />
					</svg>
				</div>
				<span className="ctc-chip" aria-hidden="true">
					<i />
				</span>
				<b className={ cn( 'ctc-plate-no', ibanOnly && 'is-sheba' ) } dir="ltr">
					{ ibanOnly ? formatSheba( card.sheba ) : number || '•••• •••• •••• ••••' }
				</b>
				<div className="ctc-plate-bottom">
					<span className="ctc-plate-holder">
						<small>به نام</small>
						<span>{ card.holder || '—' }</span>
					</span>
				</div>
			</div>
		</div>
	);
}

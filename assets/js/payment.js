/**
 * Card to card — payment page behaviour (no dependencies):
 *  - copy buttons (Clipboard API with a textarea fallback for older/insecure contexts);
 *  - 3D tilt + glare on the bank card plates (pointer, respects reduced motion);
 *  - card switcher when several cards are offered;
 *  - receipt picker: name, image preview, drag state, size check, busy submit;
 *  - deadline countdown (hours:minutes, every 30 s).
 */
( function () {
	'use strict';

	const L = window.ctcPay || {};
	const fa = ( s ) => String( s ).replace( /\d/g, ( d ) => '۰۱۲۳۴۵۶۷۸۹'[ d ] );
	const reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	/**
	 * Copy text.
	 *
	 * @param {string} text Text.
	 * @return {Promise<void>}
	 */
	const copy = ( text ) => {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
		return new Promise( ( resolve, reject ) => {
			const ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.setAttribute( 'readonly', '' );
			ta.style.cssText = 'position:fixed;top:-1000px;opacity:0';
			document.body.appendChild( ta );
			ta.select();
			try {
				document.execCommand( 'copy' ) ? resolve() : reject();
			} catch ( e ) {
				reject( e );
			}
			ta.remove();
		} );
	};

	/** Copy buttons (event delegation, works for boxes added later). */
	document.addEventListener( 'click', ( event ) => {
		const btn = event.target.closest( '.ctc [data-copy]' );
		if ( ! btn ) {
			return;
		}
		const label = btn.querySelector( '[data-copy-label]' );
		copy( btn.dataset.copy ).then( () => {
			btn.classList.add( 'is-done' );
			if ( label ) {
				label.textContent = L.copied || 'کپی شد';
			}
			window.clearTimeout( btn._ctcT );
			btn._ctcT = window.setTimeout( () => {
				btn.classList.remove( 'is-done' );
				if ( label ) {
					label.textContent = L.copy || 'کپی';
				}
			}, 1800 );
		} ).catch( () => {} );
	} );

	/**
	 * 3D tilt on a plate.
	 *
	 * @param {HTMLElement} plate Plate.
	 */
	const tilt = ( plate ) => {
		if ( reduce || plate._ctcTilt ) {
			return;
		}
		plate._ctcTilt = true;
		let frame = 0;
		plate.addEventListener( 'pointermove', ( e ) => {
			if ( 'touch' === e.pointerType ) {
				return;
			}
			const r = plate.getBoundingClientRect();
			const x = ( e.clientX - r.left ) / r.width;
			const y = ( e.clientY - r.top ) / r.height;
			cancelAnimationFrame( frame );
			frame = requestAnimationFrame( () => {
				plate.classList.add( 'is-tilting' );
				plate.style.setProperty( '--ry', `${ ( x - 0.5 ) * 14 }deg` );
				plate.style.setProperty( '--rx', `${ ( 0.5 - y ) * 12 }deg` );
				plate.style.setProperty( '--mx', `${ x * 100 }%` );
				plate.style.setProperty( '--my', `${ y * 100 }%` );
			} );
		} );
		plate.addEventListener( 'pointerleave', () => {
			cancelAnimationFrame( frame );
			plate.classList.remove( 'is-tilting' );
			plate.style.setProperty( '--rx', '0deg' );
			plate.style.setProperty( '--ry', '0deg' );
		} );
	};

	/**
	 * Card switcher.
	 *
	 * @param {HTMLElement} box Cards section.
	 */
	const switcher = ( box ) => {
		const tabs = Array.from( box.querySelectorAll( '.ctc-switch [role=tab]' ) );
		if ( tabs.length < 2 ) {
			return;
		}
		const show = ( tab ) => {
			tabs.forEach( ( t ) => {
				const on = t === tab;
				t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				t.tabIndex = on ? 0 : -1;
				const panel = document.getElementById( t.getAttribute( 'aria-controls' ) );
				if ( panel ) {
					panel.hidden = ! on;
				}
			} );
		};
		tabs.forEach( ( tab, i ) => {
			tab.addEventListener( 'click', () => show( tab ) );
			tab.addEventListener( 'keydown', ( e ) => {
				// RTL: ArrowLeft goes to the next tab.
				const step = { ArrowLeft: 1, ArrowRight: -1 }[ e.key ];
				if ( step ) {
					e.preventDefault();
					const next = tabs[ ( i + step + tabs.length ) % tabs.length ];
					next.focus();
					show( next );
				}
			} );
		} );
		show( tabs[ 0 ] );
	};

	/**
	 * Receipt form.
	 *
	 * @param {HTMLFormElement} form Form.
	 */
	const receipt = ( form ) => {
		const input = form.querySelector( 'input[type=file]' );
		const drop = form.querySelector( '.ctc-drop' );
		const name = form.querySelector( '[data-ctc-name]' );
		const preview = form.querySelector( '[data-ctc-preview]' );
		const submit = form.querySelector( '.ctc-submit' );
		const max = Number( form.dataset.max ) || 0;
		const idle = name ? name.textContent : '';
		let url = '';
		if ( ! input || ! drop ) {
			return;
		}
		const bad = ( text ) => {
			name.textContent = text;
			drop.classList.add( 'is-bad' );
			drop.classList.remove( 'has-file' );
			input.setAttribute( 'aria-invalid', 'true' );
		};
		const show = () => {
			const file = input.files && input.files[ 0 ];
			if ( url ) {
				URL.revokeObjectURL( url );
			}
			url = '';
			preview.hidden = true;
			drop.classList.remove( 'is-bad', 'has-file' );
			input.removeAttribute( 'aria-invalid' );
			if ( ! file ) {
				name.textContent = idle;
				return;
			}
			if ( max && file.size > max ) {
				bad( ( L.tooBig || '%s MB' ).replace( '%s', fa( Math.round( max / 1048576 ) ) ) );
				return;
			}
			name.textContent = file.name;
			drop.classList.add( 'has-file' );
			// HEIC / PDF have no browser preview; the name is enough.
			if ( /^image\/(jpeg|png|webp|gif)$/.test( file.type ) ) {
				url = URL.createObjectURL( file );
				preview.src = url;
				preview.hidden = false;
			}
		};
		input.addEventListener( 'change', show );
		[ 'dragenter', 'dragover' ].forEach( ( t ) => input.addEventListener( t, () => drop.classList.add( 'is-over' ) ) );
		[ 'dragleave', 'drop' ].forEach( ( t ) => input.addEventListener( t, () => drop.classList.remove( 'is-over' ) ) );
		form.addEventListener( 'submit', ( event ) => {
			const file = input.files && input.files[ 0 ];
			if ( ! file || ( max && file.size > max ) ) {
				event.preventDefault();
				if ( ! file ) {
					bad( L.pick || '' );
				}
				input.focus();
				return;
			}
			if ( submit ) {
				submit.setAttribute( 'aria-busy', 'true' );
				const label = submit.querySelector( 'span' );
				if ( label ) {
					label.textContent = L.sending || '…';
				}
			}
		} );
	};

	/** Deadline countdown. */
	const countdown = () => {
		const box = document.querySelector( '.ctc [data-ctc-deadline]' );
		const out = box && box.querySelector( '[data-ctc-left]' );
		if ( ! out ) {
			return;
		}
		const end = Date.now() + Number( box.dataset.ctcDeadline ) * 1000;
		const tick = () => {
			const left = Math.max( 0, Math.floor( ( end - Date.now() ) / 60000 ) );
			out.textContent = left > 0 ? ( L.left || '%s' ).replace( '%s', fa( `${ Math.floor( left / 60 ) }:${ String( left % 60 ).padStart( 2, '0' ) }` ) ) : '';
		};
		tick();
		window.setInterval( tick, 30000 );
	};

	const init = () => {
		if ( false !== L.tilt ) {
			document.querySelectorAll( '.ctc [data-ctc-tilt]' ).forEach( tilt );
		}
		document.querySelectorAll( '.ctc [data-ctc-cards]' ).forEach( switcher );
		document.querySelectorAll( '.ctc [data-ctc-form]' ).forEach( receipt );
		countdown();
	};

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );

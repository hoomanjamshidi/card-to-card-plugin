/**
 * Card to card — payment page behaviour (no dependencies):
 *  - copy buttons (Clipboard API with a textarea fallback for older/insecure contexts);
 *  - 3D tilt + glare on the bank card plates (pointer, respects reduced motion);
 *  - card switcher when several cards are offered;
 *  - receipt picker: name + size, image preview, drag state, big photos / HEIC shrunk to JPEG in the
 *    browser, size check, single busy submit;
 *  - deadline countdown («۲ ساعت و ۱۰ دقیقه مانده», every 30 s, red in the last hour).
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

	/** «واریز کرده‌ام؛ ارسال رسید»: smooth jump to the form and focus the file picker (plain #anchor without JS). */
	document.addEventListener( 'click', ( event ) => {
		const jump = event.target.closest( '.ctc [data-ctc-jump]' );
		const form = jump && document.getElementById( 'ctc-form' );
		if ( ! form ) {
			return;
		}
		event.preventDefault();
		form.scrollIntoView( { behavior: reduce ? 'auto' : 'smooth', block: 'start' } );
		const input = form.querySelector( 'input[type=file]' );
		if ( input ) {
			input.focus( { preventScroll: true } );
		}
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
	 * Human file size: «۸۵۰ کیلوبایت», «۲٫۴ مگابایت».
	 *
	 * @param {number} bytes Size.
	 * @return {string}
	 */
	const size = ( bytes ) => ( bytes >= 1048576
		? ( L.mb || '%s MB' ).replace( '%s', fa( ( bytes / 1048576 ).toFixed( 1 ) ).replace( '.', '٫' ) )
		: ( L.kb || '%s KB' ).replace( '%s', fa( Math.max( 1, Math.round( bytes / 1024 ) ) ) ) );

	/** Images above this are shrunk before upload (phone photos are 3–8 MB, a receipt reads fine at 2400 px). */
	const SHRINK_OVER = 1.5 * 1048576;

	/**
	 * Load a picked image.
	 *
	 * @param {File} file File.
	 * @return {Promise<HTMLImageElement>}
	 */
	const decode = ( file ) => new Promise( ( resolve, reject ) => {
		const img = new Image();
		const src = URL.createObjectURL( file );
		img.onload = () => {
			URL.revokeObjectURL( src );
			resolve( img );
		};
		img.onerror = () => {
			URL.revokeObjectURL( src );
			reject( new Error( 'decode' ) );
		};
		img.src = src;
	} );

	/**
	 * Re-encode as JPEG with the long side at most `side` px (white under transparent PNG parts;
	 * browsers apply the EXIF rotation when drawing).
	 *
	 * @param {HTMLImageElement} img     Image.
	 * @param {number}           side    Long side.
	 * @param {number}           quality JPEG quality.
	 * @return {Promise<Blob|null>}
	 */
	const encode = ( img, side, quality ) => {
		const scale = Math.min( 1, side / Math.max( img.naturalWidth, img.naturalHeight ) );
		const canvas = document.createElement( 'canvas' );
		canvas.width = Math.max( 1, Math.round( img.naturalWidth * scale ) );
		canvas.height = Math.max( 1, Math.round( img.naturalHeight * scale ) );
		const ctx = canvas.getContext( '2d' );
		ctx.fillStyle = '#fff';
		ctx.fillRect( 0, 0, canvas.width, canvas.height );
		ctx.drawImage( img, 0, 0, canvas.width, canvas.height );
		return new Promise( ( resolve ) => canvas.toBlob( resolve, 'image/jpeg', quality ) );
	};

	/**
	 * A big photo (or an iPhone HEIC the browser can read) becomes a smaller JPEG, so it passes a
	 * low server upload limit and uploads fast on mobile data. Anything else, or any failure, keeps
	 * the original file; the server checks it either way.
	 *
	 * @param {File}   file File.
	 * @param {number} max  Upload limit in bytes (0 = none).
	 * @return {Promise<File>}
	 */
	const shrink = async ( file, max ) => {
		const heic = /^image\/hei[cf]$/i.test( file.type ) || /\.hei[cf]$/i.test( file.name );
		const raster = /^image\/(jpeg|png|webp)$/.test( file.type );
		const limit = max ? Math.min( max, SHRINK_OVER ) : SHRINK_OVER;
		if ( ! ( heic || ( raster && file.size > limit ) ) || ! window.DataTransfer || ! HTMLCanvasElement.prototype.toBlob ) {
			return file;
		}
		try {
			const img = await decode( file );
			for ( const [ side, quality ] of [ [ 2400, 0.85 ], [ 2000, 0.8 ], [ 1600, 0.72 ] ] ) {
				const blob = await encode( img, side, quality );
				if ( blob && ( ! max || blob.size <= max ) && ( heic || blob.size < file.size ) ) {
					return new File( [ blob ], file.name.replace( /\.[^.]*$/, '' ) + '.jpg', { type: 'image/jpeg', lastModified: Date.now() } );
				}
			}
		} catch ( e ) {}
		return file;
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
		const hint = form.querySelector( '#ctc-file-h' );
		const preview = form.querySelector( '[data-ctc-preview]' );
		const submit = form.querySelector( '.ctc-submit' );
		const label = submit && submit.querySelector( 'span' );
		const max = Number( form.dataset.max ) || 0;
		const idle = name ? name.textContent : '';
		const idleHint = hint ? hint.textContent : '';
		const idleLabel = label ? label.textContent : '';
		let url = '';
		let turn = 0;
		let preparing = false;
		let sending = false;
		if ( ! input || ! drop || ! name ) {
			return;
		}
		const setHint = ( text ) => {
			if ( hint ) {
				hint.textContent = text;
			}
		};
		const bad = ( text ) => {
			name.textContent = text;
			setHint( idleHint );
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
			if ( preview ) {
				preview.hidden = true;
			}
			drop.classList.remove( 'is-bad', 'has-file' );
			input.removeAttribute( 'aria-invalid' );
			if ( ! file ) {
				name.textContent = idle;
				setHint( idleHint );
				return;
			}
			if ( max && file.size > max ) {
				bad( ( L.tooBig || '%s MB' ).replace( '%s', fa( Math.round( max / 1048576 ) ) ) );
				return;
			}
			name.textContent = file.name;
			setHint( L.change ? `${ size( file.size ) } · ${ L.change }` : size( file.size ) );
			drop.classList.add( 'has-file' );
			// HEIC / PDF have no browser preview; the name is enough.
			if ( preview && /^image\/(jpeg|png|webp|gif)$/.test( file.type ) ) {
				url = URL.createObjectURL( file );
				preview.src = url;
				preview.hidden = false;
			}
		};
		const busy = ( on ) => {
			preparing = on;
			drop.classList.toggle( 'is-busy', on );
			if ( on ) {
				name.textContent = L.prep || '…';
				setHint( '' );
			}
			if ( submit ) {
				submit.disabled = on;
			}
		};
		input.addEventListener( 'change', async () => {
			const mine = ++turn;
			const file = input.files && input.files[ 0 ];
			if ( file ) {
				busy( true );
				const out = await shrink( file, max );
				if ( mine !== turn ) {
					return; // Another file was picked meanwhile.
				}
				if ( out !== file ) {
					try {
						const dt = new DataTransfer();
						dt.items.add( out );
						input.files = dt.files;
					} catch ( e ) {}
				}
				busy( false );
			}
			show();
		} );
		[ 'dragenter', 'dragover' ].forEach( ( t ) => input.addEventListener( t, () => drop.classList.add( 'is-over' ) ) );
		[ 'dragleave', 'drop' ].forEach( ( t ) => input.addEventListener( t, () => drop.classList.remove( 'is-over' ) ) );
		form.addEventListener( 'submit', ( event ) => {
			const file = input.files && input.files[ 0 ];
			if ( sending || preparing || ! file || ( max && file.size > max ) ) {
				event.preventDefault();
				if ( ! file && ! preparing ) {
					bad( L.pick || '' );
					input.focus();
				}
				return;
			}
			// One submit only: a second tap would post the same receipt again.
			sending = true;
			if ( submit ) {
				submit.setAttribute( 'aria-busy', 'true' );
				if ( label ) {
					label.textContent = L.sending || '…';
				}
				if ( L.wait && ! form.querySelector( '.ctc-wait' ) ) {
					const note = document.createElement( 'p' );
					note.className = 'ctc-wait';
					note.setAttribute( 'role', 'status' );
					note.textContent = L.wait;
					submit.after( note );
				}
			}
		} );
		// Back button after a submit (page from the back-forward cache): ready to send again.
		window.addEventListener( 'pageshow', ( event ) => {
			if ( ! event.persisted ) {
				return;
			}
			sending = false;
			if ( submit ) {
				submit.removeAttribute( 'aria-busy' );
				if ( label ) {
					label.textContent = idleLabel;
				}
			}
			const note = form.querySelector( '.ctc-wait' );
			if ( note ) {
				note.remove();
			}
		} );
	};

	/**
	 * «۲ ساعت و ۱۰ دقیقه»: "2:10" read like a clock time, not like time left.
	 *
	 * @param {number} minutes Minutes left.
	 * @return {string}
	 */
	const span = ( minutes ) => {
		const h = Math.floor( minutes / 60 );
		const m = minutes % 60;
		if ( ! h ) {
			return ( L.m || '%s min' ).replace( '%s', fa( m ) );
		}
		if ( ! m ) {
			return ( L.h || '%s h' ).replace( '%s', fa( h ) );
		}
		return ( L.hm || '%1$s:%2$s' ).replace( '%1$s', fa( h ) ).replace( '%2$s', fa( m ) );
	};

	/** Deadline countdown (red in the last hour). */
	const countdown = () => {
		const box = document.querySelector( '.ctc [data-ctc-deadline]' );
		const out = box && box.querySelector( '[data-ctc-left]' );
		if ( ! out ) {
			return;
		}
		const end = Date.now() + Number( box.dataset.ctcDeadline ) * 1000;
		const tick = () => {
			const left = Math.max( 0, Math.ceil( ( end - Date.now() ) / 60000 ) );
			out.textContent = left > 0 ? ( L.left || '%s' ).replace( '%s', span( left ) ) : '';
			box.classList.toggle( 'is-urgent', left > 0 && left <= 60 );
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

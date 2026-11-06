/** Small helpers. */
import clsx, { type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export const cn = ( ...inputs: ClassValue[] ) => twMerge( clsx( inputs ) );

const FA = '۰۱۲۳۴۵۶۷۸۹';

/** Latin → Persian digits. */
export const fa = ( value: string | number ) => String( value ).replace( /\d/g, ( d ) => FA[ Number( d ) ] );

/** Persian / Arabic → Latin digits. */
export const latin = ( value: string ) =>
	value.replace( /[۰-۹]/g, ( d ) => String( FA.indexOf( d ) ) ).replace( /[٠-٩]/g, ( d ) => String( '٠١٢٣٤٥٦٧٨٩'.indexOf( d ) ) );

/** 1234567 → «۱٬۲۳۴٬۵۶۷». */
export const money = ( value: number ) => fa( Math.round( value ).toLocaleString( 'en-US' ) ).replace( /,/g, '٬' );

export const boot = () => window.ctcBoot;

/** WCAG relative luminance of «#rrggbb». */
const luminance = ( hex: string ) => {
	const m = /^#?([0-9a-f]{6})$/i.exec( hex.trim() );
	if ( ! m ) return 0;
	const c = [ 0, 2, 4 ].map( ( i ) => parseInt( m[ 1 ].slice( i, i + 2 ), 16 ) / 255 ).map( ( v ) => ( v <= 0.03928 ? v / 12.92 : ( ( v + 0.055 ) / 1.055 ) ** 2.4 ) );
	return 0.2126 * c[ 0 ] + 0.7152 * c[ 1 ] + 0.0722 * c[ 2 ];
};

/** Readable text colour on these backgrounds (same rule as Support\Colors::ink()). */
export const ink = ( ...backgrounds: string[] ) => ( Math.max( ...backgrounds.map( luminance ) ) > 0.45 ? '#111827' : '#ffffff' );

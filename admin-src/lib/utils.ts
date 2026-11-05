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

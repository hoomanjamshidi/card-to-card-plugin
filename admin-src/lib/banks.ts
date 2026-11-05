/** Bank detection and number checks — mirrors includes/Support/Banks.php. */
import type { Bank, Card } from './types';
import { boot, latin } from './utils';

export const digits = ( value: string ) => latin( value ).replace( /\D+/g, '' );

/** Luhn check of a 16-digit card. */
export function cardValid( value: string ): boolean {
	const d = digits( value );
	if ( ! /^\d{16}$/.test( d ) ) return false;
	let sum = 0;
	for ( let i = 0; i < 16; i++ ) {
		let n = Number( d[ i ] );
		if ( i % 2 === 0 ) {
			n *= 2;
			if ( n > 9 ) n -= 9;
		}
		sum += n;
	}
	return sum % 10 === 0;
}

/** «IR» + 24 digits, or ''. */
export function sheba( value: string ): string {
	const up = latin( value ).toUpperCase();
	if ( /[A-Z]/.test( up.replace( /^\s*IR/, '' ) ) ) return '';
	const d = up.replace( /\D+/g, '' );
	return d.length === 24 ? `IR${ d }` : '';
}

/** ISO 7064 mod-97. */
export function shebaValid( value: string ): boolean {
	const s = sheba( value );
	if ( ! s ) return false;
	const num = s.slice( 4 ) + '1827' + s.slice( 2, 4 );
	let rest = 0;
	for ( const ch of num ) rest = ( rest * 10 + Number( ch ) ) % 97;
	return rest === 1;
}

/** Bank slug from BIN, then IBAN code. */
export function detect( card: string, iban = '' ): string {
	const banks = boot().banks;
	const bin = digits( card ).slice( 0, 6 );
	if ( bin.length === 6 ) {
		for ( const [ slug, b ] of Object.entries( banks ) ) if ( b.bins.includes( bin ) ) return slug;
	}
	const s = sheba( iban );
	if ( s ) {
		const code = s.slice( 4, 7 );
		for ( const [ slug, b ] of Object.entries( banks ) ) if ( b.codes.includes( code ) ) return slug;
	}
	return '';
}

export const UNKNOWN: Bank = { name: '', bins: [], codes: [], colors: [ '#1f2433', '#363d52', '#11141d' ], ink: '#ffffff', accent: '#d9dde8', logo: '' };

export function bankOf( card: Card ): { slug: string; bank: Bank } {
	const slug = card.bank || detect( card.number, card.sheba );
	return { slug, bank: boot().banks[ slug ] ?? UNKNOWN };
}

export const formatCard = ( value: string ) => digits( value ).slice( 0, 16 ).replace( /(\d{4})(?=\d)/g, '$1 ' );

export const formatSheba = ( value: string ) => {
	const s = sheba( value ) || ( 'IR' + digits( value ) ).slice( 0, 26 );
	return s.replace( /(.{4})(?=.)/g, '$1 ' );
};

/**
 * Copy and optimise the bank logos into assets/banks/<slug>.svg.
 * Source: persianlabs/icons (MIT), based on zegond/logos-per-banks (CC0). Usage: node bin/build-logos.mjs <path-to-persianlabs-icons>
 */
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { optimize } from 'svgo';

const src = process.argv[ 2 ];
if ( ! src ) {
	console.error( 'Usage: node bin/build-logos.mjs <persianlabs-icons-repo>' );
	process.exit( 1 );
}
// Our slug => persianlabs file name.
const map = {
	melli: 'melli', sepah: 'sepah', mellat: 'mellat', tejarat: 'tejarat', saderat: 'saderat',
	keshavarzi: 'keshavarzi', maskan: 'maskan', refah: 'refah', 'tosee-saderat': 'tosee-saderat',
	'sanat-madan': 'sanat-madan', postbank: 'postbank', 'tosee-taavon': 'tosee-taavon', karafarin: 'karafarin',
	parsian: 'parsian', 'eghtesad-novin': 'eghtesad-novin', saman: 'saman', pasargad: 'pasargad',
	sarmayeh: 'sarmayeh', sina: 'sina', 'mehr-iran': 'mehr-iran', shahr: 'shahr', ayandeh: 'ayandeh',
	ansar: 'ansar', gardeshgari: 'gardeshgari', hekmat: 'hekmat', dey: 'dey', 'iran-zamin': 'iran-zamin',
	resalat: 'resalat', 'khavar-mianeh': 'khavar-mianeh', ghavamin: 'ghavamin', markazi: 'bank-markazi',
	'iran-venezuela': 'iran-venezuela', noor: 'noor', tosee: 'tosee', melall: 'melall', kosar: 'kosar',
	'mehr-eghtesad': 'mehr-eghtesad',
};
const out = 'assets/banks';
mkdirSync( out, { recursive: true } );
let total = 0;
for ( const [ slug, file ] of Object.entries( map ) ) {
	const path = join( src, 'packages/icons/assets/banks/color', `${ file }.svg` );
	if ( ! existsSync( path ) ) {
		console.warn( 'missing', slug );
		continue;
	}
	const { data } = optimize( readFileSync( path, 'utf8' ), {
		multipass: true,
		plugins: [
			{ name: 'preset-default', params: { floatPrecision: 1 } },
			// Unique ids: several logos can sit on one page.
			{ name: 'prefixIds', params: { prefix: `ctc-${ slug }` } },
			'removeDimensions', 'removeTitle', 'removeDesc',
			{ name: 'removeAttrs', params: { attrs: [ 'class', 'data-name' ] } },
		],
	} );
	const svg = data.replace( '<svg', '<svg aria-hidden="true" focusable="false"' );
	writeFileSync( join( out, `${ slug }.svg` ), svg );
	total += svg.length;
}
console.log( `logos: ${ Object.keys( map ).length } files, ${ Math.round( total / 1024 ) } KB` );

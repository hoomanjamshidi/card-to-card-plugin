/**
 * Minify the front-end assets: assets/css/payment.css → payment.min.css, assets/js/payment.js →
 * payment.min.js (the plugin loads the .min files unless SCRIPT_DEBUG is on).
 */
import { readFileSync, writeFileSync } from 'node:fs';
import CleanCSS from 'clean-css';
import { minify } from 'terser';

const css = new CleanCSS( { level: 2 } ).minify( readFileSync( 'assets/css/payment.css', 'utf8' ) );
if ( css.errors.length ) throw new Error( css.errors.join( '\n' ) );
writeFileSync( 'assets/css/payment.min.css', css.styles );

const js = await minify( readFileSync( 'assets/js/payment.js', 'utf8' ), { compress: true, mangle: true } );
writeFileSync( 'assets/js/payment.min.js', js.code );

console.log( `payment.min.css ${ ( css.styles.length / 1024 ).toFixed( 1 ) } KB, payment.min.js ${ ( js.code.length / 1024 ).toFixed( 1 ) } KB` );

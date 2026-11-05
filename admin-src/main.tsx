/**
 * Settings panel entry: mounts the app on #ctc-app (WooCommerce › کارت به کارت).
 */
import { createRoot } from 'react-dom/client';
import { App } from './App';
import './styles.css';

const mount = () => {
	const el = document.getElementById( 'ctc-app' );
	if ( el && window.ctcBoot ) createRoot( el ).render( <App /> );
};
if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', mount );
else mount();

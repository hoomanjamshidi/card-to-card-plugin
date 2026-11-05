/** Settings state shared by the pages: the working copy, a setter and dirty tracking. */
import { createContext, useContext } from 'react';
import type { Settings } from './types';

export type Store = {
	s: Settings;
	set: <K extends keyof Settings>( key: K, value: Settings[ K ] ) => void;
	patch: ( values: Partial<Settings> ) => void;
};

export const StoreContext = createContext<Store | null>( null );

export function useStore(): Store {
	const store = useContext( StoreContext );
	if ( ! store ) throw new Error( 'StoreContext missing' );
	return store;
}

/** Helpers for module settings UIs. */
import { useStore } from '../lib/store';
import type { ModuleMeta, Modules } from '../lib/types';
import { boot, fa } from '../lib/utils';

/** A module's settings and a patcher. */
export function useModule<K extends keyof Modules & string>( id: K ): [ Modules[ K ], ( patch: Partial<Modules[ K ]> ) => void ] {
	const { s, set } = useStore();
	const conf = ( s.modules?.[ id ] ?? boot().defaults.modules[ id ] ) as Modules[ K ];
	const update = ( patch: Partial<Modules[ K ]> ) => set( 'modules', { ...s.modules, [ id ]: { ...conf, ...patch } } as Modules );
	return [ conf, update ];
}

/** Panel data of a module (lists, usage). */
export function moduleData<T>( id: string ): T {
	return ( boot().modules.find( ( m: ModuleMeta ) => m.id === id )?.data ?? {} ) as T;
}

/** «۶۰۳۷ ۹۹۰۰ … ۱۲۳۴» → short label of a card. */
export function cardLabel( number: string, holder: string ): string {
	const tail = number.slice( -4 );
	return `${ holder || 'کارت' } — ${ tail ? `${ fa( tail ) } ••••` : 'شبا' }`;
}

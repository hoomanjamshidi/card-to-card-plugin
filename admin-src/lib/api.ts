/** REST client (cookie auth + wp_rest nonce). */
import { boot } from './utils';

export class ApiError extends Error {}

/**
 * Call a ctc/v1 route.
 *
 * @param path   Route after ctc/v1/.
 * @param init   Method and JSON body.
 */
export async function api<T>( path: string, init: { method?: string; body?: unknown } = {} ): Promise<T> {
	const res = await fetch( boot().rest + path, {
		method: init.method ?? 'GET',
		credentials: 'same-origin',
		headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': boot().nonce },
		body: init.body === undefined ? undefined : JSON.stringify( init.body ),
	} );
	const data = await res.json().catch( () => null );
	if ( ! res.ok ) {
		throw new ApiError( ( data && ( data as { message?: string } ).message ) || `HTTP ${ res.status }` );
	}
	return data as T;
}

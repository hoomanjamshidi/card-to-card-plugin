/** REST client (cookie auth + wp_rest nonce). */
import { boot } from './utils';

export class ApiError extends Error {}

/**
 * Call a ctc/v1 route.
 *
 * @param path   Route after ctc/v1/.
 * @param init   Method and JSON body.
 */
export async function api<T>( path: string, init: { method?: string; body?: unknown; timeout?: number } = {} ): Promise<T> {
	// A server that never answers must not leave the panel on its loading placeholders forever.
	const ctrl = new AbortController();
	const timer = window.setTimeout( () => ctrl.abort(), init.timeout ?? 45000 );
	let res: Response;
	try {
		res = await fetch( boot().rest + path, {
			method: init.method ?? 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': boot().nonce },
			body: init.body === undefined ? undefined : JSON.stringify( init.body ),
			signal: ctrl.signal,
		} );
	} catch ( e ) {
		throw new ApiError( ctrl.signal.aborted ? 'سرور در زمان مناسب پاسخ نداد؛ دوباره امتحان کنید.' : 'اتصال به سایت برقرار نشد؛ اینترنت را بررسی کنید و دوباره امتحان کنید.' );
	} finally {
		window.clearTimeout( timer );
	}
	const data = await res.json().catch( () => null );
	if ( res.ok && data === null ) {
		// A PHP notice or a plugin printed HTML into the answer.
		throw new ApiError( 'پاسخ سرور نامعتبر بود (احتمالاً خطا یا هشدار PHP از افزونه‌ای دیگر).' );
	}
	if ( ! res.ok ) {
		throw new ApiError( ( data && ( data as { message?: string } ).message ) || `HTTP ${ res.status }` );
	}
	return data as T;
}

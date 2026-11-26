/**
 * Pack-Out Spot field app service worker.
 *
 * The server prepends SETUP: the version, the shell address, and the script
 * and style addresses the shell loads.
 *
 * - The shell is fetched from the network when there is one, and from the
 *   cache when there is not.
 * - Scripts and styles come from the cache. One that this worker did not
 *   list is looked for in what another version of the app kept: after an
 *   update the browser lets the new worker take over only when this one has
 *   gone quiet, and until then the new page asks this one for its files.
 * - Everything else, including the API and map tiles, goes to the network
 *   untouched. Downloaded regions live in the pos-regions cache, which the
 *   app manages and this worker never clears.
 */

/* global SETUP */

const CACHE = 'pos-field-' + SETUP.version;

// What a page loads as a file of its own.
const FILES = [ 'script', 'style', 'font', 'image' ];

function sameOrigin( url ) {
	return new URL( url, self.location.href ).origin === self.location.origin;
}

/** A file under this address in what any version of the app kept. */
function keptByAny( url ) {
	return caches.keys().then( ( names ) =>
		Promise.all( names.filter( ( name ) => name.startsWith( 'pos-field-' ) ).map( ( name ) => caches.match( url, { cacheName: name } ) ) )
	).then( ( found ) => found.find( Boolean ) );
}

self.addEventListener( 'install', ( event ) => {
	event.waitUntil(
		caches.open( CACHE ).then( ( cache ) => {
			const requests = [ SETUP.shell ].concat( SETUP.assets ).map( ( url ) =>
				cache.add( new Request( url, sameOrigin( url ) ? { credentials: 'same-origin' } : { mode: 'no-cors' } ) )
			);
			return Promise.all( requests );
		} ).then( () => self.skipWaiting() )
	);
} );

self.addEventListener( 'activate', ( event ) => {
	event.waitUntil(
		caches.keys().then( ( names ) =>
			Promise.all( names.filter( ( name ) => name.startsWith( 'pos-field-' ) && name !== CACHE ).map( ( name ) => caches.delete( name ) ) )
		).then( () => self.clients.claim() )
	);
} );

self.addEventListener( 'fetch', ( event ) => {
	const request = event.request;
	if ( request.method !== 'GET' ) {
		return;
	}

	const url = new URL( request.url );
	const shell = new URL( SETUP.shell, self.location.href );

	if ( request.mode === 'navigate' && url.origin === shell.origin && url.pathname.replace( /\/$/, '' ) === shell.pathname.replace( /\/$/, '' ) ) {
		event.respondWith(
			fetch( request ).then( ( response ) => {
				if ( response.ok ) {
					const copy = response.clone();
					caches.open( CACHE ).then( ( cache ) => cache.put( SETUP.shell, copy ) );
				}
				return response;
			} ).catch( () => caches.match( SETUP.shell, { cacheName: CACHE } ) )
		);
		return;
	}

	if ( SETUP.assets.indexOf( request.url ) !== -1 ) {
		event.respondWith( caches.match( request.url, { cacheName: CACHE } ).then( ( cached ) => cached || fetch( request ) ) );
		return;
	}

	// The site's own file that this worker did not list: from the network, and with no network from what another
	// version kept. The address of such a file carries its version, so a kept copy is never an old one.
	if ( url.origin === self.location.origin && FILES.indexOf( request.destination ) !== -1 ) {
		event.respondWith( fetch( request ).catch( () => keptByAny( request.url ).then( ( cached ) => cached || Response.error() ) ) );
	}
} );

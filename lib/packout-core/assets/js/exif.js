/**
 * Pack-Out Spot: where a photo was taken.
 *
 * Reads the location a camera wrote into a JPEG file. Many phones remove it
 * before a web page receives the photo, so the answer is often "not known".
 *
 * window.packoutExif.gps( file ) resolves to { lat, lng } or null. It never rejects.
 */
( function () {
	'use strict';

	// The location sits in the header, which is well inside the first part of the file.
	var HEAD = 262144;

	function rational( view, at, little ) {
		var bottom = view.getUint32( at + 4, little );
		return bottom ? view.getUint32( at, little ) / bottom : NaN;
	}

	/** Entries of one directory, by tag: { type, count, at } where `at` is where the value or its offset is stored. */
	function directory( view, at, little ) {
		var entries = {};
		var count = view.getUint16( at, little );
		for ( var index = 0; index < count; index++ ) {
			var entry = at + 2 + index * 12;
			entries[ view.getUint16( entry, little ) ] = { type: view.getUint16( entry + 2, little ), count: view.getUint32( entry + 4, little ), at: entry + 8 };
		}
		return entries;
	}

	function degrees( view, start, entry, little ) {
		// Three fractions: degrees, minutes, seconds.
		if ( ! entry || entry.type !== 5 || entry.count < 3 ) {
			return NaN;
		}
		var at = start + view.getUint32( entry.at, little );
		return rational( view, at, little ) + rational( view, at + 8, little ) / 60 + rational( view, at + 16, little ) / 3600;
	}

	function tiff( view, start ) {
		var order = view.getUint16( start );
		if ( order !== 0x4949 && order !== 0x4d4d ) {
			return null;
		}
		var little = order === 0x4949;
		if ( view.getUint16( start + 2, little ) !== 42 ) {
			return null;
		}
		var pointer = directory( view, start + view.getUint32( start + 4, little ), little )[ 0x8825 ];
		if ( ! pointer ) {
			return null;
		}
		var gps = directory( view, start + view.getUint32( pointer.at, little ), little );
		var lat = degrees( view, start, gps[ 2 ], little );
		var lng = degrees( view, start, gps[ 4 ], little );
		if ( ! isFinite( lat ) || ! isFinite( lng ) ) {
			return null;
		}
		if ( gps[ 1 ] && String.fromCharCode( view.getUint8( gps[ 1 ].at ) ).toUpperCase() === 'S' ) {
			lat = -lat;
		}
		if ( gps[ 3 ] && String.fromCharCode( view.getUint8( gps[ 3 ].at ) ).toUpperCase() === 'W' ) {
			lng = -lng;
		}
		// A camera with no fix writes zeros.
		if ( Math.abs( lat ) > 90 || Math.abs( lng ) > 180 || ( lat === 0 && lng === 0 ) ) {
			return null;
		}
		return { lat: Math.round( lat * 1e6 ) / 1e6, lng: Math.round( lng * 1e6 ) / 1e6 };
	}

	function read( buffer ) {
		var view = new DataView( buffer );
		if ( view.byteLength < 4 || view.getUint16( 0 ) !== 0xffd8 ) {
			return null;
		}
		var at = 2;
		while ( at + 4 <= view.byteLength ) {
			var marker = view.getUint16( at );
			// Image data begins. The header is over.
			if ( ( marker & 0xff00 ) !== 0xff00 || marker === 0xffda ) {
				return null;
			}
			// APP1 that starts with "Exif\0\0".
			if ( marker === 0xffe1 && view.getUint32( at + 4 ) === 0x45786966 && view.getUint16( at + 8 ) === 0 ) {
				return tiff( view, at + 10 );
			}
			at += 2 + view.getUint16( at + 2 );
		}
		return null;
	}

	/** The location in the bytes of a file, or null. A damaged or cut-off header reads past its end. */
	function parse( buffer ) {
		try {
			return read( buffer );
		} catch ( error ) {
			return null;
		}
	}

	function gps( file ) {
		return new Promise( function ( resolve ) {
			if ( ! file || ! file.slice || ! window.FileReader || ( file.type && file.type !== 'image/jpeg' ) ) {
				resolve( null );
				return;
			}
			var reader = new window.FileReader();
			reader.onload = function () {
				resolve( parse( reader.result ) );
			};
			reader.onerror = function () {
				resolve( null );
			};
			reader.readAsArrayBuffer( file.slice( 0, HEAD ) );
		} );
	}

	/** The first location found among some files, or null. */
	function first( files ) {
		return Promise.all( Array.prototype.slice.call( files || [] ).map( gps ) ).then( function ( found ) {
			return found.filter( Boolean )[ 0 ] || null;
		} );
	}

	window.packoutExif = { gps: gps, first: first, parse: parse };
} )();

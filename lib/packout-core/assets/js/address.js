/**
 * Pack-Out Spot: is this team address free?
 *
 * As a lead or a coordinator types a new address for a team, the field says
 * whether it can be used. The server checks again when the form is saved, so
 * the form works without this file.
 *
 * The field carries data-pos-address (where to ask), data-pos-nonce, and
 * data-pos-checking (what to say while asking). The answer goes in the
 * element named by aria-describedby.
 */
( function () {
	'use strict';

	var timers = new window.WeakMap();
	var asked = new window.WeakMap();

	function say( field, text, state ) {
		var status = document.getElementById( ( field.getAttribute( 'aria-describedby' ) || '' ).split( ' ' )[ 0 ] );
		if ( status ) {
			status.textContent = text;
			status.setAttribute( 'data-state', state );
		}
		field.setAttribute( 'data-pos-available', state );
	}

	function check( field ) {
		var value = field.value.trim().toLowerCase();
		asked.set( field, value );
		if ( ! value || ! field.getAttribute( 'data-pos-address' ) ) {
			say( field, '', '' );
			return;
		}
		say( field, field.getAttribute( 'data-pos-checking' ) || '', 'checking' );
		var url = field.getAttribute( 'data-pos-address' );
		window.fetch( url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + 'address=' + encodeURIComponent( value ), {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': field.getAttribute( 'data-pos-nonce' ) || '' },
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( answer ) {
			// A slow answer to an earlier value is out of date.
			if ( asked.get( field ) !== value ) {
				return;
			}
			say( field, answer && answer.message ? answer.message : '', answer && answer.available ? 'yes' : 'no' );
		} ).catch( function () {
			if ( asked.get( field ) === value ) {
				say( field, '', '' );
			}
		} );
	}

	document.addEventListener( 'input', function ( event ) {
		var field = event.target;
		if ( ! field.getAttribute || ! field.getAttribute( 'data-pos-address' ) || ! window.fetch ) {
			return;
		}
		window.clearTimeout( timers.get( field ) );
		// What was said is about the address before this keystroke.
		asked.set( field, null );
		say( field, field.value.trim() ? field.getAttribute( 'data-pos-checking' ) || '' : '', field.value.trim() ? 'checking' : '' );
		timers.set( field, window.setTimeout( function () {
			check( field );
		}, 350 ) );
	} );
} )();

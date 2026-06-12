( function () {
	'use strict';

	var cfg = window.CrmConnectAttr || {};
	var COOKIE = cfg.cookie || 'crmc_attr';
	var DAYS = parseInt( cfg.days, 10 ) || 90;

	var TRACKED = [
		'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
		'gclid', 'fbclid', 'msclkid', 'gad_source'
	];

	function readCookie( name ) {
		var match = document.cookie.match( '(?:^|; )' + name + '=([^;]*)' );
		return match ? decodeURIComponent( match[ 1 ] ) : '';
	}

	function writeCookie( name, value, days ) {
		var date = new Date();
		date.setTime( date.getTime() + days * 86400000 );
		document.cookie = name + '=' + encodeURIComponent( value ) +
			'; expires=' + date.toUTCString() + '; path=/; SameSite=Lax';
	}

	function currentParams() {
		var out = {};
		var search = new URLSearchParams( window.location.search );
		TRACKED.forEach( function ( key ) {
			var value = search.get( key );
			if ( value ) {
				out[ key ] = value;
			}
		} );
		return out;
	}

	function touch( params ) {
		return {
			params: params,
			referrer: document.referrer || '',
			landing_page: window.location.href,
			landing_page_path: window.location.origin + window.location.pathname,
			timestamp: new Date().toISOString()
		};
	}

	var stored = {};
	try {
		stored = JSON.parse( readCookie( COOKIE ) ) || {};
	} catch ( e ) {
		stored = {};
	}
	if ( typeof stored !== 'object' || stored === null ) {
		stored = {};
	}

	var params = currentParams();
	var hasNewTouch = Object.keys( params ).length > 0;

	if ( ! stored.first ) {
		stored.first = touch( params );
	}
	if ( hasNewTouch || ! stored.last ) {
		stored.last = touch( params );
	}

	writeCookie( COOKIE, JSON.stringify( stored ), DAYS );
}() );

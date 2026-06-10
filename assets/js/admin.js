( function () {
	'use strict';

	var cfg = window.CrmConnectAdmin || {};
	var i18n = cfg.i18n || {};

	function api( path, options ) {
		options = options || {};
		var headers = { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce };
		return fetch( cfg.root + path, Object.assign( { headers: headers }, options ) )
			.then( function ( res ) {
				return res.text().then( function ( text ) {
					var data = null;
					if ( text ) { try { data = JSON.parse( text ); } catch ( e ) { data = null; } }
					if ( ! res.ok ) {
						var msg = ( data && ( data.message || ( data.data && data.data.message ) ) ) ||
							( ( i18n.badResponse || 'Something went wrong.' ) + ' (' + res.status + ')' );
						throw new Error( msg );
					}
					if ( data === null && text ) {
						throw new Error( ( i18n.badResponse || 'Something went wrong.' ) + ' (' + res.status + ')' );
					}
					return data;
				} );
			} )
			.catch( function ( err ) {
				if ( err instanceof TypeError ) { throw new Error( i18n.badResponse || 'Could not reach the server.' ); }
				throw err;
			} );
	}

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		attrs = attrs || {};
		Object.keys( attrs ).forEach( function ( key ) {
			if ( key === 'class' ) { node.className = attrs[ key ]; }
			else if ( key === 'text' ) { node.textContent = attrs[ key ]; }
			else if ( key.indexOf( 'on' ) === 0 ) { node.addEventListener( key.slice( 2 ).toLowerCase(), attrs[ key ] ); }
			else if ( attrs[ key ] !== null && attrs[ key ] !== undefined ) { node.setAttribute( key, attrs[ key ] ); }
		} );
		( children || [] ).forEach( function ( child ) {
			if ( typeof child === 'string' ) { node.appendChild( document.createTextNode( child ) ); }
			else if ( child ) { node.appendChild( child ); }
		} );
		return node;
	}

	function option( value, label, selected ) {
		var o = el( 'option', { value: value, text: label } );
		if ( selected ) { o.selected = true; }
		return o;
	}

	function select( options, value, onChange ) {
		var node = el( 'select', onChange ? { onChange: onChange } : {} );
		options.forEach( function ( opt ) { node.appendChild( option( opt.value, opt.label, opt.value === value ) ); } );
		return node;
	}

	function notice( message, kind, withSettings ) {
		var children = [ el( 'p', { text: message } ) ];
		if ( withSettings && cfg.settingsUrl ) {
			children.push( el( 'a', { class: 'button button-small', href: cfg.settingsUrl, text: i18n.goToSettings || 'Open settings' } ) );
		}
		return el( 'div', { class: 'notice notice-' + kind + ' inline crm-connect-notice' }, children );
	}

	function toast( message, kind ) {
		var node = el( 'div', { class: 'crmc-toast crmc-toast--' + ( kind || 'ok' ), text: message } );
		document.body.appendChild( node );
		requestAnimationFrame( function () { node.classList.add( 'is-in' ); } );
		setTimeout( function () {
			node.classList.remove( 'is-in' );
			setTimeout( function () { if ( node.parentNode ) { node.parentNode.removeChild( node ); } }, 250 );
		}, 2600 );
	}

	function confirmDialog( opts ) {
		opts = opts || {};
		return new Promise( function ( resolve ) {
			var overlay;
			function close( val ) {
				return function () {
					document.removeEventListener( 'keydown', onKey );
					if ( overlay && overlay.parentNode ) { overlay.parentNode.removeChild( overlay ); }
					resolve( val );
				};
			}
			function onKey( e ) {
				if ( e.key === 'Escape' ) { close( false )(); }
				else if ( e.key === 'Enter' ) { close( true )(); }
			}
			var confirmBtn = el( 'button', { type: 'button', class: 'crmc-btn crmc-btn--danger-solid', text: opts.confirmText || 'Delete', onClick: close( true ) } );
			var modal = el( 'div', { class: 'crmc-modal' }, [
				el( 'h2', { class: 'crmc-modal__title', text: opts.title || 'Are you sure?' } ),
				el( 'p', { class: 'crmc-modal__msg', text: opts.message || '' } ),
				el( 'div', { class: 'crmc-modal__actions' }, [
					el( 'button', { type: 'button', class: 'crmc-btn crmc-btn--ghost', text: opts.cancelText || 'Cancel', onClick: close( false ) } ),
					confirmBtn
				] )
			] );
			overlay = el( 'div', { class: 'crmc-modal-overlay' }, [ modal ] );
			overlay.addEventListener( 'mousedown', function ( e ) { if ( e.target === overlay ) { close( false )(); } } );
			document.addEventListener( 'keydown', onKey );
			document.body.appendChild( overlay );
			confirmBtn.focus();
		} );
	}

	function norm( s ) { return String( s || '' ).toLowerCase().replace( /[^a-z0-9]/g, '' ); }

	function autoMatch( id, label, crmFields ) {
		var fid = norm( id ), flabel = norm( label );
		for ( var i = 0; i < crmFields.length; i++ ) {
			var n = norm( crmFields[ i ].name ), l = norm( crmFields[ i ].label );
			if ( n === fid || l === fid || n === flabel || l === flabel ) { return crmFields[ i ].name; }
		}
		var alias = { name: 'firstname', fullname: 'firstname', firstname: 'firstname', lastname: 'lastname', phone: 'mobilenumber', mobile: 'mobilenumber', company: 'companyname' };
		var want = alias[ fid ] || alias[ flabel ];
		if ( want ) {
			for ( var j = 0; j < crmFields.length; j++ ) {
				if ( norm( crmFields[ j ].name ).indexOf( want ) >= 0 ) { return crmFields[ j ].name; }
			}
		}
		return '';
	}

	function pickField( fields, wants ) {
		for ( var w = 0; w < wants.length; w++ ) {
			for ( var i = 0; i < fields.length; i++ ) {
				if ( norm( fields[ i ].value ).indexOf( wants[ w ] ) >= 0 || norm( fields[ i ].label ).indexOf( wants[ w ] ) >= 0 ) { return fields[ i ].value; }
			}
		}
		return '';
	}

	function pickCrm( crmFields, wants ) {
		for ( var w = 0; w < wants.length; w++ ) {
			for ( var i = 0; i < crmFields.length; i++ ) {
				if ( norm( crmFields[ i ].name ).indexOf( wants[ w ] ) >= 0 || norm( crmFields[ i ].label ).indexOf( wants[ w ] ) >= 0 ) { return crmFields[ i ].name; }
			}
		}
		return '';
	}

	function matchSource( by, fields ) {
		return by === 'mobile_number'
			? pickField( fields, [ 'phone', 'mobile', 'whatsapp', 'messenger', 'number' ] )
			: pickField( fields, [ 'email' ] );
	}

	var TRACKING = [
		[ '_attr_last_params_utm_source', 'Campaign source (utm_source)', [ 'lastsource', 'source' ] ],
		[ '_attr_last_params_utm_medium', 'Campaign medium (utm_medium)', [ 'lastmedium', 'medium' ] ],
		[ '_attr_last_params_utm_campaign', 'Campaign name (utm_campaign)', [ 'lastcampaign', 'campaign' ] ],
		[ '_attr_last_params_utm_term', 'Campaign term (utm_term)', [ 'keyword', 'term' ] ],
		[ '_attr_last_params_utm_content', 'Campaign content (utm_content)', [ 'content' ] ],
		[ '_attr_last_params_gclid', 'Google click ID', [ 'gclid', 'clickid' ] ],
		[ '_attr_last_referrer', 'Referrer', [ 'referrer', 'firstsource' ] ],
		[ '_attr_last_landing_page', 'Landing page', [ 'landingpage', 'firstsource' ] ],
		[ '_meta_page_url', 'Page they submitted on', [ 'createdfrompage', 'pageurl' ] ]
	];

	function combo( groups, value, placeholder, onChange, allowFreeText ) {
		var labelFor = {};
		groups.forEach( function ( g ) { ( g.options || [] ).forEach( function ( o ) { labelFor[ o.value ] = o.label; } ); } );

		var wrap = el( 'span', { class: 'crmc-combo' } );
		wrap.dataset.value = value || '';

		var input = el( 'input', { type: 'text', class: 'crmc-combo__input', placeholder: placeholder || 'Search…', autocomplete: 'off' } );
		input.value = value ? ( labelFor[ value ] || '' ) : '';

		var menu = el( 'div', { class: 'crmc-combo__menu' } );
		menu.style.display = 'none';

		function build( filter ) {
			menu.innerHTML = '';
			var q = norm( filter );
			var any = false;
			groups.forEach( function ( g ) {
				var matches = ( g.options || [] ).filter( function ( o ) {
					return ! q || norm( o.label ).indexOf( q ) >= 0 || norm( o.value ).indexOf( q ) >= 0;
				} );
				if ( ! matches.length ) { return; }
				if ( g.label ) { menu.appendChild( el( 'div', { class: 'crmc-combo__group', text: g.label } ) ); }
				matches.forEach( function ( o ) {
					any = true;
					var item = el( 'div', { class: 'crmc-combo__opt' + ( o.value === wrap.dataset.value ? ' is-sel' : '' ), text: o.label } );
					item.addEventListener( 'mousedown', function ( e ) {
						e.preventDefault();
						wrap.dataset.value = o.value;
						input.value = o.label;
						menu.style.display = 'none';
						if ( onChange ) { onChange( o.value ); }
					} );
					menu.appendChild( item );
				} );
			} );
			if ( ! any ) { menu.appendChild( el( 'div', { class: 'crmc-combo__empty', text: i18n.noMatches || 'No matches' } ) ); }
		}

		input.addEventListener( 'focus', function () { build( '' ); menu.style.display = ''; input.select(); } );
		input.addEventListener( 'input', function () { build( input.value ); menu.style.display = ''; } );
		input.addEventListener( 'blur', function () {
			setTimeout( function () {
				menu.style.display = 'none';
				if ( allowFreeText && ! wrap.dataset.value && input.value.trim() ) {
					wrap.dataset.value = input.value.trim();
					labelFor[ wrap.dataset.value ] = input.value.trim();
				}
				input.value = wrap.dataset.value ? ( labelFor[ wrap.dataset.value ] || wrap.dataset.value ) : '';
			}, 120 );
		} );

		wrap.appendChild( input );
		wrap.appendChild( menu );
		return wrap;
	}

	function typeHint( type ) {
		if ( type === 'dropdown' || type === 'multiselect' ) { return ' · list'; }
		if ( type === 'date' ) { return ' · date'; }
		if ( type === 'number' ) { return ' · number'; }
		return '';
	}

	function crmGroup( crmFields ) {
		return [ { label: '', options: crmFields.map( function ( f ) { return { value: f.name, label: f.label + typeHint( f.type ) }; } ) } ];
	}

	function crmFieldByName( crmFields, name ) {
		for ( var i = 0; i < crmFields.length; i++ ) { if ( crmFields[ i ].name === name ) { return crmFields[ i ]; } }
		return null;
	}

	function isListField( field ) {
		return !! field && ( field.type === 'dropdown' || field.type === 'multiselect' );
	}

	function srcGroups( src ) {
		return [
			{ label: i18n.grpFields || 'Form fields', options: src.fields },
			{ label: i18n.grpTracking || 'Where they came from', options: src.trackables }
		];
	}

	function initSettings() {
		var button = document.getElementById( 'crm-connect-test' );
		var result = document.querySelector( '.crm-connect-test-result' );
		if ( ! button ) { return; }
		button.addEventListener( 'click', function () {
			button.disabled = true;
			result.textContent = i18n.testing || 'Checking…';
			result.className = 'crm-connect-test-result';
			api( 'connection/test', { method: 'POST', body: '{}' } ).then( function () {
				result.textContent = i18n.connected || 'Connected!';
				result.classList.add( 'is-ok' );
			} ).catch( function ( err ) {
				result.textContent = err.message;
				result.classList.add( 'is-error' );
			} ).then( function () { button.disabled = false; } );
		} );
	}

	function MappingEditor( root ) {
		this.root = root;
		this.list = document.getElementById( 'crm-connect-profiles' );
		this.template = document.getElementById( 'crm-connect-profile-template' );
		this.forms = [];
		this.objects = [];
		this.formFieldCache = {};
		this.crmFieldCache = {};
	}

	MappingEditor.prototype.boot = function () {
		var self = this;
		if ( ! cfg.configured ) {
			this.root.insertBefore( notice( i18n.notConnected, 'warning', true ), this.list );
		}
		this.list.innerHTML = '';
		this.list.appendChild( el( 'p', { class: 'crm-connect-loading', text: i18n.loadingFields || 'Loading…' } ) );

		Promise.all( [ api( 'forms' ), api( 'crm/objects' ), api( 'profiles' ) ] ).then( function ( res ) {
			self.forms = res[ 0 ] || [];
			self.objects = res[ 1 ] || [];
			self.profiles = res[ 2 ] || [];
			self.showList();
		} ).catch( function ( err ) {
			self.list.innerHTML = '';
			self.list.appendChild( notice( err.message, 'error', true ) );
		} );

		var add = document.getElementById( 'crm-connect-add-profile' );
		if ( add ) {
			add.addEventListener( 'click', function () { self.showEditor( { destinations: [] } ); } );
		}
	};

	MappingEditor.prototype.formById = function ( id ) {
		for ( var i = 0; i < this.forms.length; i++ ) { if ( this.forms[ i ].id === id ) { return this.forms[ i ]; } }
		return null;
	};

	MappingEditor.prototype.formLabel = function ( form ) {
		if ( ! form ) { return ''; }
		return ( form.name || form.id ) + ( form.title ? ' - ' + form.title : '' ) + ' (' + form.id + ')';
	};

	MappingEditor.prototype.objectLabel = function ( key ) {
		for ( var i = 0; i < this.objects.length; i++ ) { if ( this.objects[ i ].key === key ) { return this.objects[ i ].label; } }
		return key;
	};

	MappingEditor.prototype.sendsTo = function ( profile ) {
		var self = this;
		return ( profile.destinations || [] ).map( function ( d ) { return self.objectLabel( d.object ); } ).join( ', ' );
	};

	MappingEditor.prototype.showList = function () {
		var self = this;
		this.list.innerHTML = '';

		if ( ! this.profiles.length ) {
			this.list.appendChild( this.emptyState() );
			return;
		}

		var search = el( 'input', { type: 'search', class: 'crmc-search', placeholder: i18n.searchForms || 'Search forms…' } );
		this.list.appendChild( el( 'div', { class: 'crmc-list-toolbar' }, [ search ] ) );

		var tbody = el( 'tbody' );
		var table = el( 'table', { class: 'crmc-table' }, [
			el( 'thead', {}, [ el( 'tr', {}, [
				el( 'th', { text: i18n.colFormHead || 'Form' } ),
				el( 'th', { text: i18n.colSends || 'Sends to' } ),
				el( 'th', { class: 'crmc-table__c', text: i18n.colActive || 'Active' } ),
				el( 'th', { class: 'crmc-table__r' } )
			] ) ] ),
			tbody
		] );

		var rows = [];
		this.profiles.forEach( function ( profile ) {
			var form = self.formById( profile.form_id ) || { name: profile.form_name, id: profile.form_id, title: '' };
			var tr = self.renderListRow( profile, form );
			tr._search = norm( ( form.name || '' ) + ' ' + ( form.title || '' ) + ' ' + self.sendsTo( profile ) );
			rows.push( tr );
			tbody.appendChild( tr );
		} );

		search.addEventListener( 'input', function () {
			var q = norm( search.value );
			rows.forEach( function ( tr ) { tr.style.display = ( ! q || tr._search.indexOf( q ) >= 0 ) ? '' : 'none'; } );
		} );

		this.list.appendChild( el( 'div', { class: 'crmc-table-wrap' }, [ table ] ) );
	};

	MappingEditor.prototype.renderListRow = function ( profile, form ) {
		var self = this;
		var formCell = el( 'td', { class: 'crmc-table__form' }, [
			el( 'div', { class: 'crmc-table__title', text: form.name || form.id || '(form)' } ),
			el( 'div', { class: 'crmc-table__sub', text: ( form.title ? form.title + ' · ' : '' ) + ( form.id || '' ) } )
		] );
		formCell.addEventListener( 'click', function () { self.showEditor( profile ); } );

		var toggle = el( 'input', { type: 'checkbox' } );
		toggle.checked = profile.enabled !== false;
		toggle.addEventListener( 'change', function () {
			profile.enabled = toggle.checked;
			api( 'profiles', { method: 'POST', body: JSON.stringify( profile ) } ).then( function () {
				toast( toggle.checked ? ( i18n.turnedOn || 'Turned on' ) : ( i18n.turnedOff || 'Turned off' ) );
			} ).catch( function ( err ) {
				toggle.checked = ! toggle.checked;
				toast( err.message, 'error' );
			} );
		} );

		return el( 'tr', {}, [
			formCell,
			el( 'td', { text: this.sendsTo( profile ) || '-' } ),
			el( 'td', { class: 'crmc-table__c' }, [ el( 'label', { class: 'crmc-switch' }, [ toggle, el( 'span', { class: 'crmc-switch__track' } ) ] ) ] ),
			el( 'td', { class: 'crmc-table__r' }, [
				el( 'button', { type: 'button', class: 'crmc-btn crmc-btn--ghost crmc-btn--sm', text: i18n.edit || 'Edit', onClick: function () { self.showEditor( profile ); } } ),
				el( 'button', { type: 'button', class: 'crmc-btn crmc-btn--ghost crmc-btn--sm', text: i18n.duplicate || 'Duplicate', onClick: function () { self.duplicate( profile ); } } ),
				el( 'button', { type: 'button', class: 'crmc-btn crmc-btn--danger crmc-btn--sm', text: i18n.delete || 'Delete', onClick: function () { self.deleteProfile( profile ); } } )
			] )
		] );
	};

	MappingEditor.prototype.duplicate = function ( profile ) {
		var copy = JSON.parse( JSON.stringify( profile ) );
		copy.id = '';
		copy.created_at = '';
		copy.enabled = true;
		copy.form_id = '';
		copy.form_name = '';
		copy._reuse = true;
		this.showEditor( copy );
	};

	MappingEditor.prototype.deleteProfile = function ( profile ) {
		var self = this;
		confirmDialog( {
			title: i18n.delTitle || 'Delete this connection?',
			message: i18n.delMsg || 'New entries from this form will stop syncing to Freshsales. Records already sent are not affected.',
			confirmText: i18n.delete || 'Delete'
		} ).then( function ( ok ) {
			if ( ! ok ) { return; }
			var done = function () { self.profiles = self.profiles.filter( function ( p ) { return p !== profile; } ); toast( i18n.deletedToast || 'Mapping deleted' ); self.showList(); };
			if ( profile.id ) {
				api( 'profiles/' + encodeURIComponent( profile.id ), { method: 'DELETE' } ).then( done ).catch( function ( err ) { window.alert( err.message ); } );
			} else { done(); }
		} );
	};

	MappingEditor.prototype.emptyState = function () {
		var self = this;
		return el( 'div', { class: 'crm-connect-empty' }, [
			el( 'div', { class: 'crm-connect-empty__icon', text: '🔗' } ),
			el( 'h2', { text: i18n.emptyTitle || 'Connect your first form' } ),
			el( 'p', { text: i18n.emptySub || 'Pick a form and every submission flows into Freshsales - fields, tracking and all.' } ),
			el( 'button', { type: 'button', class: 'crmc-btn crmc-btn--primary', text: i18n.emptyCta || 'Connect a form', onClick: function () { self.showEditor( { destinations: [] } ); } } )
		] );
	};

	MappingEditor.prototype.showEditor = function ( profile ) {
		var self = this;
		this.list.innerHTML = '';
		this.list.appendChild( el( 'button', { type: 'button', class: 'crmc-btn crmc-btn--ghost crmc-back', text: i18n.back || '← Back to list', onClick: function () { self.showList(); } } ) );
		this.list.appendChild( this.renderEditor( profile ) );
	};

	MappingEditor.prototype.formFields = function ( formId ) {
		var self = this;
		if ( this.formFieldCache[ formId ] ) { return Promise.resolve( this.formFieldCache[ formId ] ); }
		return api( 'forms/' + encodeURIComponent( formId ) + '/fields' ).then( function ( d ) { self.formFieldCache[ formId ] = d; return d; } );
	};

	MappingEditor.prototype.crmFields = function ( object, refresh ) {
		var self = this;
		if ( ! refresh && this.crmFieldCache[ object ] ) { return Promise.resolve( this.crmFieldCache[ object ] ); }
		return api( 'crm/objects/' + encodeURIComponent( object ) + '/fields' + ( refresh ? '?refresh=1' : '' ) ).then( function ( d ) { self.crmFieldCache[ object ] = d; return d; } );
	};

	MappingEditor.prototype.defaultObject = function () {
		for ( var i = 0; i < this.objects.length; i++ ) { if ( this.objects[ i ].key === 'contacts' ) { return 'contacts'; } }
		return this.objects.length ? this.objects[ 0 ].key : '';
	};

	MappingEditor.prototype.renderEditor = function ( profile ) {
		var self = this;
		var node = this.template.content.firstElementChild.cloneNode( true );
		node.dataset.profileId = profile.id || '';
		node.dataset.enabled = profile.enabled === false ? 'false' : 'true';
		node.dataset.createdAt = profile.created_at || '';

		var formOptions = [ { value: '', label: i18n.chooseForm || 'Choose a form…' } ].concat(
			this.forms.map( function ( f ) { return { value: f.id, label: self.formLabel( f ) }; } )
		);
		var formSelect = node.querySelector( '.crm-connect-form-select' );
		formOptions.forEach( function ( opt ) { formSelect.appendChild( option( opt.value, opt.label, opt.value === profile.form_id ) ); } );

		var destWrap = node.querySelector( '.crm-connect-destinations' );
		var status = node.querySelector( '.crm-connect-profile__status' );

		if ( profile._reuse ) {
			status.textContent = i18n.reuseHint || 'Copied this mapping - choose a form to apply it to, then Save.';
		}

		var dests = profile.destinations || [];
		dests.forEach( function ( dest ) { destWrap.appendChild( self.renderDestination( node, dest ) ); } );
		if ( ! dests.length ) {
			destWrap.appendChild( self.renderDestination( node, { field_map: {}, unique: {}, catch_all: '', _fresh: true } ) );
		}

		formSelect.addEventListener( 'change', function () {
			destWrap.querySelectorAll( '.crm-connect-destination' ).forEach( function ( dn ) {
				var current = self.collectDestination( dn );
				if ( ! Object.keys( current.field_map ).length ) { current._fresh = true; }
				self.populateDestination( node, dn.querySelector( '.crm-connect-destination__body' ), current.object, current );
			} );
		} );

		node.querySelector( '.crm-connect-add-destination' ).addEventListener( 'click', function () {
			destWrap.appendChild( self.renderDestination( node, { field_map: {}, unique: {}, catch_all: '', _fresh: true } ) );
		} );

		node.querySelector( '.crm-connect-save' ).addEventListener( 'click', function () {
			var payload = self.collect( node, formSelect );
			if ( ! payload.form_id ) { status.textContent = i18n.chooseForm || 'Choose a form first.'; return; }
			status.textContent = i18n.saving || 'Saving…';
			api( 'profiles', { method: 'POST', body: JSON.stringify( payload ) } ).then( function ( saved ) {
				var idx = self.profiles.indexOf( profile );
				if ( idx >= 0 ) { self.profiles[ idx ] = saved; } else { self.profiles.push( saved ); }
				toast( i18n.savedToast || 'Mapping saved' );
				self.showList();
			} ).catch( function ( err ) { status.textContent = err.message; toast( err.message, 'error' ); } );
		} );

		node.querySelector( '.crm-connect-delete' ).addEventListener( 'click', function () {
			if ( ! node.dataset.profileId ) { self.showList(); return; }
			self.deleteProfile( profile );
		} );

		return node;
	};

	MappingEditor.prototype.collectDestination = function ( destNode ) {
		var object = destNode.querySelector( '.crm-connect-destination__head select' ).value;
		var fieldMap = {};
		destNode.querySelectorAll( '.crm-connect-row' ).forEach( function ( row ) {
			if ( row.classList.contains( 'crm-connect-row--head' ) ) { return; }
			var crmCombo = row.querySelector( '.crm-connect-row__crm .crmc-combo' );
			var crmField = crmCombo ? crmCombo.dataset.value : '';
			if ( ! crmField ) { return; }

			var rule;
			if ( row.classList.contains( 'is-static' ) ) {
				var input = row.querySelector( '.crm-connect-row__src input' );
				rule = { source: 'static', value: input ? input.value : '' };
			} else {
				var srcCombo = row.querySelector( '.crm-connect-row__src .crmc-combo' );
				rule = { source: 'field', key: srcCombo ? srcCombo.dataset.value : '' };
			}

			var wrap = row.closest( '.crm-connect-rowwrap' );
			if ( wrap ) {
				var cmap = {};
				wrap.querySelectorAll( '.crm-connect-choicerow' ).forEach( function ( cr ) {
					var from = cr.querySelector( '.crm-connect-choicerow__from' ).value.trim();
					var to = cr.querySelector( '.crm-connect-choicerow__to' ).value;
					if ( from && to ) { cmap[ from ] = to; }
				} );
				if ( Object.keys( cmap ).length ) { rule.choice_map = cmap; }
			}

			var cf = ( destNode._crm || {} )[ crmField ];
			if ( cf && ( cf.type === 'dropdown' || cf.type === 'multiselect' ) && cf.choices && cf.choices.length ) {
				rule.choices = cf.choices.map( function ( c ) { return c.value; } );
			}
			fieldMap[ crmField ] = rule;
		} );

		var acc = destNode.querySelector( '.crm-connect-account' );
		if ( acc && acc.querySelector( 'input[type=checkbox]' ).checked ) {
			var accKind = acc.querySelector( '.crm-connect-account__kind' ).value;
			if ( accKind === 'static' ) {
				var accInput = acc.querySelector( '.crm-connect-account__static' );
				var accVal = accInput ? accInput.value.trim() : '';
				if ( accVal ) { fieldMap[ '__sales_account' ] = { source: 'static', value: accVal }; }
			} else if ( accKind === 'existing' ) {
				var exCombo = acc.querySelector( '.crm-connect-account__existing .crmc-combo' );
				var exInput = exCombo ? exCombo.querySelector( '.crmc-combo__input' ) : null;
				var exVal = exCombo ? ( exCombo.dataset.value || ( exInput ? exInput.value.trim() : '' ) ) : '';
				if ( exVal ) { fieldMap[ '__sales_account' ] = { source: 'static', value: exVal, account_mode: 'existing' }; }
			} else {
				var fCombo = acc.querySelector( '.crm-connect-account__value > .crmc-combo' );
				if ( fCombo && fCombo.dataset.value ) { fieldMap[ '__sales_account' ] = { source: 'field', key: fCombo.dataset.value }; }
			}
		}

		var split = destNode.querySelector( '.crm-connect-split' );
		if ( split && ! split.querySelector( 'input[type=checkbox]' ).checked ) {
			fieldMap[ '__no_split' ] = { source: 'static', value: '1' };
		}

		var unique = { by: '', key: '' };
		var up = destNode.querySelector( '.crm-connect-upsert' );
		if ( up && up.querySelector( 'input[type=checkbox]' ).checked ) {
			unique = { by: up.querySelector( 'select' ).value, key: up.dataset.key || '' };
		}

		var catchAll = '';
		var trk = destNode.querySelector( '.crm-connect-trackables' );
		if ( trk && trk.querySelector( 'input[type=checkbox]' ).checked ) {
			var tc = trk.querySelector( '.crmc-combo' );
			catchAll = tc ? tc.dataset.value : '';
		}

		return { object: object, field_map: fieldMap, unique: unique, catch_all: catchAll };
	};

	MappingEditor.prototype.renderDestination = function ( profileNode, dest ) {
		var self = this;
		if ( ! dest.object && dest._fresh ) { dest.object = this.defaultObject(); }

		var wrap = el( 'div', { class: 'crm-connect-destination' } );
		var objOptions = [ { value: '', label: i18n.choose || 'Choose…' } ].concat(
			this.objects.map( function ( o ) { return { value: o.key, label: o.label }; } )
		);
		var body = el( 'div', { class: 'crm-connect-destination__body' } );
		var objSelect = select( objOptions, dest.object || '', function () {
			self.populateDestination( profileNode, body, objSelect.value, dest );
		} );

		wrap.appendChild( el( 'div', { class: 'crm-connect-destination__head' }, [
			el( 'label', {}, [ el( 'span', { class: 'crm-connect-label', text: i18n.addAs || 'Add as' } ), objSelect ] ),
			el( 'button', { type: 'button', class: 'crmc-btn crmc-btn--danger', text: i18n.remove || 'Remove', onClick: function () {
				confirmDialog( {
					title: i18n.remTitle || 'Remove this object?',
					message: i18n.remMsg || 'This Freshsales object and its field mapping will be removed from this form.',
					confirmText: i18n.remove || 'Remove'
				} ).then( function ( ok ) { if ( ok ) { wrap.remove(); } } );
			} } )
		] ) );
		wrap.appendChild( body );

		if ( dest.object ) { this.populateDestination( profileNode, body, dest.object, dest ); }
		return wrap;
	};

	MappingEditor.prototype.populateDestination = function ( profileNode, body, object, dest ) {
		var self = this;
		body.innerHTML = '';
		if ( ! object ) { return; }

		var formId = profileNode.querySelector( '.crm-connect-form-select' ).value;
		if ( ! formId ) { body.appendChild( el( 'p', { class: 'description', text: i18n.chooseForm || 'Choose a form first.' } ) ); return; }

		body.appendChild( el( 'p', { class: 'crm-connect-loading', text: i18n.loadingFields || 'Loading…' } ) );

		Promise.all( [ this.crmFields( object ), this.formFields( formId ) ] ).then( function ( res ) {
			body.innerHTML = '';
			var crmFields = res[ 0 ] || [];
			var src = self.sourceOptions( res[ 1 ] );

			var destNode = body.closest( '.crm-connect-destination' );
			if ( destNode ) {
				destNode._crm = {};
				crmFields.forEach( function ( f ) { destNode._crm[ f.name ] = f; } );
			}
			var textCrm = crmFields.filter( function ( f ) { return ! f.type || f.type === 'text'; } );

			var rows = el( 'div', { class: 'crm-connect-rows' } );
			rows.appendChild( el( 'div', { class: 'crm-connect-row crm-connect-row--head' }, [
				el( 'span', { text: i18n.colForm || 'Your form' } ), el( 'span', { text: '' } ), el( 'span', { text: i18n.colCrm || 'Freshsales' } ), el( 'span', { text: '' } ), el( 'span', { text: '' } )
			] ) );

			var addRow = function ( crmName, rule ) { rows.appendChild( self.renderRow( crmFields, src, crmName, rule ) ); };
			var autofill = function () {
				rows.querySelectorAll( '.crm-connect-rowwrap' ).forEach( function ( r ) { r.remove(); } );
				src.fields.forEach( function ( f ) { addRow( autoMatch( f.value, f.label, crmFields ), { source: 'field', key: f.value } ); } );
			};

			var existing = dest.field_map || {};
			var mappedKeys = Object.keys( existing ).filter( function ( k ) { return k !== '__sales_account' && k !== '__no_split'; } );
			if ( mappedKeys.length ) {
				mappedKeys.forEach( function ( crmName ) { addRow( crmName, existing[ crmName ] ); } );
			} else if ( src.fields.length ) {
				autofill();
			} else {
				rows.appendChild( el( 'p', { class: 'description', text: i18n.noFields || 'This form has no fields yet.' } ) );
			}
			body.appendChild( rows );

			body.appendChild( el( 'div', { class: 'crm-connect-rowtools' }, [
				el( 'button', { type: 'button', class: 'button-link', text: i18n.addField || '+ Field', onClick: function () { addRow( '', { source: 'field' } ); } } ),
				el( 'button', { type: 'button', class: 'button-link', text: i18n.addStatic || '+ Fixed text', onClick: function () { addRow( '', { source: 'static', value: '' } ); } } ),
				el( 'button', { type: 'button', class: 'button-link', text: i18n.autoMap || 'Auto-match', onClick: autofill } ),
				el( 'button', { type: 'button', class: 'button-link', text: i18n.addTracking || '+ Tracking', onClick: function () {
					TRACKING.forEach( function ( t ) {
						var target = ( t[ 2 ] && pickCrm( textCrm, t[ 2 ] ) ) || autoMatch( t[ 1 ], t[ 0 ], textCrm );
						addRow( target, { source: 'field', key: t[ 0 ] } );
					} );
				} } )
			] ) );

			body.appendChild( el( 'div', { class: 'crm-connect-utils' }, [
				self.renderUpsert( src, dest ),
				object === 'contacts' ? self.renderAccount( src, dest ) : null,
				object === 'contacts' ? self.renderSplit( dest ) : null,
				self.renderTrackables( crmFields, dest ),
				self.renderCreateField( object )
			] ) );
		} ).catch( function ( err ) {
			body.innerHTML = '';
			body.appendChild( notice( err.message, 'error', true ) );
		} );
	};

	MappingEditor.prototype.sourceOptions = function ( data ) {
		var fields = ( ( data && data.form ) || [] ).map( function ( f ) { return { value: f.id, label: ( f.label || f.id ), options: ( f.options || [] ) }; } );
		var tr = ( data && data.trackables ) || {};
		var trackables = Object.keys( tr ).map( function ( k ) { return { value: k, label: tr[ k ] }; } );
		return { fields: fields, trackables: trackables };
	};

	MappingEditor.prototype.renderRow = function ( crmFields, src, crmName, rule ) {
		rule = rule || { source: 'field' };
		var isStatic = rule.source === 'static';

		var choiceBox = el( 'div', { class: 'crm-connect-choices' } );
		choiceBox.style.display = 'none';

		var choiceRow = function ( field, from, to ) {
			var hasChoices = field.choices && field.choices.length;
			var target = hasChoices
				? select( [ { value: '', label: i18n.choose || 'Choose…' } ].concat( field.choices.map( function ( c ) { return { value: c.value, label: c.label }; } ) ), to || '' )
				: el( 'input', { type: 'text', value: to || '', placeholder: i18n.crmValue || 'CRM value (e.g. whatsapp)' } );
			target.className = ( target.className ? target.className + ' ' : '' ) + 'crm-connect-choicerow__to';
			return el( 'div', { class: 'crm-connect-choicerow' }, [
				el( 'input', { type: 'text', class: 'crm-connect-choicerow__from', value: from || '', placeholder: i18n.formValue || 'form value (e.g. WhatsApp)' } ),
				el( 'span', { class: 'crm-connect-row__arrow', text: '→' } ),
				target,
				el( 'button', { type: 'button', class: 'crm-connect-row__del', text: '✕', onClick: function ( e ) { e.target.closest( '.crm-connect-choicerow' ).remove(); } } )
			] );
		};

		var srcOptions = function ( key ) {
			var match = ( src.fields || [] ).filter( function ( f ) { return f.value === key; } )[ 0 ];
			return ( match && match.options ) || [];
		};

		var leftKey = function () {
			if ( isStatic ) { return ''; }
			return ( left && left.dataset && left.dataset.value ) || rule.key || '';
		};

		var renderChoices = function ( name, force ) {
			choiceBox.innerHTML = '';
			var field = crmFieldByName( crmFields, name ) || { name: name, choices: [] };
			var hasMap = rule.choice_map && Object.keys( rule.choice_map ).length;
			if ( ! name || ( ! isListField( field ) && ! force && ! hasMap ) ) {
				choiceBox.style.display = 'none';
				return;
			}
			choiceBox.style.display = '';

			var options = srcOptions( leftKey() );
			if ( options.length ) {
				choiceBox.appendChild( el( 'div', { class: 'crm-connect-choices__auto', text: ( i18n.autoChoices || 'List field. In Freshsales this field must already have these choices, or the value will not save: ' ) + options.join( ', ' ) } ) );
			}

			choiceBox.appendChild( el( 'div', { class: 'crm-connect-choices__hint', text: options.length ? ( i18n.choiceHintOptional || 'Optional - only to rename a value before it is sent:' ) : ( i18n.choiceHint || 'Map values (unmapped are skipped):' ) } ) );

			var map = rule.choice_map || {};
			Object.keys( map ).forEach( function ( from ) { choiceBox.appendChild( choiceRow( field, from, map[ from ] ) ); } );
			if ( ! Object.keys( map ).length && ! options.length ) { choiceBox.appendChild( choiceRow( field, '', '' ) ); }
			var addBtn = el( 'button', { type: 'button', class: 'button-link', text: i18n.addValue || '+ Value', onClick: function () { choiceBox.insertBefore( choiceRow( field, '', '' ), addBtn ); } } );
			choiceBox.appendChild( addBtn );
		};

		var crmField = combo( crmGroup( crmFields ), crmName, i18n.choose || 'Search…', function ( v ) { renderChoices( v ); } );
		var left = isStatic
			? el( 'input', { type: 'text', class: 'crm-connect-static', value: rule.value || '', placeholder: i18n.typeText || 'Type something…' } )
			: combo( srcGroups( src ), rule.key || '', i18n.choose || 'Search…' );

		var mapBtn = el( 'button', { type: 'button', class: 'crm-connect-row__map', text: '≡', title: i18n.mapValues || 'Map values', onClick: function () {
			if ( choiceBox.style.display === 'none' ) { renderChoices( crmField.dataset.value, true ); }
			else { choiceBox.style.display = 'none'; }
		} } );

		var row = el( 'div', { class: 'crm-connect-row' + ( isStatic ? ' is-static' : '' ) }, [
			el( 'span', { class: 'crm-connect-row__src' }, [ left ] ),
			el( 'span', { class: 'crm-connect-row__arrow', text: '→' } ),
			el( 'span', { class: 'crm-connect-row__crm' }, [ crmField ] ),
			isStatic ? el( 'span' ) : mapBtn,
			el( 'button', { type: 'button', class: 'crm-connect-row__del', text: '✕', title: i18n.remove || 'Remove', onClick: function ( e ) { e.target.closest( '.crm-connect-rowwrap' ).remove(); } } )
		] );

		if ( ! isStatic && crmName ) { renderChoices( crmName ); }

		return el( 'div', { class: 'crm-connect-rowwrap' }, [ row, choiceBox ] );
	};

	MappingEditor.prototype.renderUpsert = function ( src, dest ) {
		var unique = dest.unique || {};
		var enabled = !! unique.by || !! dest._fresh;
		var by = unique.by || 'emails';

		var container = el( 'div', { class: 'crm-connect-util crm-connect-upsert' } );
		var bySelect = select( [ { value: 'emails', label: i18n.byEmail || 'email' }, { value: 'mobile_number', label: i18n.byPhone || 'phone' } ], by );

		container.dataset.key = unique.key || matchSource( by, src.fields );
		bySelect.addEventListener( 'change', function () { container.dataset.key = matchSource( bySelect.value, src.fields ); } );

		var inline = el( 'span', { class: 'crm-connect-util__inline' }, [ i18n.matchBy || 'by', ' ', bySelect ] );
		inline.style.display = enabled ? '' : 'none';

		var cb = el( 'input', { type: 'checkbox' } );
		cb.checked = enabled;
		cb.addEventListener( 'change', function () { inline.style.display = cb.checked ? '' : 'none'; } );

		container.appendChild( el( 'label', { class: 'crm-connect-util__check' }, [ cb, el( 'span', { text: ' ' + ( i18n.noDup || "Don't duplicate people" ) } ) ] ) );
		container.appendChild( inline );
		return container;
	};

	MappingEditor.prototype.renderSplit = function ( dest ) {
		var disabled = !! ( dest.field_map || {} )[ '__no_split' ];
		var cb = el( 'input', { type: 'checkbox' } );
		cb.checked = ! disabled;
		return el( 'div', { class: 'crm-connect-util crm-connect-split' }, [
			el( 'label', { class: 'crm-connect-util__check' }, [ cb, el( 'span', { text: ' ' + ( i18n.splitName || 'Split full name into first & last' ) } ) ] )
		] );
	};

	MappingEditor.prototype.accounts = function () {
		if ( ! this.accountsPromise ) { this.accountsPromise = api( 'crm/accounts' ); }
		return this.accountsPromise;
	};

	MappingEditor.prototype.renderAccount = function ( src, dest ) {
		var self = this;
		var rule = ( dest.field_map || {} )[ '__sales_account' ] || null;
		var enabled = !! rule;
		var initialKind = rule ? ( rule.account_mode || ( rule.source === 'field' ? 'field' : 'static' ) ) : 'field';

		var container = el( 'div', { class: 'crm-connect-util crm-connect-account' } );
		var kind = select( [
			{ value: 'field', label: i18n.fromField || 'form field' },
			{ value: 'existing', label: i18n.existingAccount || 'existing account' },
			{ value: 'static', label: i18n.fixedName || 'fixed name' }
		], initialKind );
		kind.className = 'crm-connect-account__kind';

		var srcCombo = combo( srcGroups( src ), rule && rule.source === 'field' ? rule.key : '', i18n.choose || 'Search…' );
		var staticInput = el( 'input', { type: 'text', class: 'crm-connect-account__static', value: rule && rule.source === 'static' ? rule.value : '', placeholder: i18n.companyName || 'e.g. Upgaming' } );
		var existingWrap = el( 'span', { class: 'crm-connect-account__existing' } );

		var loadExisting = function () {
			if ( existingWrap.dataset.loaded ) { return; }
			existingWrap.dataset.loaded = '1';
			existingWrap.appendChild( el( 'span', { class: 'crm-connect-loading', text: i18n.loadingFields || 'Loading…' } ) );
			self.accounts().then( function ( list ) {
				existingWrap.innerHTML = '';
				var opts = ( list || [] ).map( function ( a ) { return { value: a.name, label: a.name }; } );
				existingWrap.appendChild( combo( [ { label: '', options: opts } ], rule && rule.source === 'static' ? rule.value : '', i18n.searchAccount || 'Search or type account…', null, true ) );
			} ).catch( function ( err ) {
				existingWrap.innerHTML = '';
				existingWrap.appendChild( el( 'span', { class: 'crm-connect-error', text: err.message } ) );
			} );
		};

		var valueWrap = el( 'span', { class: 'crm-connect-account__value' } );
		var sync = function () {
			valueWrap.innerHTML = '';
			if ( kind.value === 'field' ) { valueWrap.appendChild( srcCombo ); }
			else if ( kind.value === 'static' ) { valueWrap.appendChild( staticInput ); }
			else { valueWrap.appendChild( existingWrap ); loadExisting(); }
		};
		kind.addEventListener( 'change', sync );
		sync();

		var inline = el( 'span', { class: 'crm-connect-util__inline' }, [ kind, valueWrap ] );
		inline.style.display = enabled ? '' : 'none';

		var cb = el( 'input', { type: 'checkbox' } );
		cb.checked = enabled;
		cb.addEventListener( 'change', function () { inline.style.display = cb.checked ? '' : 'none'; } );

		container.appendChild( el( 'label', { class: 'crm-connect-util__check' }, [ cb, el( 'span', { text: ' ' + ( i18n.linkAccount || 'Link to a company' ) } ) ] ) );
		container.appendChild( inline );
		return container;
	};

	MappingEditor.prototype.renderTrackables = function ( crmFields, dest ) {
		var textFields = crmFields.filter( function ( f ) { return ( ! f.type || f.type === 'text' ) && f.name.indexOf( '__' ) !== 0; } );
		var current = dest.catch_all || '';
		var enabled = current !== '' || !! dest._fresh;
		if ( ! current && dest._fresh ) { current = pickCrm( textFields, [ 'description', 'note', 'detail', 'submission', 'message', 'comment', 'about' ] ); }

		var fieldCombo = combo( crmGroup( textFields ), current, i18n.choose || 'Search…' );
		var inline = el( 'span', { class: 'crm-connect-util__inline' }, [ i18n.saveInto || 'into', ' ', fieldCombo ] );
		inline.style.display = enabled ? '' : 'none';

		var cb = el( 'input', { type: 'checkbox' } );
		cb.checked = enabled;
		cb.addEventListener( 'change', function () { inline.style.display = cb.checked ? '' : 'none'; } );

		return el( 'div', { class: 'crm-connect-util crm-connect-trackables' }, [
			el( 'label', { class: 'crm-connect-util__check' }, [ cb, el( 'span', { text: ' ' + ( i18n.saveSource || 'Save where they came from' ) } ) ] ),
			inline
		] );
	};

	MappingEditor.prototype.renderCreateField = function ( object ) {
		var self = this;
		return el( 'div', { class: 'crm-connect-util' }, [
			el( 'button', { type: 'button', class: 'button-link', text: i18n.newField || '+ New field', onClick: function ( e ) {
				var label = window.prompt( i18n.newFieldPrompt || 'Name of the new field' );
				if ( ! label ) { return; }
				var btn = e.target;
				btn.disabled = true;
				api( 'crm/objects/' + encodeURIComponent( object ) + '/fields', { method: 'POST', body: JSON.stringify( { label: label, type: 'text' } ) } )
					.then( function () { return self.crmFields( object, true ); } )
					.then( function () { window.alert( i18n.newFieldDone || 'Added! Pick the object again to use it.' ); } )
					.catch( function ( err ) { window.alert( err.message ); } )
					.then( function () { btn.disabled = false; } );
			} } )
		] );
	};

	MappingEditor.prototype.collect = function ( node, formSelect ) {
		var self = this;
		var form = this.formById( formSelect.value );
		var destinations = [];

		node.querySelectorAll( '.crm-connect-destination' ).forEach( function ( destNode ) {
			var dest = self.collectDestination( destNode );
			if ( dest.object ) { destinations.push( dest ); }
		} );

		return {
			id: node.dataset.profileId || '',
			source: 'elementor',
			form_id: formSelect.value,
			form_name: form ? form.name : '',
			enabled: node.dataset.enabled !== 'false',
			created_at: node.dataset.createdAt || '',
			destinations: destinations
		};
	};

	function initSubmissions() {
		document.querySelectorAll( '.crm-connect-view' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var detail = btn.closest( 'tr' ).nextElementSibling;
				if ( detail && detail.classList.contains( 'crm-connect-detailrow' ) ) {
					detail.hidden = ! detail.hidden;
					btn.textContent = detail.hidden ? ( i18n.viewData || 'View data' ) : ( i18n.hideData || 'Hide' );
				}
			} );
		} );

		document.querySelectorAll( '.crm-connect-copy' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var cell = btn.closest( 'td' );
				var parts = [ '# CRM Connect submission #' + ( btn.dataset.id || '' ) ];
				cell.querySelectorAll( 'pre[data-copy]' ).forEach( function ( pre ) {
					parts.push( '\n## ' + pre.getAttribute( 'data-copy' ) + '\n' + pre.textContent );
				} );
				var text = parts.join( '\n' );
				var done = function () { var t = btn.textContent; btn.textContent = i18n.copied || 'Copied!'; setTimeout( function () { btn.textContent = t; }, 1500 ); };
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( text ).then( done, function () { window.prompt( 'Copy:', text ); } );
				} else {
					window.prompt( 'Copy:', text );
				}
			} );
		} );
	}

	function init() {
		var wrap = document.querySelector( '.crm-connect[data-crm-page]' );
		if ( ! wrap ) { return; }
		var page = wrap.getAttribute( 'data-crm-page' );
		if ( page === 'settings' ) { initSettings(); }
		else if ( page === 'mappings' ) { new MappingEditor( wrap ).boot(); }
		else if ( page === 'submissions' ) { initSubmissions(); }
	}

	if ( document.readyState !== 'loading' ) { init(); }
	else { document.addEventListener( 'DOMContentLoaded', init ); }
}() );

/* global HMI */
/**
 * HTML & Markdown Importer – admin screen.
 *
 * Steps: upload (file by file in chunks, or one chunked ZIP) → scan → import media one by one
 * → import items parents first → finalize (link rewriting).
 */
( function () {
	'use strict';

	const $ = ( sel, root = document ) => root.querySelector( sel );
	const $$ = ( sel, root = document ) => Array.from( root.querySelectorAll( sel ) );
	const t = HMI.i18n;

	const state = {
		session: null,
		plan: null,
		running: false,
		finalized: false,
	};

	/** Minimal sprintf for %s, %d, %1$s, %2$d and %%. */
	function sprintf( format, ...args ) {
		let index = 0;
		return format.replace( /%(?:(\d+)\$)?([sd%])/g, ( match, pos, type ) => {
			if ( type === '%' ) {
				return '%';
			}
			const value = args[ pos ? parseInt( pos, 10 ) - 1 : index++ ];
			return type === 'd' ? String( parseInt( value, 10 ) || 0 ) : String( value ?? '' );
		} );
	}

	function el( tag, attrs = {}, ...children ) {
		const node = document.createElement( tag );
		for ( const [ key, value ] of Object.entries( attrs ) ) {
			if ( value === null || value === undefined || value === false ) {
				continue;
			}
			if ( key === 'class' ) {
				node.className = value;
			} else if ( key.startsWith( 'on' ) ) {
				node.addEventListener( key.slice( 2 ), value );
			} else if ( value === true ) {
				node.setAttribute( key, '' );
			} else {
				node.setAttribute( key, value );
			}
		}
		for ( const child of children.flat() ) {
			if ( child !== null && child !== undefined && child !== false ) {
				node.append( child instanceof Node ? child : document.createTextNode( String( child ) ) );
			}
		}
		return node;
	}

	async function api( method, path, body, retries = 0 ) {
		const options = {
			method,
			headers: { 'X-WP-Nonce': HMI.nonce },
			credentials: 'same-origin',
		};
		if ( body instanceof FormData ) {
			options.body = body;
		} else if ( body !== undefined ) {
			options.headers[ 'Content-Type' ] = 'application/json';
			options.body = JSON.stringify( body );
		}
		for ( let attempt = 0; ; attempt++ ) {
			try {
				const response = await fetch( HMI.root + path, options );
				let data = null;
				try {
					data = await response.json();
				} catch ( e ) {
					data = null;
				}
				if ( ! response.ok ) {
					const error = new Error( ( data && data.message ) || response.status + ' ' + response.statusText );
					error.status = response.status;
					throw error;
				}
				return data;
			} catch ( error ) {
				// Retry network errors and server errors, not client errors.
				if ( attempt >= retries || ( error.status && error.status < 500 ) ) {
					throw error;
				}
				await new Promise( ( resolve ) => setTimeout( resolve, 1000 * ( attempt + 1 ) ) );
			}
		}
	}

	function extension( name ) {
		const dot = name.lastIndexOf( '.' );
		return dot === -1 ? '' : name.slice( dot + 1 ).toLowerCase();
	}

	function formatSize( bytes ) {
		if ( bytes > 1048576 ) {
			return ( bytes / 1048576 ).toFixed( 1 ) + ' MB';
		}
		return Math.max( 1, Math.round( bytes / 1024 ) ) + ' KB';
	}

	function setStatus( text ) {
		$( '#hmi-status-text' ).textContent = text;
	}

	function setProgress( done, total ) {
		const bar = $( '#hmi-progress' );
		bar.max = Math.max( 1, total );
		bar.value = done;
	}

	function log( message, type = 'info' ) {
		$( '#hmi-log' ).append( el( 'li', { class: 'hmi-log-' + type }, message ) );
	}

	/* ---------------------------------------------------------------- Step 1: source */

	function source() {
		return $( 'input[name="hmi-source"]:checked' ).value;
	}

	function selectedFolderFiles() {
		const files = Array.from( $( '#hmi-folder' ).files || [] );
		const accepted = [];
		let skipped = 0;
		for ( const file of files ) {
			const path = file.webkitRelativePath || file.name;
			const hidden = path.split( '/' ).some( ( part ) => part.startsWith( '.' ) || part === '__MACOSX' );
			if ( hidden || ! HMI.extensions.includes( extension( path ) ) ) {
				skipped++;
				continue;
			}
			accepted.push( { file, path } );
		}
		return { accepted, skipped };
	}

	function describeSelection() {
		const info = $( '#hmi-selection' );
		if ( source() === 'zip' ) {
			const zip = $( '#hmi-zip' ).files[ 0 ];
			info.textContent = zip ? zip.name + ' (' + formatSize( zip.size ) + ')' : '';
			return;
		}
		const { accepted, skipped } = selectedFolderFiles();
		if ( ! accepted.length && ! skipped ) {
			info.textContent = '';
			return;
		}
		const docs = accepted.filter( ( f ) => HMI.docExt.includes( extension( f.path ) ) ).length;
		const size = accepted.reduce( ( sum, f ) => sum + f.file.size, 0 );
		info.textContent =
			accepted.length + ' files (' + formatSize( size ) + '), ' + docs + ' HTML/Markdown' +
			( skipped ? ' – ' + sprintf( t.skippedFiles, skipped ) : '' );
	}

	async function uploadFile( file, path, kind, onChunk ) {
		const size = HMI.chunkSize;
		const chunks = Math.max( 1, Math.ceil( file.size / size ) );
		for ( let index = 0; index < chunks; index++ ) {
			const form = new FormData();
			form.append( 'chunk', file.slice( index * size, ( index + 1 ) * size ), 'chunk' );
			form.append( 'path', path );
			form.append( 'offset', String( index * size ) );
			form.append( 'kind', kind );
			await api( 'POST', '/sessions/' + state.session + '/upload', form, 2 );
			if ( onChunk ) {
				onChunk( index + 1, chunks );
			}
		}
	}

	async function upload() {
		const isZip = source() === 'zip';
		const zip = $( '#hmi-zip' ).files[ 0 ];
		const { accepted } = selectedFolderFiles();

		if ( isZip ? ! zip : ! accepted.length ) {
			window.alert( t.noFiles );
			return;
		}
		if ( ! isZip && ! accepted.some( ( f ) => HMI.docExt.includes( extension( f.path ) ) ) ) {
			window.alert( t.noDocs );
			return;
		}

		startRunning();
		$( '#hmi-step-progress' ).hidden = false;
		$( '#hmi-log' ).replaceChildren();
		$( '#hmi-upload' ).disabled = true;

		try {
			state.session = ( await api( 'POST', '/sessions' ) ).id;

			if ( isZip ) {
				await uploadFile( zip, 'upload.zip', 'zip', ( done, total ) => {
					setProgress( done, total );
					setStatus( sprintf( t.uploadingZip, Math.round( ( done / total ) * 100 ) ) );
				} );
				setStatus( t.extracting );
				const result = await api( 'POST', '/sessions/' + state.session + '/extract' );
				if ( result.skipped ) {
					log( sprintf( t.skippedFiles, result.skipped ) );
				}
			} else {
				let done = 0;
				const queue = accepted.slice();
				const worker = async () => {
					while ( queue.length ) {
						const { file, path } = queue.shift();
						await uploadFile( file, path, 'file' );
						done++;
						setProgress( done, accepted.length );
						setStatus( sprintf( t.uploading, done, accepted.length ) );
					}
				};
				await Promise.all( [ worker(), worker(), worker(), worker() ] );
			}

			setStatus( t.scanning );
			state.plan = await api( 'POST', '/sessions/' + state.session + '/scan' );
			renderPlan();
			$( '#hmi-step-progress' ).hidden = true;
			$( '#hmi-step-plan' ).hidden = false;
			$( '#hmi-step-plan' ).scrollIntoView( { behavior: 'smooth' } );
		} catch ( error ) {
			setStatus( t.error + ': ' + error.message );
			log( error.message, 'error' );
			$( '#hmi-upload' ).disabled = false;
		} finally {
			stopRunning();
		}
	}

	/* ---------------------------------------------------------------- Step 2: plan */

	function renderPlan() {
		const rows = $( '#hmi-plan-rows' );
		rows.replaceChildren();
		for ( const item of state.plan.items ) {
			const notes = [];
			if ( item.kind === 'container' ) {
				notes.push( el( 'span', { class: 'hmi-tag' }, t.container ) );
			}
			if ( item.existing ) {
				notes.push( el( 'a', { class: 'hmi-tag hmi-tag-existing', href: item.existing.edit_url, target: '_blank' }, t.existing ) );
			}
			if ( item.links ) {
				notes.push( el( 'span', { class: 'hmi-tag' }, sprintf( t.links, item.links ) ) );
			}
			for ( const dup of item.duplicates ) {
				notes.push( el( 'div', { class: 'hmi-note' }, sprintf( t.duplicate, dup.split( '/' ).pop() ) ) );
			}
			for ( const missing of item.missing ) {
				notes.push( el( 'div', { class: 'hmi-note hmi-warning' }, sprintf( t.missing, missing ) ) );
			}

			rows.append(
				el(
					'tr',
					{ 'data-key': item.key, class: 'hmi-depth-' + Math.min( item.depth, 6 ) },
					el( 'th', { scope: 'row', class: 'check-column' }, el( 'input', { type: 'checkbox', class: 'hmi-include', checked: true, 'aria-label': item.title } ) ),
					el(
						'td',
						{ class: 'hmi-title-cell' },
						el( 'span', { class: 'hmi-indent', style: '--hmi-depth:' + item.depth } ),
						el( 'input', { type: 'text', class: 'hmi-title regular-text', value: item.title, 'data-original': item.title } )
					),
					el( 'td', { class: 'hmi-source-cell' }, item.source ? el( 'code', {}, item.source ) : '—' ),
					el( 'td', { class: 'num' }, String( item.media.length ) ),
					el( 'td', {}, notes )
				)
			);
		}
		updateSummary();
	}

	function includedKeys() {
		return new Set( $$( '#hmi-plan-rows tr' ).filter( ( row ) => $( '.hmi-include', row ).checked ).map( ( row ) => row.dataset.key ) );
	}

	function settings() {
		const form = $( '#hmi-step-plan' );
		const value = ( name ) => {
			const field = $( '[name="' + name + '"]', form );
			return field ? field.value : '0';
		};
		const checked = ( name ) => {
			const field = $( '[name="' + name + '"]', form );
			return field ? field.checked : false;
		};
		return {
			post_type: $( 'input[name="post_type"]:checked', form ).value,
			status: value( 'status' ),
			parent_page: value( 'parent_page' ) || '0',
			parent_cat: value( 'parent_cat' ) || '0',
			strip_h1: checked( 'strip_h1' ),
			update_existing: checked( 'update_existing' ),
			featured_image: checked( 'featured_image' ),
			containers: checked( 'containers' ),
			folder_categories: checked( 'folder_categories' ),
		};
	}

	function mediaFor( keys ) {
		return state.plan.media.filter( ( media ) => media.used_by.some( ( key ) => keys.has( key ) ) );
	}

	function updateSummary() {
		const keys = includedKeys();
		$( '#hmi-summary' ).textContent = sprintf( t.summary, keys.size, mediaFor( keys ).length );
		$( '#hmi-all' ).checked = keys.size === state.plan.items.length;
	}

	function togglePostType() {
		const type = $( 'input[name="post_type"]:checked' ).value;
		$$( '[data-for]' ).forEach( ( node ) => {
			node.hidden = node.dataset.for !== type;
		} );
	}

	/* ---------------------------------------------------------------- Step 3: import */

	async function runImport() {
		const keys = includedKeys();
		if ( ! keys.size ) {
			return;
		}
		const options = settings();
		const items = state.plan.items.filter( ( item ) => keys.has( item.key ) );
		const media = mediaFor( keys );
		const titles = {};
		$$( '#hmi-plan-rows tr' ).forEach( ( row ) => {
			const input = $( '.hmi-title', row );
			if ( input.value.trim() !== input.dataset.original ) {
				titles[ row.dataset.key ] = input.value.trim();
			}
		} );

		startRunning();
		$( '#hmi-step-plan' ).hidden = true;
		$( '#hmi-step-progress' ).hidden = false;
		$( '#hmi-log' ).replaceChildren();
		$( '#hmi-result-rows' ).replaceChildren();
		$( '.hmi-results' ).hidden = false;

		const total = media.length + items.length + 1;
		let done = 0;
		const step = () => setProgress( ++done, total );

		for ( const [ index, file ] of media.entries() ) {
			setStatus( sprintf( t.importingMedia, index + 1, media.length ) );
			try {
				await api( 'POST', '/sessions/' + state.session + '/media', { path: file.path }, 2 );
			} catch ( error ) {
				log( file.path + ': ' + error.message, 'error' );
			}
			step();
		}

		for ( const [ index, item ] of items.entries() ) {
			setStatus( sprintf( t.importingItem, index + 1, items.length, titles[ item.key ] || item.title ) );
			try {
				const result = await api(
					'POST',
					'/sessions/' + state.session + '/item',
					{ key: item.key, title: titles[ item.key ] || '', settings: options },
					1
				);
				addResult( item, result );
			} catch ( error ) {
				addResult( item, null, error.message );
			}
			step();
		}

		setStatus( t.finalizing );
		try {
			const result = await api( 'POST', '/sessions/' + state.session + '/finalize', {}, 1 );
			state.finalized = true;
			log( sprintf( t.doneLinks, result.updated, result.unresolved ) );
		} catch ( error ) {
			log( error.message, 'error' );
		}
		step();
		setStatus( t.done );
		stopRunning();
		$( '#hmi-restart' ).hidden = false;
	}

	function addResult( item, result, error ) {
		let status;
		let links = [];
		if ( error ) {
			status = el( 'span', { class: 'hmi-warning' }, t.error + ': ' + error );
		} else if ( result.skipped ) {
			status = t.skipped;
		} else if ( ! result.post_id && result.term_id ) {
			status = t.category;
		} else {
			status = result.updated ? t.updated : t.created;
			links = [
				' · ',
				el( 'a', { href: result.edit_url, target: '_blank' }, t.edit ),
				' · ',
				el( 'a', { href: result.view_url, target: '_blank' }, t.view ),
			];
		}
		const warnings = ( result && result.warnings ) || [];
		$( '#hmi-result-rows' ).append(
			el(
				'tr',
				{},
				el( 'td', {}, el( 'span', { class: 'hmi-indent', style: '--hmi-depth:' + item.depth } ), ( result && result.title ) || item.title ),
				el( 'td', {}, status, links ),
				el( 'td', {}, warnings.map( ( w ) => el( 'div', { class: 'hmi-note hmi-warning' }, w ) ) )
			)
		);
	}

	/* ---------------------------------------------------------------- Lifecycle */

	function beforeUnload( event ) {
		event.preventDefault();
		event.returnValue = t.confirmLeave;
		return t.confirmLeave;
	}

	function startRunning() {
		state.running = true;
		window.addEventListener( 'beforeunload', beforeUnload );
	}

	function stopRunning() {
		state.running = false;
		window.removeEventListener( 'beforeunload', beforeUnload );
	}

	async function reset() {
		if ( state.session && ! state.finalized ) {
			api( 'DELETE', '/sessions/' + state.session ).catch( () => {} );
		}
		state.session = null;
		state.plan = null;
		state.finalized = false;
		$( '#hmi-folder' ).value = '';
		$( '#hmi-zip' ).value = '';
		$( '#hmi-selection' ).textContent = '';
		$( '#hmi-upload' ).disabled = false;
		$( '#hmi-step-plan' ).hidden = true;
		$( '#hmi-step-progress' ).hidden = true;
		$( '#hmi-restart' ).hidden = true;
		$( '.hmi-results' ).hidden = true;
		setProgress( 0, 1 );
		setStatus( '' );
	}

	document.addEventListener( 'DOMContentLoaded', () => {
		$$( 'input[name="hmi-source"]' ).forEach( ( radio ) =>
			radio.addEventListener( 'change', () => {
				$$( '.hmi-picker' ).forEach( ( node ) => {
					node.hidden = node.dataset.source !== source();
				} );
				describeSelection();
			} )
		);
		$( '#hmi-folder' ).addEventListener( 'change', describeSelection );
		$( '#hmi-zip' ).addEventListener( 'change', describeSelection );
		$( '#hmi-upload' ).addEventListener( 'click', upload );
		$( '#hmi-import' ).addEventListener( 'click', runImport );
		$( '#hmi-cancel' ).addEventListener( 'click', reset );
		$( '#hmi-restart' ).addEventListener( 'click', reset );
		$$( 'input[name="post_type"]' ).forEach( ( radio ) => radio.addEventListener( 'change', togglePostType ) );
		$( '#hmi-plan-rows' ).addEventListener( 'change', ( event ) => {
			if ( event.target.classList.contains( 'hmi-include' ) ) {
				updateSummary();
			}
		} );
		$( '#hmi-all' ).addEventListener( 'change', ( event ) => {
			$$( '.hmi-include' ).forEach( ( box ) => {
				box.checked = event.target.checked;
			} );
			updateSummary();
		} );
		togglePostType();
	} );
} )();

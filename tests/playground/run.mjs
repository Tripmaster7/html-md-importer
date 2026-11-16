// End-to-end test against a running WordPress Playground (see README "Playground test").
// Drives the plugin's REST API exactly like assets/admin.js: chunked upload, scan,
// media, items, finalize. Then checks the result through the core REST API and the
// front end, runs the import a second time and checks that nothing was duplicated.
//
// Usage: node tests/playground/run.mjs <base-url> <zip|folder> <page|post> [out-dir]

import { readFile, readdir, stat, mkdir, writeFile } from 'node:fs/promises';
import { join, relative, extname, sep } from 'node:path';

const [ base, source, postType = 'page', outDir ] = process.argv.slice( 2 );
if ( ! base || ! source ) {
	console.error( 'Usage: node tests/playground/run.mjs <base-url> <zip|folder> <page|post> [out-dir]' );
	process.exit( 2 );
}

const CHUNK = 256 * 1024; // Small on purpose: exercises multi-chunk uploads.
const ALLOWED = [ 'html', 'htm', 'md', 'markdown', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'pdf' ];
const failures = [];
const check = ( ok, message ) => {
	console.log( ( ok ? '  ok   ' : '  FAIL ' ) + message );
	if ( ! ok ) {
		failures.push( message );
	}
};

async function rest( method, path, body ) {
	const headers = { 'X-HMI-Test-User': '1' };
	let payload = body;
	if ( body && ! ( body instanceof FormData ) ) {
		headers[ 'Content-Type' ] = 'application/json';
		payload = JSON.stringify( body );
	}
	const response = await fetch( base + '/wp-json' + path, { method, headers, body: payload } );
	const text = await response.text();
	let data;
	try {
		data = JSON.parse( text );
	} catch {
		throw new Error( `${ method } ${ path }: HTTP ${ response.status }, not JSON: ${ text.slice( 0, 500 ) }` );
	}
	if ( ! response.ok ) {
		throw new Error( `${ method } ${ path }: HTTP ${ response.status } ${ data.code }: ${ data.message }` );
	}
	return data;
}

async function upload( session, buffer, path, kind ) {
	const chunks = Math.max( 1, Math.ceil( buffer.length / CHUNK ) );
	for ( let index = 0; index < chunks; index++ ) {
		const send = async () => {
			const form = new FormData();
			form.append( 'chunk', new Blob( [ buffer.subarray( index * CHUNK, ( index + 1 ) * CHUNK ) ] ), 'chunk' );
			form.append( 'path', path );
			form.append( 'offset', String( index * CHUNK ) );
			form.append( 'kind', kind );
			await rest( 'POST', `/html-md-importer/v1/sessions/${ session }/upload`, form );
		};
		await send();
		if ( index === 0 && chunks > 1 ) {
			await send(); // Simulated retry of the same chunk: must not duplicate bytes.
		}
	}
	return chunks;
}

async function walk( dir ) {
	const files = [];
	for ( const entry of await readdir( dir, { withFileTypes: true } ) ) {
		const full = join( dir, entry.name );
		if ( entry.isDirectory() ) {
			files.push( ...( await walk( full ) ) );
		} else if ( ALLOWED.includes( extname( entry.name ).slice( 1 ).toLowerCase() ) ) {
			files.push( full );
		}
	}
	return files;
}

async function runImport( round ) {
	console.log( `\n== Round ${ round }: import ${ source } as ${ postType }s` );
	const session = ( await rest( 'POST', '/html-md-importer/v1/sessions' ) ).id;

	if ( ( await stat( source ) ).isDirectory() ) {
		const files = await walk( source );
		for ( const file of files ) {
			await upload( session, await readFile( file ), relative( source, file ).split( sep ).join( '/' ), 'file' );
		}
		console.log( `  uploaded ${ files.length } files` );
	} else {
		const chunks = await upload( session, await readFile( source ), 'upload.zip', 'zip' );
		const extracted = await rest( 'POST', `/html-md-importer/v1/sessions/${ session }/extract` );
		console.log( `  uploaded ZIP in ${ chunks } chunks, extract: ${ JSON.stringify( extracted ) }` );
	}

	const plan = await rest( 'POST', `/html-md-importer/v1/sessions/${ session }/scan` );
	console.log( `  plan: ${ plan.items.length } items, ${ plan.media.length } media` );
	for ( const item of plan.items ) {
		console.log( `    ${ '  '.repeat( item.depth ) }${ item.title }  [${ item.kind }, ${ item.media.length } img${ item.existing ? ', existing #' + item.existing.id : '' }]` );
	}

	let mediaErrors = 0;
	for ( const file of plan.media ) {
		try {
			await rest( 'POST', `/html-md-importer/v1/sessions/${ session }/media`, { path: file.path } );
		} catch ( error ) {
			mediaErrors++;
			console.log( '  media error: ' + error.message );
		}
	}
	check( mediaErrors === 0, `all ${ plan.media.length } media imported without error` );

	const settings = {
		post_type: postType,
		status: 'publish',
		parent_page: '0',
		parent_cat: '0',
		strip_h1: true,
		update_existing: true,
		featured_image: postType === 'post',
		containers: true,
		folder_categories: true,
	};
	const results = {};
	for ( const item of plan.items ) {
		const result = await rest( 'POST', `/html-md-importer/v1/sessions/${ session }/item`, { key: item.key, title: '', settings } );
		results[ item.key ] = result;
		for ( const warning of result.warnings || [] ) {
			console.log( `  warning (${ item.title }): ${ warning }` );
		}
	}
	const final = await rest( 'POST', `/html-md-importer/v1/sessions/${ session }/finalize`, {} );
	console.log( `  finalize: ${ JSON.stringify( final ) }` );
	return { plan, results };
}

async function verify( plan, results ) {
	console.log( '\n== Verify' );
	const route = postType === 'page' ? 'pages' : 'posts';
	const ids = Object.values( results ).map( ( r ) => r.post_id ).filter( Boolean );
	const posts = await rest( 'GET', `/wp/v2/${ route }?context=edit&per_page=100&include=${ ids.join( ',' ) }` );
	check( posts.length === ids.length, `${ ids.length } ${ route } exist` );

	if ( outDir ) {
		await mkdir( outDir, { recursive: true } );
	}
	const byId = Object.fromEntries( posts.map( ( p ) => [ p.id, p ] ) );
	let leftovers = 0;
	let htmlBlocks = 0;
	for ( const item of plan.items ) {
		const result = results[ item.key ];
		if ( ! result.post_id ) {
			continue;
		}
		const post = byId[ result.post_id ];
		const raw = post.content.raw;
		if ( raw.includes( '#hmi-link-' ) || raw.includes( 'data-hmi' ) ) {
			leftovers++;
		}
		htmlBlocks += ( raw.match( /<!-- wp:html/g ) || [] ).length;
		if ( postType === 'page' && item.parent ) {
			const parent = results[ item.parent ];
			if ( parent && parent.post_id ) {
				check( post.parent === parent.post_id, `"${ post.title.raw }" is a child of #${ parent.post_id }` );
			}
		}
		if ( outDir ) {
			await writeFile( join( outDir, `${ post.id }.html` ), raw );
		}
	}
	check( leftovers === 0, 'no #hmi-link- placeholders or data-hmi attributes left' );
	check( htmlBlocks === 0, `no Custom HTML fallback blocks (found ${ htmlBlocks })` );

	// Media: attachments with generated sizes.
	const attachments = [];
	for ( let page = 1; ; page++ ) {
		const batch = await rest( 'GET', `/wp/v2/media?per_page=100&page=${ page }&media_type=image&context=edit` );
		attachments.push( ...batch );
		if ( batch.length < 100 ) {
			break;
		}
	}
	const withSizes = attachments.filter( ( a ) => Object.keys( a.media_details?.sizes || {} ).length > 1 );
	const small = attachments.filter( ( a ) => ( a.media_details?.width || 0 ) <= 300 && ( a.media_details?.height || 0 ) <= 300 );
	console.log( `  media library: ${ attachments.length } images, ${ withSizes.length } with generated sizes, ${ small.length } too small for sizes` );
	check( withSizes.length + small.length >= attachments.length, 'image sub-sizes were generated for every large image' );

	// Front end: every page renders, every <img> loads, every internal link resolves.
	let broken = 0;
	let images = 0;
	let links = 0;
	for ( const post of posts ) {
		const response = await fetch( post.link );
		check( response.ok, `front end ${ post.link } → ${ response.status }` );
		const html = await response.text();
		const start = html.indexOf( 'class="entry-content' );
		const content = start >= 0 ? html.slice( start ) : html;
		for ( const [ , src ] of content.matchAll( /<img[^>]+src="([^"]+)"/g ) ) {
			images++;
			const r = await fetch( src.replace( /&#038;|&amp;/g, '&' ), { method: 'HEAD' } );
			if ( ! r.ok ) {
				broken++;
				console.log( `    broken image ${ src } → ${ r.status }` );
			}
		}
		for ( const [ , href ] of content.matchAll( /<a[^>]+href="(http[^"#]+)/g ) ) {
			if ( ! href.startsWith( base ) || href.includes( '/wp-content/' ) ) {
				continue;
			}
			links++;
			const r = await fetch( href, { method: 'HEAD' } );
			if ( ! r.ok ) {
				broken++;
				console.log( `    broken link ${ href } → ${ r.status }` );
			}
		}
	}
	check( broken === 0, `front end: ${ images } images and ${ links } internal links load` );
}

async function mediaTotal() {
	const response = await fetch( base + '/wp-json/wp/v2/media?per_page=1', { headers: { 'X-HMI-Test-User': '1' } } );
	return Number( response.headers.get( 'X-WP-Total' ) );
}

try {
	const before = await mediaTotal();
	const first = await runImport( 1 );
	const afterFirst = await mediaTotal();
	check( afterFirst - before <= first.plan.media.length, `round 1 added ${ afterFirst - before } attachments (at most ${ first.plan.media.length })` );
	await verify( first.plan, first.results );

	const second = await runImport( 2 );
	check( ( await mediaTotal() ) === afterFirst, 're-import added no attachments' );
	const same = Object.keys( first.results ).every( ( key ) => first.results[ key ].post_id === second.results[ key ].post_id );
	check( same, 're-import kept the same post IDs' );
	check( Object.values( second.results ).every( ( r ) => ! r.post_id || r.updated ), 're-import updated instead of creating' );
	await verify( second.plan, second.results );
} catch ( error ) {
	failures.push( error.message );
	console.error( '\nERROR ' + error.message );
}

console.log( failures.length ? `\n${ failures.length } FAILED` : '\nALL PASSED' );
process.exit( failures.length ? 1 : 0 );

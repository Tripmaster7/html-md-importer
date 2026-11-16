// Validates saved post content with the block editor's own parser and validator
// (@wordpress/blocks + @wordpress/block-library), i.e. the check that produces
// "This block contains unexpected or invalid content" in the editor.
//
// Needs: npm i @wordpress/blocks @wordpress/block-library jsdom   (in any folder, then set NODE_PATH)
// Usage: node tests/playground/validate-blocks.cjs <folder with *.html from run.mjs>...

const { JSDOM } = require( 'jsdom' );
const dom = new JSDOM( '<!doctype html><html><body></body></html>', { url: 'http://localhost/' } );
for ( const key of [ 'window', 'document', 'navigator', 'Node', 'Element', 'HTMLElement', 'DOMParser', 'MutationObserver', 'getComputedStyle', 'requestAnimationFrame', 'CustomEvent' ] ) {
	if ( global[ key ] === undefined && dom.window[ key ] !== undefined ) {
		global[ key ] = key === 'getComputedStyle' || key === 'requestAnimationFrame' ? dom.window[ key ].bind( dom.window ) : dom.window[ key ];
	}
}
global.window.matchMedia ??= () => ( { matches: false, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {} } );
global.requestAnimationFrame ??= ( cb ) => setTimeout( cb, 0 );

const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { parse } = require( '@wordpress/blocks' );
require( '@wordpress/block-library' ).registerCoreBlocks();

let blocks = 0;
let invalid = 0;
const counts = {};

function walk( list, file ) {
	for ( const block of list ) {
		blocks++;
		counts[ block.name ] = ( counts[ block.name ] || 0 ) + 1;
		if ( ! block.isValid ) {
			invalid++;
			console.log( `INVALID ${ file }: ${ block.name }` );
			for ( const issue of block.validationIssues || [] ) {
				console.log( '   ' + issue.args.map( ( a ) => ( typeof a === 'string' ? a : JSON.stringify( a ) ) ).join( ' ' ).slice( 0, 600 ) );
			}
			console.log( '   source: ' + ( block.originalContent || '' ).slice( 0, 300 ) );
		}
		walk( block.innerBlocks || [], file );
	}
}

const quiet = console.warn;
for ( const dir of process.argv.slice( 2 ) ) {
	for ( const name of fs.readdirSync( dir ).filter( ( f ) => f.endsWith( '.html' ) ) ) {
		const raw = fs.readFileSync( path.join( dir, name ), 'utf8' );
		console.warn = () => {}; // The validator logs every issue as a warning; we print our own summary.
		console.error = console.warn;
		const parsed = parse( raw );
		console.warn = quiet;
		walk( parsed, name );
	}
}
console.log( `\n${ blocks } blocks, ${ invalid } invalid` );
console.log( Object.entries( counts ).map( ( [ k, v ] ) => `${ k }: ${ v }` ).join( ', ' ) );
process.exit( invalid ? 1 : 0 );

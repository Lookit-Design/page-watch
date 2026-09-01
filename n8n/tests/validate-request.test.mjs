/**
 * Exercises the real "Validate request" node from the shipped workflow file.
 *
 * The code is read out of the workflow JSON rather than copied here, so the
 * tests fail if the node is edited without the expectations being revisited.
 *
 * Run with: node --test n8n/tests/
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname( fileURLToPath( import.meta.url ) );
const workflow = JSON.parse(
	readFileSync( join( here, '..', 'lookit-page-watch-capture-v2.json' ), 'utf8' )
);

const node = workflow.nodes.find( ( item ) => item.name === 'Validate request' );
assert.ok( node, 'The workflow has no "Validate request" node.' );

// eslint-disable-next-line no-new-func
const validate = new Function( '$input', node.parameters.jsCode );

const TOKEN = 'a'.repeat( 32 );

function run( config ) {
	const json = {
		provider: 'mshots',
		shared_token: TOKEN,
		allowed_hosts: 'example.com',
		browserless_url: 'http://127.0.0.1:3000/screenshot?token=x',
		mode: 'capture',
		url: 'https://example.com/about/',
		width: 1440,
		full_page: true,
		sent_token: TOKEN,
		...config,
	};

	return validate( { first: () => ( { json } ) } )[ 0 ].json;
}

test( 'a matching token and allowed host is authorised', () => {
	const out = run( {} );
	assert.equal( out.authorised, true );
	assert.equal( out.reason, '' );
} );

test( 'a wrong token is refused as a token problem', () => {
	const out = run( { sent_token: 'b'.repeat( 32 ) } );
	assert.equal( out.authorised, false );
	assert.equal( out.reason, 'token' );
} );

test( 'a missing token is refused as a token problem', () => {
	const out = run( { sent_token: '' } );
	assert.equal( out.authorised, false );
	assert.equal( out.reason, 'token' );
} );

test( 'the placeholder token is never accepted', () => {
	const placeholder = 'CHANGE-ME-LONG-RANDOM-STRING';
	const out = run( { shared_token: placeholder, sent_token: placeholder } );
	assert.equal( out.authorised, false );
	assert.equal( out.reason, 'token' );
} );

test( 'a short shared token is never accepted', () => {
	const short = 'a'.repeat( 31 );
	const out = run( { shared_token: short, sent_token: short } );
	assert.equal( out.authorised, false );
	assert.equal( out.reason, 'token' );
} );

test( 'a host outside the allowlist is refused as a host problem', () => {
	const out = run( { url: 'https://elsewhere.test/' } );
	assert.equal( out.authorised, false );
	assert.equal( out.reason, 'host' );
} );

test( 'an unconfigured allowlist is reported separately from a rejected host', () => {
	const out = run( { allowed_hosts: 'CHANGE-ME-ALLOWED-HOSTS' } );
	assert.equal( out.authorised, false );
	assert.equal( out.reason, 'hosts_unset' );
} );

test( 'a non-http scheme is reported as a URL problem', () => {
	const out = run( { url: 'file:///etc/passwd' } );
	assert.equal( out.authorised, false );
	assert.equal( out.reason, 'url' );
} );

test( 'credentials embedded in the URL are refused', () => {
	const out = run( { url: 'https://user:pass@example.com/' } );
	assert.equal( out.authorised, false );
	assert.equal( out.reason, 'url' );
} );

test( 'the allowlist is never reported to a caller with a bad token', () => {
	const out = run( {
		sent_token: 'b'.repeat( 32 ),
		url: 'https://elsewhere.test/',
		allowed_hosts: 'CHANGE-ME-ALLOWED-HOSTS',
	} );
	assert.equal( out.reason, 'token' );
} );

test( 'a ping skips the host allowlist but still needs the token', () => {
	const allowed = run( { mode: 'ping', url: '' } );
	assert.equal( allowed.authorised, true );
	assert.equal( allowed.reason, '' );

	const refused = run( { mode: 'ping', url: '', sent_token: 'b'.repeat( 32 ) } );
	assert.equal( refused.authorised, false );
	assert.equal( refused.reason, 'token' );
} );

test( 'a ping is not treated as a capture', () => {
	assert.equal( run( { mode: 'ping' } ).mode, 'ping' );
	assert.equal( run( { mode: 'capture' } ).mode, 'capture' );
	assert.equal( run( { mode: undefined } ).mode, 'capture' );
	assert.equal( run( { mode: 'PING' } ).mode, 'capture' );
} );

test( 'a wildcard allows subdomains but not the bare domain', () => {
	assert.equal( run( { allowed_hosts: '*.example.com', url: 'https://www.example.com/' } ).authorised, true );
	assert.equal( run( { allowed_hosts: '*.example.com', url: 'https://example.com/' } ).authorised, false );
	assert.equal( run( { allowed_hosts: '*.example.com', url: 'https://notexample.com/' } ).authorised, false );
} );

test( 'a trailing dot cannot be used to slip past the allowlist', () => {
	assert.equal( run( { url: 'https://example.com./' } ).authorised, true );
} );

test( 'the allowlist is matched case insensitively and ignores spacing', () => {
	const out = run( { allowed_hosts: ' EXAMPLE.COM , other.test ', url: 'https://Example.COM/' } );
	assert.equal( out.authorised, true );
} );

test( 'a host that merely ends with an allowed host is refused', () => {
	assert.equal( run( { url: 'https://evil-example.com/' } ).authorised, false );
	assert.equal( run( { url: 'https://example.com.evil.test/' } ).authorised, false );
} );

test( 'the requested width is clamped to what the renderers accept', () => {
	assert.equal( run( { width: 100 } ).width, 320 );
	assert.equal( run( { width: 99999 } ).width, 1920 );
	assert.equal( run( { width: 1440 } ).width, 1440 );
	assert.equal( run( { width: 'wide' } ).width, 1440 );
} );

test( 'the mShots target carries a cache buster', () => {
	const out = run( {} );
	assert.match( out.mshots_target, /[?&]pw=\d+$/ );
} );

test( 'an unknown provider falls back to mShots', () => {
	assert.equal( run( { provider: 'something-else' } ).provider, 'mshots' );
	assert.equal( run( { provider: 'browserless' } ).provider, 'browserless' );
} );

test( 'every connection points at a node that exists', () => {
	const names = new Set( workflow.nodes.map( ( item ) => item.name ) );

	for ( const [ from, outputs ] of Object.entries( workflow.connections ) ) {
		assert.ok( names.has( from ), `Connections reference a missing node: ${ from }` );

		for ( const branch of outputs.main ) {
			for ( const target of branch ) {
				assert.ok(
					names.has( target.node ),
					`${ from } points at a missing node: ${ target.node }`
				);
			}
		}
	}
} );

test( 'a refusal is answered with a fixed status code', () => {
	const codes = {
		'Respond unauthorised': 401,
		'Respond refused': 403,
		'Respond ping OK': 200,
	};

	for ( const [ name, code ] of Object.entries( codes ) ) {
		const responder = workflow.nodes.find( ( item ) => item.name === name );
		assert.ok( responder, `The workflow has no "${ name }" node.` );
		assert.equal(
			responder.parameters.options.responseCode,
			code,
			`${ name } should answer with ${ code }.`
		);
	}
} );

test( 'a rejected token is routed to the 401 response and nothing else', () => {
	const branches = workflow.connections[ 'Token rejected?' ].main;
	assert.deepEqual(
		branches[ 0 ].map( ( target ) => target.node ),
		[ 'Respond unauthorised' ]
	);
	assert.deepEqual(
		branches[ 1 ].map( ( target ) => target.node ),
		[ 'Respond refused' ]
	);
} );

test( 'a ping never reaches a renderer', () => {
	const branches = workflow.connections[ 'Ping?' ].main;
	assert.deepEqual(
		branches[ 0 ].map( ( target ) => target.node ),
		[ 'Respond ping OK' ]
	);
	assert.deepEqual(
		branches[ 1 ].map( ( target ) => target.node ),
		[ 'Which provider' ]
	);
} );

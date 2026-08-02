import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';

test( 'PHP materializer validates and projects generic provider form topology', () => {
	const output = execFileSync( 'php', [ 'tests/form-materializer-smoke.php' ], {
		cwd: process.cwd(),
		encoding: 'utf8',
	} );

	assert.match( output, /PASS form-materializer-smoke\.php \(\d+ assertions\)/ );
} );

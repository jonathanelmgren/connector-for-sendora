#!/usr/bin/env node
// The local gate: PHPCS, PHPStan, PHPUnit and Plugin Check, the same steps
// CI runs. Starts wp-env when it is not running; leaves it running, so the
// next run is quicker (`npm run env:stop` stops it). Stops at the first
// failing step.
import { refuseSendoraVariables, spawn } from './env.mjs';

refuseSendoraVariables();

const MS_PER_SECOND = 1000;

const STEPS = [
	['lint', ['node', ['bin/php.mjs', 'vendor/bin/phpcs']]],
	[
		'stan',
		[
			'node',
			[
				'bin/php.mjs',
				'php',
				'-d',
				'memory_limit=1G',
				'vendor/bin/phpstan',
				'analyse',
				'--no-progress',
			],
		],
	],
	['wp-env', ['node', ['bin/wp-env.mjs', 'start']]],
	['test', ['node', ['bin/test.mjs']]],
	['plugin-check', ['node', ['bin/plugin-check.mjs']]],
];

const started = Date.now();
for (const [name, [command, args]] of STEPS) {
	const stepStarted = Date.now();
	console.log(`\n== ${name}`);
	const status = spawn(command, args);
	const seconds = Math.round((Date.now() - stepStarted) / MS_PER_SECOND);
	if (status !== 0) {
		console.error(`\n== ${name} failed (exit ${status}, ${seconds}s)`);
		process.exit(status);
	}
	console.log(`== ${name} passed (${seconds}s)`);
}
console.log(
	`\nAll checks passed in ${Math.round((Date.now() - started) / MS_PER_SECOND)}s.`,
);

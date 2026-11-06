#!/usr/bin/env node
// PHPUnit against the WordPress test suite in wp-env's tests-cli container.
// Arguments go to PHPUnit, e.g. `npm test -- --filter MailerTest`.
import {
	pluginDir,
	refuseSendoraVariables,
	run,
	wpEnvBin,
} from './env.mjs';

refuseSendoraVariables();
run(wpEnvBin, [
	'run',
	'tests-cli',
	`--env-cwd=${pluginDir}`,
	'vendor/bin/phpunit',
	...process.argv.slice(2),
]);

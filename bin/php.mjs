#!/usr/bin/env node
// A PHP tool that needs no WordPress (PHPCS, PHPStan) in a throwaway
// container without a network, so the host needs no PHP.
import { inComposerContainer, refuseSendoraVariables, run } from './env.mjs';

refuseSendoraVariables();
run('docker', [
	...inComposerContainer({ network: false }),
	...process.argv.slice(2),
]);

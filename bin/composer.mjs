#!/usr/bin/env node
// Composer in a throwaway container, so the host needs no PHP or Composer.
import { inComposerContainer, refuseSendoraVariables, run } from './env.mjs';

refuseSendoraVariables();
run('docker', [
	...inComposerContainer(),
	'composer',
	...process.argv.slice(2),
]);

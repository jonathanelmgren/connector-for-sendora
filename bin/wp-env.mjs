#!/usr/bin/env node
// wp-env, refusing to start with a Sendora key in the shell. Ports come from
// WP_ENV_PORT and WP_ENV_TESTS_PORT, which wp-env reads itself.
import { refuseSendoraVariables, run, wpEnvBin } from './env.mjs';

refuseSendoraVariables();
run(wpEnvBin, process.argv.slice(2));

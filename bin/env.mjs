// Shared by the wrappers in bin/. The host needs Node and Docker only: PHP
// tools run in a throwaway Composer container or in wp-env's tests-cli
// container. wp-env reads its ports from WP_ENV_PORT and WP_ENV_TESTS_PORT
// itself, so several checkouts can run side by side.
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { basename, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

export const root = dirname(dirname(fileURLToPath(import.meta.url)));

/** The plugin's slug: its main file and text domain. */
export const SLUG = 'sendora-for-wp';

/** wp-env mounts the checkout under its directory name. */
export const pluginDir = `wp-content/plugins/${basename(root)}`;

/** The image the Composer container runs: Alpine Linux with PHP and Composer. */
export const COMPOSER_IMAGE = 'composer:2';

/**
 * Variables that would hand a run a Sendora key or address: the plugin's
 * constant's name, the key's name other projects on this machine use, the
 * SDK's own two, and the API URL those projects use. A run refuses to start
 * with any of them set, so no key can reach a test, a lint or a local site
 * from the shell.
 */
export const SENDORA_VARIABLES = [
	'SENDORA_WP_API_KEY',
	'SENDORA_API_KEY',
	'SENDORA_API_TOKEN',
	'SENDORA_BASE_URL',
	'SENDORA_API_URL',
];

/** Exits when a Sendora variable is set in this process's environment. */
export function refuseSendoraVariables() {
	const set = SENDORA_VARIABLES.filter(
		(name) => (process.env[name] ?? '') !== '',
	);
	if (set.length > 0) {
		console.error(
			`Refusing to run with ${set.join(', ')} set: tests and local runs never use a Sendora key or Sendora's API. Unset it and run again.`,
		);
		process.exit(1);
	}
}

/** wp-env from this checkout's node_modules. */
export const wpEnvBin = join(root, 'node_modules', '.bin', 'wp-env');

/**
 * Runs `command` in the checkout; returns its exit status.
 *
 * @param {string} command
 * @param {string[]} args
 */
export function spawn(command, args) {
	const result = spawnSync(command, args, { cwd: root, stdio: 'inherit' });
	if (result.error) {
		throw result.error;
	}
	return result.status ?? 1;
}

/**
 * Runs `command` and exits with its status.
 *
 * @param {string} command
 * @param {string[]} args
 */
export function run(command, args) {
	process.exit(spawn(command, args));
}

/**
 * Runs wp-env and returns its result with stdout captured; stderr (wp-env's
 * own progress lines) still goes to the terminal.
 *
 * @param {string[]} args
 */
export function wpEnvOutput(args) {
	/** Generous upper bound for output captured from a wp-env command. */
	const MAX_OUTPUT_BYTES = 16 * 1024 * 1024;
	const result = spawnSync(wpEnvBin, args, {
		cwd: root,
		encoding: 'utf8',
		maxBuffer: MAX_OUTPUT_BYTES,
		stdio: ['ignore', 'pipe', 'inherit'],
	});
	if (result.error) {
		throw result.error;
	}
	return result;
}

/**
 * Whether Docker runs rootless, where the container's root is the host user.
 * Rootful Docker (a CI runner) writes as the real root instead.
 */
function dockerIsRootless() {
	const result = spawnSync(
		'docker',
		['info', '--format', '{{json .SecurityOptions}}'],
		{ encoding: 'utf8' },
	);
	return result.status === 0 && result.stdout.includes('name=rootless');
}

/**
 * `docker run` arguments for a command in a throwaway Composer container
 * working in the checkout. Append the command. On rootful Docker the
 * container runs as the host user, so the files it writes stay the host's;
 * rootless Docker maps its root to the host user already. The container
 * gets no network beyond what Composer needs, and none of the host's
 * environment.
 *
 * @param {{ network?: boolean, cwd?: string }} [options] network: false cuts the container off, for tools that need none; cwd: the directory to work in, relative to the checkout
 */
export function inComposerContainer({ network = true, cwd = '' } = {}) {
	const user =
		dockerIsRootless() || process.getuid === undefined
			? []
			: ['--user', `${process.getuid()}:${process.getgid?.()}`];
	return [
		'run',
		'--rm',
		...user,
		...(network ? [] : ['--network', 'none']),
		'-v',
		`${root}:/app`,
		'-w',
		cwd === '' ? '/app' : `/app/${cwd}`,
		'-e',
		'COMPOSER_HOME=/tmp/composer',
		COMPOSER_IMAGE,
	];
}

/**
 * The paths in .distignore, relative to the checkout, without leading or
 * trailing slashes: the development files a release leaves out.
 */
export function distIgnored() {
	return readFileSync(join(root, '.distignore'), 'utf8')
		.split('\n')
		.map((line) => line.trim().replace(/^\/|\/$/g, ''))
		.filter((line) => line !== '' && !line.startsWith('#'));
}

#!/usr/bin/env node
// Runs Plugin Check (the plugin-check plugin pinned in .wp-env.json) on the
// release zip, build/connector-for-sendora.zip from `npm run build`: the zip
// is unpacked into wp-env's tests site as its own plugin, checked, and
// removed. Exits 1 on any error; warnings are printed and do not fail.
import {
	copyFileSync,
	cpSync,
	existsSync,
	readdirSync,
	readFileSync,
	rmSync,
} from 'node:fs';
import { homedir } from 'node:os';
import { join, resolve } from 'node:path';
import {
	inComposerContainer,
	refuseSendoraVariables,
	root,
	SLUG,
	spawn,
	wpEnvOutput,
} from './env.mjs';

refuseSendoraVariables();

/**
 * Runtime checks install a second WordPress inside the tests site, which
 * fails in wp-env. They cover enqueued asset size and scope; the plugin
 * enqueues no assets.
 */
const RUNTIME_CHECKS = [
	'enqueued_scripts_scope',
	'enqueued_styles_scope',
	'enqueued_scripts_size',
	'enqueued_styles_size',
	'non_blocking_scripts',
];

/** Where `npm run build` leaves the zip, relative to the checkout. */
const BUILD_DIR = 'build';
const ZIP = `${SLUG}.zip`;

/** Where the zip is unpacked, inside BUILD_DIR. */
const UNPACKED = 'plugin-check';

/**
 * wp-env's home: WP_ENV_HOME, else `~/wp-env` where Docker comes from a
 * snap package and `~/.wp-env` elsewhere, as wp-env picks it.
 */
function wpEnvHome() {
	if (process.env.WP_ENV_HOME) {
		return resolve(process.env.WP_ENV_HOME);
	}
	return join(homedir(), existsSync('/snap') ? 'wp-env' : '.wp-env');
}

/**
 * wp-env's working directory for this checkout: the one under its home
 * whose docker-compose.yml mounts this checkout. (`wp-env install-path`
 * prints nothing when its output is not a terminal.)
 */
function wpEnvInstallPath() {
	const home = wpEnvHome();
	const match = (existsSync(home) ? readdirSync(home) : [])
		.map((entry) => join(home, entry))
		.find((dir) => {
			const compose = join(dir, 'docker-compose.yml');
			return (
				existsSync(compose) &&
				readFileSync(compose, 'utf8').includes(`${root}:`)
			);
		});
	if (match === undefined) {
		throw new Error(`No wp-env install for ${root} in ${home}: run npm run env:start.`);
	}
	return match;
}

/**
 * Plugin Check copies an object-cache.php drop-in into wp-content before it
 * runs. With rootless Docker the container user cannot write there but the
 * host can, so the drop-in is placed from here and removed afterwards.
 */
function placeDropIn(installPath) {
	const target = join(
		installPath,
		'tests-WordPress',
		'wp-content',
		'object-cache.php',
	);
	if (existsSync(target)) {
		return () => {};
	}
	copyFileSync(
		join(installPath, 'plugin-check', 'drop-ins', 'object-cache.copy.php'),
		target,
	);
	return () => rmSync(target, { force: true });
}

// A PHPUnit run before this one may have reinstalled the tests site, which
// leaves Plugin Check inactive. Activating an active plugin is a no-op.
const activated = wpEnvOutput([
	'run',
	'tests-cli',
	'wp',
	'plugin',
	'activate',
	'plugin-check',
]);
if (activated.status !== 0) {
	console.error('Plugin Check could not be activated.');
	process.exit(activated.status || 1);
}

/**
 * Unpacks the zip and puts it in the tests site's plugins directory, next to
 * the checkout, the way a site that installs it would have it. With rootless
 * Docker the container user cannot write there but the host can, so the
 * files are copied from here. Returns what removes them again.
 */
function placeBuiltPlugin(installPath) {
	if (!existsSync(join(root, BUILD_DIR, ZIP))) {
		console.error(`No ${BUILD_DIR}/${ZIP}: run npm run build first.`);
		process.exit(1);
	}
	const unpacked = join(root, BUILD_DIR, UNPACKED);
	rmSync(unpacked, { recursive: true, force: true });
	const status = spawn('docker', [
		...inComposerContainer({ network: false, cwd: BUILD_DIR }),
		'unzip',
		'-q',
		ZIP,
		'-d',
		UNPACKED,
	]);
	if (status !== 0) {
		console.error(`${BUILD_DIR}/${ZIP} could not be unpacked.`);
		process.exit(status);
	}
	const target = join(
		installPath,
		'tests-WordPress',
		'wp-content',
		'plugins',
		SLUG,
	);
	rmSync(target, { recursive: true, force: true });
	cpSync(join(unpacked, SLUG), target, { recursive: true });
	rmSync(unpacked, { recursive: true, force: true });
	return () => rmSync(target, { recursive: true, force: true });
}

const installPath = wpEnvInstallPath();
const removeBuiltPlugin = placeBuiltPlugin(installPath);
const removeDropIn = placeDropIn(installPath);
let result;
try {
	result = wpEnvOutput([
		'run',
		'tests-cli',
		'wp',
		'plugin',
		'check',
		SLUG,
		`--exclude-checks=${RUNTIME_CHECKS.join(',')}`,
		'--format=json',
	]);
} finally {
	removeDropIn();
	removeBuiltPlugin();
}
if (result.status !== 0) {
	console.error('Plugin Check did not run.');
	process.exit(result.status || 1);
}

// The JSON format prints a `FILE: <path>` line, then that file's findings as
// one JSON array.
const findings = [];
let file = '';
for (const line of result.stdout.split('\n')) {
	if (line.startsWith('FILE: ')) {
		file = line.slice('FILE: '.length);
	} else if (line.startsWith('[')) {
		for (const finding of JSON.parse(line)) {
			findings.push({ file, ...finding });
		}
	}
}

for (const { type, file: path, line, code, message } of findings) {
	console.log(
		`${type.padEnd('WARNING'.length)}  ${path}:${line}  ${code}  ${message}`,
	);
}
const errors = findings.filter(({ type }) => type === 'ERROR').length;
console.log(
	`Plugin Check: ${errors} errors, ${findings.length - errors} warnings.`,
);
process.exit(errors > 0 ? 1 : 0);

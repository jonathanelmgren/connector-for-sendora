#!/usr/bin/env node
// Builds the distributable plugin: build/connector-for-sendora/ and
// build/connector-for-sendora.zip, the directory zipped as WordPress expects.
//
//   node bin/build.mjs            the version in the plugin's files
//   node bin/build.mjs v1.2.3     also checks the files say 1.2.3, for a release tag
//
// The build holds the files git knows (tracked, or new and not ignored) less
// those in .distignore, production packages only with Sendora's SDK and its
// dependencies prefixed by Strauss, and the translation template. Needs the
// dev install (`npm run composer -- install`), for Strauss and WP-CLI.
import { spawnSync } from 'node:child_process';
import { copyFileSync, existsSync, mkdirSync, readFileSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { makePot } from './make-pot.mjs';
import {
	distIgnored,
	inComposerContainer,
	refuseSendoraVariables,
	root,
	SLUG,
	spawn,
} from './env.mjs';

refuseSendoraVariables();

const BUILD_DIR = 'build';
const STAGING = `${BUILD_DIR}/${SLUG}`;
const ZIP = `${SLUG}.zip`;

/**
 * The lock file the build installs from, removed once it has. composer.json
 * ships, so a reviewer can see where vendor/ comes from.
 */
const COMPOSER_LOCK = 'composer.lock';

/** Where the plugin's files state its version, and how to read it there. */
const VERSION_SOURCES = [
	[`${SLUG}.php`, /^\s*\*\s*Version:\s*(\S+)/m],
	[`${SLUG}.php`, /define\(\s*'CONNECTOR_FOR_SENDORA_VERSION',\s*'([^']+)'\s*\)/],
	['readme.txt', /^Stable tag:\s*(\S+)/m],
	['package.json', /^\s*"version":\s*"([^"]+)"/m],
];

/** Exits with a message and status 1. */
function fail(message) {
	console.error(`build: ${message}`);
	process.exit(1);
}

/** Runs a step; exits when it fails. */
function step(name, status) {
	if (status !== 0) {
		fail(`${name} failed (exit ${status})`);
	}
}

/** The one version every source states. */
function version() {
	const found = VERSION_SOURCES.map(([file, pattern]) => {
		const match = readFileSync(join(root, file), 'utf8').match(pattern);
		if (match === null) {
			fail(`no version found in ${file} (${pattern})`);
		}
		return [file, match[1]];
	});
	const versions = new Set(found.map(([, found_version]) => found_version));
	if (versions.size !== 1) {
		fail(`the versions differ: ${found.map(([file, v]) => `${file} ${v}`).join(', ')}`);
	}
	return [...versions][0];
}

/** The files git knows, less development files. */
function shippedFiles() {
	const listed = spawnSync(
		'git',
		['ls-files', '--cached', '--others', '--exclude-standard', '-z'],
		{ cwd: root, encoding: 'utf8' },
	);
	if (listed.status !== 0) {
		fail('git ls-files failed');
	}
	const ignored = [...distIgnored(), BUILD_DIR];
	return listed.stdout
		.split('\0')
		.filter((path) => path !== '' && existsSync(join(root, path)))
		.filter(
			(path) =>
				!ignored.some((entry) => path === entry || path.startsWith(`${entry}/`)),
		);
}

const built = version();
// An empty argument, as the release workflow passes when run by hand, is no tag.
const tag = process.argv[2] || undefined;
if (tag !== undefined && tag.replace(/^v/, '') !== built) {
	fail(`tag ${tag} does not match version ${built} in the plugin's files`);
}

rmSync(join(root, BUILD_DIR), { recursive: true, force: true });
for (const path of [...shippedFiles(), COMPOSER_LOCK]) {
	const target = join(root, STAGING, path);
	mkdirSync(dirname(target), { recursive: true });
	copyFileSync(join(root, path), target);
}

const inStaging = (network) => inComposerContainer({ network, cwd: STAGING });
step(
	'composer install',
	spawn('docker', [
		...inStaging(true),
		'composer',
		'install',
		'--no-dev',
		'--no-scripts',
		'--no-interaction',
		'--no-progress',
		'--prefer-dist',
	]),
);
// The prefix-namespaces script, with Strauss from the checkout's dev install.
// Strauss loads the first vendor/autoload.php it finds, which would be the
// staging directory's, without Strauss in it; dump-autoload writes it again.
rmSync(join(root, STAGING, 'vendor', 'autoload.php'));
step('strauss', spawn('docker', [...inStaging(false), 'php', '/app/bin/strauss.php']));
step(
	'dump-autoload',
	spawn('docker', [
		...inStaging(false),
		'composer',
		'dump-autoload',
		'--optimize',
		'--no-dev',
	]),
);
rmSync(join(root, STAGING, COMPOSER_LOCK));
step('make-pot', makePot(STAGING));
step(
	'zip',
	spawn('docker', [
		...inComposerContainer({ network: false, cwd: BUILD_DIR }),
		'zip',
		'-r',
		'-q',
		'-X',
		ZIP,
		SLUG,
	]),
);
console.log(`Built ${SLUG} ${built}: ${BUILD_DIR}/${ZIP}`);

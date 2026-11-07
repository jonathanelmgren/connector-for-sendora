#!/usr/bin/env node
// Writes the translation template, languages/connector-for-sendora.pot, from
// the plugin's PHP with WP-CLI's i18n command in a throwaway container.
//
//   node bin/make-pot.mjs           the checkout, without its development files
//   node bin/make-pot.mjs <dir>     a release build in <dir>, relative to the checkout
import { pathToFileURL } from 'node:url';
import {
	distIgnored,
	inComposerContainer,
	refuseSendoraVariables,
	SLUG,
	spawn,
} from './env.mjs';

/** WP-CLI from the dev install, run with PHP (vendor/bin/wp is a shell script). */
const WP_CLI = '/app/vendor/wp-cli/wp-cli/php/boot-fs.php';

/** Where the template goes, relative to the plugin's directory. */
export const POT_FILE = `languages/${SLUG}.pot`;

/**
 * Writes the template for the plugin in `dir`; returns the exit status.
 *
 * @param {string} dir The plugin's directory relative to the checkout, or '' for the checkout.
 */
export function makePot(dir = '') {
	const exclude = dir === '' ? ['vendor', ...distIgnored()] : ['vendor'];
	return spawn('docker', [
		...inComposerContainer({ network: false, cwd: dir }),
		'php',
		WP_CLI,
		'i18n',
		'make-pot',
		'.',
		POT_FILE,
		`--slug=${SLUG}`,
		`--domain=${SLUG}`,
		`--exclude=${exclude.join(',')}`,
		// The container's root is the host user under rootless Docker.
		'--allow-root',
	]);
}

if (import.meta.url === pathToFileURL(process.argv[1]).href) {
	refuseSendoraVariables();
	process.exit(makePot(process.argv[2] ?? ''));
}

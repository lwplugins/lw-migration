/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * At the bottom of Connections: what a connection is, what it is good for,
 * how it is made and what to know about it (spec 19: one direction, the
 * code's rights, one code once in 15 minutes, signed requests, the e-mail
 * to the admins, single sites only, http and blocked REST).
 */
export default function ConnectionsAbout() {
	return (
		<section className="lw-links-about" aria-labelledby="lw-links-about">
			<h2 id="lw-links-about" className="lw-links-about__title">
				{ __( 'What connections are for', 'lw-migration' ) }
			</h2>
			<p className="lw-links-about__lead">
				{ __(
					'A connection pairs two of your sites once. After that, one of them can send itself onto the other, or copy the other one onto itself, server to server: no file to download and upload again, and no service in between.',
					'lw-migration'
				) }
			</p>
			<div className="lw-links-about__columns">
				<div>
					<h3 className="lw-links-about__heading">
						{ __( 'Good for', 'lw-migration' ) }
					</h3>
					<ul className="lw-links-about__list">
						<li>
							{ __(
								'Moving a site to a new host: install WordPress and this plugin there, connect the two, then bring the old site over.',
								'lw-migration'
							) }
						</li>
						<li>
							{ __(
								'Refreshing a staging copy with the content of the live site.',
								'lw-migration'
							) }
						</li>
						<li>
							{ __(
								'Putting a finished staging site onto the live address.',
								'lw-migration'
							) }
						</li>
					</ul>
				</div>
				<div>
					<h3 className="lw-links-about__heading">
						{ __( 'How it works', 'lw-migration' ) }
					</h3>
					<ol className="lw-links-about__list">
						<li>
							{ __(
								'On one site, make a code under New connection and choose what the other site may do with it: copy it, replace its content, or both.',
								'lw-migration'
							) }
						</li>
						<li>
							{ __(
								'On the other site, type the code within 15 minutes. The connection works from there: that site sends or brings the content.',
								'lw-migration'
							) }
						</li>
						<li>
							{ __(
								"Choose Send this site there or Bring that site here on the connection's card. The site whose content is replaced backs up its database first, unless you switch that off.",
								'lw-migration'
							) }
						</li>
					</ol>
				</div>
			</div>
			<h3 className="lw-links-about__heading">
				{ __( 'Good to know', 'lw-migration' ) }
			</h3>
			<ul className="lw-links-about__list">
				<li>
					{ __(
						'A code connects one site, once. The two sites sign every request with a shared secret that is stored encrypted and never shown, and the admins of the site that made the code get an e-mail about each new connection.',
						'lw-migration'
					) }
				</li>
				<li>
					{ __(
						'Both sites need LW Migration, and neither can be a multisite network. Use https on both: over plain http the whole site travels unencrypted.',
						'lw-migration'
					) }
				</li>
				<li>
					{ __(
						'A security plugin that blocks the REST API for visitors blocks connections too; allow the lw-migration/v1/link routes in it.',
						'lw-migration'
					) }
				</li>
				<li>
					{ __(
						'Either site can remove the connection at any time; after that it needs a new code.',
						'lw-migration'
					) }
				</li>
			</ul>
		</section>
	);
}

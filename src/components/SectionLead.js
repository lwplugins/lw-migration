/**
 * A section's title and one line on what it is for, at the top of the
 * section like the other sections' leads (and over its loading shapes).
 *
 * @param {Object} props
 * @param {string} props.title Heading.
 * @param {string} props.intro What the section is for.
 */
export default function SectionLead( { title, intro } ) {
	return (
		<section className="lw-lead">
			<h2 className="lw-lead__title">{ title }</h2>
			<p className="lw-lead__intro">{ intro }</p>
		</section>
	);
}

/**
 * One section of the detail panel: a small heading, an optional action
 * beside it (a text button), and its body.
 *
 * @param {Object}  props
 * @param {string}  props.title    Heading.
 * @param {Element} props.action   Beside the heading (optional).
 * @param {Element} props.children Body.
 */
export default function DetailSection( { title, action = null, children } ) {
	return (
		<section className="lw-bk-detail__section">
			<div className="lw-bk-detail__head">
				<h4 className="lw-bk-detail__heading">{ title }</h4>
				{ action }
			</div>
			{ children }
		</section>
	);
}

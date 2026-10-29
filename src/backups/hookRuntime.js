/**
 * A minimal hook runtime for tests: one mounted component whose state,
 * refs, memos and effects keep their slot across renders (like React does),
 * re-rendered on every state change. Pair it with
 * a `vi.mock` of the element package that spreads `runtime.element`.
 */

/**
 * @param {Object[]} a Dependencies.
 * @param {Object[]} b Dependencies.
 * @return {boolean} Some dependency differs (or one list is missing).
 */
const changed = ( a, b ) =>
	! a || ! b || a.length !== b.length || a.some( ( x, i ) => x !== b[ i ] );

/**
 * @return {{element: Object, mount: (hook: (props: Object) => Object, props: Object) => Object}} The element package's stand-in and a mount().
 */
export function createRuntime() {
	let slots = [];
	let index = 0;
	let render = () => {};
	let effects = [];

	const slot = ( make ) => {
		const key = index++;
		if ( ! ( key in slots ) ) {
			slots[ key ] = make();
		}
		return slots[ key ];
	};

	const element = {
		useRef: ( value ) => slot( () => ( { current: value } ) ),
		useState: ( initial ) => {
			// A function is a lazy initial value, as in React.
			const cell = slot( () => ( {
				value: 'function' === typeof initial ? initial() : initial,
			} ) );
			if ( ! cell.set ) {
				cell.set = ( next ) => {
					cell.value =
						'function' === typeof next ? next( cell.value ) : next;
					render();
				};
			}
			return [ cell.value, cell.set ];
		},
		useCallback: ( fn, deps ) => {
			const cell = slot( () => ( { fn, deps } ) );
			if ( changed( cell.deps, deps ) ) {
				cell.fn = fn;
				cell.deps = deps;
			}
			return cell.fn;
		},
		useEffect: ( run, deps ) => {
			const cell = slot( () => ( { deps: null, cleanup: null } ) );
			if ( changed( cell.deps, deps ) ) {
				cell.deps = deps;
				effects.push( () => {
					cell.cleanup?.();
					cell.cleanup = run() || null;
				} );
			}
		},
	};

	/**
	 * @param {(props: Object) => Object} hook  The hook under test.
	 * @param {Object}                    props First argument of the hook.
	 * @return {{result: () => Object, update: (props: Object) => void, unmount: () => void}} Mounted hook.
	 */
	function mount( hook, props ) {
		let current = props;
		let result;
		let live = true;
		slots = [];
		render = () => {
			if ( ! live ) {
				return;
			}
			index = 0;
			result = hook( current );
			const run = effects;
			effects = [];
			run.forEach( ( effect ) => effect() );
		};
		render();
		return {
			result: () => result,
			update: ( next ) => {
				current = next;
				render();
			},
			unmount: () => {
				live = false;
				slots
					.filter( ( cell ) => 'cleanup' in cell )
					.forEach( ( cell ) => cell.cleanup?.() );
			},
		};
	}

	return { element, mount };
}

/** The one runtime the tests share. */
export const runtime = createRuntime();

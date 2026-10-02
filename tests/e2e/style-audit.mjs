/**
 * Runtime audit of every Elementor style control (WID-N6).
 *
 * For each podcast widget it builds one Elementor page with a baseline
 * instance (Style Source "Custom", nothing set) and one instance per style
 * control (or group control) with a distinctive value, then compares the
 * computed styles of every element inside each instance with the
 * baseline. Controls that only act on hover are compared while hovering
 * their target. A control that changes nothing fails the suite.
 *
 * The result is the generated table in CONTROL-AUDIT.md (between the
 * style-audit markers). The suite fails when the committed table differs
 * from what it measured; regenerate it with
 *
 *   cd tests/e2e && EPM_WRITE_AUDIT=1 WP_URL=… WP_CLI=… node style-audit.mjs
 *
 * Pages created here are deleted at the end; the measured data is saved
 * to screenshots/style-audit.json.
 */
import fs from 'node:fs';
import { BASE, php, assert, finish, launch, newPage } from './lib.mjs';

const AUDIT = new URL('../../CONTROL-AUDIT.md', import.meta.url);
const START = '<!-- style-audit:start -->';
const END = '<!-- style-audit:end -->';

// Controls that style a hover (or playing) state: compared while hovering.
const HOVER = {
	play_button_background_hover: '.epm-player__play',
	play_button_color_hover: '.epm-player__play',
	secondary_color_hover: '.epm-player__speed',
	subscribe_accent: '.epm-subscribe__link',
	list_accent: '.epm-episode-card__play',
	video_accent: '.epm-video__facade',
	video_on_accent: '.epm-video__facade',
};

// Properties compared per element.
const PROPS = ['color', 'background-color', 'background-image', 'border-top-color', 'border-top-width', 'border-top-style', 'border-top-left-radius', 'width', 'height', 'font-size', 'font-weight', 'padding-top', 'padding-left', 'row-gap', 'column-gap', 'box-shadow', 'fill', 'stroke', 'margin-top', 'outline-color'];

const plan = php(`
	$fx  = get_option( 'epm_test_fixtures' );
	$ep1 = (int) $fx['ep1'];
	update_post_meta( $ep1, '_epm_guest_image_id', (int) $fx['square_image'] );
	update_post_meta( $ep1, '_epm_youtube_url', 'https://youtu.be/dQw4w9WgXcQ' );
	EPM\\Episodes::clear_data_cache( $ep1 );
	$ep = [ 'source' => 'specific', 'episode_id' => (string) $ep1 ];
	$bases = [
		'epm-podcast-player'   => $ep + [ 'layout' => 'full', 'show_download' => 'yes', 'show_description' => 'yes', 'show_date' => 'yes', 'show_episode_number' => 'yes' ],
		'epm-episode-list'     => [ 'layout' => 'cards', 'number' => 3 ],
		'epm-latest-episode'   => [ 'layout' => 'full', 'show_cta' => 'yes', 'cta_url' => [ 'url' => 'https://example.org/' ] ],
		'epm-podcast-hero'     => [ 'show_cta' => 'yes', 'cta_url' => [ 'url' => 'https://example.org/' ] ],
		'epm-episode-header'   => $ep + [ 'show_artwork' => 'yes', 'show_description' => 'yes' ],
		'epm-episode-metadata' => $ep,
		'epm-guest'            => $ep + [ 'show_bio' => 'yes' ],
		'epm-subscribe-links'  => [],
		'epm-transcript'       => $ep,
		'epm-show-notes'       => $ep,
		'epm-chapters'         => $ep,
		'epm-episode-video'    => $ep,
	];
	// Register every control with its label: Elementor keeps only the
	// CSS-relevant parts of style controls on frontend requests.
	$optimized = new ReflectionProperty( \\Elementor\\Core\\Frontend\\Performance::class, 'is_frontend' );
	$optimized->setAccessible( true );
	$optimized->setValue( null, false );
	$plan = [];
	foreach ( $bases as $type => $base ) {
		$base['style_source'] = 'custom';
		$probe    = \\Elementor\\Plugin::$instance->elements_manager->create_element_instance( [ 'id' => 'p', 'elType' => 'widget', 'widgetType' => $type, 'settings' => [], 'elements' => [] ] );
		$controls = $probe->get_controls();
		$style    = array_filter(
			$controls,
			static function ( $c ) {
				return 'style' === ( $c['tab'] ?? '' ) && empty( $c['is_common'] ) && 0 !== strpos( (string) ( $c['name'] ?? '' ), '_' );
			}
		);
		$label = static function ( $id ) use ( $controls ) {
			$c       = $controls[ $id ] ?? [];
			$section = (string) ( $controls[ $c['section'] ?? '' ]['label'] ?? '' );
			return trim( $section . ' → ' . (string) ( $c['label'] ?? $id ), ' →' );
		};
		// Group controls: typography and box shadow carry a prefix; a border
		// group is "<prefix>border" with "<prefix>width" next to it.
		$groups = [];
		foreach ( $style as $id => $c ) {
			if ( ! empty( $c['groupPrefix'] ) ) {
				$groups[ $c['groupPrefix'] ] = $c['groupType'] ?? ( isset( $style[ $c['groupPrefix'] . 'box_shadow_type' ] ) ? 'box-shadow' : '' );
			} elseif ( preg_match( '/^(.+_)border$/', $id, $m ) && isset( $style[ $m[1] . 'width' ] ) ) {
				$groups[ $m[1] ] = 'border';
			}
		}
		$variants = [];
		foreach ( $style as $id => $c ) {
			if ( 'style_source' === $id ) {
				continue;
			}
			foreach ( array_keys( $groups ) as $prefix ) {
				if ( 0 === strpos( $id, $prefix ) ) {
					continue 2;
				}
			}
			$v = null;
			switch ( $c['type'] ) {
				case 'color':
					$v = '#ff00aa';
					break;
				case 'slider':
					$max = $c['range']['px']['max'] ?? 100;
					$v   = [ 'unit' => 'px', 'size' => min( 37, $max ) ];
					break;
				case 'dimensions':
					$v = [ 'unit' => 'px', 'top' => '37', 'right' => '37', 'bottom' => '37', 'left' => '37', 'isLinked' => true ];
					break;
				case 'select':
					$v = 'square';
					break;
			}
			if ( null === $v ) {
				continue;
			}
			$variants[] = [ 'control' => $id, 'label' => $label( $id ), 'settings' => [ $id => $v ] ];
		}
		foreach ( $groups as $prefix => $kind ) {
			if ( isset( $style[ $prefix . 'typography' ] ) ) {
				$s  = [ $prefix . 'typography' => 'custom', $prefix . 'font_size' => [ 'unit' => 'px', 'size' => 31 ], $prefix . 'font_weight' => '900' ];
				$lb = $label( $prefix . 'typography' );
			} elseif ( 'border' === $kind ) {
				$s  = [ $prefix . 'border' => 'dashed', $prefix . 'width' => [ 'unit' => 'px', 'top' => '5', 'right' => '5', 'bottom' => '5', 'left' => '5', 'isLinked' => true ], $prefix . 'color' => '#ff00aa' ];
				$lb = $label( $prefix . 'border' );
			} elseif ( isset( $style[ $prefix . 'box_shadow_type' ] ) ) {
				$s  = [ $prefix . 'box_shadow_type' => 'yes', $prefix . 'box_shadow' => [ 'horizontal' => 7, 'vertical' => 7, 'blur' => 0, 'spread' => 0, 'color' => 'rgba(255,0,170,1)' ] ];
				$lb = $label( $prefix . 'box_shadow_type' );
			} else {
				continue;
			}
			$variants[] = [ 'control' => rtrim( $prefix, '_' ) . ' (group)', 'label' => $lb, 'settings' => $s ];
		}
		$elements = [ [ 'id' => 'b' . substr( md5( $type ), 0, 6 ), 'elType' => 'widget', 'widgetType' => $type, 'settings' => $base, 'elements' => [] ] ];
		foreach ( $variants as $i => &$variant ) {
			$variant['id'] = 'v' . substr( md5( $type . $i ), 0, 6 );
			$elements[]    = [ 'id' => $variant['id'], 'elType' => 'widget', 'widgetType' => $type, 'settings' => array_merge( $base, $variant['settings'] ), 'elements' => [] ];
		}
		unset( $variant );
		$slug = 'epm-style-audit-' . str_replace( 'epm-', '', $type );
		$old  = get_page_by_path( $slug );
		if ( $old ) {
			wp_delete_post( $old->ID, true );
		}
		$pid = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $slug, 'post_name' => $slug ] );
		update_post_meta( $pid, '_elementor_edit_mode', 'builder' );
		update_post_meta( $pid, '_elementor_template_type', 'wp-page' );
		update_post_meta( $pid, '_elementor_version', ELEMENTOR_VERSION );
		update_post_meta( $pid, '_elementor_data', wp_slash( wp_json_encode( [ [ 'id' => 'c' . substr( md5( $slug ), 0, 6 ), 'elType' => 'container', 'settings' => [], 'isInner' => false, 'elements' => $elements ] ] ) ) );
		$plan[ $type ] = [ 'page' => $pid, 'url' => get_permalink( $pid ), 'title' => (string) $probe->get_title(), 'baseline' => $elements[0]['id'], 'variants' => $variants ];
	}
	\\Elementor\\Plugin::$instance->files_manager->clear_cache();
	echo wp_json_encode( $plan );
`);

const browser = await launch();
const rows = [];
try {
	const page = await newPage(browser, { width: 1280, height: 900 });
	for (const [type, p] of Object.entries(plan)) {
		await page.goto(p.url);
		await page.waitForTimeout(300);
		// Computed styles of every element in an instance (optionally while hovering a target).
		const fingerprint = async (id, hover) => {
			if (hover) {
				const target = page.locator(`.elementor-element-${id} ${hover}`).first();
				if (await target.count()) {
					await target.hover({ force: true });
					await page.waitForTimeout(350);
				}
			}
			const out = await page.evaluate(
				([id, props]) => {
					const root = document.querySelector(`.elementor-element-${id}`);
					if (!root) {
						return null;
					}
					return [...root.querySelectorAll('*')].map((el) => {
						const cs = getComputedStyle(el);
						const cls = typeof el.className === 'string' ? el.className : (el.className && el.className.baseVal) || '';
						const epm = cls.split(/\s+/).find((c) => /^epm-/.test(c) && !/--/.test(c)) || '';
						return { el: epm ? `.${epm}` : el.tagName.toLowerCase(), values: props.map((pr) => cs.getPropertyValue(pr)) };
					});
				},
				[id, PROPS]
			);
			await page.mouse.move(0, 0);
			return out;
		};
		const base = await fingerprint(p.baseline);
		for (const v of p.variants) {
			const hover = HOVER[v.control] || null;
			const baseState = hover ? await fingerprint(p.baseline, hover) : base;
			const got = await fingerprint(v.id, hover);
			let status = 'no effect';
			let element = '';
			let props = [];
			if (!got) {
				status = 'not rendered';
			} else if (got.length !== baseState.length) {
				status = 'changes markup';
			} else {
				const changed = got.map((g, i) => ({ g, b: baseState[i] })).filter(({ g, b }) => g.values.join('|') !== b.values.join('|'));
				if (changed.length) {
					status = 'works';
					const first = changed.find(({ g }) => /^\.epm-/.test(g.el)) || changed[0];
					element = first.g.el;
					props = PROPS.filter((pr, i) => first.g.values[i] !== first.b.values[i]);
				}
			}
			rows.push({ widget: p.title, type, control: v.control, label: v.label, state: hover ? 'hover' : 'rest', status, element, props });
		}
	}
	await page.context().close();
} finally {
	await browser.close();
	php(`
		$fx = get_option( 'epm_test_fixtures' );
		foreach ( ${JSON.stringify(Object.values(plan).map((p) => p.page))} as $id ) { wp_delete_post( (int) $id, true ); }
		delete_post_meta( (int) $fx['ep1'], '_epm_guest_image_id' );
		delete_post_meta( (int) $fx['ep1'], '_epm_youtube_url' );
		EPM\\Episodes::clear_data_cache( (int) $fx['ep1'] );
		echo 1;
	`);
}

fs.writeFileSync('screenshots/style-audit.json', JSON.stringify(rows, null, 1));

console.log('Every style control changes what it says (WID-N6)');
let lastWidget = '';
for (const r of rows) {
	if (r.widget !== lastWidget) {
		console.log(`  ${r.widget}`);
		lastWidget = r.widget;
	}
	assert(r.status === 'works', `${r.control} (${r.label || 'no label'})${r.state === 'hover' ? ' on hover' : ''}: ${r.status}${r.element ? ` → ${r.element} ${r.props.join(', ')}` : ''}`);
}

// The generated table in CONTROL-AUDIT.md.
const table = [
	'| Widget | Control | Label | State | Result | First element changed |',
	'|---|---|---|---|---|---|',
	...rows.map((r) => `| ${r.widget} | \`${r.control}\` | ${r.label || '—'} | ${r.state} | ${r.status} | ${r.element ? `\`${r.element}\`` : '—'} |`),
].join('\n');
const doc = fs.readFileSync(AUDIT, 'utf8');
const start = doc.indexOf(START);
const end = doc.indexOf(END);
const current = start >= 0 && end > start ? doc.slice(start + START.length, end).trim() : '';
if (process.env.EPM_WRITE_AUDIT) {
	const next = start >= 0 && end > start ? doc.slice(0, start + START.length) + '\n' + table + '\n' + doc.slice(end) : `${doc}\n${START}\n${table}\n${END}\n`;
	fs.writeFileSync(AUDIT, next);
	console.log(`  (CONTROL-AUDIT.md table written: ${rows.length} controls)`);
} else {
	assert(current === table, 'CONTROL-AUDIT.md lists exactly what was measured (regenerate with EPM_WRITE_AUDIT=1)');
}

finish('style audit');

<?php
/**
 * Plugin Name: Contrast Fix
 * Plugin URI:  https://github.com/blogpocket/contrast-fix
 * Description: Detecta y corrige los errores de contraste de WCAG en el DOM renderizado. Mide el ratio real de cada texto, corrige solo lo que incumple y conserva el tono del color original en vez de arrasar con blanco o negro puro.
 * Version:     2.2.0
 * Author:      Antonio Cambronero (Blogpocket.com)
 * Author URI:  https://www.blogpocket.com
 * License:     GPLv2 or later
 * Text Domain: contrast-fix
 *
 * CAMBIO DE FILOSOFÍA RESPECTO A LA 1.0
 *
 *   1.0 -> recorría `body *` y forzaba color:#fff o #000 !important en TODOS los
 *          elementos, midiera o no. Destruía el diseño y aun así fallaba porque
 *          calculaba mal el fondo (ignoraba el canal alfa).
 *
 *   2.0 -> MIDE el ratio de contraste real de cada texto contra su fondo efectivo
 *          (con composición alfa correcta). Si cumple, NO LO TOCA. Si no cumple,
 *          ajusta la luminosidad del color original conservando su tono, hasta
 *          alcanzar el umbral. Solo recurre a blanco o negro si el tono no da más
 *          de sí.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CF_VERSION', '2.2.0' );
define( 'CF_OPTION', 'cf_settings' );

/* ------------------------------------------------------------------------- *
 * OPCIONES
 * ------------------------------------------------------------------------- */

function cf_defaults() {
	return array(
		'enabled'        => 1,
		'level'          => 'AA',        // AA (4.5:1 / 3:1) o AAA (7:1 / 4.5:1)
		'preserve_hue'   => 1,           // Ajusta la luminosidad conservando el tono
		'fix_borders'    => 0,           // Corrige también bordes de campos de formulario (3:1)
		'bg_image_mode'  => 'wave',      // wave | skip
		'neutralize_animations' => 1,    // Fuerza opacity:1 en animaciones atascadas en 0
		'exclude'        => '',          // Selectores CSS a excluir, uno por línea
		'debug'          => 0,
	);
}

function cf_options() {
	static $o = null;
	if ( null === $o ) {
		$saved = get_option( CF_OPTION, array() );
		$o     = array_merge( cf_defaults(), is_array( $saved ) ? $saved : array() );
	}
	return $o;
}

/* ------------------------------------------------------------------------- *
 * CARGA DEL SCRIPT
 * ------------------------------------------------------------------------- */

add_action( 'wp_enqueue_scripts', 'cf_enqueue', 5 );

function cf_enqueue() {
	$o = cf_options();

	if ( empty( $o['enabled'] ) || is_admin() ) {
		return;
	}

	$exclude = array_values(
		array_filter(
			array_map( 'trim', preg_split( '/[\r\n]+/', (string) $o['exclude'] ) )
		)
	);

	$cfg = array(
		'level'       => 'AAA' === $o['level'] ? 'AAA' : 'AA',
		'preserveHue' => ! empty( $o['preserve_hue'] ),
		'fixBorders'  => ! empty( $o['fix_borders'] ),
		'bgImageMode' => 'skip' === $o['bg_image_mode'] ? 'skip' : 'wave',
		'neutralizeAnimations' => ! empty( $o['neutralize_animations'] ),
		'exclude'     => $exclude,
		'debug'       => ! empty( $o['debug'] ),
	);

	wp_register_script( 'contrast-fix', '', array(), CF_VERSION, true );
	wp_add_inline_script( 'contrast-fix', 'window.CF_CONFIG=' . wp_json_encode( $cfg ) . ';', 'before' );
	wp_add_inline_script( 'contrast-fix', cf_script() );
	wp_enqueue_script( 'contrast-fix' );
}

function cf_script() {
	ob_start();
	?>
(function () {
	'use strict';

	var CFG = window.CF_CONFIG || {};
	var LEVEL = CFG.level === 'AAA' ? 'AAA' : 'AA';

	// Umbrales WCAG. Texto grande = >=24px, o >=18.66px si es negrita.
	var MIN_NORMAL = LEVEL === 'AAA' ? 7.0 : 4.5;
	var MIN_LARGE  = LEVEL === 'AAA' ? 4.5 : 3.0;
	var MIN_UI     = 3.0; // Componentes de interfaz (bordes de campos).

	var EXCLUDE_DEFAULT = [
		'#wpadminbar', '#wpadminbar *',
		'.screen-reader-text', '.sr-only', '.visually-hidden',
		'[data-cf-skip]', '[data-cf-skip] *'
	];
	var EXCLUDE = EXCLUDE_DEFAULT.concat(CFG.exclude || []).join(',');

	var seen = new WeakSet();
	var report = [];
	var scheduled = false;

	// La neutralización de animaciones solo actúa cuando la página ya se ha asentado.
	// Así no matamos los fundidos de entrada que se completan solos: solo tocamos los
	// que se quedan atascados en opacity:0.
	var settled = false;

	/* ===================== COLOR ===================== */

	var _probe = null;
	var _colorCache = new Map();

	/**
	 * Resuelve CUALQUIER color CSS a sRGB dejando que lo pinte el navegador.
	 *
	 * La 2.0 solo entendía rgb() y rgba() por expresión regular. Los temas de bloques
	 * modernos (theme.json, CSS vars, color-mix) hacen que getComputedStyle devuelva
	 * oklch(), lab(), color(srgb ...) o hsl(). La regex fallaba, parseColor devolvía
	 * null y check() abandonaba el elemento EN SILENCIO. Ese texto no se corregía nunca
	 * y WAVE seguía dando Low Contrast.
	 */
	function resolveViaCanvas(str) {
		try {
			if (!_probe) {
				var c = document.createElement('canvas');
				c.width = c.height = 1;
				_probe = c.getContext('2d', { willReadFrequently: true });
			}
			// Centinela: si el navegador no entiende el color, fillStyle no cambia.
			_probe.fillStyle = '#010203';
			_probe.fillStyle = str;
			if (_probe.fillStyle === '#010203' && str.toLowerCase().replace(/\s/g, '') !== '#010203') {
				return null; // formato no reconocido
			}
			_probe.clearRect(0, 0, 1, 1);
			_probe.fillRect(0, 0, 1, 1);
			var d = _probe.getImageData(0, 0, 1, 1).data;
			return { r: d[0], g: d[1], b: d[2], a: d[3] / 255 };
		} catch (e) {
			return null;
		}
	}

	function parseColor(str) {
		if (!str) return null;
		str = String(str).trim();
		if (str === 'transparent' || str === 'rgba(0, 0, 0, 0)') return { r: 0, g: 0, b: 0, a: 0 };

		if (_colorCache.has(str)) return _colorCache.get(str);

		var out = null;

		// Camino rápido: el formato que devuelve el navegador el 95% de las veces.
		var m = str.match(/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:[\s,/]+([\d.%]+))?\s*\)$/i);
		if (m) {
			var a = 1;
			if (m[4] !== undefined) {
				a = m[4].indexOf('%') !== -1 ? parseFloat(m[4]) / 100 : parseFloat(m[4]);
			}
			out = { r: +m[1], g: +m[2], b: +m[3], a: a };
		} else {
			// Cualquier otra cosa: oklch, lab, color(), hsl, nombres… lo resuelve el navegador.
			out = resolveViaCanvas(str);
		}

		_colorCache.set(str, out);
		return out;
	}

	/** Composición alfa: color "over" sobre color "under" (opaco). */
	function blend(over, under) {
		var a = over.a;
		return {
			r: over.r * a + under.r * (1 - a),
			g: over.g * a + under.g * (1 - a),
			b: over.b * a + under.b * (1 - a),
			a: 1
		};
	}

	function relLuminance(c) {
		var srgb = [c.r, c.g, c.b].map(function (v) {
			v = v / 255;
			return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
		});
		return 0.2126 * srgb[0] + 0.7152 * srgb[1] + 0.0722 * srgb[2];
	}

	function contrastRatio(a, b) {
		var la = relLuminance(a), lb = relLuminance(b);
		var hi = Math.max(la, lb), lo = Math.min(la, lb);
		return (hi + 0.05) / (lo + 0.05);
	}

	function rgbToHsl(c) {
		var r = c.r / 255, g = c.g / 255, b = c.b / 255;
		var max = Math.max(r, g, b), min = Math.min(r, g, b);
		var h = 0, s = 0, l = (max + min) / 2;
		var d = max - min;
		if (d !== 0) {
			s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
			if (max === r)      h = ((g - b) / d + (g < b ? 6 : 0));
			else if (max === g) h = ((b - r) / d + 2);
			else                h = ((r - g) / d + 4);
			h /= 6;
		}
		return { h: h, s: s, l: l };
	}

	function hslToRgb(hsl) {
		var h = hsl.h, s = hsl.s, l = hsl.l;
		function hue2rgb(p, q, t) {
			if (t < 0) t += 1;
			if (t > 1) t -= 1;
			if (t < 1 / 6) return p + (q - p) * 6 * t;
			if (t < 1 / 2) return q;
			if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6;
			return p;
		}
		var r, g, b;
		if (s === 0) {
			r = g = b = l;
		} else {
			var q = l < 0.5 ? l * (1 + s) : l + s - l * s;
			var p = 2 * l - q;
			r = hue2rgb(p, q, h + 1 / 3);
			g = hue2rgb(p, q, h);
			b = hue2rgb(p, q, h - 1 / 3);
		}
		return { r: Math.round(r * 255), g: Math.round(g * 255), b: Math.round(b * 255), a: 1 };
	}

	function toHex(c) {
		function h(v) { return ('0' + Math.max(0, Math.min(255, Math.round(v))).toString(16)).slice(-2); }
		return '#' + h(c.r) + h(c.g) + h(c.b);
	}

	/**
	 * Busca el color más cercano al original que cumpla el ratio, moviendo solo la
	 * luminosidad y conservando tono y saturación. Si el tono no da más de sí,
	 * recurre a blanco o negro.
	 */
	function fixColor(fg, bg, minRatio) {
		if (!CFG.preserveHue) {
			return contrastRatio({ r: 255, g: 255, b: 255, a: 1 }, bg) >= contrastRatio({ r: 0, g: 0, b: 0, a: 1 }, bg)
				? { r: 255, g: 255, b: 255, a: 1 }
				: { r: 0, g: 0, b: 0, a: 1 };
		}

		var hsl = rgbToHsl(fg);
		var bgLum = relLuminance(bg);
		// Si el fondo es claro, oscurecemos el texto. Si es oscuro, lo aclaramos.
		var darken = bgLum > 0.18;

		var dirs = darken ? [-1, 1] : [1, -1];

		for (var d = 0; d < dirs.length; d++) {
			var dir = dirs[d];
			for (var step = 1; step <= 100; step++) {
				var l = hsl.l + dir * step * 0.01;
				if (l < 0 || l > 1) break;
				var cand = hslToRgb({ h: hsl.h, s: hsl.s, l: l });
				if (contrastRatio(cand, bg) >= minRatio) return cand;
			}
		}

		// El tono no llega: blanco o negro, el que más contraste dé.
		var white = { r: 255, g: 255, b: 255, a: 1 };
		var black = { r: 0, g: 0, b: 0, a: 1 };
		return contrastRatio(white, bg) >= contrastRatio(black, bg) ? white : black;
	}

	/* ===================== DOM ===================== */

	/** Fondo efectivo, componiendo correctamente las capas semitransparentes. */
	function effectiveBg(el) {
		var layers = [];
		var hasImage = false;
		var p = el;

		while (p && p.nodeType === 1) {
			var cs = getComputedStyle(p);

			var bgImg = cs.backgroundImage;
			if (bgImg && bgImg !== 'none') hasImage = true;

			var c = parseColor(cs.backgroundColor);
			if (c && c.a > 0) {
				layers.push(c);
				if (c.a >= 1) break; // Capa opaca: no hace falta seguir subiendo.
			}
			p = p.parentElement;
		}

		// Base: si no encontramos capa opaca, el lienzo del navegador es blanco.
		var base = { r: 255, g: 255, b: 255, a: 1 };
		var result;
		if (layers.length && layers[layers.length - 1].a >= 1) {
			result = layers.pop();
		} else {
			result = base;
		}
		// Componemos de abajo arriba.
		for (var i = layers.length - 1; i >= 0; i--) {
			result = blend(layers[i], result);
		}
		return { rgb: result, hasImage: hasImage };
	}

	function hasOwnText(el) {
		for (var i = 0; i < el.childNodes.length; i++) {
			var n = el.childNodes[i];
			if (n.nodeType === 3 && n.nodeValue && n.nodeValue.trim()) return true;
		}
		var tag = el.tagName;
		if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') {
			var type = (el.getAttribute('type') || '').toLowerCase();
			if (['hidden', 'checkbox', 'radio', 'range', 'color', 'file'].indexOf(type) === -1) return true;
		}
		return false;
	}

	function isVisible(el, cs) {
		if (cs.display === 'none' || cs.visibility === 'hidden') return false;

		// OJO: NO descartamos por opacity:0.
		// Las animaciones de entrada (fade-in, fade-up, AOS, ext-animate…) arrancan con
		// opacity:0 y la suben a 1 con @keyframes. Si saltásemos esos elementos, nunca
		// los corregiríamos: las animaciones CSS no modifican atributos, así que el
		// MutationObserver no se entera de que han terminado. WAVE, en cambio, los
		// evalúa como texto visible y da error. WCAG tampoco tiene en cuenta la opacidad
		// del elemento al calcular el contraste, así que la ignoramos igual que el validador.

		// Lo que sí descartamos: lo que no se renderiza (ancestro con display:none).
		if (!el.getClientRects().length) return false;

		return true;
	}

	function minRatioFor(cs) {
		var size = parseFloat(cs.fontSize) || 16;
		var weight = parseInt(cs.fontWeight, 10) || 400;
		var bold = weight >= 700 || cs.fontWeight === 'bold' || cs.fontWeight === 'bolder';
		var large = size >= 24 || (bold && size >= 18.66);
		return large ? MIN_LARGE : MIN_NORMAL;
	}

	/**
	 * Opacidad acumulada del elemento y todos sus ancestros.
	 *
	 * WAVE multiplica esta opacidad por el alfa del color del texto. Si sale 0, el
	 * foreground le queda en #00000000 y el ratio en 1:1 -> "Low Contrast".
	 * La 2.1 ignoraba la opacidad por completo: medía el color contra el fondo, le
	 * salía bien, y WAVE seguía dando error. Los dos teníamos razón según nuestro
	 * propio modelo. Manda el de WAVE.
	 */
	function cumulativeOpacity(el) {
		var o = 1, p = el;
		while (p && p.nodeType === 1) {
			var v = parseFloat(getComputedStyle(p).opacity);
			if (!isNaN(v)) o *= v;
			if (o === 0) return 0;
			p = p.parentElement;
		}
		return o;
	}

	/**
	 * Animaciones de entrada atascadas en opacity:0.
	 *
	 * Las librerías de animación (ext-animate, AOS, Animate.css, WOW) arrancan el
	 * elemento en opacity:0 y lo suben a 1 con @keyframes. Cuando la animación se
	 * dispara al hacer scroll, todo lo que está bajo el pliegue se queda en 0 hasta
	 * que el usuario baja. WAVE analiza el DOM entero sin hacer scroll: ve opacidad 0
	 * y canta error.
	 *
	 * Ningún color arregla eso: con alfa 0, el ratio es 1:1 se ponga lo que se ponga.
	 * La única salida es forzar la opacidad a 1.
	 *
	 * Solo lo hacemos cuando la página ya se ha asentado (window.load + margen) y solo
	 * sobre elementos que TIENEN una animación CSS activa. Así no estropeamos los
	 * fundidos que se completan solos ni tocamos los menús que usan opacity:0 para
	 * ocultarse de verdad.
	 */
	function neutralizeStuckAnimation(el) {
		var node = el;
		var tocado = false;
		while (node && node.nodeType === 1 && node !== document.documentElement) {
			var cs = getComputedStyle(node);
			var op = parseFloat(cs.opacity);
			var anim = cs.animationName;
			if (op < 1 && anim && anim !== 'none') {
				node.style.setProperty('opacity', '1', 'important');
				if (CFG.debug) node.setAttribute('data-cf-anim', anim + ' (opacidad forzada a 1)');
				tocado = true;
			}
			node = node.parentElement;
		}
		return tocado;
	}

	/* ===================== CORRECCIÓN ===================== */

	function check(el) {
		if (!el || el.nodeType !== 1) return;
		if (el.matches && el.matches(EXCLUDE)) return;
		if (!hasOwnText(el)) return;

		var cs = getComputedStyle(el);
		if (!isVisible(el, cs)) return;

		var fg = parseColor(cs.color);
		if (!fg) return;

		var bgInfo = effectiveBg(el);

		// Fondos con imagen o degradado: no se puede saber el color real bajo el texto.
		// 'skip'  -> no tocamos (respeta cabeceras con foto, pero WAVE puede protestar).
		// 'wave'  -> lo tratamos como hace WAVE: usando el background-color computado.
		if (bgInfo.hasImage && CFG.bgImageMode === 'skip') {
			if (CFG.debug) report.push({ el: el, motivo: 'omitido (fondo con imagen)', ratio: null });
			return;
		}

		var bg = bgInfo.rgb;

		// --- MODELO DE OPACIDAD DE WAVE ---
		// alfa efectivo del texto = alfa del color x opacidad acumulada.
		var op = cumulativeOpacity(el);

		if (op < 1 && CFG.neutralizeAnimations && settled) {
			if (neutralizeStuckAnimation(el)) {
				op = cumulativeOpacity(el); // Recalculamos tras forzar la opacidad.
			}
		}

		if (op === 0) {
			// Sin animación que neutralizar (o con la neutralización desactivada):
			// el texto es invisible de verdad. Ningún color lo arregla.
			if (CFG.debug) report.push({ elemento: el.tagName.toLowerCase(), antes: '-', despues: '-', fondo: toHex(bg), ratio_antes: 1, ratio_despues: 1, umbral: 0 });
			return;
		}

		var fgEff = { r: fg.r, g: fg.g, b: fg.b, a: fg.a * op };

		// El texto también puede ser semitransparente: hay que componerlo sobre el fondo.
		var fgSolid = fgEff.a < 1 ? blend(fgEff, bg) : fgEff;

		var min = minRatioFor(cs);
		var ratio = contrastRatio(fgSolid, bg);

		if (ratio >= min) {
			seen.add(el);
			return; // Cumple. NO SE TOCA. Esta es la diferencia con la 1.0.
		}

		// Si el problema es la opacidad y no el color, subir la opacidad es lo único
		// que sirve. fixColor no puede hacer nada contra un alfa bajo.
		var nuevo = fixColor(fgSolid, bg, min);
		var hex = toHex(nuevo);
		el.style.setProperty('color', hex, 'important');

		if (CFG.debug) {
			el.setAttribute('data-cf-fixed', ratio.toFixed(2) + ' -> ' + contrastRatio(nuevo, bg).toFixed(2));
			report.push({
				elemento: el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).join('.') : ''),
				antes: toHex(fgSolid),
				despues: hex,
				fondo: toHex(bg),
				ratio_antes: +ratio.toFixed(2),
				ratio_despues: +contrastRatio(nuevo, bg).toFixed(2),
				umbral: min
			});
		}

		seen.add(el);
	}

	/** Bordes de campos de formulario: WCAG 1.4.11 exige 3:1. */
	function checkBorder(el) {
		var cs = getComputedStyle(el);
		if (!isVisible(el, cs)) return;
		if (parseFloat(cs.borderTopWidth) === 0) return;

		var bc = parseColor(cs.borderTopColor);
		if (!bc || bc.a === 0) return;

		var bg = effectiveBg(el.parentElement || el).rgb;
		var solid = bc.a < 1 ? blend(bc, bg) : bc;

		if (contrastRatio(solid, bg) >= MIN_UI) return;

		var nuevo = fixColor(solid, bg, MIN_UI);
		el.style.setProperty('border-color', toHex(nuevo), 'important');
	}

	/* ===================== BARRIDO ===================== */

	function sweep(root) {
		root = root || document.body;
		if (!root || root.nodeType !== 1) return;

		report = [];

		// El <body> también tiene texto propio: la 1.0 lo ignoraba (usaba `body *`).
		check(document.body);

		var list = root.querySelectorAll('*');
		for (var i = 0; i < list.length; i++) {
			check(list[i]);
		}

		if (CFG.fixBorders) {
			var campos = root.querySelectorAll('input, textarea, select');
			for (var j = 0; j < campos.length; j++) {
				checkBorder(campos[j]);
			}
		}

		if (CFG.debug && report.length) {
			console.groupCollapsed('[Contrast Fix] ' + report.length + ' correcciones (' + LEVEL + ')');
			console.table(report);
			console.groupEnd();
		}
	}

	/** Agrupa las mutaciones en un solo barrido por frame: evita el colapso de la 1.0. */
	function schedule() {
		if (scheduled) return;
		scheduled = true;
		requestAnimationFrame(function () {
			scheduled = false;
			observer.disconnect();
			sweep(document.body);
			connect();
		});
	}

	var observer = new MutationObserver(function (mutations) {
		for (var i = 0; i < mutations.length; i++) {
			var mu = mutations[i];
			// Ignoramos nuestros propios cambios de estilo para no entrar en bucle.
			if (mu.type === 'attributes' && mu.attributeName === 'style' && seen.has(mu.target)) continue;
			schedule();
			return;
		}
	});

	function connect() {
		observer.observe(document.body, {
			childList: true,
			subtree: true,
			characterData: true,
			// La 1.0 solo escuchaba childList: los cambios de clase (menús, pestañas,
			// acordeones, modo oscuro) no se reevaluaban nunca.
			attributes: true,
			attributeFilter: ['class', 'style']
		});
	}

	function boot() {
		sweep(document.body);
		connect();

		// Las fuentes web cambian el tamaño del texto -> puede cambiar el umbral.
		if (document.fonts && document.fonts.ready) {
			document.fonts.ready.then(function () { schedule(); });
		}

		// Animaciones y transiciones CSS: no generan mutaciones del DOM, así que el
		// MutationObserver es ciego a ellas. Un fade-up que se dispara al hacer scroll
		// llega mucho después del último barrido programado. Escuchamos su final.
		document.addEventListener('animationend', schedule, true);
		document.addEventListener('animationstart', schedule, true);
		document.addEventListener('transitionend', schedule, true);

		window.addEventListener('load', function () {
			schedule();
			// Damos margen a las animaciones de entrada para que se completen solas.
			// A partir de aquí, lo que siga en opacity:0 está atascado y se neutraliza.
			setTimeout(function () { settled = true; schedule(); }, 1200);
		});
		setTimeout(function () { settled = true; schedule(); }, 3000);
		setTimeout(schedule, 5000);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}

	// API para depurar desde la consola: CF.audit()
	window.CF = {
		audit: function () {
			var fallos = [];
			document.querySelectorAll('*').forEach(function (el) {
				if (el.matches(EXCLUDE) || !hasOwnText(el)) return;
				var cs = getComputedStyle(el);
				if (!isVisible(el, cs)) return;
				var fg = parseColor(cs.color);
				if (!fg) return;
				var bgi = effectiveBg(el);
				var bg = bgi.rgb;
				var op = cumulativeOpacity(el);
				var fgEff = { r: fg.r, g: fg.g, b: fg.b, a: fg.a * op };
				var solid = fgEff.a < 1 ? blend(fgEff, bg) : fgEff;
				var min = minRatioFor(cs);
				var r = contrastRatio(solid, bg);
				if (r < min) {
					fallos.push({
						elemento: el.tagName.toLowerCase(),
						texto: (el.textContent || '').trim().slice(0, 40),
						ratio: +r.toFixed(2),
						umbral: min,
						opacidad_acumulada: +op.toFixed(2),
						causa: op < 1 ? (op === 0 ? 'OPACIDAD 0 (animación atascada)' : 'opacidad reducida') : 'color',
						fondo_con_imagen: bgi.hasImage
					});
				}
			});
			if (fallos.length) console.table(fallos);
			else console.info('[Contrast Fix] Sin errores de contraste (' + LEVEL + ').');
			return fallos;
		},
		sweep: function () { sweep(document.body); },

		/**
		 * CF.why('.mi-selector') — explica paso a paso qué le pasa a ese elemento:
		 * si se excluyó, si se le vio texto, si se consideró visible, qué color y qué
		 * fondo se calcularon, qué ratio salió y si se corrigió o no.
		 * Es la herramienta para cerrar cualquier duda sin adivinar.
		 */
		why: function (sel) {
			var el = typeof sel === 'string' ? document.querySelector(sel) : sel;
			if (!el) {
				console.warn('[Contrast Fix] No encuentro ningún elemento con: ' + sel);
				return null;
			}

			var i = {
				elemento: el.tagName.toLowerCase(),
				clases: (typeof el.className === 'string' ? el.className : '') || '(ninguna)',
				texto: (el.textContent || '').trim().slice(0, 50)
			};

			if (el.matches(EXCLUDE)) {
				i.RESULTADO = '⛔ EXCLUIDO por un selector de la lista de exclusión';
				console.table([i]); return i;
			}
			if (!hasOwnText(el)) {
				i.RESULTADO = '⛔ SALTADO: no tiene nodos de texto propios (el texto está en un hijo). Prueba CF.why() sobre el hijo.';
				console.table([i]); return i;
			}

			var cs = getComputedStyle(el);
			i.display = cs.display;
			i.visibility = cs.visibility;
			i.opacity = cs.opacity;
			i.rects = el.getClientRects().length;

			if (!isVisible(el, cs)) {
				i.RESULTADO = '⛔ SALTADO: no se considera renderizado (ancestro con display:none, o visibility:hidden)';
				console.table([i]); return i;
			}

			i.color_computado = cs.color;
			var fg = parseColor(cs.color);
			if (!fg) {
				i.RESULTADO = '⛔ SALTADO: NO SE PUDO INTERPRETAR EL COLOR "' + cs.color + '". Repórtalo al autor.';
				console.table([i]); return i;
			}
			i.color_interpretado = toHex(fg) + (fg.a < 1 ? ' (alfa ' + fg.a + ')' : '');

			var bgi = effectiveBg(el);
			i.fondo_calculado = toHex(bgi.rgb);
			i.fondo_con_imagen = bgi.hasImage;

			if (bgi.hasImage && CFG.bgImageMode === 'skip') {
				i.RESULTADO = '⛔ SALTADO: fondo con imagen o degradado, y el modo es «Omitir». Cámbialo a «Modo WAVE» en los ajustes.';
				console.table([i]); return i;
			}

			i.opacidad_acumulada = +cumulativeOpacity(el).toFixed(3);
			i.animacion = cs.animationName;
			var op = cumulativeOpacity(el);
			var fgEff = { r: fg.r, g: fg.g, b: fg.b, a: fg.a * op };
			i.alfa_efectivo_como_lo_ve_WAVE = +fgEff.a.toFixed(3);

			if (op === 0) {
				i.RESULTADO = '🔴 OPACIDAD ACUMULADA 0. WAVE ve el texto como #00000000 y da ratio 1:1 -> Low Contrast. Ningún color arregla esto: hay que subir la opacidad. Activa «Neutralizar animaciones atascadas» en los ajustes.';
				console.table([i]); return i;
			}

			var solid = fgEff.a < 1 ? blend(fgEff, bgi.rgb) : fgEff;
			i.tam_fuente = cs.fontSize;
			i.peso_fuente = cs.fontWeight;
			i.umbral = minRatioFor(cs);
			i.ratio = +contrastRatio(solid, bgi.rgb).toFixed(2);

			if (i.ratio >= i.umbral) {
				i.RESULTADO = '✅ CUMPLE (' + i.ratio + ' >= ' + i.umbral + '). No se toca. Si WAVE da error aquí, WAVE está calculando un fondo distinto al mío.';
			} else {
				var nuevo = fixColor(solid, bgi.rgb, i.umbral);
				i.color_propuesto = toHex(nuevo);
				i.ratio_propuesto = +contrastRatio(nuevo, bgi.rgb).toFixed(2);
				i.inline_aplicado = el.style.getPropertyValue('color') || '(NINGUNO — el script no llegó a este elemento)';
				i.RESULTADO = '⚠️ NO CUMPLE (' + i.ratio + ' < ' + i.umbral + ')';
			}

			console.table([i]);
			return i;
		}
	};
})();
	<?php
	return ob_get_clean();
}

/* ------------------------------------------------------------------------- *
 * PANEL DE AJUSTES
 * ------------------------------------------------------------------------- */

add_action( 'admin_menu', 'cf_admin_menu' );

function cf_admin_menu() {
	add_options_page( 'Contrast Fix', 'Contrast Fix', 'manage_options', 'cf-settings', 'cf_settings_page' );
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'cf_action_links' );

function cf_action_links( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=cf-settings' ) ) . '">Ajustes</a>' );
	return $links;
}

add_action( 'admin_init', 'cf_register_settings' );

function cf_register_settings() {
	register_setting( 'cf_group', CF_OPTION, 'cf_sanitize' );
}

function cf_sanitize( $in ) {
	return array(
		'enabled'       => empty( $in['enabled'] ) ? 0 : 1,
		'level'         => ( isset( $in['level'] ) && 'AAA' === $in['level'] ) ? 'AAA' : 'AA',
		'preserve_hue'  => empty( $in['preserve_hue'] ) ? 0 : 1,
		'fix_borders'   => empty( $in['fix_borders'] ) ? 0 : 1,
		'bg_image_mode' => ( isset( $in['bg_image_mode'] ) && 'skip' === $in['bg_image_mode'] ) ? 'skip' : 'wave',
		'neutralize_animations' => empty( $in['neutralize_animations'] ) ? 0 : 1,
		'exclude'       => isset( $in['exclude'] ) ? sanitize_textarea_field( $in['exclude'] ) : '',
		'debug'         => empty( $in['debug'] ) ? 0 : 1,
	);
}

function cf_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$o = cf_options();
	?>
	<div class="wrap">
		<h1>Contrast Fix <span style="font-size:13px;color:#666;">v<?php echo esc_html( CF_VERSION ); ?></span></h1>
		<p>Mide el contraste real de cada texto contra su fondo y corrige <strong>solo lo que incumple</strong>, conservando el tono del color original. Comprueba el resultado en <a href="https://wave.webaim.org/" target="_blank" rel="noopener">WAVE (WebAIM)</a>.</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'cf_group' ); ?>
			<table class="form-table" role="presentation">

				<tr>
					<th scope="row"><label for="cf_enabled">Activar</label></th>
					<td><input type="checkbox" id="cf_enabled" name="<?php echo esc_attr( CF_OPTION ); ?>[enabled]" value="1" <?php checked( $o['enabled'], 1 ); ?>></td>
				</tr>

				<tr>
					<th scope="row"><label for="cf_level">Nivel WCAG</label></th>
					<td>
						<select id="cf_level" name="<?php echo esc_attr( CF_OPTION ); ?>[level]">
							<option value="AA" <?php selected( $o['level'], 'AA' ); ?>>AA — 4.5:1 texto normal · 3:1 texto grande (lo que exige WAVE)</option>
							<option value="AAA" <?php selected( $o['level'], 'AAA' ); ?>>AAA — 7:1 texto normal · 4.5:1 texto grande</option>
						</select>
						<p class="description">Texto grande = 24px o más, o 18,66px o más si es negrita.</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="cf_preserve_hue">Conservar el tono</label></th>
					<td>
						<input type="checkbox" id="cf_preserve_hue" name="<?php echo esc_attr( CF_OPTION ); ?>[preserve_hue]" value="1" <?php checked( $o['preserve_hue'], 1 ); ?>>
						<p class="description">Ajusta la <strong>luminosidad</strong> del color original hasta que cumpla el umbral, manteniendo tono y saturación. Un azul flojo se convierte en un azul más oscuro, no en negro. Sin esta opción el plugin fuerza blanco o negro puro, que es lo que hacía la versión 1.0 con <em>todos</em> los elementos. <strong>Recomendado.</strong></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="cf_bg_image_mode">Fondos con imagen o degradado</label></th>
					<td>
						<select id="cf_bg_image_mode" name="<?php echo esc_attr( CF_OPTION ); ?>[bg_image_mode]">
							<option value="wave" <?php selected( $o['bg_image_mode'], 'wave' ); ?>>Modo WAVE — usar el color de fondo computado, ignorando la imagen</option>
							<option value="skip" <?php selected( $o['bg_image_mode'], 'skip' ); ?>>Omitir — no tocar el texto sobre imágenes</option>
						</select>
						<p class="description">Ningún script puede saber el color exacto de los píxeles bajo el texto. <strong>Modo WAVE</strong> replica el cálculo del validador y elimina el error, pero puede alterar el texto de cabeceras con foto. <strong>Omitir</strong> respeta el diseño, pero WAVE seguirá protestando ahí. Si eliges «modo WAVE» y una cabecera te queda mal, excluye su selector abajo y arréglala con una capa oscura en CSS.</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="cf_neutralize">Animaciones atascadas</label></th>
					<td>
						<input type="checkbox" id="cf_neutralize" name="<?php echo esc_attr( CF_OPTION ); ?>[neutralize_animations]" value="1" <?php checked( $o['neutralize_animations'], 1 ); ?>>
						<p class="description">
							Las librerías de animación de entrada (<code>ext-animate</code>, AOS, Animate.css, WOW) arrancan el elemento en <code>opacity: 0</code>. Cuando la animación se dispara al hacer scroll, todo lo que está bajo el pliegue se queda en 0 hasta que el usuario baja. <strong>WAVE analiza el DOM entero sin hacer scroll</strong>: ve opacidad 0, calcula el color del texto como <code>#00000000</code>, le sale un ratio de 1:1 y canta <em>Low Contrast</em>.<br><br>
							Con alfa 0 <strong>ningún color arregla el ratio</strong>: la única salida es forzar la opacidad a 1. Esta opción lo hace, pero <strong>solo</strong> sobre elementos con una animación CSS activa y <strong>solo</strong> después de que la página se haya asentado, así que los fundidos que se completan solos no se tocan. Los que se disparan al hacer scroll perderán el fundido (el desplazamiento se mantiene).<br><br>
							<strong>El arreglo de verdad está en el tema:</strong> configura la extensión de animaciones para que no use opacidad, o desactiva las animaciones de entrada en las páginas que audites.
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="cf_fix_borders">Bordes de formulario</label></th>
					<td>
						<input type="checkbox" id="cf_fix_borders" name="<?php echo esc_attr( CF_OPTION ); ?>[fix_borders]" value="1" <?php checked( $o['fix_borders'], 1 ); ?>>
						<p class="description">Aplica el mínimo de 3:1 a los bordes de <code>input</code>, <code>textarea</code> y <code>select</code> (WCAG 1.4.11). WAVE no lo marca como error, pero es un fallo real de accesibilidad.</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="cf_exclude">Selectores excluidos</label></th>
					<td>
						<textarea id="cf_exclude" name="<?php echo esc_attr( CF_OPTION ); ?>[exclude]" rows="5" class="large-text code" placeholder=".hero-banner&#10;.logo&#10;#slider .caption"><?php echo esc_textarea( $o['exclude'] ); ?></textarea>
						<p class="description">Un selector CSS por línea. Estos elementos y su contenido no se tocan. También puedes añadir el atributo <code>data-cf-skip</code> a cualquier elemento del HTML.</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="cf_debug">Modo depuración</label></th>
					<td>
						<input type="checkbox" id="cf_debug" name="<?php echo esc_attr( CF_OPTION ); ?>[debug]" value="1" <?php checked( $o['debug'], 1 ); ?>>
						<p class="description">Muestra en la consola una tabla con cada corrección (color antes, color después, fondo, ratio antes, ratio después) y marca los elementos con <code>data-cf-fixed</code>. Además habilita <code>CF.audit()</code> en la consola, que lista los textos que <em>siguen</em> incumpliendo. <strong>Desactívalo en producción.</strong></p>
					</td>
				</tr>

			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

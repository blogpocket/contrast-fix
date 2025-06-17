<?php
/*
Plugin Name: Contrast Fix
Description: Ajusta automáticamente el color de texto según el fondo.
Version: 1.0
*/
function cf_enqueue_script(){
  wp_register_script(
    'contrast-fix',
    false,
    [],
    null,
    true
  );
  wp_add_inline_script('contrast-fix', "
(function(){
  // 1) Parsea “rgb(...)” o “rgba(...)” y devuelve {r,g,b,a}
  function parseRGBA(str) {
    const m = str.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*(\d*\.?\d+))?\)/);
    if (!m) return null;
    return {
      r: +m[1],
      g: +m[2],
      b: +m[3],
      a: m[4] !== undefined ? +m[4] : 1
    };
  }

  // 2) Sube por padres hasta encontrar un fondo no transparente
  function getEffectiveRGB(el) {
    let p = el;
    while (p && p.nodeType === 1) {
      const rgba = parseRGBA(window.getComputedStyle(p).backgroundColor);
      if (rgba && rgba.a > 0) {
        return [rgba.r/255, rgba.g/255, rgba.b/255];
      }
      p = p.parentElement;
    }
    return [1,1,1]; // blanco si no hay fondo sólido
  }

  // 3) Calcula luminancia WCAG
  function luminance([r,g,b]) {
    [r,g,b] = [r,g,b].map(c =>
      c <= 0.03928 ? c/12.92 : Math.pow((c+0.055)/1.055, 2.4)
    );
    return 0.2126*r + 0.7152*g + 0.0722*b;
  }

  // 4) Elige el color (negro o blanco) con mayor ratio de contraste
  function bestContrastColor(rgb) {
    const lum = luminance(rgb);
    const ratioWhite = (1 + 0.05) / (lum + 0.05);
    const ratioBlack = (lum + 0.05) / 0.05;
    return ratioWhite > ratioBlack ? '#fff' : '#000';
  }

  // 5) Recorre todos los elementos y aplica el color con ¡important!
  function fixContrast() {
    document.querySelectorAll('body *').forEach(el => {
      const rgb = getEffectiveRGB(el);
      const color = bestContrastColor(rgb);
      el.style.setProperty('color', color, 'important');
    });
  }

  // Ejecutar al cargar DOM y al cargar recursos
  document.addEventListener('DOMContentLoaded', fixContrast);
  window.addEventListener('load', fixContrast);

  // Reaplicar si cambian elementos o atributos dinámicamente
  const observer = new MutationObserver(fixContrast);
  observer.observe(document.body, { childList: true, subtree: true, attributes: true });

})();
");
  wp_enqueue_script('contrast-fix');
}
add_action('wp_enqueue_scripts', 'cf_enqueue_script');

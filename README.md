=== Contrast Fix ===
Contributors: Antonio Cambronero (Blogpocket.com)
Tags: accessibility, contrast, texto
Requires at least: 5.0
Tested up to: 6.3
Stable tag: 1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ajusta el color del texto dinámicamente para garantizar un contraste suficiente con los colores de fondo, eliminando errores de contraste.

== Installation ==

1. Sube la carpeta `contrast-fix` al directorio `/wp-content/plugins/`.
2. En el administrador de WordPress, ve a **Plugins** y activa **Contrast Fix**.
3. No requiere configuración adicional. El plugin ajustará automáticamente el contraste del texto al cargar la página.

== Changelog ==

= 1.1 =
- observer.disconnect(): desconecta antes de tocar estilos para que no se vuelva a disparar en cascada.
- observer.observe(...) sin attributes: true: ahora solo vigila nodos nuevos o eliminados, no cambios de atributos.
- Corrección inicial con fixContrast() y luego solo en nuevos nodos.
* Corrección para no bloquear las páginas
= 1.0 =
* Initial release: dynamic contrast adjustment.

== License ==
Este plugin está licenciado bajo la GPLv2 o posterior.

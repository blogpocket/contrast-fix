# Contrast Fix

**Versión:** 2.0.0
**Autor:** Antonio Cambronero (Blogpocket.com)

Detecta y corrige los errores de contraste de WCAG en el DOM renderizado. **Mide** el ratio real de cada texto, corrige **solo lo que incumple** y conserva el tono del color original.

## Qué cambia respecto a la 1.0

La 1.0 recorría `body *` y forzaba `color: #fff` o `#000` con `!important` en **todos** los elementos, midiera o no. Aplanaba el diseño entero y aun así se dejaba errores.

### El fallo de cálculo

`getEffectiveRGB` cogía el primer fondo con `alpha > 0` y lo trataba como opaco.

Un `rgba(0, 0, 0, 0.5)` sobre blanco **no es negro**: es gris medio (`#808080`). La 1.0 lo leía como negro puro, calculaba que un texto blanco encima tenía 21:1 de contraste y lo daba por bueno. El ratio real es **3.98:1** — error de WAVE. El plugin creía haberlo arreglado y no lo había tocado.

La 2.0 hace **composición alfa** correcta capa por capa, y también compone el propio color del texto si es semitransparente (la 1.0 ni lo miraba).

### Otros errores que se escapaban

| Problema en la 1.0 | Solución en la 2.0 |
|---|---|
| Selector `body *`: el texto suelto de `<body>` no se tocaba | Se comprueba también `<body>` |
| `MutationObserver` solo escuchaba `childList` | Escucha `class` y `style`: menús, pestañas, acordeones, modo oscuro |
| Elementos ocultos al cargar que aparecen después | Se reevalúan al hacerse visibles |
| Sin *debounce*: cada mutación disparaba un `querySelectorAll('body *')` completo con `getComputedStyle` de toda la cadena de ancestros | Barrido agrupado por frame con `requestAnimationFrame` |
| Ignoraba el tamaño y el peso de la fuente | Aplica el umbral correcto: 3:1 para texto grande, 4.5:1 para el normal |

## Cómo corrige

Si el texto **cumple**, no se toca. Punto.

Si no cumple, ajusta la **luminosidad** del color original conservando tono y saturación, hasta alcanzar el umbral. Un azul de marca flojo (`#5AA0F0`, ratio 2.72) se convierte en `#1473DF` (ratio 4.62) — mismo tono, 212°. La 1.0 lo habría puesto negro.

Solo recurre a blanco o negro puro cuando el tono no da más de sí.

## Umbrales

| | Texto normal | Texto grande |
|---|---|---|
| **AA** (lo que exige WAVE) | 4.5:1 | 3:1 |
| **AAA** | 7:1 | 4.5:1 |

Texto grande = 24px o más, o 18,66px o más si es negrita.

## Fondos con imagen o degradado

Ningún script puede saber el color exacto de los píxeles bajo el texto. Dos modos:

- **Modo WAVE** (por defecto): replica el cálculo del validador, que usa el `background-color` computado e ignora la imagen. Elimina el error, pero puede alterar el texto de cabeceras con foto.
- **Omitir**: respeta el diseño, pero WAVE seguirá protestando ahí.

Si eliges «modo WAVE» y una cabecera te queda mal, excluye su selector en los ajustes y arréglala como toca: una capa oscura semitransparente en CSS bajo el texto.

## Panel de ajustes

**Escritorio → Ajustes → Contrast Fix**

- Nivel WCAG (AA / AAA)
- Conservar el tono (recomendado)
- Modo para fondos con imagen
- Bordes de campos de formulario (WCAG 1.4.11, mínimo 3:1)
- Selectores excluidos, uno por línea. También sirve el atributo `data-cf-skip` en el HTML.
- Modo depuración

## Depuración

Con el modo depuración activo:

- La consola muestra una tabla con cada corrección: color antes, color después, fondo, ratio antes, ratio después y umbral aplicado.
- Los elementos corregidos llevan `data-cf-fixed="2.72 -> 4.62"`.
- Se habilita **`CF.audit()`** en la consola: lista los textos que **siguen** incumpliendo, con su ratio y si están sobre una imagen de fondo. Es la herramienta para cazar lo que se resista.
- **`CF.sweep()`** fuerza un barrido manual.

Desactívalo en producción.

## Instalación

1. Sube la carpeta `contrast-fix` a `/wp-content/plugins/`.
2. Actívalo desde **Plugins**.
3. Revisa **Ajustes → Contrast Fix**.

Pruébalo en un entorno de test antes de producción.

## Registro de cambios

### 2.2.0

- **El modelo de opacidad de WAVE.** WAVE multiplica la opacidad acumulada del elemento y sus ancestros por el alfa del color del texto, y premultiplica el RGB. Si la opacidad computada es 0, el foreground le queda en `#00000000` y el ratio en **1:1** → *Low Contrast*. Este plugin ignoraba la opacidad por completo: medía el color contra el fondo, le salía bien, y WAVE seguía dando error. Los dos teníamos razón según nuestro propio modelo. Manda el de WAVE.
- **Neutralización de animaciones atascadas.** Las librerías de animación de entrada (`ext-animate`, AOS, Animate.css, WOW) arrancan el elemento en `opacity: 0`. Cuando la animación se dispara al hacer scroll, todo lo que está bajo el pliegue se queda en 0 hasta que el usuario baja — y WAVE analiza el DOM entero sin hacer scroll. Con alfa 0 **ningún color arregla el ratio**, así que la única salida es forzar `opacity: 1`. Se hace solo sobre elementos con animación CSS activa y solo después de que la página se haya asentado, para no matar los fundidos que se completan solos. Nueva opción en el panel.
- `CF.audit()` y `CF.why()` informan de la opacidad acumulada y del alfa efectivo tal y como lo ve WAVE, y distinguen si la causa del fallo es el color o la opacidad.

### 2.1.0

- **`parseColor` solo entendía `rgb()` y `rgba()`.** Los temas de bloques modernos (`theme.json`, variables CSS, `color-mix()`) hacen que `getComputedStyle` devuelva `oklch()`, `lab()`, `color(srgb …)` o `hsl()`. La expresión regular fallaba, `parseColor` devolvía `null` y `check()` abandonaba el elemento **en silencio**: ese texto no se corregía nunca. Ahora el color lo resuelve el propio navegador mediante un canvas de 1×1 px, con caché.
- Nueva herramienta **`CF.why('.selector')`** en la consola: explica paso a paso qué le pasa a un elemento concreto (si se excluyó, si tiene texto propio, si se consideró renderizado, qué color y qué fondo se calcularon, qué ratio salió, si se corrigió y qué inline se aplicó). Es lo que hay que usar cuando WAVE marca algo que el plugin dice haber arreglado.

### 2.0.1

- **Animaciones de entrada.** Los elementos con `opacity: 0` (fade-in, fade-up, AOS, `ext-animate`…) se saltaban en el barrido y no se corregían nunca: las animaciones CSS no modifican atributos, así que el `MutationObserver` era ciego a ellas. Ahora se evalúan igual que hace WAVE, que tampoco tiene en cuenta la opacidad al calcular el contraste.
- Se escuchan `animationend`, `animationstart` y `transitionend`: cubre los fade-up que se disparan al hacer scroll, mucho después del último barrido programado.
- El criterio de «no renderizado» pasa a ser `getClientRects()`. Antes solo se miraba el `display` del propio elemento, así que el texto dentro de una pestaña o un acordeón cerrado (con el `display:none` en el ancestro) se procesaba sin necesidad.

### 2.0.0

- Composición alfa correcta: el fallo de cálculo que hacía que la 1.0 diera por buenos textos que WAVE marcaba como error.
- Se mide el ratio real y solo se corrige lo que incumple.
- Corrección conservando tono y saturación del color original.
- Umbrales según tamaño y peso de fuente (texto grande vs. normal).
- Nivel AA o AAA seleccionable.
- El texto semitransparente también se compone sobre el fondo.
- `MutationObserver` con `attributeFilter` en `class` y `style`, agrupado por frame.
- Pasadas adicionales en `load`, tras cargar las fuentes web, y a los 1,5 s y 4 s.
- Bordes de campos de formulario (WCAG 1.4.11).
- Selectores excluidos y atributo `data-cf-skip`.
- Panel de ajustes y modo depuración con `CF.audit()`.

### 1.0

- Versión inicial: forzaba blanco o negro en todos los elementos.

## Licencia

GPLv2 o superior.

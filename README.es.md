<h1 align="center">NextCloud Submission</h1>

<p align="center">
  <img src="https://img.shields.io/badge/version-2.1.0-informational" alt="Versión">
  <a href="https://moodle.org"><img src="https://img.shields.io/badge/Moodle-4.5%20--%205.1-orange?logo=moodle" alt="Moodle"></a>
  <img src="https://img.shields.io/badge/Workplace-4.5%2B-blue" alt="Moodle Workplace">
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/License-GPL--3.0-green" alt="Licencia">
  <a href="https://tresipunt.com"><img src="https://img.shields.io/badge/made%20by-Tresipunt-F84015" alt="Hecho por Tresipunt"></a>
</p>

<p align="center"><b>Entregar una tarea escribiendo en el documento, no subiendo un fichero.</b></p>

<p align="center"><a href="README.md">🇬🇧 English</a> · <b>🇪🇸 Español</b> · <a href="README.ca.md">Català</a></p>

Plugin de entrega que sustituye la subida de ficheros por un documento ofimático
alojado en NextCloud y editado en línea. El profesorado publica un enunciado,
cada alumno recibe su copia privada, escribe en ella desde dentro de Moodle y,
al entregar, se congela una copia que el profesorado corrige. No se adjunta nada
y no se descarga nada.

No modifica el núcleo de Moodle ni el tema, y no añade nada a las tareas donde no
está activado.

---

## ✨ Qué hace

- **Un documento por alumno, creado solo** — la primera vez que alguien abre la
  tarea, su copia de la plantilla se crea en NextCloud y se comparte con él. No
  hay ningún paso que el alumnado pueda equivocar.
- **Se escribe desde dentro de Moodle** — el documento se abre embebido en la
  página de la tarea. El alumnado no navega por NextCloud ni ve más documento que
  el suyo.
- **Entregar congela el trabajo** — la entrega es una copia aparte, que ya nadie
  toca. El alumno puede seguir con su borrador y el profesorado corrige lo que se
  entregó.
- **Se corrige sin salir de la pantalla de calificación** — la copia congelada se
  abre en el panel de corrección, junto a la rúbrica y el comentario.
- **Tareas de grupo y reintentos** — un documento por grupo cuando la tarea
  funciona así, y uno por intento cuando el profesorado la reabre.
- **El acceso se concede al entrar y se retira al salir** — quien puede abrir la
  tarea en Moodle recibe acceso al documento; perder un rol o una matrícula en el
  curso se lo quita (ver las limitaciones más abajo).
- **Copia de seguridad, restauración, importación y duplicado** — el enunciado
  viaja con la tarea y, con datos de usuario, también el borrador y la entrega de
  cada estudiante. Se copian en NextCloud, así que dos tareas nunca escriben en
  el mismo documento.
- **Avisa cuando el servicio está caído** — si NextCloud no responde, la tarea lo
  explica con palabras claras y rechaza la entrega, en vez de aceptar un trabajo
  que nunca se guardó.
- **Un registro de incidencias que dice qué falló** — cada llamada a NextCloud
  queda anotada con un código, agrupada por causa y con las credenciales
  enmascaradas, en una página propia para la administración.

## 🧭 Casos de uso

### Un departamento de lengua que corrige la redacción, no el fichero

El alumnado entrega redacciones. Con subida de ficheros, la mitad llegan en un
formato que no toca, algunas se abren con las fuentes movidas y unas cuantas
resultan estar vacías. El departamento activa este plugin con una plantilla
`.docx` que ya lleva el encabezado y los criterios de evaluación: todo el mundo
escribe en el mismo documento y el profesorado lo abre en el panel de corrección
y comenta ahí.

### Un centro sin suite ofimática en los equipos

Los ordenadores del aula no tienen procesador de textos y el alumnado trabaja
desde tabletas en casa. El centro ya tiene NextCloud con ONLYOFFICE. Configurar
el documento para que se abra en el editor embebido hace que baste con el
navegador, y el trabajo se queda en los servidores de la institución en lugar de
en una cuenta personal.

### Una tarea con un enunciado que hay que leer, no adjuntar

El profesorado prepara el enunciado como documento en vez de como PDF adjunto, y
se muestra en la cabecera de la tarea para todo el mundo. Cambiarlo cambia lo que
ven todos, sin una versión nueva que subir ni nadie trabajando con la copia de la
semana pasada.

### Un centro al que le auditan qué guarda de las personas

La institución responde solicitudes de protección de datos. El plugin declara las
cuatro tablas que mantiene y el hecho de que salen documentos del sitio, y una
solicitud de supresión borra tanto las filas en Moodle como los documentos en
NextCloud, en vez de dejar el trabajo —con el nombre de la persona dentro— en el
otro sistema.

## ⚙️ Cómo funciona

- El plugin habla con NextCloud mediante **una sola cuenta de servicio**, que es
  la propietaria de todos los documentos. Nadie necesita una contraseña de
  NextCloud para trabajar.
- Cada tarea mantiene tres clases de documento en la misma carpeta: el
  **enunciado** que publica el profesorado, el **borrador** de cada alumno y la
  **entrega congelada** que se crea al entregar.
- A quien abre una tarea se le da acceso al documento que le corresponde, y solo
  a ese. El permiso se renueva en cada visita, así que un acceso caducado se
  recupera con solo volver a entrar en la tarea.
- El documento se muestra de una de **tres maneras**, a elección de la
  administración: el editor embebido por Moodle —el más limpio para trabajar, y
  el recomendado: el alumnado ve el editor y nada más—, la página de NextCloud
  embebida, o un enlace que abre en una pestaña nueva, que funciona en cualquier
  sitio y no obliga a tocar nada en NextCloud. Las dos primeras tienen requisitos
  de infraestructura (más abajo).
- **La corrección se hace sobre el propio documento entregado**, y el
  profesorado decide si escribe en él. La entrega congelada se abre en el panel
  de calificación lista para editar: anotar el trabajo es justamente la idea, así
  que las correcciones se guardan en ese mismo fichero y el plugin no conserva
  una copia anterior. La consecuencia conviene saberla antes de confiar en ello:
  una vez corregida una entrega, lo que existe es el documento tal y como lo dejó
  quien corrigió, no tal y como lo entregó el alumnado. Los centros que necesiten
  poder demostrar qué se entregó exactamente deberían activar el **versionado de
  ficheros de NextCloud**, que conserva las revisiones anteriores.
- **Una entrega cerrada es de solo lectura.** Cuando la entrega está bloqueada,
  ha pasado su fecha límite o ya se ha entregado, el borrador se abre en solo
  lectura y el editor integrado ya no puede guardar en él. Con el editor
  integrado, cuenta lo que se guardó («Guardar cambios») o se entregó antes del
  cierre: lo que siga abierto en el editor en ese momento no se conserva.
- **Borrar es una decisión, no lo que pasa por defecto.** Borrar una entrega o
  una tarea en Moodle deja los documentos en NextCloud salvo que la
  administración pida otra cosa: un borrado aquí sería irreversible en el otro
  sistema. Reiniciar el curso quita las entregas y conserva el enunciado.
- **Nunca se escribe encima de un documento.** Si un nombre ya está ocupado en
  NextCloud —un documento conservado de una entrega eliminada, o el del curso
  anterior tras un reinicio—, el nuevo recibe un nombre numerado y el antiguo
  queda intacto.
- **Los documentos restaurados solo se copian si son de fiar.** Un documento de
  una copia de seguridad se copia si es de este sitio y quien restaura podría
  haber hecho la copia de su tarea. Si no, el enunciado parte de la plantilla,
  los borradores no se copian y el registro de incidencias lo anota.
- Todo lo que el plugin hace contra NextCloud se escribe en un **registro de
  incidencias** con su código, y se conserva el tiempo que diga la
  administración.

### Limitaciones

- **Perder una matrícula o un rol retira todos los accesos del curso.** El
  plugin no comprueba si la persona sigue matriculada por otra vía (por ejemplo,
  por cohorte además de a mano) ni si conserva otro rol. El acceso a sus propios
  documentos vuelve la próxima vez que entra en la tarea.

### Qué necesita el sitio según cómo se muestre el documento

Los requisitos son acumulativos: lee la fila que estés usando.

| Presentación | Qué necesita el sitio |
|---|---|
| Enlace, abierto en pestaña nueva | Nada más que una sesión de NextCloud. Funciona en todas partes |
| Página de NextCloud embebida | Proxy inverso **y** mismo dominio que Moodle **y** `https` en ambos |
| Document Server embebido | El editor alcanzable por Moodle y por el navegador, los dos por `https` |

> **El Document Server embebido es el mejor de los tres para trabajar, y el que
> hay que elegir si el sitio puede cumplir sus requisitos.** Lo que ve el
> alumnado es el editor y nada más: la barra de ONLYOFFICE y su documento, dentro
> de la página de la tarea. No viene con él nada de la interfaz de NextCloud —ni
> gestor de archivos, ni navegación, ni barra lateral, ni otros documentos—
> porque Moodle embebe el editor directamente, no la página que lo contiene.
>
> Eso no solo queda más limpio, sino más acotado: desde ahí no hay manera de
> escaparse a otros ficheros, y no hay que explicar nada de un segundo sistema,
> porque para el alumnado no existe un segundo sistema. Guardar es cosa del
> editor y lo hace solo, así que tampoco hay nada que recordar antes de entregar.
>
> Embeber la **página de NextCloud** es la opción intermedia, y la más floja:
> exige todo lo que se describe más abajo **y** además enseña la interfaz de
> NextCloud alrededor del documento. Está para los sitios cuyo editor no se puede
> alcanzar directamente.

> **El modo de pestaña nueva no necesita nada de lo anterior.** Todo lo que se
> explica en el resto de esta sección existe porque un documento mostrado
> *dentro* de Moodle viaja en un marco. Abrirlo en una pestaña nueva es una
> navegación normal: `X-Frame-Options` y `frame-ancestors` no entran en juego,
> así que **no hay que tocar nada en NextCloud**; la cookie de sesión viaja como
> siempre, así que Moodle y NextCloud no tienen por qué compartir dominio; y no
> hay ningún proxy inverso que montar.
>
> Es el modo que hay que usar cuando el NextCloud no es tuyo para configurarlo, o
> cuando quien lo administra no va a poner un proxy delante. Lo que se pierde es
> tener el documento dentro de la página: el alumnado trabaja en otra pestaña y
> vuelve a Moodle para entregar.

Embeber la página de NextCloud tiene tres condiciones, y cada una falla a su
manera:

**1. Un proxy inverso** delante de NextCloud, que retire `X-Frame-Options` y
amplíe `frame-ancestors`. NextCloud fuerza las dos desde su propio `.htaccess`,
así que no se pueden cambiar desde NextCloud. Apache, porque el arreglo edita
*parte* del valor de una cabecera:

```apache
Header unset X-Frame-Options
Header always unset X-Frame-Options
Header edit Content-Security-Policy "frame-ancestors 'self'" "frame-ancestors 'self' https://tu-moodle"
```

`Header edit` sustituye solo ese fragmento: reemplazar la cabecera entera
destruiría el valor de un solo uso que autoriza los scripts de NextCloud.

**2. NextCloud bajo el mismo dominio registrable que Moodle** —
`moodle.centro.edu` y `nc.centro.edu`. Las cookies de sesión de NextCloud son
`SameSite=Lax`, y un marco cuenta como una petición del sitio que lo contiene:
desde otro dominio, el navegador ni manda la sesión que hay ni acepta la que crea
el login, así que dentro del marco sale un formulario de acceso **que no entra
nunca**, por más que la contraseña sea correcta. Reescribir las cookies a
`SameSite=None; Secure` en el proxy es posible y no es recomendable: deja el
resultado en manos de la política de cookies de terceros de cada navegador y
manipula desde fuera la protección CSRF de NextCloud.

**3. `https` en los dos lados**, o el navegador bloquea el marco por contenido
mixto. Y `https` no va solo de las direcciones que escribes: **cada pieza detrás
del proxy construye sus propios enlaces a partir de lo que cree ser**, y detrás
de un proxy ninguna lo sabe. Si una se queda en `http`, el navegador bloquea sus
llamadas y el síntoma no menciona nunca el protocolo —un documento que se
descarga en vez de abrirse, un marco vacío, un error de descarga genérico—:

| Pieza | Cómo se le dice | Si falta |
|---|---|---|
| NextCloud | `overwritehost`, `overwriteprotocol` | Construye sus URLs en `http`; el navegador bloquea sus propias peticiones |
| Document Server | `X-Forwarded-Proto: https` desde el proxy | Pide sus propios recursos por `http` |
| App ONLYOFFICE | `DocumentServerUrl` (navegador), `DocumentServerInternalUrl` (NextCloud), `StorageUrl` (editor) | No se llega al editor, o el editor no puede descargar el documento |

Cuando algo no abre, el diagnóstico útil está en la **consola del navegador**, no
en los registros del servidor: un `Mixed Content … has been blocked` nombra la
URL culpable, y con ella la pieza que se quedó en `http`. Si el registro del
propio editor no dice nada después de intentar abrir un documento, la petición
nunca llegó tan lejos.

Si alguna de las tres no se puede cumplir, usa el modo de enlace: funciona
siempre.

## 📋 Requisitos

| Requisito | Versión |
|---|---|
| Moodle | 4.5 – 5.1 (Moodle Workplace 4.5+) |
| PHP | 8.1+ |
| Otros plugins | No requiere |
| NextCloud | Una instancia alcanzable y una cuenta de servicio capaz de crear y compartir documentos |
| Editor en línea | ONLYOFFICE o Collabora instalado en NextCloud, para los modos embebidos |

## 🚀 Instalación

1. Copiar el código en `mod/assign/submission/tipnc/` (en Moodle 5.x,
   `public/mod/assign/submission/tipnc/`).
2. Completar la instalación desde **Administración del sitio › Notificaciones**
   (o por CLI: `php admin/cli/upgrade.php --non-interactive`).
3. Purgar las cachés (**Administración del sitio › Desarrollo › Purgar cachés**
   o `php admin/cli/purge_caches.php`).
4. Rellenar los ajustes de conexión (más abajo). Hasta que no estén la dirección,
   la cuenta y la contraseña, el plugin avisa de que no está configurado en lugar
   de aparentar que funciona.

En NextCloud, la cuenta de servicio necesita una **carpeta de trabajo** y una
**plantilla** dentro de ella, con exactamente los nombres que digan los ajustes
correspondientes.

## 🔧 Ajustes

En **Administración del sitio › Plugins › Plugins de entrega de tareas ›
NextCloud Submission**:

| Ajuste | Efecto |
|---|---|
| **Conexión con NextCloud** | |
| URL NextCloud | La dirección que usa el navegador de las personas. La que ven en la barra de direcciones |
| Dominio NextCloud | La dirección con la que el servidor de Moodle llega a NextCloud. A menudo la misma; no siempre, detrás de un proxy |
| Usuario NextCloud | La cuenta de servicio propietaria de todos los documentos |
| Contraseña | Su contraseña. Se guarda enmascarada y no se escribe nunca en el registro |
| **Documentos** | |
| Nombre de la carpeta | Carpeta de trabajo dentro del espacio de la cuenta de servicio |
| Nombre de la plantilla | Documento del que se saca cada copia |
| Ruta para ver un documento | Parte de la URL de NextCloud que abre un documento en el editor en línea |
| **En las tareas** | |
| Activado por defecto en las tareas nuevas | Si las tareas nuevas vienen ya con este tipo de entrega activado |
| **El editor** | |
| Cómo se abre el documento | Editor embebido, página de NextCloud embebida o enlace en pestaña nueva. Ver los requisitos de arriba |
| Dirección del Document Server | Dónde vive el editor; solo para el editor embebido |
| Secreto de firma | Compartido con el Document Server, para que Moodle y el editor se reconozcan |
| Pedir que se confirme que el documento está guardado | Pide confirmación al alumnado antes de entregar. No hace falta con el editor embebido, que guarda solo |
| **El acceso a los documentos** | |
| El acceso caduca a los | Cuánto dura un permiso. Se renueva en cada visita, así que un acceso caducado se recupera volviendo a entrar en la tarea. Cuanto más corto, más seguro; «Nunca» deja el acceso puesto para siempre |
| **Al borrar** | |
| Cuando se borra una entrega | Si se conservan los documentos, se borra la entrega congelada o se borra todo |
| Cuando se borra una tarea | Si se conservan o se borran sus documentos en NextCloud |
| **Cómo se ve el enunciado** | |
| Dónde se muestra el enunciado | En la cabecera de la actividad, en la región principal o en un destino propio |
| Destino del enunciado | Un selector CSS, para el destino propio |
| Anchura máxima de la página de la tarea | Ensancha la página para que el documento embebido sea usable |
| Altura del documento embebido | Cuánto mide de alto el marco |
| **Registro de incidencias** | |
| Conservar las incidencias | Días que se guarda una incidencia. Una tarea diaria retira el resto |

El registro está en **Administración del sitio › Plugins › Plugins de entrega de
tareas › Registro de incidencias**, y pide la capacidad
`assignsubmission/tipnc:view_errors`, concedida de serie a los gestores.

## 🗑️ Desinstalación

Quitar el plugin borra sus cuatro tablas y todo lo que Moodle guardaba sobre
estos documentos. **Los documentos de NextCloud no se tocan**: son de la cuenta
de servicio y se quedan en su carpeta, para resolverlos desde NextCloud.

## 🛠️ Desarrollo

```bash
# Tests unitarios
vendor/bin/phpunit --testsuite assignsubmission_tipnc_testsuite

# JavaScript, después de tocar cualquier cosa en amd/src/ (desde esta carpeta)
npx grunt amd
```

## 📄 Licencia

[GNU GPL v3 or later](https://www.gnu.org/copyleft/gpl.html) — 2026 [Tresipunt](https://tresipunt.com) (contacte@tresipunt.com)

---

<p align="center">
  <a href="https://tresipunt.com"><img src="pix/tresipunt_logo.png" alt="Tresipunt" width="160"></a>
</p>

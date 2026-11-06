<h1 align="center">NextCloud Submission</h1>

<p align="center">
  <img src="https://img.shields.io/badge/version-2.1.0-informational" alt="Versió">
  <a href="https://moodle.org"><img src="https://img.shields.io/badge/Moodle-4.5%20--%205.1-orange?logo=moodle" alt="Moodle"></a>
  <img src="https://img.shields.io/badge/Workplace-4.5%2B-blue" alt="Moodle Workplace">
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/License-GPL--3.0-green" alt="Llicència">
  <a href="https://tresipunt.com"><img src="https://img.shields.io/badge/made%20by-Tresipunt-F84015" alt="Fet per Tresipunt"></a>
</p>

<p align="center"><b>Lliurar una tasca escrivint al document, no pujant un fitxer.</b></p>

<p align="center"><a href="README.md">🇬🇧 English</a> · <a href="README.es.md">🇪🇸 Español</a> · <b>Català</b></p>

Connector de lliurament que substitueix la pujada de fitxers per un document
ofimàtic allotjat a NextCloud i editat en línia. El professorat publica un
enunciat, cada alumne rep la seva còpia privada, hi escriu des de dins del Moodle
i, en lliurar, es congela una còpia que el professorat corregeix. No s'adjunta
res i no es descarrega res.

No modifica el nucli del Moodle ni el tema, i no afegeix res a les tasques on no
està activat.

---

## ✨ Què fa

- **Un document per alumne, creat tot sol** — el primer cop que algú obre la
  tasca, la seva còpia de la plantilla es crea a NextCloud i es comparteix amb
  ell. No hi ha cap pas que l'alumnat pugui equivocar.
- **S'escriu des de dins del Moodle** — el document s'obre encastat a la pàgina
  de la tasca. L'alumnat no navega per NextCloud ni veu cap més document que el
  seu.
- **Lliurar congela la feina** — el lliurament és una còpia a part, que ja ningú
  no toca. L'alumne pot continuar amb l'esborrany i el professorat corregeix el
  que es va lliurar.
- **Es corregeix sense sortir de la pantalla de qualificació** — la còpia
  congelada s'obre al plafó de correcció, al costat de la rúbrica i el comentari.
- **Tasques de grup i reintents** — un document per grup quan la tasca funciona
  així, i un per intent quan el professorat la reobre.
- **L'accés es concedeix en entrar i es retira en sortir** — qui pot obrir la
  tasca al Moodle rep accés al document; perdre un rol o una matrícula al curs
  l'hi treu (vegeu les limitacions més avall).
- **Còpia de seguretat, restauració, importació i duplicat** — l'enunciat viatja
  amb la tasca i, amb dades d'usuari, també l'esborrany i el lliurament de cada
  estudiant. Es copien a NextCloud, així que dues tasques mai no escriuen al
  mateix document.
- **Avisa quan el servei és caigut** — si NextCloud no respon, la tasca ho
  explica amb paraules clares i rebutja el lliurament, en comptes d'acceptar una
  feina que no s'ha desat mai.
- **Un registre d'incidències que diu què ha fallat** — cada crida a NextCloud
  queda anotada amb un codi, agrupada per causa i amb les credencials
  emmascarades, en una pàgina pròpia per a l'administració.

## 🧭 Casos d'ús

### Un departament de llengua que corregeix la redacció, no el fitxer

L'alumnat lliura redaccions. Amb pujada de fitxers, la meitat arriben en un
format que no toca, algunes s'obren amb les tipografies mogudes i unes quantes
resulten ser buides. El departament activa aquest connector amb una plantilla
`.docx` que ja porta la capçalera i els criteris d'avaluació: tothom escriu al
mateix document i el professorat l'obre al plafó de correcció i hi comenta.

### Un centre sense suite ofimàtica als equips

Els ordinadors de l'aula no tenen processador de textos i l'alumnat treballa des
de tauletes a casa. El centre ja té NextCloud amb ONLYOFFICE. Configurar el
document perquè s'obri a l'editor encastat fa que n'hi hagi prou amb el
navegador, i la feina es queda als servidors de la institució en comptes d'anar a
un compte personal.

### Una tasca amb un enunciat que s'ha de llegir, no adjuntar

El professorat prepara l'enunciat com a document en comptes de com a PDF adjunt,
i es mostra a la capçalera de la tasca per a tothom. Canviar-lo canvia el que
veuen tots, sense cap versió nova per pujar ni ningú treballant amb la còpia de
la setmana passada.

### Un centre a qui auditen què guarda de les persones

La institució respon sol·licituds de protecció de dades. El connector declara les
quatre taules que manté i el fet que surten documents del lloc, i una sol·licitud
de supressió esborra tant les files del Moodle com els documents de NextCloud, en
comptes de deixar la feina —amb el nom de la persona a dins— a l'altre sistema.

## ⚙️ Com funciona

- El connector parla amb NextCloud mitjançant **un sol compte de servei**, que és
  el propietari de tots els documents. Ningú no necessita cap contrasenya de
  NextCloud per treballar.
- Cada tasca manté tres menes de document a la mateixa carpeta: l'**enunciat**
  que publica el professorat, l'**esborrany** de cada alumne i el **lliurament
  congelat** que es crea en lliurar.
- A qui obre una tasca se li dona accés al document que li pertoca, i només a
  aquell. El permís es renova a cada visita, així que un accés caducat es
  recupera només tornant a entrar a la tasca.
- El document es mostra d'una de **tres maneres**, a elecció de l'administració:
  l'editor encastat pel Moodle —el més net per treballar-hi, i el recomanat:
  l'alumnat veu l'editor i res més—, la pàgina de NextCloud encastada, o un
  enllaç que obre en una pestanya nova, que funciona a qualsevol lloc i no obliga
  a tocar res a NextCloud. Les dues primeres tenen requisits d'infraestructura
  (més avall).
- **La correcció es fa sobre el mateix document lliurat**, i el professorat
  decideix si hi escriu. El lliurament congelat s'obre al plafó de qualificació a
  punt per editar: anotar la feina és justament la idea, així que les correccions
  es desen en aquell mateix fitxer i el connector no conserva cap còpia anterior.
  La conseqüència convé saber-la abans de confiar-hi: un cop corregit un
  lliurament, el que existeix és el document tal com el va deixar qui va
  corregir, no tal com el va lliurar l'alumnat. Els centres que necessitin poder
  demostrar què es va lliurar exactament haurien d'activar el **versionat de
  fitxers de NextCloud**, que conserva les revisions anteriors.
- **Esborrar és una decisió, no el que passa per defecte.** Esborrar un
  lliurament o una tasca al Moodle deixa els documents a NextCloud llevat que
  l'administració demani una altra cosa: un esborrat aquí seria irreversible a
  l'altre sistema. Reiniciar el curs treu els lliuraments i conserva l'enunciat.
- **Un lliurament tancat és de només lectura.** Quan el lliurament està
  bloquejat, ha passat la data límit o ja s'ha lliurat, l'esborrany s'obre en
  només lectura i l'editor integrat ja no hi pot desar. Amb l'editor integrat,
  compta el que s'ha desat («Desa els canvis») o lliurat abans del tancament: el
  que encara estigui obert a l'editor en aquell moment no es conserva.
- **Mai no s'escriu a sobre d'un document.** Si un nom ja està ocupat a
  NextCloud —un document conservat d'un lliurament eliminat, o el del curs
  anterior després d'un reinici—, el nou rep un nom numerat i l'antic queda
  intacte.
- **Els documents restaurats només es copien si són de fiar.** Un document d'una
  còpia de seguretat es copia si és d'aquest lloc i qui restaura podria haver fet
  la còpia de la seva tasca. Si no, l'enunciat parteix de la plantilla, els
  esborranys no es copien i el registre d'incidències ho anota.
- Tot el que el connector fa contra NextCloud s'escriu en un **registre
  d'incidències** amb el seu codi, i es conserva el temps que digui
  l'administració.

### Limitacions

- **Perdre una matrícula o un rol retira tots els accessos del curs.** El
  connector no comprova si la persona continua matriculada per una altra via
  (per exemple, per cohort a més de manualment) ni si conserva un altre rol.
  L'accés als seus propis documents torna la propera vegada que entra a la tasca.

### Què necessita el lloc segons com es mostri el document

Els requisits són acumulatius: llegeix la fila que estiguis fent servir.

| Presentació | Què necessita el lloc |
|---|---|
| Enllaç, obert en pestanya nova | Res més que una sessió de NextCloud. Funciona a tot arreu |
| Pàgina de NextCloud encastada | Proxy invers **i** mateix domini que el Moodle **i** `https` a tots dos |
| Document Server encastat | L'editor accessible pel Moodle i pel navegador, tots dos per `https` |

> **El Document Server encastat és el millor dels tres per treballar-hi, i el que
> cal triar si el lloc pot complir els seus requisits.** El que veu l'alumnat és
> l'editor i res més: la barra d'ONLYOFFICE i el seu document, dins de la pàgina
> de la tasca. No hi ve res de la interfície de NextCloud —ni gestor de fitxers,
> ni navegació, ni barra lateral, ni altres documents— perquè el Moodle encasta
> l'editor directament, no la pàgina que el conté.
>
> Això no només queda més net, sinó més acotat: des d'allà no hi ha manera
> d'escapar-se a altres fitxers, i no cal explicar res d'un segon sistema, perquè
> per a l'alumnat no existeix cap segon sistema. Desar és cosa de l'editor i ho
> fa tot sol, així que tampoc no hi ha res per recordar abans de lliurar.
>
> Encastar la **pàgina de NextCloud** és l'opció intermèdia, i la més fluixa:
> exigeix tot el que es descriu més avall **i** a sobre mostra la interfície de
> NextCloud al voltant del document. Hi és per als llocs on l'editor no es pot
> assolir directament.

> **El mode de pestanya nova no necessita res de tot això.** Tot el que s'explica
> a la resta d'aquesta secció existeix perquè un document mostrat *dins* del
> Moodle viatja en un marc. Obrir-lo en una pestanya nova és una navegació
> normal: `X-Frame-Options` i `frame-ancestors` no hi entren, així que **no cal
> tocar res a NextCloud**; la galeta de sessió viatja com sempre, així que el
> Moodle i NextCloud no han de compartir domini; i no hi ha cap proxy invers per
> muntar.
>
> És el mode que cal fer servir quan el NextCloud no és teu per configurar-lo, o
> quan qui l'administra no hi posarà un proxy al davant. El que es perd és tenir
> el document dins de la pàgina: l'alumnat treballa en una altra pestanya i torna
> al Moodle per lliurar.

Encastar la pàgina de NextCloud té tres condicions, i cadascuna falla a la seva
manera:

**1. Un proxy invers** davant de NextCloud, que retiri `X-Frame-Options` i ampliï
`frame-ancestors`. NextCloud força totes dues des del seu propi `.htaccess`, així
que no es poden canviar des de NextCloud. Apache, perquè l'arranjament edita
*part* del valor d'una capçalera:

```apache
Header unset X-Frame-Options
Header always unset X-Frame-Options
Header edit Content-Security-Policy "frame-ancestors 'self'" "frame-ancestors 'self' https://el-teu-moodle"
```

`Header edit` substitueix només aquell fragment: reemplaçar la capçalera sencera
destruiria el valor d'un sol ús que autoritza els scripts de NextCloud.

**2. NextCloud sota el mateix domini registrable que el Moodle** —
`moodle.centre.edu` i `nc.centre.edu`. Les galetes de sessió de NextCloud són
`SameSite=Lax`, i un marc compta com una petició del lloc que el conté: des d'un
altre domini, el navegador ni envia la sessió que hi ha ni accepta la que crea el
login, així que dins del marc surt un formulari d'accés **que no entra mai**, per
més que la contrasenya sigui correcta. Reescriure les galetes a
`SameSite=None; Secure` al proxy és possible i no és recomanable: deixa el
resultat en mans de la política de galetes de tercers de cada navegador i
manipula des de fora la protecció CSRF de NextCloud.

**3. `https` als dos costats**, o el navegador bloqueja el marc per contingut
mixt. I `https` no va només de les adreces que escrius: **cada peça darrere del
proxy construeix els seus propis enllaços a partir del que es pensa que és**, i
darrere d'un proxy cap no ho sap. Si una es queda en `http`, el navegador
bloqueja les seves crides i el símptoma no esmenta mai el protocol —un document
que es descarrega en comptes d'obrir-se, un marc buit, un error de descàrrega
genèric—:

| Peça | Com se li diu | Si falta |
|---|---|---|
| NextCloud | `overwritehost`, `overwriteprotocol` | Construeix els seus URL en `http`; el navegador bloqueja les seves pròpies peticions |
| Document Server | `X-Forwarded-Proto: https` des del proxy | Demana els seus propis recursos per `http` |
| App ONLYOFFICE | `DocumentServerUrl` (navegador), `DocumentServerInternalUrl` (NextCloud), `StorageUrl` (editor) | No s'arriba a l'editor, o l'editor no pot descarregar el document |

Quan alguna cosa no s'obre, el diagnòstic útil és a la **consola del navegador**,
no als registres del servidor: un `Mixed Content … has been blocked` anomena
l'URL culpable, i amb ell la peça que s'ha quedat en `http`. Si el registre del
mateix editor no diu res després d'intentar obrir un document, la petició no hi
va arribar mai.

Si alguna de les tres no es pot complir, fes servir el mode d'enllaç: funciona
sempre.

## 📋 Requisits

| Requisit | Versió |
|---|---|
| Moodle | 4.5 – 5.1 (Moodle Workplace 4.5+) |
| PHP | 8.1+ |
| Altres connectors | No en requereix |
| NextCloud | Una instància accessible i un compte de servei capaç de crear i compartir documents |
| Editor en línia | ONLYOFFICE o Collabora instal·lat a NextCloud, per als modes encastats |

## 🚀 Instal·lació

1. Copiar el codi a `mod/assign/submission/tipnc/` (al Moodle 5.x,
   `public/mod/assign/submission/tipnc/`).
2. Completar la instal·lació des d'**Administració del lloc › Notificacions**
   (o per CLI: `php admin/cli/upgrade.php --non-interactive`).
3. Purgar les memòries cau (**Administració del lloc › Desenvolupament › Purga
   les memòries cau** o `php admin/cli/purge_caches.php`).
4. Omplir els paràmetres de connexió (més avall). Fins que no hi siguin l'adreça,
   el compte i la contrasenya, el connector avisa que no està configurat en
   comptes d'aparentar que funciona.

A NextCloud, el compte de servei necessita una **carpeta de treball** i una
**plantilla** a dins, amb exactament els noms que diguin els paràmetres
corresponents.

## 🔧 Configuració

A **Administració del lloc › Connectors › Connectors de lliurament de tasques ›
NextCloud Submission**:

| Paràmetre | Efecte |
|---|---|
| **Connexió amb NextCloud** | |
| URL NextCloud | L'adreça que fa servir el navegador de les persones. La que veuen a la barra d'adreces |
| Domini NextCloud | L'adreça amb què el servidor del Moodle arriba a NextCloud. Sovint la mateixa; no sempre, darrere d'un proxy |
| Usuari NextCloud | El compte de servei propietari de tots els documents |
| Contrasenya | La seva contrasenya. Es desa emmascarada i no s'escriu mai al registre |
| **Documents** | |
| Nom de la carpeta | Carpeta de treball dins de l'espai del compte de servei |
| Nom de la plantilla | Document del qual se'n treu cada còpia |
| Ruta per veure un document | Part de l'URL de NextCloud que obre un document a l'editor en línia |
| **A les tasques** | |
| Activat per defecte a les tasques noves | Si les tasques noves ja vénen amb aquest tipus de lliurament activat |
| **L'editor** | |
| Com s'obre el document | Editor encastat, pàgina de NextCloud encastada o enllaç en pestanya nova. Vegeu els requisits de dalt |
| Adreça del Document Server | On viu l'editor; només per a l'editor encastat |
| Secret de signatura | Compartit amb el Document Server, perquè el Moodle i l'editor es reconeguin |
| Demanar que es confirmi que el document està desat | Demana confirmació a l'alumnat abans de lliurar. No cal amb l'editor encastat, que desa tot sol |
| **L'accés als documents** | |
| L'accés caduca al cap de | Quant dura un permís. Es renova a cada visita, així que un accés caducat es recupera tornant a entrar a la tasca. Com més curt, més segur; «Mai» deixa l'accés posat per sempre |
| **En esborrar** | |
| Quan s'esborra un lliurament | Si es conserven els documents, s'esborra el lliurament congelat o s'esborra tot |
| Quan s'esborra una tasca | Si es conserven o s'esborren els seus documents a NextCloud |
| **Com es veu l'enunciat** | |
| On es mostra l'enunciat | A la capçalera de l'activitat, a la regió principal o en una destinació pròpia |
| Destinació de l'enunciat | Un selector CSS, per a la destinació pròpia |
| Amplada màxima de la pàgina de la tasca | Eixampla la pàgina perquè el document encastat sigui usable |
| Alçada del document encastat | Quant fa d'alt el marc |
| **Registre d'incidències** | |
| Conservar les incidències | Dies que es guarda una incidència. Una tasca diària retira la resta |

El registre és a **Administració del lloc › Connectors › Connectors de lliurament
de tasques › Registre d'incidències**, i demana la capacitat
`assignsubmission/tipnc:view_errors`, concedida de sèrie als gestors.

## 🗑️ Desinstal·lació

Treure el connector esborra les seves quatre taules i tot el que el Moodle
guardava sobre aquests documents. **Els documents de NextCloud no es toquen**:
són del compte de servei i es queden a la seva carpeta, per resoldre'ls des de
NextCloud.

## 🛠️ Desenvolupament

```bash
# Tests unitaris
vendor/bin/phpunit --testsuite assignsubmission_tipnc_testsuite

# JavaScript, després de tocar qualsevol cosa a amd/src/ (des d'aquesta carpeta)
npx grunt amd
```

## 📄 Llicència

[GNU GPL v3 or later](https://www.gnu.org/copyleft/gpl.html) — 2026 [Tresipunt](https://tresipunt.com) (contacte@tresipunt.com)

---

<p align="center">
  <a href="https://tresipunt.com"><img src="pix/tresipunt_logo.png" alt="Tresipunt" width="160"></a>
</p>

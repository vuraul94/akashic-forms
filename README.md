# Akashic Forms

Constructor de formularios para WordPress con cola de sincronización a Google Sheets.

Los envíos se guardan en la base de datos del sitio y se encolan; un cron vacía la cola
escribiendo cada envío como una fila en una hoja de cálculo de Google. Si Google falla o
está sin cuota, el envío no se pierde: se reintenta con backoff.

- **Versión:** 1.3.0
- **Requiere:** WordPress 5.8+, PHP 7.4+
- **Text domain:** `akashic-forms`

---

## Instalación

> **`composer install` es obligatorio.** El directorio `vendor/` está en `.gitignore` y no
> viaja en el repositorio. Sin él, la integración con Google muere con un error fatal en
> `includes/class-akashic-forms-google-drive.php`.

```bash
cd wp-content/plugins/akashic-forms
composer install --no-dev --optimize-autoloader
```

Después activa el plugin desde el escritorio de WordPress. La activación crea las tablas;
las actualizaciones posteriores aplican los cambios de esquema solos al comparar
`AKASHIC_FORMS_DB_VERSION` con la opción `akashic_forms_db_version`.

### Requisitos del servidor

| Requisito | Para qué | Si falta |
|---|---|---|
| `composer install` | Cliente de Google | Error fatal al tocar Sheets |
| extensión `ZipArchive` | Importar opciones desde `.xlsx` | Mensaje de error en el importador |
| extensión `iconv` | Export CSV | Se exporta sin limpiar bytes inválidos |
| `curl`, `json` | Guzzle (cliente de Google) | Error fatal |
| WP-Cron operativo | Vaciar la cola | La cola no avanza |

En sitios con poco tráfico WP-Cron no se dispara si nadie visita la web. Conviene un cron
real del sistema:

```php
// wp-config.php
define( 'DISABLE_WP_CRON', true );
```

```cron
*/5 * * * * curl -s https://tu-sitio.com/wp-cron.php?doing_wp_cron > /dev/null 2>&1
```

### Google Cloud

1. Crea un proyecto y habilita la **Google Sheets API**.
2. Crea credenciales OAuth de tipo *aplicación web*.
3. Añade como URI de redirección autorizado, exactamente:
   `https://tu-sitio.com/wp-admin/admin.php?page=akashic-forms-google-drive-settings`
4. Pega el Client ID y el Client Secret en **Akashic Forms → Google Drive** y autoriza.
5. Comparte la hoja de cálculo destino con la cuenta que autorizaste.

El scope solicitado es solo `spreadsheets`: el plugin no puede leer ni tocar el resto de tu Drive.

Las credenciales se pueden definir por constante, y entonces tienen prioridad sobre lo
guardado en base de datos:

```php
// wp-config.php
define( 'AKASHIC_FORMS_GOOGLE_CLIENT_ID',     '...' );
define( 'AKASHIC_FORMS_GOOGLE_CLIENT_SECRET', '...' );
```

---

## Uso

Crea un formulario en **Akashic Forms → All Forms** e insértalo con el shortcode:

```
[akashic_form id="123"]
```

### Configuración por formulario

Cada formulario tiene cuatro cajas de ajustes en su pantalla de edición:

- **Form Fields** — los campos, arrastrables para reordenar. 21 tipos disponibles: `text`,
  `email`, `password`, `url`, `tel`, `search`, `color`, `date`, `time`, `number`, `range`,
  `textarea`, `select`, `radio`, `checkbox` (múltiple), `checkbox_single`, `datalist`,
  `file`, `hidden`, `output`, `fieldset`.
  Por campo: etiqueta, `name`, obligatorio, valor único, expresión regular, min/max/step,
  placeholder, selección múltiple, modal de ayuda (HTML + color) y, en los de archivo,
  formatos permitidos y tamaño máximo, cada uno con su mensaje de error propio.
  Las opciones de `select`, `radio`, `checkbox` y `datalist` se pueden importar de un CSV
  o un XLSX.
- **Submission Settings** — qué pasa al enviar: redirigir a una URL, mostrar un mensaje
  inline o abrir un modal. También el texto del botón y su estado "enviando".
- **Google Drive Integration Settings** — ID de la hoja y nombre de la pestaña. Sin estos
  dos valores el envío se guarda pero queda en la cola como fallido.
- **Email Notification Settings** — destinatario, asunto y cuerpo. **Ver
  [Pendiente](#pendiente): estos ajustes se guardan pero todavía no se envía ningún correo.**

### Ajustes globales

**Akashic Forms → Settings**

| Ajuste | Opción | Defecto |
|---|---|---|
| Activar cron | `akashic_forms_cron_enabled` | sí |
| Intervalo | `akashic_forms_cron_interval` | `five_minutes` |
| Envíos por ejecución | `akashic_forms_queue_batch_size` | 10 (1–100) |
| Reintentos antes de rendirse | `akashic_forms_max_attempts` | 5 (1–20) |

Guardar estos ajustes reprograma el cron al instante.

### Pantallas de administración

- **Submissions** — los envíos de un formulario, con borrado individual o masivo (que
  también elimina del disco los archivos asociados) y export a CSV.
- **Queue** — el estado de la sincronización: filtro por estado, búsqueda, cuenta atrás al
  timeout, motivo del fallo, **Force Sync** (procesa también los fallidos) y **Clear Queue**.
- **Google Drive** — credenciales, autorización y estado del token.

---

## Cómo funciona un envío

1. El shortcode pinta el formulario desde el post meta `_akashic_form_fields`.
2. El JS valida en cliente, pide un nonce fresco por AJAX (para que el formulario siga
   funcionando en páginas cacheadas) y hace `POST` a `/wp-json/akashic-forms/v1/sync`.
3. El endpoint REST comprueba honeypot y límite de tasa, valida campo a campo, sanea,
   sube los archivos y escribe en dos tablas: la cola (`pending`) y el registro de envíos.
4. Cada 5 minutos el cron renueva el token de Google si hace falta, recupera los envíos
   colgados, reclama un lote de forma atómica y escribe las filas en la hoja.
5. Si algo falla, el envío vuelve a `pending` con `next_attempt_at` en el futuro
   (backoff `60 · 2^intentos`, tope 6 h) hasta agotar los reintentos.

Los errores que no son culpa del envío —cuota agotada o token muerto— devuelven la fila a
`pending` **sin** gastar un intento y detienen el lote, para no quemar más cuota.

### Tablas

| Tabla | Contenido |
|---|---|
| `{prefix}akashic_form_submissions` | Registro permanente. Alimenta la pantalla de envíos y el CSV. |
| `{prefix}akashic_form_queue` | Estado de la sincronización: `pending`, `processing`, `completed`, `failed`, con intentos y próximo reintento. |
| `{prefix}akashic_form_field_values` | Índice de valores para las validaciones de unicidad. Se rellena solo. |

Los datos de los envíos se guardan como JSON. Las filas antiguas en formato `serialize()`
se siguen leyendo sin migración manual.

---

## Extender

### Filtros

```php
// Activar el log del plugin sin encender WP_DEBUG entero.
add_filter( 'akashic_forms_enable_log', '__return_true' );

// Ajustar el límite de tasa del endpoint de envío.
add_filter( 'akashic_forms_rate_limit', function ( $limits ) {
    $limits['form_max']      = 5;    // envíos por IP y formulario en la ventana
    $limits['form_window']   = 300;  // segundos
    $limits['global_max']    = 20;   // envíos por IP en total en la ventana global
    $limits['global_window'] = 3600;
    return $limits;
} );

// Resolver la IP real detrás de un proxy o CDN.
add_filter( 'akashic_forms_client_ip', function ( $ip, $forwarded ) {
    return $forwarded ? trim( explode( ',', $forwarded )[0] ) : $ip;
}, 10, 2 );

// Tamaño máximo (MB) para los campos de archivo que no lo definen.
add_filter( 'akashic_forms_default_max_upload_size', function () {
    return 25;
} );
```

### Eliminar el plugin

`uninstall.php` borra las tres tablas, las opciones, los formularios y su post meta.
**Los archivos que subieron los visitantes se dejan en disco a propósito**, para no
destruir documentos que quizá sigan haciendo falta. Usa "Clear Submissions" en cada
formulario antes de borrar el plugin si quieres que desaparezcan.

---

## Seguridad

El endpoint de envío es público a propósito, y está protegido por:

- **Honeypot + marca de tiempo firmada** — descarta bots que rellenan todos los campos o
  envían el formulario en menos de dos segundos.
- **Límite de tasa por IP** — 5 envíos por formulario cada 5 minutos y 20 por hora en
  total, configurables. Responde `429`. La IP se guarda hasheada, nunca en claro.
- **Solo campos declarados** — cualquier clave que no corresponda a un campo del
  formulario se descarta.
- **Saneado en la entrada y escapado en la salida** — nada de lo que llega se imprime sin
  escapar en el escritorio.
- **Nonce verificado cuando llega** — un nonce inválido da `403`; su ausencia se tolera
  porque los visitantes anónimos no tienen cookies.

Los archivos subidos van a `wp-content/uploads/akashic-forms/AAAA/MM/` con nombres de 32
caracteres hexadecimales aleatorios, y el directorio lleva un `.htaccess` que desactiva el
listado y la ejecución de PHP.

> **Nota de privacidad:** esos archivos siguen siendo servidos por el servidor web, así que
> cualquiera con la URL puede descargarlos. El nombre aleatorio los hace inenumerables,
> pero no son privados. Si el formulario recoge documentos personales y necesitas control
> de acceso real, hay que servirlos por un endpoint que compruebe sesión — lo que rompería
> los enlaces clicables desde la hoja de cálculo. Es una decisión de producto pendiente.

En el escritorio, todas las acciones exigen `manage_options` y validan nonce, y los valores
del CSV que empiezan por `=`, `+`, `-` o `@` se neutralizan para que Excel no los ejecute
como fórmulas.

---

## Pendiente

### 1. Envío de notificaciones por correo

Los tres ajustes del metabox **Email Notification Settings** (destinatario, asunto y
cuerpo con el marcador `{all_fields}`) se guardan correctamente en el post meta, pero
**nada los consume**: no queda ni una llamada a `wp_mail()` en el plugin.

El código que lo hacía vivía en `includes/class-akashic-forms-submission-handler.php`,
borrado en el commit `1fb786f`. Se puede recuperar con:

```bash
git show 1fb786f^:includes/class-akashic-forms-submission-handler.php
```

Al reponerlo conviene mejorarlo sobre el original:

- cuerpo en HTML, no solo texto plano;
- varios destinatarios;
- `Reply-To` con el correo de quien envía el formulario;
- adjuntar los archivos subidos, o enlazarlos;
- autorespuesta de confirmación al visitante;
- enviarlo desde el endpoint REST y **no** desde el procesador de cola, para que la
  notificación no dependa de que Google responda. El original lo enviaba dentro del `try`
  de la sincronización, así que un fallo de Sheets se comía también el correo.

### 2. Campo de captcha

Hoy el endpoint se defiende con honeypot y límite de tasa, que paran el spam automatizado
corriente pero no un ataque dirigido. Falta un captcha de verdad:

- un tipo de campo nuevo, o una casilla por formulario en **Submission Settings**;
- ajustes globales para la clave pública y la privada del proveedor
  (reCAPTCHA v3 o hCaptcha, ambos con nivel gratuito);
- validación del token en el servidor dentro de `handle_sync_request()`, junto a las
  comprobaciones de honeypot que ya están ahí;
- que sea opcional por formulario: en formularios internos estorba.

---

## Desarrollo

```bash
# Sintaxis de todo el PHP del plugin
for f in akashic-forms.php uninstall.php includes/*.php; do php -l "$f"; done

# Sintaxis del JS público
node --check assets/js/akashic-forms-public.js
```

No hay tests automatizados. Los dos sitios donde más duele romper algo sin darse cuenta son
la validación del endpoint REST y el mapeo de campos a columnas de la hoja.

### Estructura

```
akashic-forms.php                    Cabecera, constantes, logger, i18n, upgrades
uninstall.php                        Limpieza al borrar el plugin
includes/
  class-akashic-forms-cpt.php                     Tipo de contenido "akashic_forms"
  class-akashic-forms-metabox.php                 Constructor de formularios (admin)
  class-akashic-forms-options-importer.php        Lector de CSV y XLSX
  class-akashic-forms-shortcode.php               Render del formulario público
  class-akashic-forms-rest-api.php                Endpoint de envío
  class-akashic-forms-db.php                      Esquema y consultas
  class-akashic-forms-queue-processor.php         Cron, reintentos, escritura en Sheets
  class-akashic-forms-google-drive.php            OAuth y cliente de Sheets
  class-akashic-forms-admin.php                   Menús, ajustes, CSV, acciones
  class-akashic-forms-*-list-table.php            Tablas de envíos y de cola
assets/
  css/akashic-forms-public.css
  js/akashic-forms-public.js
languages/
```

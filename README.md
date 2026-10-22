# Lucuma Bricks Bridge

Plugin de WordPress que expone un endpoint REST para **leer y escribir el contenido Bricks de una
página** desde fuera. Sirve para que los JSON generados en `webs/` (formato `bricksCopiedElements`)
se importen directo, sin abrir Bricks ni pegar a mano.

## Instalación

1. Plugins → Añadir nuevo → Subir plugin → `lucuma-bricks-bridge.zip` → Activar. O por Git: el archivo
   principal está en la raíz del repo, así que cualquier desplegador desde Git lo reconoce como plugin.
2. Comprobar: `https://lucumaagency.com/wp-json/lucuma-bricks/v1/health` → `{"ok":true,"bricks":"2.x"}`.
3. Autenticación: la misma contraseña de aplicación del usuario Developer (`~/.lucuma-wp.env`).
   Solo usuarios con permiso de editar la página pueden escribir.

## Uso desde la máquina de Claude (`webs/bricks_push.py`)

```bash
set -a; source ~/.lucuma-wp.env; set +a
python3 webs/bricks_push.py nosotros --info                         # estado y respaldos
python3 webs/bricks_push.py nosotros servicios-PEGAR/nosotros.json  # reemplaza el contenido
python3 webs/bricks_push.py 1178 archivo.json --append               # añade secciones al final
python3 webs/bricks_push.py nosotros --restore                       # vuelve al último respaldo
python3 webs/bricks_push.py --nueva "Título" archivo.json --slug mi-pagina [--publish]
```

## Qué hace al escribir

- Valida el JSON (ids únicos, padres e hijos existentes) y rechaza si algo está roto.
- **Regenera los IDs** a 6 caracteres como hace Bricks al pegar (desactivable con `--keep-ids`).
- Fusiona `globalClasses` del JSON en las clases globales del sitio, solo las que no existan.
- **Guarda un respaldo** del contenido anterior (últimos 5 por página) antes de tocar nada.
- Escribe `_bricks_page_content_2`, marca la página en modo Bricks y regenera su CSS si el sitio
  sirve el CSS como archivos externos.

## Endpoints

| Método | Ruta | Qué hace |
|---|---|---|
| GET | `/lucuma-bricks/v1/health` | Versión de Bricks y del plugin |
| GET | `/lucuma-bricks/v1/pages/{id}` | Contenido actual, modo y lista de respaldos |
| POST | `/lucuma-bricks/v1/pages/{id}/content` | Body: el JSON de Bricks + `mode` (`replace`/`append`), `regenerate_ids`, `nota` |
| POST | `/lucuma-bricks/v1/pages/{id}/restore` | `{backup: "KEY"}` o vacío para el último |
| POST | `/lucuma-bricks/v1/pages` | `{title, slug, status, content, globalClasses}` crea la página y la rellena |
| GET | `/lucuma-bricks/v1/classes` | Clases globales del sitio |
| GET | `/lucuma-bricks/v1/templates` | Plantillas de Bricks: id, título, tipo y nº de elementos por área (1.0.5) |
| POST | `/lucuma-bricks/v1/templates` | `{title, type, status}` crea una plantilla vacía (1.1.0) |
| GET | `/lucuma-bricks/v1/templates/{id}/settings` | Tipo, ajustes (`templateConditions`…) y respaldos (1.1.0) |
| POST | `/lucuma-bricks/v1/templates/{id}/settings` | `{type?, conditions?, settings?, nota?}`: cambia tipo y condiciones, con respaldo (1.1.0) |
| POST | `/lucuma-bricks/v1/templates/{id}/settings/restore` | `{backup?}` vuelve al último respaldo de ajustes (1.1.0) |
| POST | `/lucuma-bricks/v1/classes` | `{classes:[...], nota?}` actualiza por id y añade las nuevas; respaldo en opción (1.1.0, admin) |
| POST | `/lucuma-bricks/v1/pages/{id}/noindex` | `{noindex: true/false}` marca la página como noindex (1.1.0) |
| POST/DELETE | `/lucuma-bricks/v1/preview` | Crea (`{hours}`) o revoca el token de vista previa privada (1.1.0, admin) |

**Vista previa privada (1.1.0).** Con el token, `?lucuma_preview=TOKEN` deja ver un borrador
(`/?page_id=ID&lucuma_preview=TOKEN`) sin sesión, y `&lb_header=ID&lb_footer=ID&lb_content=ID` fuerza
plantillas de Bricks en esa visita (filtro `bricks/active_templates`) para probar plantillas sin
condiciones. Solo se guarda el hash del token, caduca (máx. 7 días), la respuesta va con noindex y sin
caché. Sin el token exacto el borrador sigue siendo invisible.

**Áreas (desde 1.0.5).** `GET /pages/{id}`, `POST /pages/{id}/content` y `POST /pages/{id}/css` aceptan
`area`: `content` (por defecto), `header` o `footer`. Así se editan las plantillas de header y footer de
Bricks, que guardan sus elementos en `_bricks_page_header_2` / `_bricks_page_footer_2`. Los respaldos
recuerdan su área y `restore` la respeta. Para cambios puntuales en una plantilla usar `regenerate_ids:
false` (`--keep-ids`) para no alterar los IDs que usa el CSS.

## Límites conocidos

- Los ajustes de página (`_bricks_page_settings`) no se tocan. Las condiciones de plantilla se guardan en
  el formato de Bricks tal cual (copiarlo de una plantilla existente con GET .../settings).
- Si Bricks cambia el nombre de la meta en una versión futura, actualizar `META_CONTENT`.
- Lo que escribe es exactamente lo que pegarías: el JSON tiene que ser válido para la versión de
  Bricks instalada (hoy 2.3.7).

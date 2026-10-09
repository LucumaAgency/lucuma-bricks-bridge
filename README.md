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

## Límites conocidos

- Escribe solo el contenido de la página. Header, footer y ajustes de página (`_bricks_page_settings`)
  no se tocan.
- Si Bricks cambia el nombre de la meta en una versión futura, actualizar `META_CONTENT`.
- Lo que escribe es exactamente lo que pegarías: el JSON tiene que ser válido para la versión de
  Bricks instalada (hoy 2.3.7).

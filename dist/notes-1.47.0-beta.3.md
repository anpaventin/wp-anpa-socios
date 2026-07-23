## anpa-socios 1.47.0-beta.3 (prerelease)

Cambio de enfoque no ancho da área (a pedido): o plugin queda **neutro e portable**.

### Cambiado

- **Ancho da área de socios**: retírase do plugin calquera ancho forzado. Agora a área respecta o **ancho de contido do tema activo** (un sitio que queira 720 px mantén 720 px). O ensanche visual do sitio ANPA faise no **tema fillo** (`anpa-ventin-child`), non no plugin.

> Para ver a área máis ancha en migration hai que **actualizar tamén o tema fillo** (o CSS do ensanche vive alí agora). Só coa actualización do plugin, a área verase ao ancho do tema (columna de contido).

### Notas técnicas

- Só presentación; sen cambios de esquema (DB 1.37.0).
- Suite en verde (959 tests, 2761 assertions).

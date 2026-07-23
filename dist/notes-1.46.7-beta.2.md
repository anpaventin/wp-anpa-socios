## anpa-socios 1.46.7-beta.2 (prerelease)

Fase 28 (coherencia visual de Xestión). Inclúe o da beta.1 (cabeceira multinivel de
Fillos) máis os cambios de Actividades e as tarxetas públicas.

### Actividades (Xestión)

- **Estado efectivo**: a columna «Estado» xa non mostra só o estado da base de datos.
  Agora unha actividade activa **sen ningún grupo no curso activo** amósase como
  **«Sen grupo»** e trátase como inactiva (agóchase salvo que actives «Mostrar
  inactivos»). Estados: **Activa** / **Sen grupo** / **Inactiva**.
- **Botón «Grupos» retirado** do listado: había demasiados botóns por fila. Os grupos
  seguen xestionándose desde o formulario de edición da actividade («Xestionar grupos»).
- Sen cambios no contrato de datos: `row.estado` e a exportación CSV mantéñense
  (a etiqueta efectiva é só de presentación).

### Actividades ofertadas (páxina pública)

- **Tarxetas máis anchas** (ancho mínimo 260px, antes 220px) e **máximo 4 columnas
  por fila**, para que non queden demasiado estreitas en pantallas grandes/completas.

### Notas técnicas

- Sen cambios de esquema (DB 1.37.0).
- Novo helper puro `activityEffectiveState(row)` en `admin-management.js`; grella
  pública en `extraescolares.css` (min 260px + tope de 4 columnas ≥1200px).
- Novos tests `Test_ANPA_Socios_Actividades_Effective_State` +
  `Test_ANPA_Socios_Fillos_Grouped_Header`. Suite en verde (958 tests, 2754 assertions).

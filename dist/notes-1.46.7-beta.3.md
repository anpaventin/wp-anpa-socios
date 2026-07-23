## anpa-socios 1.46.7-beta.3 (prerelease)

Axustes sobre a beta.2 (fase 28).

### Corrixido

- **Ordenar por «Estado» en Actividades**: agora ordena polo **estado efectivo**
  visible (Activa / Sen grupo / Inactiva). Antes ordenaba polo estado cru da base
  de datos, que non coincidía co que se ve. A exportación CSV segue usando o estado
  real (contrato canónico intacto).

### Mellora

- **Listado de Fillos**: as columnas de datos do proxenitor (as tres primeiras)
  resáltanse cun fondo distinto para diferencialas visualmente das columnas do/a
  fillo/a, reforzando a cabeceira agrupada.

### Notas técnicas

- Sen cambios de esquema (DB 1.37.0).
- Columna de presentación `_estado_efectivo` para mostrar/ordenar/buscar; CSV segue
  en `ACTIV_COLS` (estado cru). Tint de proxenitor vía clase `anpa-fillos-table`.
- Suite en verde (959 tests, 2760 assertions).

## anpa-socios 1.46.7-beta.1 (prerelease)

Fase 28 (coherencia visual de Xestión) — primeira porción: cabeceira multinivel no
listado de Fillos.

### Novo

- **Listado de Fillos con cabeceira agrupada**: engádese unha fila de cabeceira que
  agrupa as columnas en **«Datos do proxenitor»** (apelidos, nome, email) e
  **«Datos do/a fillo/a»** (apelidos, nome, data, curso, aula, estado), máis a
  columna de accións. Facilita ler unha táboa cunha morea de columnas.

### Garantías

- **Sen cambios no contrato de datos**: `FILLOS_COLS`, a orde de columnas, a busca,
  a ordenación, a paxinación e a exportación CSV quedan **idénticas**. A cabeceira é
  puramente presentacional.
- Sen cambios de esquema (DB 1.37.0).

### Notas técnicas

- Novo helper presentacional `prependGroupedHeader()` en `admin-management.js` (non
  toca `buildTable` nin o resto de táboas), chamado só desde `renderFillos`.
- CSS acoutado `.anpa-mgmt-colgroup` en `admin-management.css`.
- Novo test `Test_ANPA_Socios_Fillos_Grouped_Header`. Suite en verde (952 tests, 2739 assertions).

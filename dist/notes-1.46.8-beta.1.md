## anpa-socios 1.46.8-beta.1 (prerelease)

Tres correccións de detalle que se escaparon na fase 28.

### Corrixido

- **Columna «Estado» en Actividades**: a cabeceira mostraba `estado_efectivo` en vez de «Estado». A clave da etiqueta non coincidía coa que usa a táboa (que retira o guión baixo inicial). Agora aparece «Estado».
- **Checkboxes e radios en móbil**: o tic quedaba desprazado ao canto inferior-dereito, por riba do bordo, en vez de dentro do recadro. Debíase a que os checkbox/radio herdaban o `padding` e o ancho dos inputs de texto; agora reséntase o modelo de caixa para que se debuxen de forma nativa a 16 px. Corrixido en Xestión (admin) e no formulario de alta (asociarse).
- **Botón «Xestionar grupos»**: eliminado do formulario de edición de cada actividade. Era redundante — os grupos xa se xestionan na lista inline (crear, editar e historial), e o botón só reabría a mesma lista noutra vista.

### Notas técnicas

- Sen cambios de esquema (DB 1.37.0).
- `accent-color` verde nos checkbox/radio para coherencia visual.
- Suite en verde (959 tests, 2761 assertions).

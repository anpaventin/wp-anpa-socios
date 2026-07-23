## anpa-socios 1.46.8 (estable)

Correccións de detalle detectadas ao validar a fase 28 en dispositivos.

### Corrixido

- **Columna «Estado» en Actividades**: a cabeceira mostraba `estado_efectivo` en vez de «Estado». A clave da etiqueta non coincidía coa que usa a táboa (que retira o guión baixo inicial). Agora aparece «Estado» e a orde por esa columna usa o estado efectivo visible (Activa / Sen grupo / Inactiva). O CSV segue exportando o estado cru.
- **Checkboxes e radios**: o tic aparecía desprazado ao canto (por herdar o `padding`/ancho dos inputs de texto) e, ao estreitar moito a xanela ou en móbil, chegaba a verse un **dobre tic**. Agora só se neutraliza o modelo de caixa herdado e déixase que o tema / wp-admin debuxen o seu propio checkmark, evitando o tic duplicado. Corrixido en Xestión (admin) e no formulario de alta.
- **Botón «Xestionar grupos»**: eliminado do formulario de edición de cada actividade. Era redundante — os grupos xa se xestionan na lista inline (crear, editar e historial).

### Notas técnicas

- Sen cambios de esquema (DB 1.37.0).
- Suite en verde (959 tests, 2761 assertions).

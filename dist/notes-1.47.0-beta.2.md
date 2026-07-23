## anpa-socios 1.47.0-beta.2 (prerelease)

Correccións sobre a beta.1.

### Corrixido

- **Checkboxes e radios en Xestión**: xa non se ven enormes ao marcar nin diminutos (un simple punto) ao desmarcar. Restáurase o tamaño de caixa nativo de wp-admin (1rem en escritorio, maior en móbil) sen forzar o renderizado, polo que o tic vese ben e sen duplicarse en calquera ancho.

### Área de socios — redeseño (parte 1, axuste)

- **Ancho en pantallas grandes**: na beta.1 a área seguía a verse estreita (~720 px) porque colgaba do ancho de contido do tema. Agora usa un ancho propio máis amplo e, en pantallas grandes, sáese de forma controlada da columna estreita do tema (centrada e limitada ao 92% do ancho da xanela para non provocar scroll horizontal). En móbil/tablet mantense a unha columna.

### Notas técnicas

- Sen cambios de esquema (DB 1.37.0). Só presentación.
- Suite en verde (959 tests, 2761 assertions).
- Aínda pendente nesta liña: nova navegación persistente + panel de benvida con extraescolares (próxima beta).

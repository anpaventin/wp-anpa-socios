## anpa-socios 1.47.0-beta.1 (prerelease)

Primeira entrega da **fase 32** (redeseño da área de socios) + un axuste visual en Xestión.

### Área de socios — redeseño (parte 1: layout)

- **Ancho fluído e responsive**: retírase o ancho fixo de 720 px que en pantallas grandes deixaba a área nunha columna estreita e descentrada. Agora a área adáptase ao ancho de contido do tema (con *fallback* propio) e escala mellor en escritorio, tablet e móbil.
- **Herdanza segura do tema**: a área toma do tema activo os tokens estruturais (tipografía, bordos, radios, superficies, ancho), cun *fallback* propio. A paleta de cores de acción mantense propia e accesible (non se reintroduce ningún choque de contraste).
- **Campos cunha medida lexible**: os campos dos formularios xa non quedan minúsculos; usan un ancho cómodo.

> Nesta beta **aínda non** cambia a navegación nin a pantalla de entrada; iso chega na seguinte beta (navegación persistente + panel de benvida con extraescolares).

### Xestión (admin)

- **Marxe dereita**: a fila dos cadros (Socios / Extraescolares / Operacións) e a fila de botóns e busca xa non quedan pegadas ao borde dereito do navegador; déixase un pequeno espazo á dereita.

### Notas técnicas

- Sen cambios de esquema (DB 1.37.0).
- Só presentación: sen cambios en endpoints, permisos nin datos.
- Suite en verde (959 tests, 2761 assertions).

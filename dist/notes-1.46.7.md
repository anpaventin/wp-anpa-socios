## anpa-socios 1.46.7 (estable)

Versión estable que consolida a fase 28 (coherencia visual e relacións de Xestión), validada previamente nas betas 1.46.7-beta.1 a beta.3.

### Melloras de Xestión (fase 28)

- **Listado de Fillos**: cabeceira multinivel (Datos do proxenitor / Datos do/a fillo/a) e columnas do proxenitor resaltadas cun fondo distinto para diferencialas visualmente. Sen cambios en busca, orde, paxinación nin CSV.
- **Actividades**: mostra o estado efectivo do curso activo (Activa / Sen grupo / Inactiva). A orde pola columna «Estado» ordena por ese estado efectivo visible; a exportación CSV conserva o estado cru (contrato canónico intacto).
- **Empresas**: móstranse por defecto só as actividades efectivamente activas no curso activo, con opción de amosar as desactivadas e editar no fluxo canónico.
- **Actividades públicas**: desglose por grupos reais (nome, horario, cursos, prazas e mínimo) cun único prezo.

### Notas técnicas

- Sen cambios de esquema (DB 1.37.0).
- `_estado_efectivo` é un campo de presentación (mostrar/ordenar/buscar); o CSV segue en `ACTIV_COLS` co estado cru.
- Suite en verde (959 tests, 2760 assertions).

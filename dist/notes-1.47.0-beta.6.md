## anpa-socios 1.47.0-beta.6 (prerelease)

Fase 32 — redeseño da área de socios/as (navegación + entrada).

### Novo

- **Navegación persistente**: substitúese o antigo menú despregable por unha barra de navegación sempre visible cando tes sesión iniciada: **Inicio · Extraescolares · Fillos/as · Os meus datos · Conta / IBAN**, coa indicación de «Conectada/o como …» e **Pechar sesión** separados. É accesible por teclado (Tab/Enter/Espazo), marca a sección activa e move o foco ao título ao cambiar.
- **Pantalla de entrada tipo panel**: ao entrar (ou ao restaurar a sesión) xa non se abre directamente o formulario de datos, senón un **panel de benvida** que destaca as **actividades extraescolares** (resumo das túas matrículas) e ofrece un botón directo a **Nova matrícula**, ademais de accesos rápidos a fillos/as, os teus datos e conta/IBAN.

### Detalles

- Non se perde ningunha función: os datos persoais, 2º proxenitor, IBAN, fillos/as, extraescolares e a solicitude/anulación de baixa seguen accesibles.
- O panel degrada con elegancia: se falla a carga do resumo, séguense mostrando os accesos.
- O fluxo de empresa non cambia. En pretemporada segue amosándose só a mensaxe informativa (sen formulario).

### Notas técnicas

- Só presentación/navegación; sen cambios de esquema (DB 1.37.0), endpoints nin permisos.
- Non-regresión cuberta por probas (nav + panel presentes; todos os pasos previos conservados).
- Suite en verde (963 tests, 2787 assertions).

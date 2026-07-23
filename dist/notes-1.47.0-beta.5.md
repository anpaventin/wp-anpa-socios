## anpa-socios 1.47.0-beta.5 (prerelease)

### Corrixido

- **Editar un fillo/a dentro da ficha do socio/a (Xestión)**: as etiquetas dos campos aparecían **ao lado** dos controis; agora aparecen **enriba**, coherente co resto de formularios.
- **Instalación inicial — estrutura escolar**: os niveis (cursos) e a última aula que se creaban **non coincidían** cos que introducía o usuario no asistente. Causa: a migración sementaba unha estrutura por defecto (niveis 1–6, aulas A–D) e o asistente omitía a súa creación ao detectar que xa había niveis. Agora, **nunha instalación limpa (sen datos dependentes)**, o asistente substitúe esa estrutura por defecto pola que introduce o usuario. Se xa hai datos (fillos/as ou grupos que usan a estrutura), respéctase a existente e non se toca.

### Notas técnicas

- A substitución de estrutura só ocorre cando NON hai asignacións de aula (`fillos_cursos`) nin vínculos de grupo (`grupos_niveis`) — é dicir, nunha instalación nova. Faise dentro dunha transacción (rollback ante erro).
- Sen cambios de esquema (DB 1.37.0).
- Suite en verde (959 tests, 2761 assertions).

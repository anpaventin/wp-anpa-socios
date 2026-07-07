# ANPA Socios

> Plugin irmao de `anpa-verificacion` (Fase 1). Depende do
> REST boundary de Fase 1 pero NON require_once o seu codigo
> interno.

Sistema de altas de socios: formulario publico na pagina
`/socios/asociarse/` + endpoint REST
`POST /wp-json/anpa-socios/v1/crear-socio` que escribe na
taboa `wp_anpa_socios` de producion.

## Alcance deste paso (Fase 2 paso 1)

Solo "Alta de socio/a responsable" (nome + apelidos). NON
inclue fillos/as, SEPA, baixa, gestion interna, nin cron
anual. Esos chegan en cambios irmans futuros:
`fase2-socios-fillos`, `fase2-socios-sepa`, `fase2-socios-baixa`,
`fase2-socios-gestion`, `fase2-socios-cron`.

## Shortcode

`[anpa_socios_asociarse]` renderiza o formulario de 3 pasos.

## Contrato REST

### Request

```
POST /wp-json/anpa-socios/v1/crear-socio
Content-Type: application/json

{
  "token":    "<32-char alfanumerico obtido en /anpa/v1/verificar-codigo>",
  "nome":     "<max 50 chars>",
  "apelidos": "<max 100 chars>"
}
```

O `token` e o mesmo que Fase 1 devolve en
`POST /wp-json/anpa/v1/verificar-codigo` (campo `token` do
response JSON). E o token que o handler valida via
`get_transient('anpa_token_' . $token)` — almacenado por
Fase 1 con TTL 30 min, single-use.

### Response

- `200 { success: true, message: "Alta completada" }` en
  todos os casos exitosos: fresh insert, duplicate-update
  (mismo email, novos dados), e reactivacion (estado de
  'baixa' a 'activo').
- `400 WP_Error 'anpa_socios_invalid_token' "Token invalido ou
  caducado"` se o transient non existe, expirou, ou xa foi
  consumido.
- `400 WP_Error 'anpa_socios_invalid' "Datos invalidos"` se
  nome ou apelidos fallan a validacion (empty, overlong, ou
  control chars).
- `500 WP_Error 'anpa_socios_db_error' "Erro interno"` se a
  query falla con un erro distinto a duplicate-key. O transient
  NON se borra neste caso, polo que o usuario pode reintentar
  sen volver a Fase 1.

## Loxica do handler (12 pasos)

1. Sanitize `token` (max 64 chars).
2. Sanitize `nome`.
3. Sanitize `apelidos`.
4. `ANPA_Socios_Payload::validar_nome($nome)` — max 50, trim,
   sen control chars.
5. `ANPA_Socios_Payload::validar_apelidos($apelidos)` — max
   100, trim, sen control chars.
6. `get_transient('anpa_token_' . $token)` → email.
7. Se transient false → 400 token invalido. **Non se borra o
   transient** (xa e false, borrar e no-op).
8. Build SQL: `INSERT INTO wp_anpa_socios (...) VALUES (...)
   ON DUPLICATE KEY UPDATE actualizado_en = NOW(), estado =
   'activo', nome = VALUES(nome), apelidos = VALUES(apelidos)`.
9. Execute via `$wpdb->query`.
10. **Comprobar `$wpdb->last_error`**:
    - Se non-vacio E non-1062 (duplicate-key) → 500.
      **NON chamar `delete_transient`** — o token segue
      valido para retry.
    - Se 1062 (silent no-op do upsert) → continue.
11. **`delete_transient('anpa_token_' . $token)`** — SOLO
    despois de DB write exitoso. Single-use enforcement.
12. Return 200.

A orde dos pasos 9-12 e o contrato critico: `delete_transient`
NON se chama se a DB write falla. Esto permite o retry sen
repetir o fluxo de Fase 1.

## Loxica do JS (3 pasos, vanilla ES6)

| Step | Submit action | Success | Failure |
|---|---|---|---|
| email | POST `/anpa/v1/solicitar-codigo` | advance to codigo (on 200) | stay on email (on 4xx/network) |
| codigo | POST `/anpa/v1/verificar-codigo` | read `response.token`, advance to datos (on 200) | stay on codigo (on 4xx) |
| datos | POST `/anpa-socios/v1/crear-socio` | advance to ok (on 200) | stay on datos (on 4xx/5xx) |

**Critico**: cada `await fetch(...)` comproba `response.ok`.
`fetch()` so rexeita a promesa en erros de RED, NON en 4xx/5xx.
Un 400 do servidor DEBE ir ao estado "error", non ao seguinte
paso.

O JS non distingue "email xa rexistrado" vs "email novo" (contrato
de privacidade de Fase 1: ambos devolven 200 con a mesma
mensaxe).

## TDD

Pure-logic class `ANPA_Socios_Payload` con 10 unit tests
(6 para nome, 4 para apelidos) en
`tests/Test_ANPA_Socios_Payload.php`. PHPUnit strict, sin WP
bootstrap, RED→GREEN evidence capturada no apply-progress do
cambio SDD `fase2-socios-altas-socios`.

## Integracion con Fase 1

| Aspecto | Estado |
|---|---|
| `anpa-verificacion` plugin | Activo en producion, byte-identical ao local |
| `wp_anpa_socios` schema | id, email UNIQUE, nome, **apelidos** (R13), estado DEFAULT 'activo', creado_en, actualizado_en |
| `verificar-codigo` response | `{ success: true, token: <32-char> }` (linha 309-314 de `class-anpa-rest.php` de Fase 1) |
| Transient | `anpa_token_<token>` → email string, TTL 30 min, single-use |
| Rate limit de Fase 1 | 3 requests / email / hora. O `crear-socio` non engade rate limit propio; o token e o recurso escaso (ver S9 do spec). |

## R13 (exception documentada a Fase 1 frozen)

A columna `apelidos` foi anadida a `wp_anpa_socios` via
`dbDelta()` en `class-anpa-db.php` (Fase 1). Esta e a UNICA
modificacion a Fase 1 neste cambio. Razon: evitar migracion
de datos reais de socios no futuro cando Fase 2 precise
ordenar/exportar por apelidos. dbDelta() preserva as filas
existentes (DEFAULT '' aplicalhes).

Rollback manual: `ALTER TABLE wp_anpa_socios DROP COLUMN
apelidos;` (non parte do apply do plugin).

## Activacion

- **No apply**: a columna `apelidos` xa existe en producion
  (R13 aplicado via `wp eval` o 2026-06-15).
- **Plugin anpa-socios**: pendente de activacion. Sigue os
  pasos do apply-progress.md do cambio SDD
  `fase2-socios-altas-socios`:
  1. Subir plugin via pscp/sync-sftp.
  2. `wp plugin activate anpa-socios`.
  3. Crear a pagina `/socios/asociarse/` con o shortcode.
  4. E2E S1 (fresh insert), S2 (duplicate), S10
    (reactivacion).

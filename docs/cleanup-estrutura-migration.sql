-- ============================================================
-- Cleanup de estrutura escolar: datos incoherentes
-- Executar en migration.anpaventin.es (taboa prefix: wp_)
-- ============================================================

-- 1. Comprobar niveis inactivos sen referencias reais
SELECT '--- NIVELES INACTIVOS ---' AS '';
SELECT n.id, n.curso_escolar, n.codigo, n.etiqueta,
       (SELECT COUNT(*) FROM wp_anpa_fillos_cursos WHERE nivel_id = n.id) AS fillo_refs,
       (SELECT COUNT(*) FROM wp_anpa_grupos_niveis WHERE nivel_id = n.id) AS grupo_refs
FROM wp_anpa_niveis n
WHERE n.estado = 'inactivo';

-- 2. Eliminar aulas de niveis inactivos sen referencias
DELETE a FROM wp_anpa_aulas a
INNER JOIN wp_anpa_niveis n ON n.id = a.nivel_id AND n.estado = 'inactivo'
LEFT JOIN wp_anpa_fillos_cursos fc ON fc.nivel_id = n.id
LEFT JOIN wp_anpa_grupos_niveis gn ON gn.nivel_id = n.id
WHERE fc.nivel_id IS NULL AND gn.nivel_id IS NULL;

-- 3. Eliminar niveis inactivos sen referencias
DELETE n FROM wp_anpa_niveis n
LEFT JOIN wp_anpa_fillos_cursos fc ON fc.nivel_id = n.id
LEFT JOIN wp_anpa_grupos_niveis gn ON gn.nivel_id = n.id
WHERE n.estado = 'inactivo' AND fc.nivel_id IS NULL AND gn.nivel_id IS NULL;

-- 4. Eliminar aulas orfas (nivel_id non existe)
DELETE a FROM wp_anpa_aulas a
LEFT JOIN wp_anpa_niveis n ON n.id = a.nivel_id
WHERE n.id IS NULL;

-- 5. Comprobar duplicados (curso_escolar, codigo)
SELECT '--- DUPLICADOS ---' AS '';
SELECT curso_escolar, codigo, COUNT(*) AS cnt, GROUP_CONCAT(id) AS ids
FROM wp_anpa_niveis
GROUP BY curso_escolar, codigo
HAVING cnt > 1;

-- 6. Resolver duplicados: manter o primeiro, marcar os demais como inactivos
-- DESCOMENTAR SÓ SE HAI DUPLICADOS:
-- UPDATE wp_anpa_niveis
-- SET estado = 'inactivo'
-- WHERE id IN (
--   SELECT id FROM (
--     SELECT n2.id
--     FROM wp_anpa_niveis n2
--     WHERE EXISTS (
--       SELECT 1 FROM wp_anpa_niveis n1
--       WHERE n1.curso_escolar = n2.curso_escolar
--         AND n1.codigo = n2.codigo
--         AND n1.id < n2.id
--     )
--   ) AS dupes
-- );

-- 7. Verificar resultado
SELECT '--- RESULTADO FINAL ---' AS '';
SELECT curso_escolar, COUNT(*) AS total, estado
FROM wp_anpa_niveis
GROUP BY curso_escolar, estado
ORDER BY curso_escolar, estado;

SELECT '--- AULAS POR NIVEL ---' AS '';
SELECT n.curso_escolar, n.codigo, COUNT(a.id) AS aulas, a.estado
FROM wp_anpa_niveis n
LEFT JOIN wp_anpa_aulas a ON a.nivel_id = n.id AND a.estado = 'activo'
GROUP BY n.id
ORDER BY n.curso_escolar, n.orde;

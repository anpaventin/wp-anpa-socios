<?php
/**
 * One-shot cleanup: remove inconsistent niveles/aulas data.
 *
 * Usage: wp anpa-socios cleanup-estrutura
 *
 * @package ANPA_Socios
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

WP_CLI::add_command( 'anpa-socios cleanup-estrutura', function () {
    global $wpdb;

    $niveis_t = ANPA_Socios_DB::tabela_niveis();
    $aulas_t  = ANPA_Socios_DB::tabela_aulas();
    $fc_t     = ANPA_Socios_DB::tabela_fillos_cursos();
    $gn_t     = ANPA_Socios_DB::tabela_grupos_niveis();

    WP_CLI::line( '=== Cleanup estrutura escolar ===' );

    $inactivos = $wpdb->get_results(
        "SELECT id, curso_escolar, codigo, etiqueta, orde FROM {$niveis_t} WHERE estado = 'inactivo'",
        ARRAY_A
    );
    WP_CLI::line( sprintf( 'Inactive niveles: %d', count( $inactivos ) ) );

    $deleted = 0;
    $kept    = 0;
    foreach ( $inactivos as $n ) {
        $nid     = (int) $n['id'];
        $fc_refs = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$fc_t} WHERE nivel_id = %d", $nid ) );
        $gn_refs = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$gn_t} WHERE nivel_id = %d", $nid ) );
        if ( $fc_refs + $gn_refs > 0 ) {
            WP_CLI::line( "  KEEP #{$nid} ({$n['curso_escolar']}/{$n['codigo']}): {$fc_refs} fillos + {$gn_refs} grupos" );
            $kept++;
            continue;
        }
        $wpdb->delete( $aulas_t, array( 'nivel_id' => $nid ), array( '%d' ) );
        $wpdb->delete( $niveis_t, array( 'id' => $nid ), array( '%d' ) );
        $deleted++;
        WP_CLI::line( "  DELETE #{$nid} ({$n['curso_escolar']}/{$n['codigo']})" );
    }

    // Orphan aulas
    $orphans = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$aulas_t} a LEFT JOIN {$niveis_t} n ON n.id = a.nivel_id WHERE n.id IS NULL"
    );
    if ( $orphans > 0 ) {
        $wpdb->query(
            "DELETE a FROM {$aulas_t} a LEFT JOIN {$niveis_t} n ON n.id = a.nivel_id WHERE n.id IS NULL"
        );
        WP_CLI::line( "Orphan aulas removed: {$orphans}" );
    }

    // Duplicate codigos
    $dupes = $wpdb->get_results(
        "SELECT curso_escolar, codigo, COUNT(*) AS cnt, GROUP_CONCAT(id) AS ids
         FROM {$niveis_t} GROUP BY curso_escolar, codigo HAVING cnt > 1",
        ARRAY_A
    );
    foreach ( $dupes as $d ) {
        WP_CLI::warning( "Duplicate: {$d['curso_escolar']}/{$d['codigo']} ids: {$d['ids']}" );
        $ids = explode( ',', (string) $d['ids'] );
        sort( $ids );
        $keep = (int) $ids[0];
        foreach ( $ids as $id_str ) {
            $id = (int) $id_str;
            if ( $id === $keep ) {
                continue;
            }
            $wpdb->update( $niveis_t, array( 'estado' => 'inactivo' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
        }
    }

    WP_CLI::success( "Deleted: {$deleted}, Kept: {$kept}" );
} );

<?php
/**
 * Compiles .po files to .mo format.
 * Run: php languages/compile-mo.php
 *
 * Based on the WordPress MO file format (gettext binary).
 * @see https://www.gnu.org/software/gettext/manual/html_node/MO-Files.html
 */

function compile_po_to_mo( $po_file, $mo_file ) {
    $entries = parse_po( $po_file );
    if ( empty( $entries ) ) {
        echo "No entries found in $po_file\n";
        return false;
    }

    // Sort by original string (required by MO format for binary search).
    ksort( $entries );

    $count = count( $entries );
    $originals = '';
    $translations = '';
    $orig_offsets = array();
    $trans_offsets = array();
    $orig_pos = 0;
    $trans_pos = 0;

    foreach ( $entries as $original => $translation ) {
        $orig_offsets[] = array( strlen( $original ), $orig_pos );
        $trans_offsets[] = array( strlen( $translation ), $trans_pos );
        $originals .= $original . "\0";
        $translations .= $translation . "\0";
        $orig_pos += strlen( $original ) + 1;
        $trans_pos += strlen( $translation ) + 1;
    }

    // Header: magic, revision, count, offset of orig table, offset of trans table, hash size, hash offset.
    $header_size = 28;
    $table_size = $count * 8; // each entry: length (4 bytes) + offset (4 bytes)
    $orig_table_offset = $header_size;
    $trans_table_offset = $orig_table_offset + $table_size;
    $strings_offset = $trans_table_offset + $table_size;
    $trans_strings_offset = $strings_offset + strlen( $originals );

    $output = '';
    // Magic number (little-endian).
    $output .= pack( 'V', 0x950412de );
    // Revision.
    $output .= pack( 'V', 0 );
    // Number of strings.
    $output .= pack( 'V', $count );
    // Offset of table with original strings.
    $output .= pack( 'V', $orig_table_offset );
    // Offset of table with translation strings.
    $output .= pack( 'V', $trans_table_offset );
    // Size of hashing table (0 = no hash).
    $output .= pack( 'V', 0 );
    // Offset of hashing table.
    $output .= pack( 'V', $trans_table_offset + $table_size );

    // Original strings table.
    foreach ( $orig_offsets as $entry ) {
        $output .= pack( 'V', $entry[0] );
        $output .= pack( 'V', $strings_offset + $entry[1] );
    }

    // Translation strings table.
    foreach ( $trans_offsets as $entry ) {
        $output .= pack( 'V', $entry[0] );
        $output .= pack( 'V', $trans_strings_offset + $entry[1] );
    }

    // Original strings.
    $output .= $originals;
    // Translation strings.
    $output .= $translations;

    file_put_contents( $mo_file, $output );
    echo "Compiled: $mo_file (" . $count . " entries)\n";
    return true;
}

function parse_po( $file ) {
    $content = file_get_contents( $file );
    if ( false === $content ) {
        return array();
    }

    $entries = array();
    $current_msgid = null;
    $current_msgstr = null;
    $in_msgid = false;
    $in_msgstr = false;
    $is_header = true;
    $header_value = '';

    $lines = explode( "\n", $content );
    foreach ( $lines as $line ) {
        $line = rtrim( $line, "\r" );

        if ( preg_match( '/^msgid\s+"(.*)"$/', $line, $m ) ) {
            // Save previous entry.
            if ( null !== $current_msgid && ! $is_header ) {
                $entries[ $current_msgid ] = $current_msgstr ?? '';
            }
            $current_msgid = stripcslashes( $m[1] );
            $current_msgstr = null;
            $in_msgid = true;
            $in_msgstr = false;
            $is_header = ( '' === $current_msgid );
        } elseif ( preg_match( '/^msgstr\s+"(.*)"$/', $line, $m ) ) {
            $current_msgstr = stripcslashes( $m[1] );
            $in_msgid = false;
            $in_msgstr = true;
            if ( $is_header ) {
                $header_value = $current_msgstr;
            }
        } elseif ( preg_match( '/^"(.*)"$/', $line, $m ) ) {
            $val = stripcslashes( $m[1] );
            if ( $in_msgid ) {
                $current_msgid .= $val;
            } elseif ( $in_msgstr ) {
                $current_msgstr .= $val;
                if ( $is_header ) {
                    $header_value .= $val;
                }
            }
        } elseif ( '' === trim( $line ) || 0 === strpos( $line, '#' ) ) {
            // Comment or blank line.
            $in_msgid = false;
            $in_msgstr = false;
        }
    }

    // Save last entry.
    if ( null !== $current_msgid && ! $is_header && '' !== $current_msgid ) {
        $entries[ $current_msgid ] = $current_msgstr ?? '';
    }

    // Include header as empty-string key.
    if ( '' !== $header_value ) {
        $entries[''] = $header_value;
    }

    return $entries;
}

// --- Main ---
$dir = __DIR__;
$locales = array( 'es_ES', 'gl_ES' );
foreach ( $locales as $locale ) {
    $po = $dir . '/anpa-socios-' . $locale . '.po';
    $mo = $dir . '/anpa-socios-' . $locale . '.mo';
    if ( file_exists( $po ) ) {
        compile_po_to_mo( $po, $mo );
    } else {
        echo "PO file not found: $po\n";
    }
}
echo "Done.\n";

<?php
/**
 * CSV/XLSX importer for multi-value field options (select, radio, checkbox).
 *
 * @package AkashicForms
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Akashic_Forms_Options_Importer' ) ) {

    class Akashic_Forms_Options_Importer {

        /**
         * Maximum total uncompressed size allowed inside an uploaded .xlsx archive (50 MB).
         */
        const MAX_XLSX_UNCOMPRESSED_SIZE = 52428800;

        /**
         * Maximum number of option rows returned by a single import.
         */
        const MAX_ROWS = 20000;

        /**
         * Parse an uploaded file into an array of options.
         *
         * @param string $tmp_path      Path to the uploaded (temp) file.
         * @param string $original_name Original file name, used to detect the extension.
         * @return array|WP_Error Array of array('value' => ..., 'label' => ...) or WP_Error on failure.
         */
        public static function parse_file( $tmp_path, $original_name ) {
            $ext = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );

            if ( 'csv' === $ext ) {
                return self::parse_csv( $tmp_path );
            }

            if ( 'xlsx' === $ext ) {
                return self::parse_xlsx( $tmp_path );
            }

            return new WP_Error( 'akashic_invalid_file_type', __( 'Unsupported file type. Please upload a .csv or .xlsx file.', 'akashic-forms' ) );
        }

        /**
         * Parse a CSV file.
         */
        private static function parse_csv( $path ) {
            $handle = fopen( $path, 'r' );
            if ( ! $handle ) {
                return new WP_Error( 'akashic_file_read_error', __( 'Could not read the uploaded file.', 'akashic-forms' ) );
            }

            // Skip a UTF-8 BOM if present.
            $bom = fread( $handle, 3 );
            if ( "\xEF\xBB\xBF" !== $bom ) {
                rewind( $handle );
            }

            $delimiter = self::detect_csv_delimiter( $path );

            $rows = array();
            while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter ) ) ) {
                if ( 1 === count( $row ) && null === $row[0] ) {
                    continue;
                }
                $rows[] = $row;

                if ( count( $rows ) >= self::MAX_ROWS ) {
                    akashic_forms_log( sprintf( 'Options importer: CSV truncated after reading %d rows.', self::MAX_ROWS ) );
                    break;
                }
            }
            fclose( $handle );

            return self::rows_to_options( $rows );
        }

        /**
         * Guess whether the CSV uses commas or semicolons.
         */
        private static function detect_csv_delimiter( $path ) {
            $sample          = (string) file_get_contents( $path, false, null, 0, 2000 );
            $comma_count     = substr_count( $sample, ',' );
            $semicolon_count = substr_count( $sample, ';' );

            return $semicolon_count > $comma_count ? ';' : ',';
        }

        /**
         * Parse an XLSX file (first worksheet only) without any third-party library,
         * since an .xlsx file is just a zip archive of XML parts.
         */
        private static function parse_xlsx( $path ) {
            if ( ! class_exists( 'ZipArchive' ) ) {
                return new WP_Error( 'akashic_zip_unavailable', __( 'The PHP ZipArchive extension is required to import .xlsx files.', 'akashic-forms' ) );
            }

            $zip = new ZipArchive();
            if ( true !== $zip->open( $path ) ) {
                return new WP_Error( 'akashic_file_read_error', __( 'Could not read the uploaded .xlsx file.', 'akashic-forms' ) );
            }

            $total_size = 0;
            for ( $i = 0; $i < $zip->numFiles; $i++ ) {
                $stat = $zip->statIndex( $i );
                if ( false === $stat ) {
                    continue;
                }
                $total_size += isset( $stat['size'] ) ? (int) $stat['size'] : 0;

                if ( $total_size > self::MAX_XLSX_UNCOMPRESSED_SIZE ) {
                    $zip->close();
                    akashic_forms_log( 'Options importer: rejected .xlsx file, uncompressed contents exceed the size limit.' );
                    return new WP_Error( 'akashic_file_too_large', __( 'The uploaded .xlsx file is too large once uncompressed. Please upload a smaller file.', 'akashic-forms' ) );
                }
            }

            $shared_strings = array();
            $shared_xml     = $zip->getFromName( 'xl/sharedStrings.xml' );
            if ( false !== $shared_xml ) {
                $shared_strings = self::extract_shared_strings( $shared_xml );
            }

            $sheet_path = self::get_first_sheet_path( $zip );
            if ( ! $sheet_path ) {
                $zip->close();
                return new WP_Error( 'akashic_no_sheet', __( 'Could not find a worksheet in the uploaded file.', 'akashic-forms' ) );
            }

            $sheet_xml = $zip->getFromName( $sheet_path );
            $zip->close();

            if ( false === $sheet_xml ) {
                return new WP_Error( 'akashic_no_sheet', __( 'Could not read the worksheet from the uploaded file.', 'akashic-forms' ) );
            }

            $rows = self::extract_rows_from_sheet( $sheet_xml, $shared_strings );

            return self::rows_to_options( $rows );
        }

        /**
         * Extract all shared strings from xl/sharedStrings.xml.
         */
        private static function extract_shared_strings( $xml ) {
            $strings = array();

            $sxml = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOENT );
            if ( false === $sxml ) {
                return $strings;
            }

            foreach ( $sxml->si as $si ) {
                if ( isset( $si->t ) ) {
                    $strings[] = (string) $si->t;
                } else {
                    $text = '';
                    if ( isset( $si->r ) ) {
                        foreach ( $si->r as $run ) {
                            $text .= (string) $run->t;
                        }
                    }
                    $strings[] = $text;
                }
            }

            return $strings;
        }

        /**
         * Locate the XML part for the first worksheet in the workbook.
         */
        private static function get_first_sheet_path( ZipArchive $zip ) {
            if ( false !== $zip->locateName( 'xl/worksheets/sheet1.xml' ) ) {
                return 'xl/worksheets/sheet1.xml';
            }

            $workbook_xml = $zip->getFromName( 'xl/workbook.xml' );
            $rels_xml     = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );

            if ( false === $workbook_xml || false === $rels_xml ) {
                return false;
            }

            $workbook = simplexml_load_string( $workbook_xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOENT );
            $rels     = simplexml_load_string( $rels_xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOENT );

            if ( false === $workbook || false === $rels || empty( $workbook->sheets->sheet ) ) {
                return false;
            }

            $first_sheet = $workbook->sheets->sheet[0];
            $r_attrs     = $first_sheet->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' );
            $r_id        = isset( $r_attrs['id'] ) ? (string) $r_attrs['id'] : '';

            foreach ( $rels->Relationship as $rel ) {
                if ( (string) $rel['Id'] === $r_id ) {
                    return 'xl/' . ltrim( (string) $rel['Target'], '/' );
                }
            }

            return false;
        }

        /**
         * Convert a worksheet XML part into a plain array of rows/columns,
         * resolving shared-string references and keeping empty cells aligned.
         */
        private static function extract_rows_from_sheet( $xml, $shared_strings ) {
            $sxml = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOENT );
            if ( false === $sxml || ! isset( $sxml->sheetData ) ) {
                return array();
            }

            $rows = array();

            foreach ( $sxml->sheetData->row as $row ) {
                $row_data  = array();
                $col_index = 0;

                foreach ( $row->c as $cell ) {
                    $ref            = (string) $cell['r'];
                    $cell_col_index = $ref ? self::column_letters_to_index( preg_replace( '/[0-9]/', '', $ref ) ) : $col_index;

                    while ( $col_index < $cell_col_index ) {
                        $row_data[ $col_index ] = '';
                        $col_index++;
                    }

                    $type  = (string) $cell['t'];
                    $value = isset( $cell->v ) ? (string) $cell->v : '';

                    if ( 's' === $type ) {
                        $value = isset( $shared_strings[ (int) $value ] ) ? $shared_strings[ (int) $value ] : '';
                    } elseif ( 'inlineStr' === $type ) {
                        $value = isset( $cell->is->t ) ? (string) $cell->is->t : '';
                    }

                    $row_data[ $col_index ] = $value;
                    $col_index++;
                }

                $rows[] = $row_data;
            }

            return $rows;
        }

        /**
         * Convert a spreadsheet column reference (A, B, ..., AA, ...) into a 0-based index.
         */
        private static function column_letters_to_index( $letters ) {
            $letters = strtoupper( $letters );
            $index   = 0;

            for ( $i = 0; $i < strlen( $letters ); $i++ ) {
                $index = $index * 26 + ( ord( $letters[ $i ] ) - ord( 'A' ) + 1 );
            }

            return $index - 1;
        }

        /**
         * Turn raw rows (first row = header) into a normalized list of options,
         * matching "Value"/"Name" columns case-insensitively.
         */
        private static function rows_to_options( $rows ) {
            $rows = array_values(
                array_filter(
                    $rows,
                    function ( $row ) {
                        return ! empty(
                            array_filter(
                                (array) $row,
                                function ( $cell ) {
                                    return '' !== trim( (string) $cell );
                                }
                            )
                        );
                    }
                )
            );

            if ( empty( $rows ) ) {
                return new WP_Error( 'akashic_empty_file', __( 'The uploaded file does not contain any data.', 'akashic-forms' ) );
            }

            $header = array_map(
                function ( $cell ) {
                    return strtolower( trim( (string) $cell ) );
                },
                array_shift( $rows )
            );

            $value_index = array_search( 'value', $header, true );
            $name_index  = array_search( 'name', $header, true );

            if ( false === $value_index && false === $name_index ) {
                // No recognizable header row: treat it as data and use column position instead.
                array_unshift( $rows, $header );
                $value_index = 0;
                $name_index  = isset( $header[1] ) ? 1 : 0;
            } elseif ( false === $value_index ) {
                $value_index = ( 0 === $name_index ) ? 1 : 0;
            } elseif ( false === $name_index ) {
                $name_index = ( 0 === $value_index ) ? 1 : 0;
            }

            $options = array();
            foreach ( $rows as $row ) {
                $value = isset( $row[ $value_index ] ) ? trim( (string) $row[ $value_index ] ) : '';
                $label = isset( $row[ $name_index ] ) ? trim( (string) $row[ $name_index ] ) : '';

                if ( '' === $value && '' === $label ) {
                    continue;
                }

                if ( '' === $value ) {
                    $value = $label;
                }
                if ( '' === $label ) {
                    $label = $value;
                }

                $options[] = array(
                    'value' => $value,
                    'label' => $label,
                );

                if ( count( $options ) >= self::MAX_ROWS ) {
                    akashic_forms_log( sprintf( 'Options importer: option list truncated at %d rows.', self::MAX_ROWS ) );
                    break;
                }
            }

            if ( empty( $options ) ) {
                return new WP_Error( 'akashic_empty_file', __( 'No valid rows were found in the uploaded file.', 'akashic-forms' ) );
            }

            return $options;
        }
    }
}

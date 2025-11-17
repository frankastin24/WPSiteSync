<?php
// -------------------------
// DATABASE EXPORT / IMPORT (pure PHP)
// -------------------------

/**
 * Export current DB to $file (database.sql)
 * Uses mysqli and performs SHOW CREATE TABLE and SELECTs.
 */
function tss_export_database($file) {
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    if ($mysqli->connect_errno) throw new Exception('DB connect failed: ' . $mysqli->connect_error);
    $mysqli->set_charset('utf8mb4');

    $tables = [];
    $res = $mysqli->query("SHOW TABLES");
    if (!$res) throw new Exception('SHOW TABLES failed: ' . $mysqli->error);
    while ($row = $res->fetch_array()) $tables[] = $row[0];

    $sql = "-- Two-Site Sync export\n-- Generated: " . date('c') . "\n\n";
    $sql .= "SET foreign_key_checks = 0;\n\n";

    foreach ($tables as $table) {
        // structure
        $r = $mysqli->query("SHOW CREATE TABLE `{$table}`");
        $rr = $r->fetch_assoc();
        $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $sql .= $rr['Create Table'] . ";\n\n";

        // data: build INSERTs in batches for performance
        $res2 = $mysqli->query("SELECT * FROM `{$table}`");
        if ($res2 && $res2->num_rows) {
            $columns = array_keys($res2->fetch_assoc());
            // rewind result pointer - fetch_assoc advanced pointer; re-run query
            $res2 = $mysqli->query("SELECT * FROM `{$table}`");
            $cols_escaped = array_map(function($c) use ($mysqli) { return '`' . $mysqli->real_escape_string($c) . '`'; }, $columns);
            $colList = implode(',', $cols_escaped);
            $batch = [];
            $counter = 0;
            while ($row = $res2->fetch_assoc()) {
                $vals = array_map(function($v) use ($mysqli) {
                    if (is_null($v)) return 'NULL';
                    return "'" . $mysqli->real_escape_string($v) . "'";
                }, array_values($row));
                $batch[] = '(' . implode(',', $vals) . ')';
                $counter++;
                if ($counter % 250 == 0) {
                    $sql .= "INSERT INTO `{$table}` ({$colList}) VALUES " . implode(',', $batch) . ";\n";
                    $batch = [];
                }
            }
            if (!empty($batch)) {
                $sql .= "INSERT INTO `{$table}` ({$colList}) VALUES " . implode(',', $batch) . ";\n";
            }
            $sql .= "\n";
        }
    }

    $sql .= "SET foreign_key_checks = 1;\n";

    file_put_contents($file, $sql);
    $mysqli->close();
}

/**
 * Transactional import of SQL file.
 * Attempts to use transaction if storage engine supports it. Uses multi_query but wraps in START TRANSACTION/COMMIT.
 * On error, attempts rollback.
 */
function tss_import_database_transactional($sqlfile) {
    if (!file_exists($sqlfile)) throw new Exception('SQL file missing for import.');

    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    if ($mysqli->connect_errno) throw new Exception('DB connect failed: ' . $mysqli->connect_error);
    $mysqli->set_charset('utf8mb4');

    $sql = file_get_contents($sqlfile);
    if ($sql === false) throw new Exception('Could not read SQL file.');

    // Some SQL exports include DELIMITER or comments; we'll attempt to execute with multi_query.
    // Wrap in transaction where possible
    $useTransaction = true;
    // disable autocommit
    if ($useTransaction) {
        $mysqli->autocommit(false);
        $mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
        $mysqli->query('START TRANSACTION');
    }

    // execute multi_query
    $ok = $mysqli->multi_query($sql);
    if (!$ok) {
        $err = $mysqli->error;
        if ($useTransaction) {
            $mysqli->rollback();
            $mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
            $mysqli->autocommit(true);
        }
        $mysqli->close();
        throw new Exception('SQL import failed: ' . $err);
    }

    // fetch and drain results
    do {
        if ($res = $mysqli->store_result()) {
            $res->free();
        }
    } while ($mysqli->more_results() && $mysqli->next_result());

    if ($useTransaction) {
        $mysqli->query('COMMIT');
        $mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
        $mysqli->autocommit(true);
    }
    $mysqli->close();
}
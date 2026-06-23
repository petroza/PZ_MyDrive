<?php
declare(strict_types=1);

/*
 * MyDrive — store.php
 * Minimal flat-file (JSON) data layer with crash-safe, lock-serialized writes.
 * No database needed, so the whole app stays portable on shared hosting.
 * The personal-drive build only needs the generic accessors below; the
 * Drive-specific stores (stars, trash) live in drive.php on top of these.
 */

require_once __DIR__ . '/bootstrap.php';

function pzc_db_path(string $name): string {
    return PZC_DATA . '/' . preg_replace('/[^a-z0-9_]/', '', $name) . '.json';
}

function pzc_db_read(string $name): array {
    return pzc_read_json(pzc_db_path($name));
}

/**
 * Locked read-modify-write of a named JSON store. $fn gets the data by
 * reference and may return a value, which is passed back to the caller.
 * Delegates to pzc_locked_update (lock-file serialization + atomic rename),
 * so concurrent writers never lose updates and a crash can't leave a
 * half-written file.
 */
function pzc_db_mutate(string $name, callable $fn) {
    return pzc_locked_update(pzc_db_path($name), $fn);
}

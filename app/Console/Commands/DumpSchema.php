<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB; // Asegúrate de importar DB
use Illuminate\Support\Facades\Schema;

class DumpSchema extends Command
{
    protected $signature = 'schema:dump';
    protected $description = 'Dumps the database schema (tables, columns, types, keys) using SQL Server 2012 compatible queries for indexes.';

    public function handle()
    {
        $this->info('Attempting to connect to database: ' . DB::connection()->getDatabaseName());

        try {
            $tables = Schema::getTables();

            if (empty($tables)) {
                $this->warn('No tables found in the database.');
                return 0;
            }

            $this->info("Tables found in database '" . DB::connection()->getDatabaseName() . "':");

            foreach ($tables as $table) {
                $tableName = $table['name'];

                $this->line("\n--------------------------------------------------");
                $this->line("Table: <fg=cyan>{$tableName}</>");
                $this->line('--------------------------------------------------');

                $columns = Schema::getColumns($tableName);

                // ---- MODIFICACIÓN AQUÍ PARA OBTENER ÍNDICES ----
                $rawIndexesQuery = "
                    SELECT
                        idx.name AS name,
                        idx.type_desc AS type, -- Aunque no lo uses en la tabla final, lo obtenemos por consistencia
                        idx.is_unique AS [unique],
                        idx.is_primary_key AS [primary],
                        STUFF(
                            (SELECT ',' + col_inner.name
                             FROM sys.index_columns AS idxcol_inner
                             INNER JOIN sys.columns AS col_inner ON idxcol_inner.object_id = col_inner.object_id AND idxcol_inner.column_id = col_inner.column_id
                             WHERE idxcol_inner.object_id = idx.object_id AND idxcol_inner.index_id = idx.index_id
                             ORDER BY idxcol_inner.key_ordinal
                             FOR XML PATH(''), TYPE).value('.', 'NVARCHAR(MAX)'),
                            1, 1, ''
                        ) AS columns_list_str
                    FROM
                        sys.indexes AS idx
                    INNER JOIN
                        sys.tables AS tbl ON idx.object_id = tbl.object_id
                    INNER JOIN
                        sys.schemas AS scm ON tbl.schema_id = scm.schema_id
                    WHERE
                        tbl.name = ? AND scm.name = SCHEMA_NAME()
                        AND idx.type <> 0
                        AND idx.is_hypothetical = 0
                        AND idx.is_disabled = 0
                    ORDER BY
                        idx.name
                ";

                $rawIndexesResult = DB::select($rawIndexesQuery, [$tableName]);

                $indexes = [];
                foreach ($rawIndexesResult as $rawIndex) {
                    $indexes[] = [
                        'name' => $rawIndex->name,
                        // La consulta devuelve una cadena de columnas separadas por coma.
                        // Tu código espera un array para 'columns' (para in_array y implode).
                        'columns' => $rawIndex->columns_list_str ? explode(',', $rawIndex->columns_list_str) : [],
                        'unique' => (bool) $rawIndex->unique,
                        'primary' => (bool) $rawIndex->primary,
                        // 'type' => $rawIndex->type, // Puedes incluirlo si lo necesitas más adelante
                    ];
                }
                // ---- FIN DE LA MODIFICACIÓN ----


                try {
                    $foreignKeys = Schema::getForeignKeys($tableName);
                } catch (\Exception $e) {
                    $this->comment("Could not retrieve foreign keys for {$tableName}: {$e->getMessage()}");
                    $foreignKeys = [];
                }

                $headers = ['Column Name', 'Type', 'Length', 'Precision', 'Scale', 'Unsigned', 'Fixed', 'Not Null', 'Default', 'Autoincrement', 'PK', 'Comment'];
                $columnData = [];

                foreach ($columns as $column) {
                    $isPrimaryKey = false;
                    // Esta lógica de determinar si una columna es PK ahora usará el array 'columns' que creamos
                    foreach ($indexes as $index) {
                        if ($index['primary'] && in_array($column['name'], $index['columns'])) {
                            $isPrimaryKey = true;
                            break;
                        }
                    }

                    $columnData[] = [
                        $column['name'],
                        $column['type'],
                        $column['length'] ?? null,
                        $column['precision'] ?? null,
                        $column['scale'] ?? null,
                        isset($column['unsigned']) && $column['unsigned'] ? 'Yes' : 'No',
                        isset($column['fixed']) && $column['fixed'] ? 'Yes' : 'No',
                        $column['nullable'] ? 'No' : 'Yes',
                        $column['default'] ?? null,
                        isset($column['auto_increment']) && $column['auto_increment'] ? 'Yes' : 'No',
                        $isPrimaryKey ? '<fg=green>Yes</>' : 'No',
                        $column['comment'] ?? null,
                    ];
                }
                $this->table($headers, $columnData);

                if (!empty($indexes)) {
                    $this->line("\n<fg=yellow>Indexes:</>");
                    $indexHeaders = ['Name', 'Columns', 'Is Unique', 'Is Primary'];
                    $indexData = [];
                    foreach ($indexes as $index) {
                        $indexData[] = [
                            $index['name'],
                            implode(', ', $index['columns']), // Esto seguirá funcionando porque 'columns' es un array
                            $index['unique'] ? 'Yes' : 'No',
                            $index['primary'] ? 'Yes' : 'No',
                        ];
                    }
                    $this->table($indexHeaders, $indexData);
                } else {
                    $this->line("\n<fg=yellow>No indexes found for this table.</>");
                }

                // ... (resto del código para foreign keys sin cambios)
                if (!empty($foreignKeys)) {
                    $this->line("\n<fg=yellow>Foreign Keys:</>");
                    $fkHeaders = ['Name', 'Local Columns', 'Foreign Table', 'Foreign Columns'];
                    $fkData = [];
                    foreach ($foreignKeys as $fk) {
                        $fkData[] = [
                            $fk['name'],
                            implode(', ', $fk['columns']),
                            $fk['foreign_table'],
                            implode(', ', $fk['foreign_columns']),
                        ];
                    }
                    $this->table($fkHeaders, $fkData);
                } else {
                    $this->line("\n<fg=yellow>No foreign keys found for this table.</>");
                }
            }
        } catch (\Exception $e) {
            $this->error("An error occurred: " . $e->getMessage());
            $this->error("Trace: \n" . $e->getTraceAsString());
            return 1;
        }

        return 0;
    }
}
<?php

namespace Viodev;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class Bulk
{
    public static function insert(string $table_name, array $data, bool $ignore_exceptions = false, int $inserts_per_query = 1000)
    {
        if(empty($data)) return;

        foreach (array_chunk($data, $inserts_per_query) as $chunked_inserts) {
            try {
                DB::table($table_name)->insert($chunked_inserts);
            } catch (QueryException $e) {
                if (!$ignore_exceptions) throw $e;
            }
        }
    }

    public static function insertRetryException(string $table_name, array $data, int $inserts_per_query = 1000)
    {
        if(empty($data)) return;

        foreach (array_chunk($data, $inserts_per_query) as $chunked_inserts) {
            try {
                DB::table($table_name)->insert($chunked_inserts);
            } catch (QueryException $e) {
                if($e->errorInfo[1] == 1062){
                    foreach ($chunked_inserts as $insert) {
                        try {
                            DB::table($table_name)->insert($insert);
                        } catch (QueryException $e){} # Pass duplicates
                    }
                }
            }
        }
    }

    public static function updateByCase($table_name, array $data, $updates_per_query = 1000)
    {
        if(empty($data)) return;

        $updates = [];

        foreach(array_chunk($data, $updates_per_query, true) as $chunked_data){
            $changes_by_column = [];

            foreach($chunked_data as $id => $changes){
                foreach($changes as $column => $change){
                    $changes_by_column[$column][] = [
                        'id' => $id,
                        'data' => $change
                    ];
                }
            }

            $case_strings = [];
            $params = [];

            foreach($changes_by_column as $column => $changes){
                $string = "`$column` = CASE ";
                foreach($changes as $change){
                    $params[] = $change['data'];
                    $string .= "WHEN id = {$change['id']} THEN ? ";
                }
                $string .= "ELSE `$column` END";

                $case_strings[] = $string;
            }

            $ids_string = implode(',', array_keys($chunked_data));
            $cases_string = implode(', ', $case_strings);

            $updates[] = [
                'statement' => "UPDATE $table_name SET $cases_string WHERE id IN ($ids_string)",
                'params' => $params
            ];
        }

        foreach($updates as $update){
            DB::update($update['statement'], $update['params']);
        }
    }

    public static function updateByIds(Builder $query, $ids, $data, $ids_per_query = 1000)
    {
        self::updateByColumnIds($query, 'id', $ids, $data, $ids_per_query);
    }

    public static function updateByColumnIds(Builder $query, $column_name, $ids, $data, $ids_per_query = 1000)
    {
        if(empty($ids)) return;

        foreach(array_chunk($ids, $ids_per_query) as $chunked_ids){
            $query->clone()->whereIn($column_name, $chunked_ids->toArray())->update($data);
        }
    }
}
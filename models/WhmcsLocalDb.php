<?php
namespace WHMCS\Microsoft365\Models;

use WHMCS\Database\Capsule as DB;

class WhmcsLocalDb {
    private $columns;

    public function __construct(array $columns = [])
    {
        // If $columns is set, we only select those columns, otherwise just select all columns
        $this->columns = !empty($columns) ? implode(',', $columns) : '*';
    }

    public function getById(string $target, int $id)
    {
        return DB::table($target)->select($this->columns)->where('id', $id)->first();
    }

    public function getAll(string $target)
    {
        return DB::table($target)->select($this->columns)->get();
    }

    public function getByConditions(string $target, array $conditions)
    {
        $db = DB::table($target)->select($this->columns);

        foreach ($conditions as $key => $value) {
            $db = $db->where("{$key}", $value);
        }

        return $db->get();
    }

}
?>
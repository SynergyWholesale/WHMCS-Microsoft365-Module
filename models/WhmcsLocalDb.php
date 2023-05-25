<?php
namespace WHMCS\Microsoft365\Models;

use WHMCS\Database\Capsule as DB;

class WhmcsLocalDb {
    private $columns;
    const WHMCS_TENANT_TABLE = 'tblclients';

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

    public function create(string $target, array $data)
    {
        return DB::table($target)->insert($data);
    }

    public function update(string $target, $id, $data)
    {
        return DB::table($target)->where('id', $id)->update($data);
    }

    public function delete(string $target, $id)
    {
        return DB::table($target)->where('id', $id)->delete();
    }

    public function getSubscriptionsForCreate($serviceId)
    {
        $subscriptionOrder = [];
        $configOptions = DB::table('tblhostingconfigoptions')
            ->join('tblproductconfigoptions', 'tblhostingconfigoptions.', '=', 'tblproductconfigoptions.id')
            ->select(['tblproductconfigoptions.optionname', 'tblhostingconfigoptions.*'])
            ->where('tblhostingconfigoptions.relid', '=', $serviceId)
            ->get();

        if ($configOptions) {
            foreach ($configOptions as $row)
            {
                if (strpos($row->optionname, '|') && $row->qty > 0) {
                    $productId = explode('|', $row->optionname)[0];

                    $subscriptionOrder[] = [
                        'productId' => $productId,
                        'quantity' => $row->qty,
                    ];
                }
            }
        }
        return $subscriptionOrder;
    }

    public function getProductAndServiceCustomFields($productId, $serviceId, $remoteOnly = true)
    {

        // Select values of custom fields belong to a service
        $customValues = DB::table('tblcustomfieldsvalues')
            ->select($this->columns)
            ->where('relid', $serviceId)
            ->get();
        $fieldValues = [];
        /* Sample $fieldValues:
         * ['2' => '12797912'],
         * ['3' => '123asy231bs'],
         * */
        foreach ($customValues as $row) {
            $fieldValues[$row->fieldid] = $row->value;
        }

        // Select custom fields of a product
        $customFields = DB::table('tblcustomfields')
            ->select($this->columns)
            ->where('relid', $productId)
            ->get();

        $return = [];
        foreach ($customFields as $row) {
            if($row->fieldtype == 'checkbox') {
                $return[] = [
                    $row->fieldname => [
                        'fieldId' => $row->id,
                        'value' => $fieldValues[$row->id] ?? false,
                    ]
                ];
                continue;
            }

            $return[] = [
                $row->fieldname => [
                    'fieldId' => $row->id,
                    'value' => $fieldValues[$row->id] ?? '',
                ]
            ];
        }

        return $return;
    }

    public function createNewCustomFieldValues($fieldId, $serviceId, $value)
    {
        $now = date('Y-m-d H:i:s');
        return DB::table('tblcustomfieldsvalues')
            ->insert([
                'fieldid' => $fieldId,
                'relid' => $serviceId,
                'value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ]) ? $value : false;
    }

    public function updateCustomFieldValues($fieldId, $serviceId, $value)
    {
        $now = date('Y-m-d H:i:s');
        return DB::table('tblcustomfieldsvalues')
            ->where('fieldid', $fieldId)
            ->where('relid', $serviceId)
            ->update([
                'value' => $value,
                'updated_at' => $now,
            ]) ? $value : false;
    }

}
?>
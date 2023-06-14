<?php

namespace WHMCS\Module\Server\SynergywholesaleMicrosoft365;

use WHMCS\Database\Capsule as DB;

class WhmcsLocalDb
{
    // Database tables
    const WHMCS_TENANT_TABLE = 'tblclients';
    const WHMCS_HOSTING_TABLE = 'tblhosting';
    const WHMCS_PRODUCT_TABLE = 'tblproducts';
    const WHMCS_CURRENCY_TABLE = 'tblcurrencies';

    public function updateServiceStatus(int $id, string $status) {
        return DB::table(self::WHMCS_HOSTING_TABLE)->where('id', $id)->update(['domainstatus' => $status]);
    }

    public function getClientById(int $id) {
        return DB::table(self::WHMCS_TENANT_TABLE)->select('*')->find('id', $id);
    }

    public function getProductById(int $id) {
        return DB::table(self::WHMCS_PRODUCT_TABLE)->select('*')->find('id', $id);
    }

    public function getServiceById(int $id) {
        return DB::table(self::WHMCS_HOSTING_TABLE)->select('*')->find('id', $id);
    }

    public function getCurrencyById(int $id) {
        return DB::table(self::WHMCS_CURRENCY_TABLE)->select('*')->find('id', $id);
    }

    public function getSubscriptionsForAction($serviceId, $action, $localProductId = '')
    {
        $return = [];
        $configOptions = DB::table('tblhostingconfigoptions')
            ->join('tblproductconfigoptions', 'tblhostingconfigoptions.configid', '=', 'tblproductconfigoptions.id')
            ->select(['tblproductconfigoptions.optionname', 'tblhostingconfigoptions.*'])
            ->where('tblhostingconfigoptions.relid', '=', $serviceId)
            ->get();

        if (empty($configOptions)) {
            return $return;
        }

        switch ($action) {
            case 'changePlan':
                $customFieldsNeeded = $this->getProductAndServiceCustomFields($localProductId, $serviceId);

                // Otherwise if it has more than 1 subscriptions, we loop through and return the list of subscriptions
                foreach (explode(', ', $customFieldsNeeded['Remote Subscriptions']['value']) as $eachSubscription) {
                    $subscriptionId = explode('|', $eachSubscription)[1];
                    $remoteProductId = explode('|', $eachSubscription)[0];

                    // Then we add each subscription record into $return array
                    $return[$remoteProductId] = [
                        'subscriptionId' => $subscriptionId,
                    ];
                }
                break;
            case 'compare':
                foreach ($configOptions as $row) {
                    if (strpos($row->optionname, '|') && $row->qty >= 0) {
                        $productId = explode('|', $row->optionname)[0];

                        // For compare action, we would want to take all config options even if quantity is 0
                        $return[] = [
                            'productId' => $productId,
                            'productName' => $row->optionname,
                            'quantity' => $row->qty,
                        ];
                    }
                }
                break;
            case 'create':
            default:
                foreach ($configOptions as $row) {
                    if (strpos($row->optionname, '|') && $row->qty >= 0) {
                        $productId = explode('|', $row->optionname)[0];
                        // If this option has 0 quantity, that means user didn't order it, just skip it
                        if ($row->qty == 0) {
                            break;
                        }

                        // Otherwise if it's greater than 0, we add it to order
                        $return[] = [
                            'productId' => $productId,
                            'productName' => $row->optionname,
                            'quantity' => $row->qty,
                        ];
                    }
                }
                break;
        }

        return $return;
    }

    public function getProductAndServiceCustomFields($productId, $serviceId)
    {
        // Select values of custom fields belong to a service
        $customValues = DB::table('tblcustomfieldsvalues')
            ->select('*')
            ->where('relid', $serviceId)
            ->get();

        $fieldValues = [];
        foreach ($customValues as $row) {
            $fieldValues[$row->fieldid] = $row->value;
        }

        // Select custom fields of a product
        $customFields = DB::table('tblcustomfields')
            ->select('*')
            ->where('relid', $productId)
            ->get();

        $return = [];
        foreach ($customFields as $row) {
            $return[$row->fieldname] = [
                'fieldId' => $row->id,
                'value' => $fieldValues[$row->id] ?? ($row->fieldtype == 'checkbox' ? false : ''),
            ];
        }

        // Retrieve all custom fields
        return $return;
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
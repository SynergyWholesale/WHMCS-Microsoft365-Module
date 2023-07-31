<?php

namespace WHMCS\Module\Server\SynergywholesaleMicrosoft365;

use WHMCS\Database\Capsule as DB;
use WHMCS\Module\Server\SynergywholesaleMicrosoft365\ModuleEnums as ModuleEnums;

class WhmcsLocalDb
{
    public function updateServiceStatus(int $id, string $status)
    {
        return DB::table(ModuleEnums::WHMCS_HOSTING_TABLE)->where('id', $id)->update(['domainstatus' => $status]);
    }

    public function getClientById(int $id)
    {
        return DB::table(ModuleEnums::WHMCS_TENANT_TABLE)->find($id);
    }

    public function getProductById(int $id)
    {
        return DB::table(ModuleEnums::WHMCS_PRODUCT_TABLE)->find($id);
    }

    public function getServiceById(int $id)
    {
        return DB::table(ModuleEnums::WHMCS_HOSTING_TABLE)->find($id);
    }

    public function getCurrencyById(int $id)
    {
        return DB::table(ModuleEnums::WHMCS_CURRENCY_TABLE)->find( $id);
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
            case 'sync':
                foreach ($configOptions as $row) {
                    if (strpos($row->optionname, '|') && $row->qty >= 0) {
                        $productId = explode('|', $row->optionname)[0];

                        // For compare action, we would want to take all config options even if quantity is 0
                        $return[$productId] = [
                            'hostingConfigOptionId' => $row->id,
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
                            continue;
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
        $existingRow = DB::table('tblcustomfieldsvalues')
            ->where('fieldid', $fieldId)
            ->where('relid', $serviceId)
            ->first();

        $now = date('Y-m-d H:i:s');

        // If field not inserted, then create field
        if (!$existingRow) {
            return DB::table('tblcustomfieldsvalues')
                ->insert([
                    'fieldid' => $fieldId,
                    'relid' => $serviceId,
                    'value' => $value,
                    'updated_at' => $now,
                ]) ? $value : false;
        }

        // If field already existed, just update
        return DB::table('tblcustomfieldsvalues')
            ->where('fieldid', $fieldId)
            ->where('relid', $serviceId)
            ->update([
                'value' => $value,
                'updated_at' => $now,
            ]) ? $value : false;
    }

    public function getRemoteProductIdsFromPackage($packageId)
    {
        $return = [];
        // We get the list of config options based on the product id
        $configOptionList = DB::table('tblproductconfigoptions')
            ->join('tblproductconfiggroups', 'tblproductconfigoptions.gid', '=', 'tblproductconfiggroups.id')
            ->join('tblproductconfiglinks', 'tblproductconfiglinks.gid', '=', 'tblproductconfiggroups.id')
            ->join('tblproducts', 'tblproductconfiglinks.pid', '=', 'tblproducts.id')
            ->select(['tblproductconfigoptions.optionname'])
            ->where('tblproducts.id', '=', $packageId)
            ->get();

        // If it's empty, it means nothing in the DB
        if (empty($configOptionList)) {
            return $return;
        }

        // Loop through the option names and retrieve the product ID
        foreach ($configOptionList as $option) {
            $return[] = explode('|', $option->optionname)[0];
        }

        return $return;
    }

    /** Update quantity for a hosting config option */
    public function updateHostingConfigOptionQuantity($hostingConfigOptionId, int $quantity = 0)
    {
        return DB::table('tblhostingconfigoptions')
            ->where('id', $hostingConfigOptionId)
            ->update([
                'qty' => $quantity,
            ]);
    }

    /** Update new valid password for service */
    public function updateServiceValidPassword($serviceId, $newPassword)
    {
        return DB::table('tblhosting')
            ->where('id', $serviceId)
            ->update([
                'password' => $newPassword,
            ]);
    }

    /** Check if the current password meets the minimum requirement for MS 365 */
    public function checkPasswordMeetRequirement($password)
    {
        return preg_match("/^(?=.*[0-9])(?=.*[a-z])(?=.*[A-Z])(?=(.*[\W]){2,})(?!.* ).{8,12}$/", $password);
    }

    /** Auto generate a new password that meets the requirement in MS 365 */
    public function generateValidPassword(): string
    {
        // Sketch out the characters that we can pick
        $alphabet = "abcdefghijklmnopqrstuvwxyz";
        $numeric = "0123456789";
        $special = "{}[]()!@$^*_+=~";

        $finalPassword = "";

        $count = 0;
        // Each time we add 3 characters, so the last round we don't want to include in the count, so only loop till maximum count of 9
        while ($count <= 9) {
            $randomAlphabetInt = rand(0, 25);
            $randomNumericInt = rand(0, 9);
            $randomSpecialInt = rand(0, 14);

            $finalPassword .= ($count % 2 == 0) ? strtoupper($alphabet[$randomAlphabetInt]) : $alphabet[$randomAlphabetInt];

            $finalPassword .= $numeric[$randomNumericInt];
            $finalPassword .= $special[$randomSpecialInt];
            $count = strlen($finalPassword);
        }

        return $finalPassword;
    }

    /** Create new config option group */
    public function createConfigOptionGroup($data)
    {
        return DB::table(ModuleEnums::WHMCS_CONFIG_GROUPS_TABLE)
            ->insert($data);
    }

    /** Check if a config option group with same name existed */
    public function getConfigOptionGroupByName($name)
    {
        return DB::table(ModuleEnums::WHMCS_CONFIG_GROUPS_TABLE)
            ->where('name', "{$name}")
            ->first();
    }

    /** Create new config option that belongs to a config group */
    public function createConfigOption($data)
    {
        return DB::table(ModuleEnums::WHMCS_CONFIG_OPTIONS_TABLE)
            ->insert($data);
    }

    /** Assign a config option to a product group */
    public function assignConfigGroupToProduct($data)
    {
        $alreadyAssigned = DB::table(ModuleEnums::WHMCS_PRODUCT_CONFIG_LINKS_TABLE)
            ->where('gid', $data['gid'])
            ->where('pid', $data['pid'])
            ->first();

        return empty($alreadyAssigned)
            ? DB::table(ModuleEnums::WHMCS_PRODUCT_CONFIG_LINKS_TABLE)
                ->insert($data)
            : false;
    }

    /** Disable option 'create config option' of a product */
    public function disableProductCreateConfigOptions($productId)
    {
        return DB::table(ModuleEnums::WHMCS_PRODUCT_TABLE)
            ->where('id', $productId)
            ->update([
                'configoption3' => ''
            ]);
    }

    /** Get all custom fields of a product */
    public function getProductCustomFields($productId)
    {
        return DB::table(ModuleEnums::WHMCS_CUSTOM_FIELDS_TABLE)
            ->where('relid', $productId)
            ->get();
    }

    /** Create new custom fields for product */
    public function createNewProductCustomField($data)
    {
        return DB::table(ModuleEnums::WHMCS_CUSTOM_FIELDS_TABLE)
            ->insert($data);
    }

    /** Disable option 'create custom fields' of a product */
    public function disableProductCreateCustomFields($productId)
    {
        return DB::table(ModuleEnums::WHMCS_PRODUCT_TABLE)
            ->where('id', $productId)
            ->update([
                'configoption4' => ''
            ]);
    }
}
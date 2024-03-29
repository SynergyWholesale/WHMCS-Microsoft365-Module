<?php

namespace WHMCS\Module\Server\SynergywholesaleMicrosoft365;

class ModuleEnums
{
    const WHMCS_TENANT_TABLE = 'tblclients';
    const WHMCS_HOSTING_TABLE = 'tblhosting';
    const WHMCS_PRODUCT_TABLE = 'tblproducts';
    const WHMCS_CURRENCY_TABLE = 'tblcurrencies';
    const WHMCS_CONFIG_GROUPS_TABLE = 'tblproductconfiggroups';
    const WHMCS_CONFIG_OPTIONS_TABLE = 'tblproductconfigoptions';
    const WHMCS_PRODUCT_CONFIG_LINKS_TABLE = 'tblproductconfiglinks';
    const WHMCS_CUSTOM_FIELDS_TABLE = 'tblcustomfields';
    const WHMCS_PRODUCT_CONFIG_OPTIONS_SUB_TABLE = 'tblproductconfigoptionssub';
    const WHMCS_PRICING_TABLE = 'tblpricing';

    const STATE_MAP = [
        'Australian Capital Territory' => 'ACT',
        'New South Wales' => 'NSW',
        'Northern Territory' => 'NT',
        'Queensland' => 'QLD',
        'South Australia' => 'SA',
        'Tasmania' => 'TAS',
        'Victoria' => 'VIC',
        'Western Australia' => 'WA',
    ];

    const MODULE_NAME = '{{MODULE_NAME}}';
}
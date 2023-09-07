<?php

namespace SynergyWholesale\WHMCS\Test;

use PHPUnit\Framework\TestCase;

/**
 * Synergy Wholesale Microsoft 365 Module Test
 *
 * PHPUnit test that asserts the fundamental requirements of a WHMCS
 * server module.
 *
 * Custom module tests are added in addition.
 *
 * @copyright Copyright (c) Synergy Wholesale Pty Ltd 2020
 * @license https://github.com/synergywholesale/whmcs-microsoft365-module/LICENSE
 */

class SynergyWholesaleMicrosoft365ModuleTest extends TestCase
{
    public static function providerCoreFunctionNames()
    {
        return [
            ['CreateAccount'],
            ['SuspendAccount'],
            ['UnsuspendAccount'],
            ['TerminateAccount'],
            ['ChangePackage'],
            ['AdminCustomButtonArray'],
            ['ConfigOptions'],
            ['MetaData'],
            ['ClientArea'],
            ['sync'],
        ];
    }

    /**
     * Test Core Module Functions Exist
     *
     * This test confirms that the functions recommended by WHMCS (and more)
     * are defined in this module.
     *
     * @param $method
     *
     * @dataProvider providerCoreFunctionNames
     */
    public function testCoreModuleFunctionsExist($method)
    {
        $this->assertTrue(function_exists('synergywholesale_microsoft365' . '_' . $method));
    }
}
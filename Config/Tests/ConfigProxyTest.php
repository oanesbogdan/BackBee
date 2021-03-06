<?php

/*
 * Copyright (c) 2011-2015 Lp digital system
 *
 * This file is part of BackBee.
 *
 * BackBee is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * BackBee is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with BackBee. If not, see <http://www.gnu.org/licenses/>.
 *
 * @author Charles Rouillon <charles.rouillon@lp-digital.fr>
 */

namespace BackBee\Config\Tests;

use BackBee\Config\Config;
use BackBee\Config\ConfigProxy;
use BackBee\DependencyInjection\Container;

/**
 * Set of tests for BackBee\Config\ConfigProxy.
 *
 * @category    BackBee
 *
 * @copyright   Lp digital system
 * @author      e.chau <eric.chau@lp-digital.fr>
 *
 * @coversDefaultClass \BackBee\Config\ConfigProxy
 */
class ConfigProxyTest extends \PHPUnit_Framework_TestCase
{
    /**
     * test restore of ConfigProxy.
     *
     * @covers ::__construct
     * @covers ::restore
     * @covers ::isRestored
     */
    public function testConfigProxy()
    {
        // prepare variables we need to perform tests on Config\ConfigProxy
        $container = new Container();
        $config = new Config(__DIR__.'/ConfigTest_Resources');
        $config_dump = $config->dump();

        // set of tests on Config\ConfigProxy
        $config_proxy = new ConfigProxy();
        $this->assertFalse($config_proxy->isRestored());
        $config_proxy->restore($container, $config_dump);
        $this->assertTrue($config_proxy->isRestored());
        $this->assertEquals($config_dump, $config_proxy->dump());
    }
}

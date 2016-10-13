<?php
/**
 * This file is part of OXID eSales Testing Library.
 *
 * OXID eSales Testing Library is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * OXID eSales Testing Library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with OXID eSales Testing Library. If not, see <http://www.gnu.org/licenses/>.
 *
 * @link http://www.oxid-esales.com
 * @copyright (C) OXID eSales AG 2003-2014
 */

namespace OxidEsales\TestingLibrary\Services\ShopInstaller;

use OxConfigFile;
use OxidEsales\Eshop\Core\Config;
use OxidEsales\Eshop\Core\DbMetaDataHandler;
use OxidEsales\Eshop\Core\Edition\EditionPathProvider;
use OxidEsales\Eshop\Core\Edition\EditionRootPathProvider;
use OxidEsales\Eshop\Core\Edition\EditionSelector;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Setup\Core;
use OxidEsales\TestingLibrary\ServiceCaller;
use OxidEsales\TestingLibrary\Services\Library\Cache;
use OxidEsales\TestingLibrary\Services\Library\DatabaseHandler;
use OxidEsales\TestingLibrary\Services\Library\Request;
use OxidEsales\TestingLibrary\Services\Library\ServiceConfig;
use OxidEsales\TestingLibrary\Services\Library\ShopServiceInterface;
use OxidEsales\EshopProfessional\Core\Serial;
use OxidEsales\Eshop\Setup\Setup;
use OxidEsales\TestingLibrary\TestConfig;

/**
 * Class for shop installation.
 */
class ShopInstaller implements ShopServiceInterface
{
    /** @var DatabaseHandler */
    private $dbHandler;

    /** @var ServiceConfig */
    private $serviceConfig;

    /** @var oxConfigFile */
    private $shopConfig;

    /** @var EditionPathProvider */
    private $editionPathProvider;

    /**
     * Includes configuration files.
     *
     * @param ServiceConfig $config
     */
    public function __construct($config)
    {
        $this->serviceConfig = $config;

        $this->shopConfig = Registry::get("oxConfigFile");
        $this->dbHandler = new DatabaseHandler($this->shopConfig);
    }

    /**
     * Starts installation of the shop.
     *
     * @param Request $request
     *
     * @throws \Exception
     */
    public function init($request)
    {
        if (!class_exists('\OxidEsales\Eshop\Setup\Setup')) {
            throw new \Exception("Shop Setup directory has to be present!");
        }

        $serialNumber = $request->getParameter('serial', false);
        $serialNumber = $serialNumber ? $serialNumber : $this->getDefaultSerial();

        $this->setupDatabase();

        if ($tempDir = $request->getParameter('tempDirectory')) {
            $this->insertConfigValue('string', 'sCompileDir', $tempDir);
        }
        $this->insertConfigValue('int', 'sOnlineLicenseNextCheckTime', time() + 25920000);

        if (!$request->getParameter('addDemoData')) {
            $this->insertInitialData();
        }

        $this->runShopMigrations();

        if ($request->getParameter('addDemoData')) {
            $this->insertDemoData();
        }

        $this->runViewsRegeneration();

        if ($request->getParameter('international', false)) {
            $this->convertToInternational();
        }

        $this->setConfigurationParameters();

        if ($this->getDbHandler()->getCharsetMode() == 'utf8') {
            $this->convertToUtf();
        }

        $this->setSerialNumber($serialNumber);

        if ($request->getParameter('turnOnVarnish', $this->getShopConfig()->turnOnVarnish)) {
            $this->turnVarnishOn();
        }

        $cache = new Cache();
        $cache->clearTemporaryDirectory();
    }

    /**
     * Sets up database.
     */
    public function setupDatabase()
    {
        $dbHandler = $this->getDbHandler();
        $dbHandler->query("alter schema character set latin1 collate latin1_general_ci");
        $dbHandler->query("set character set latin1");

        $dbHandler->query('drop database `' . $dbHandler->getDbName() . '`');
        $dbHandler->query('create database `' . $dbHandler->getDbName() . '` collate ' . $dbHandler->getCharsetMode() . '_general_ci');

        $baseEditionPathProvider = new EditionPathProvider(new EditionRootPathProvider(new EditionSelector(EditionSelector::COMMUNITY)));

        $dbHandler->import($baseEditionPathProvider->getDatabaseSqlDirectory() . "/database_schema.sql", 'latin1');
    }

    /**
     * Run migrations
     */
    protected function runShopMigrations()
    {
        $vendorDirectory = $this->getVendorDirectory();
        exec("{$vendorDirectory}/bin/oe-eshop-facts oe-eshop-db_migrate");
    }

    /**
     * Run views regeneration
     */
    protected function runViewsRegeneration()
    {
        $vendorDirectory = $this->getVendorDirectory();
        exec("{$vendorDirectory}/bin/oe-eshop-facts oe-eshop-db_views_regenerate");
    }

    /**
     * Get vendor directory
     *
     * @return string
     */
    protected function getVendorDirectory()
    {
        $testConfig = new TestConfig();
        $vendorDirectory = $testConfig->getVendorDirectory();

        return $vendorDirectory;
    }

    /**
     * Inserts initial data to shop.
     */
    public function insertInitialData()
    {
        $baseEditionPathProvider = new EditionPathProvider(new EditionRootPathProvider(new EditionSelector(EditionSelector::COMMUNITY)));
        $this->getDbHandler()->import($baseEditionPathProvider->getDatabaseSqlDirectory() . "/initial_data.sql", 'latin1');
    }

    /**
     * Inserts demo data to shop.
     */
    public function insertDemoData()
    {
        $this->getDbHandler()->import($this->getEditionPathProvider()->getDatabaseSqlDirectory() . "/demodata.sql", 'latin1');
    }

    /**
     * Convert shop to international.
     */
    public function convertToInternational()
    {
        $this->getDbHandler()->import($this->getEditionPathProvider()->getDatabaseSqlDirectory() . "/en.sql", 'latin1');
    }

    /**
     * Inserts missing configuration parameters
     */
    public function setConfigurationParameters()
    {
        $dbHandler = $this->getDbHandler();
        $sShopId = $this->getShopId();

        $dbHandler->query("delete from oxconfig where oxvarname in ('iSetUtfMode','blLoadDynContents','sShopCountry');");
        $dbHandler->query(
            "insert into oxconfig (oxid, oxshopid, oxvarname, oxvartype, oxvarvalue) values " .
            "('config1', '{$sShopId}', 'iSetUtfMode',       'str',  ENCODE('0', '{$this->getConfigKey()}') )," .
            "('config2', '{$sShopId}', 'blLoadDynContents', 'bool', ENCODE('1', '{$this->getConfigKey()}') )," .
            "('config3', '{$sShopId}', 'sShopCountry',      'str',  ENCODE('de','{$this->getConfigKey()}') )"
        );
    }

    /**
     * Adds serial number to shop.
     *
     * @param string $serialNumber
     */
    public function setSerialNumber($serialNumber = null)
    {
        if (class_exists('OxidEsales\EshopProfessional\Core\Serial')) {
            $dbHandler = $this->getDbHandler();

            $shopId = $this->getShopId();

            $serial = new Serial();
            $serial->setEd($this->getServiceConfig()->getShopEdition() == 'EE' ? 2 : 1);

            $serial->isValidSerial($serialNumber);

            $maxDays = $serial->getMaxDays($serialNumber);
            $maxArticles = $serial->getMaxArticles($serialNumber);
            $maxShops = $serial->getMaxShops($serialNumber);

            $dbHandler->query("update oxshops set oxserial = '{$serialNumber}'");
            $dbHandler->query("delete from oxconfig where oxvarname in ('aSerials','sTagList','IMD','IMA','IMS')");
            $dbHandler->query(
                "insert into oxconfig (oxid, oxshopid, oxvarname, oxvartype, oxvarvalue) values " .
                "('serial1', '{$shopId}', 'aSerials', 'arr', ENCODE('" . serialize(array($serialNumber)) . "','{$this->getConfigKey()}') )," .
                "('serial2', '{$shopId}', 'sTagList', 'str', ENCODE('" . time() . "','{$this->getConfigKey()}') )," .
                "('serial3', '{$shopId}', 'IMD',      'str', ENCODE('" . $maxDays . "','{$this->getConfigKey()}') )," .
                "('serial4', '{$shopId}', 'IMA',      'str', ENCODE('" . $maxArticles . "','{$this->getConfigKey()}') )," .
                "('serial5', '{$shopId}', 'IMS',      'str', ENCODE('" . $maxShops . "','{$this->getConfigKey()}') )"
            );
        }
    }

    /**
     * Converts shop to utf8.
     */
    public function convertToUtf()
    {
        $dbHandler = $this->getDbHandler();

        $rs = $dbHandler->query(
            "SELECT oxvarname, oxvartype, DECODE( oxvarvalue, '{$this->getConfigKey()}') AS oxvarvalue
                       FROM oxconfig
                       WHERE oxvartype IN ('str', 'arr', 'aarr')"
        );

        while ($aRow = mysqli_fetch_assoc($rs)) {
            if ($aRow['oxvartype'] == 'arr' || $aRow['oxvartype'] == 'aarr') {
                $aRow['oxvarvalue'] = unserialize($aRow['oxvarvalue']);
            }
            if (!empty($aRow['oxvarvalue']) && !is_int($aRow['oxvarvalue'])) {
                $this->updateConfigValue($aRow['oxid'], $this->stringToUtf($aRow['oxvarvalue']));
            }
        }

        // Change currencies value to same as after 4.6 setup because previous encoding break it.
        $shopId = 1;

        $query = "REPLACE INTO `oxconfig` (`OXID`, `OXSHOPID`, `OXMODULE`, `OXVARNAME`, `OXVARTYPE`, `OXVARVALUE`) VALUES
            ('3c4f033dfb8fd4fe692715dda19ecd28', $shopId, '', 'aCurrencies', 'arr', 0x4dbace2972e14bf2cbd3a9a45157004422e928891572b281961cdebd1e0bbafe8b2444b15f2c7b1cfcbe6e5982d87434c3b19629dacd7728776b54d7caeace68b4b05c6ddeff2df9ff89b467b14df4dcc966c504477a9eaeadd5bdfa5195a97f46768ba236d658379ae6d371bfd53acd9902de08a1fd1eeab18779b191f3e31c258a87b58b9778f5636de2fab154fc0a51a2ecc3a4867db070f85852217e9d5e9aa60507);";

        $dbHandler->query($query);
    }

    /**
     * Turns varnish on.
     */
    public function turnVarnishOn()
    {
        $dbHandler = $this->getDbHandler();

        $dbHandler->query("DELETE from oxconfig WHERE oxshopid = 1 AND oxvarname in ('iLayoutCacheLifeTime', 'blReverseProxyActive');");
        $dbHandler->query(
            "INSERT INTO oxconfig (oxid, oxshopid, oxvarname, oxvartype, oxvarvalue) VALUES
              ('35863f223f91930177693956aafe69e6', 1, 'iLayoutCacheLifeTime', 'str', 0xB00FB55D),
              ('dbcfca66eed01fd43963443d35b109e0', 1, 'blReverseProxyActive',  'bool', 0x07);"
        );
    }

    /**
     * @return oxConfigFile
     */
    protected function getShopConfig()
    {
        return $this->shopConfig;
    }

    /**
     * @return ServiceConfig
     */
    protected function getServiceConfig()
    {
        return $this->serviceConfig;
    }

    /**
     * @return DatabaseHandler
     */
    protected function getDbHandler()
    {
        return $this->dbHandler;
    }

    /**
     * Returns default demo serial number for testing.
     *
     * @return string|null
     */
    protected function getDefaultSerial()
    {
        if ($this->getServiceConfig()->getShopEdition() != 'CE') {
            $core = new Core();
            /** @var Setup $setup */
            $setup = $core->getInstance('Setup');
            return $setup->getDefaultSerial();
        }
    }

    /**
     * @return EditionPathProvider
     */
    protected function getEditionPathProvider()
    {
        if (is_null($this->editionPathProvider)) {
            $editionPathSelector = new EditionRootPathProvider(new EditionSelector());
            $this->editionPathProvider = new EditionPathProvider($editionPathSelector);
        }

        return $this->editionPathProvider;
    }

    /**
     * @return string
     */
    protected function getConfigKey()
    {
        $configKey = $this->getShopConfig()->getVar('sConfigKey');
        return $configKey ?: Config::DEFAULT_CONFIG_KEY;
    }

    /**
     * Returns shop id.
     *
     * @return string
     */
    private function getShopId()
    {
        return '1';
    }

    /**
     * Insert new configuration value to database.
     *
     * @param string $type
     * @param string $name
     * @param string $value
     */
    private function insertConfigValue($type, $name, $value)
    {
        $dbHandler = $this->getDbHandler();
        $shopId = 1;

        $dbHandler->query("DELETE from oxconfig WHERE oxvarname = '$name';");
        $dbHandler->query("REPLACE INTO `oxconfig` (`OXID`, `OXSHOPID`, `OXMODULE`, `OXVARNAME`, `OXVARTYPE`, `OXVARVALUE`) VALUES
            ('$name', $shopId, '', '$name', '$type', ENCODE('{$value}','{$this->getConfigKey()}'));");
        if ($shopId === 1) {
            $dbHandler->query("REPLACE INTO `oxconfig` (`OXID`, `OXSHOPID`, `OXMODULE`, `OXVARNAME`, `OXVARTYPE`, `OXVARVALUE`) VALUES
                ('${name}_subshop', 2, '', '$name', '$type', ENCODE('{$value}','{$this->getConfigKey()}'));");
        }
    }

    /**
     * Updates configuration value.
     *
     * @param string $id
     * @param string $value
     */
    private function updateConfigValue($id, $value)
    {
        $dbHandler = $this->getDbHandler();

        $value = is_array($value) ? serialize($value) : $value;
        $value = $dbHandler->escape($value);
        $dbHandler->query("update oxconfig set oxvarvalue = ENCODE( '{$value}','{$this->getConfigKey()}') where oxvarname = '{$id}';");
    }

    /**
     * Converts input string to utf8.
     *
     * @param string $input String for conversion.
     *
     * @return array|string
     */
    private function stringToUtf($input)
    {
        if (is_array($input)) {
            $temp = array();
            foreach ($input as $key => $value) {
                $temp[$this->stringToUtf($key)] = $this->stringToUtf($value);
            }
            $input = $temp;
        } elseif (is_string($input)) {
            $input = iconv('iso-8859-15', 'utf-8', $input);
        }

        return $input;
    }
}

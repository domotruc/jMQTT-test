<?php

use Facebook\WebDriver\WebDriverBy as By;
use Facebook\WebDriver\WebDriverSelect as Select;

require_once (__DIR__ . '/../vendor/autoload.php');
include_once 'MqttTestCase.php';

/**
 * Install the plugin
 * @author domotruc
 */
class tInstallTest extends MqttTestCase {

    /**
     * Check the plugin is not installed
     * @group install
     */
    public function testNotInstalled() {
        self::gotoPluginsMngt();
        self::assertElementNotFound(By::xpath("//span[text()='jMQTT']"), 'jMQTT is installed', 3);
    }
    
    /**
     * Install the plugin and go back to the Jeedom Plugin Management page.
     * @depends testNotInstalled
     * @group install
     */
    public function testInstall() {
        
        if ($_ENV['plugin_source'] == 'market') {
            self::$wd->findElement(By::className('displayStore'))->click();
            self::$wd->findElement(By::id('in_search'))->sendKeys('jMQTT');
            self::$wd->findElement(By::id('bt_search'))->click();
            self::$wd->findElement(By::xpath("//span[text()='jMQTT']"))->click();
    
            $this->waitElemIsClickable(By::linkText('Installer ' . $_ENV['plugin_version']))->click();
            $this->waitElemIsClickable(By::xpath("//button[@data-bb-handler='cancel']"))->click();
        }
        
        if ($_ENV['plugin_source'] == 'github') {
            $this->waitElemIsClickable(By::id('bt_addPluginFromOtherSource'))->click();
            (new Select(self::$wd->findElement(By::xpath("//select[@data-l1key='source']"))))->selectByValue('github');
            $this->waitElemIsClickable(By::xpath("//div[@class='repoSource repo_github']//input[@data-l1key='logicalId']"))->clear()->sendKeys('jMQTT');
            $this->waitElemIsClickable(By::xpath("//input[@data-l2key='user']"))->clear()->sendKeys('domotruc');
            $this->waitElemIsClickable(By::xpath("//input[@data-l2key='repository']"))->clear()->sendKeys('jMQTT');
            $this->waitElemIsClickable(By::xpath("//input[@data-l2key='version']"))->clear()->sendKeys($_ENV['plugin_version']);
            $this->waitElemIsClickable(By::id('bt_repoAddSaveUpdate'))->click();
            $this->waitElemIsClickable(By::id('div_repoAddAlert'), 'Timeout exceeded regarding installation of jMQTT', 30);
        }
        
        // Goto plugin management page
        self::$wd->navigate()->refresh();

        $this->assertCount(1, self::$wd->findElements(By::xpath("//div[@data-plugin_id='jMQTT']")), 'jMQTT is not installed');
    }

    /**
     * Configure the plugin and check deamon is OK
     * @depends testInstall
     */
    public function testConfigurePlugin() {
        $this->gotoPluginsMngt();
        $this->waitElemIsClickable(By::xpath("//div[@data-plugin_id='jMQTT']"))->click();

        // Activate the plugin if necessary
        $el = $this->waitElemIsVisible(By::xpath("//label[text()='Statut']//following-sibling::div//descendant::span"));
        if ($el->getText() != 'Actif')
            $this->waitElemIsClickable(By::xpath("//a[text()=' Activer']"))->click();

        sleep(2);
        
        // Log in debug mode
        $this->waitElemIsClickable(By::xpath("//input[@data-l2key='100']"))->click();
        $this->waitElemIsClickable(By::xpath("//a[@id='bt_savePluginLogConfig']"))->click();
        
        $dep = $this->waitElemIsVisible(By::xpath("//td[@class='dependancyState']//descendant::span"))->getText();
        if ($dep == "NOK") {
            $this->waitElemIsClickable(By::className('launchInstallPluginDependancy'))->click();
            for($i=1; $i<36 ; $i++) {
                sleep(5);
                print('.');
                $dep = self::$wd->findElement(By::xpath("//td[@class='dependancyState']//descendant::span"))->getText();
                if ($dep == 'OK')
                    break;
            }
        }
        $this->assertEquals('OK', $dep, 'dependancies are NOK');
    }

    /**
     * Test that the plugin does not contain any equipment
     * @depends testConfigurePlugin
     */
    public function testNoEqpt() {
        $this->gotoPluginMngt();
        $this->assertNoBroker();
    }
    
    /*
     * Activate and test jMQTT API
     */
//     public function testActivateAPI() {
        
//         // Desactivate the JSON RPC API in case it is activated
//         $this->setJsonRpcApi(false);
        
//         // API request should not return anything
//         $resp = self::$apiClient->sendRequest('ping');
//         $this->assertNull($resp);
        
//         // Activate the API
//         $this->setMqttApi(true);
        
//         // Send a ping to the API
//         $resp = self::$apiClient->sendRequest('ping', array());
//         $this->assertEquals("Vous n'êtes pas autorisé à effectuer cette action (JSON-RPC disable)", $resp['error']['message']);
        
//         // Activate the JSON RPC API
//         $this->setJsonRpcApi(true);
        
//         $resp = self::$apiClient->sendRequest('ping', array());
//         $this->assertEquals("pong", $resp['result']);
//     }
}

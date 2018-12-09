<?php

use Facebook\WebDriver\WebDriverBy as By;
use Facebook\WebDriver\WebDriverSelect as Select;

require_once (__DIR__ . '/../vendor/autoload.php');
include_once 'MqttTestCase.php';

class InstallTest extends MqttTestCase {

    /**
     * Check the plugin is not installed
     * @group install
     */
    public function testNotInstalled() {
        //self::gotoPluginMngt();
        self::assertElementNotFound(By::xpath("//span[text()='jMQTT']"), 'jMQTT is installed');
    }
    
    /**
     * Install the plugin and go back to the Jeedom Plugin Management page.
     * @depends testNotInstalled
     * @group install
     */
    public function testInstall() {
        //self::gotoPluginMngt();
        self::$wd->findElement(By::className('displayStore'))->click();
        self::$wd->findElement(By::id('in_search'))->sendKeys('jMQTT');
        self::$wd->findElement(By::id('bt_search'))->click();
        self::$wd->findElement(By::xpath("//span[text()='jMQTT']"))->click();

        $this->waitElemIsClickable(By::linkText('Installer ' . $_ENV['plugin_version']))->click();
        //$this->waitElemIsClickable(By::xpath("//button[text()='Annuler']"))->click();
        $this->waitElemIsClickable(By::xpath("//button[@data-bb-handler='cancel']"))->click();
        
        // Goto plugin management page
        self::$wd->navigate()->refresh();

        $this->assertCount(1, self::$wd->findElements(By::xpath("//div[@data-plugin_id='jMQTT']")), 'jMQTT is not installed');
    }

    /**
     * Configure the plugin and check deamon is OK
     */
    public function testConfigurePlugin() {
        $this->gotoPluginsMngt();
        self::$wd->findElement(By::xpath("//div[@data-plugin_id='jMQTT']"))->click();

        // Activate the plugin if necessary
        $el = self::$wd->findElement(By::xpath("//label[text()='Statut']//following-sibling::div//descendant::span"));
        if ($el->getText() != 'Actif')
            self::$wd->findElement(By::xpath("//a[text()=' Activer']"))->click();

        sleep(2);
        
        // Log in debug mode
        $this->waitElemIsClickable(By::xpath("//input[@data-l2key='100']"))->click();
        self::$wd->findElement(By::xpath("//a[@id='bt_savePluginLogConfig']"))->click();
        
        $dep = self::$wd->findElement(By::xpath("//td[@class='dependancyState']//descendant::span"))->getText();
        if ($dep == "NOK") {
            $this->waitElemIsClickable(By::className('launchInstallPluginDependancy'))->click();
            for($i=1; $i<36 ; $i++) {
                sleep(5);
                print('.');
                $dep = self::$wd->findElement(By::xpath("//td[@class='dependancyState']//descendant::span"))->getText();
                if ($dep == 'OK')
                    break;
            }
            $this->assertEquals('OK', $dep, 'dependancies are NOK');
        }
            
        self::$wd->findElement(By::xpath("//input[@data-l1key='mqttAdress']"))->clear()->sendKeys($_ENV['mosquitto_host']);
        self::$wd->findElement(By::xpath("//input[@data-l1key='mqttPort']"))->clear()->sendKeys($_ENV['mosquitto_port']);
        self::$wd->findElement(By::xpath("//input[@data-l1key='mqttId']"))->clear()->sendKeys($_ENV['mosquitto_client_id']);
        self::$wd->findElement(By::xpath("//input[@data-l1key='mqttTopic']"))->clear()->sendKeys('#');
        (new Select(self::$wd->findElement(By::xpath("//select[@data-l1key='api']"))))->selectByValue('disable');
        $this->waitForDeamonRestartDelay();
        self::$wd->findElement(By::id('bt_savePluginConfig'))->click();

        $el = self::$wd->findElement(By::xpath("//td[@class='deamonState']//descendant::span"));
        $this->assertEquals($el->getText(), 'OK', 'daemon is NOK');
    }

    /*
     *
     */
    public function testNoEqpt() {
        $this->gotoPluginMngt();
        $this->assertNoEqpt();
    }
    
    public function testActivateAPI() {
        
        // Desactivate the JSON RPC API in case it is activated
        $this->setJsonRpcApi(false);
        
        // API request should not return anything
        $resp = self::$apiClient->sendRequest('ping', array());
        $this->assertNull($resp);
        
        // Activate the API
        $this->setMqttApi(true);
        
        // Send a ping to the API
        $resp = self::$apiClient->sendRequest('ping', array());
        $this->assertEquals("Vous n'êtes pas autorisé à effectuer cette action (JSON-RPC disable)", $resp['error']['message']);
        
        // Activate the JSON RPC API
        $this->setJsonRpcApi(true);
        
        $resp = self::$apiClient->sendRequest('ping', array());
        $this->assertEquals("pong", $resp['result']);
    }
}

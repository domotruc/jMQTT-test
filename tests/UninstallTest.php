<?php


use Facebook\WebDriver\WebDriverBy as By;

require_once (__DIR__ . '/../vendor/autoload.php');
include_once 'MqttTestCase.php';

class UninstallTest extends MqttTestCase {

    public function testDesactivateJsonRpcAPI() {
        $this->setJsonRpcApi(false);
        
        $resp = self::$apiClient->sendRequest('ping');
        $this->assertEquals("Vous n'êtes pas autorisé à effectuer cette action (JSON-RPC disable)", $resp['error']['message']);
    }
    
    /**
     * Check the plugin is installed
     * @group uninstall
     */
    public function testInstalled() {
        $this->gotoPluginsMngt();
        $this->assertCount(1, self::$wd->findElements(By::xpath("//div[@data-plugin_id='jMQTT']")), 'jMQTT is not installed');
        
        //$this->waitElemIsClickable(By::xpath("//div[@data-plugin_id='jMQTT']"))->click();
        //$el = $this->waitElemIsVisible(By::xpath("//label[text()='Statut']//following-sibling::div//descendant::span"));
        //$this->assertEquals($el->getText(), 'Actif', 'plugin is not activated');
        //self::gotoPluginMngt();
    }
    
    /**
     * Uninstall the plugin and go back to the Jeedom Plugin Management page.
     * @depends testInstalled
     * @group uninstall
     */
    public function testUnistall() {
        $this->waitElemIsClickable(By::xpath("//div[@data-plugin_id='jMQTT']"))->click();
        $this->waitElemIsClickable(By::className('removePlugin'))->click();
        $this->waitElemIsClickable(By::xpath("//button[@data-bb-handler='confirm']"))->click();
        $this->waitElemIsVisible(By::className('pluginListContainer'));
        self::assertElementNotFound(By::xpath("//span[text()='jMQTT']"), 'jMQTT is installed');
        
        $this->assertTrue(function_exists('ssh2_connect'), 'SSH2 is not install. apt-get install php-ssh2 on debian system');
        
        $session = ssh2_connect($_ENV['ssh_host'], $_ENV['ssh_port']);
        ssh2_auth_password($session, $_ENV['ssh_username'], $_ENV['ssh_password']);
        
        $ver = $this->ssh2_exec($session, "php -version");
        $ver = substr($ver, 4, 1);
        if ($ver == 5) {
            $php_dev_lib = "php5-dev";
            $php_cli_ini = "/etc/php5/cli/php.ini";
            $php_apache_ini = "/etc/php5/apache2/php.ini";
        }
        elseif ($ver == 7) {
            $php_dev_lib = "php7.0-dev";
            $php_cli_ini = "/etc/php/7.0/cli/php.ini";
            $php_apache_ini = "/etc/php/7.0/apache2/php.ini";
        }
        else {
            $this->assertFalse(true, 'Inconsistent version of php');
        }
        
        $this->ssh2_exec($session, "sed -i.bak '/extension=mosquitto.so/d' " . $php_apache_ini, true);
        echo PHP_EOL . $this->ssh2_exec($session, "service reload apache2", true);
        $this->ssh2_exec($session, "sed -i.bak '/extension=mosquitto.so/d' " . $php_cli_ini, true);
        echo PHP_EOL . $this->ssh2_exec($session, "pecl uninstall Mosquitto-alpha", true);
        echo PHP_EOL . $this->ssh2_exec($session, "apt -y remove " . $php_dev_lib, true);
        echo PHP_EOL . $this->ssh2_exec($session, "apt -y remove mosquitto mosquitto-clients libmosquitto-dev", true);
        echo PHP_EOL . $this->ssh2_exec($session, "apt -y autoremove", true);
        
        ssh2_disconnect($session);
    }
}

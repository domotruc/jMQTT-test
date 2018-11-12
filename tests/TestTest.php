<?php
use Facebook\WebDriver\WebDriverBy as By;

require_once('vendor/autoload.php');
include_once 'MqttTestCase.php';

class UninstallTest extends MqttTestCase {
    
    // private static $eqptsRef;
    public static function setUpBeforeClass() {
    }
    
    
   
    /**
     * Check the plugin is installed
     */
    public function test () {

        $this->assertTrue(function_exists('ssh2_connect'), 'SSH2 is not install. apt-get install php-ssh2 on debian system');
        
        $session = ssh2_connect($_ENV['ssh_host'], 22);
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
        //$this->ssh2_exec($session, 

        //$result_dio = stream_get_contents($dio_stream);
//        $str = fgets(ssh2_fetch_stream($channel, SSH2_STREAM_STDIO), 8192); 
        //$ver = ssh2_exec($connection, "php_ver=`php -version`;echo \${php_ver:4:1}");
        //$stream = ssh2_exec($connection, "sed -i.bak '/extension=mosquitto.so/d' php.ini");
    }
}
    
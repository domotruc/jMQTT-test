<?php

use Facebook\WebDriver\WebDriverBy as By;
use Facebook\WebDriver\WebDriverSelect as Select;

use MqttGen\MqttGen;

require_once('vendor/autoload.php');
include_once 'MqttTestCase.php';
include_once 'MqttEqpts.class.php';

class RemoveEqptTest extends MqttTestCase {
      
    public function testRemoveEqpt() {
        $this->deleteAllEqpts();
    }
}

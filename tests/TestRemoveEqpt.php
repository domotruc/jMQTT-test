<?php

require_once (__DIR__ . '/../vendor/autoload.php');
include_once 'MqttTestCase.php';
include_once 'MqttEqpts.class.php';

class TestRemoveEqpt extends MqttTestCase {
      
    public function testRemoveEqpt() {
        $this->deleteAllEqpts();
    }
}

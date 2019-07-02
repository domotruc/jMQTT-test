<?php

require_once (__DIR__ . '/../vendor/autoload.php');
include_once 'MqttTestCase.php';
include_once 'MqttEqpts.class.php';

/**
 * Brokers creation and test
 * @author domotruc
 */
class tCreateBrokersTest extends MqttTestCase {
    
    public static function setUpBeforeClass() {
        parent::setUpBeforeClass();
    }
    
    public function testNoBroker() {
        $this->deleteAllBrokers();
    }
    
    /**
     * @depends testNoBroker
     */
    public function testBrokerCreation() {
        $this->addBrokers();
        $eqptsRef = new MqttEqpts($this);
        $eqptsRef->assert();
    }
}

<?php

require_once (__DIR__ . '/../vendor/autoload.php');
include_once 'MqttTestCase.php';
include_once 'MqttEqpts.class.php';

use MqttPlay\MqttPlay;

class TestLongCmdName extends MqttTestCase {

    // private static $eqptsRef;
    public static function setUpBeforeClass() {
        parent::setUpBeforeClass();
    }

    public function testActivateAPI() {
        $this->activateAndAssertAPI();
    }

    /**
     *
     * @depends testActivateAPI
     */
    public function testEqptAtStart() {
        $this->gotoPluginMngt();

        /* @var MqttEqpts $eqptsRef */
        $eqptsRef = new MqttEqpts($this, self::$apiClient, true);
        $eqptsRef->assert(true);
       
        return $eqptsRef;
    }

    /**
     * @depends testEqptAtStart
     * @param array $eqptsRef
     */
    public function testShortCmdName($eqptsRef) {
        $mqttPlay = new MqttPlay(__DIR__ . '/long_cmd_name.txt', false, ' ', $_ENV['mosquitto_host'], $_ENV['mosquitto_port']);

        $eqptName = 'N';
        
        // Delete the equipment if it exists
        $this->disableIncludeMode();
        $eqptsRef->deleteFromInterface($eqptName);

        // Create the N equipment manually
        $msg = $mqttPlay->nextMessage(false);
        $topic = substr($msg[MqttPlay::S_TOPIC], 0, strrpos($msg[MqttPlay::S_TOPIC], '/')) . '/#';
        $eqptsRef->addFromInterface($eqptName, true, $topic);
        
        $this->gotoPluginMngt();
        $eqptsRef->assert(true);
        
        while (($msg = $mqttPlay->nextMessage()) != null) {
            $eqptsRef->setCmdInfo($eqptName, $msg[MqttPlay::S_TOPIC], $msg[MqttPlay::S_PAYLOAD]);
            $eqptsRef->assert(false);
        }
        
        return $eqptsRef;
    }
    
    /**
     * @depends testShortCmdName
     * @param array $eqptsRef
     */
    public function testLongCmdName($eqptsRef) {
        $mqttPlay = new MqttPlay(__DIR__ . '/long_cmd_name.txt', false, ' ', $_ENV['mosquitto_host'], $_ENV['mosquitto_port']);
        
        $eqptName = 'N';
        
        // Delete the equipment created in the previous test
        $eqptsRef->deleteFromInterface($eqptName);
        $this->gotoPluginMngt();
        $eqptsRef->assert(true);
        
        $is_first = true;
        $this->enableIncludeMode();
        $eqptsRef->addFromMqttBroker();
        while (($msg = $mqttPlay->nextMessage()) != null) {
            if ($is_first) {
                $eqptsRef->add($eqptName);
                $this->disableIncludeMode(); // disabled here to avoid include the jmqtt_test equipment
                $this->waitEquipmentInclusion($eqptName);
                $eqptsRef->assert(true);
                $is_first = false;
            }
            
            $eqptsRef->setCmdInfo($eqptName, $msg[MqttPlay::S_TOPIC], $msg[MqttPlay::S_PAYLOAD]);
            $eqptsRef->assert(false);
        }
        
        return $eqptsRef;
    }
    
}
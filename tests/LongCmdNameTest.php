<?php

require_once (__DIR__ . '/../vendor/autoload.php');
include_once 'MqttTestCase.php';
include_once 'MqttEqpts.class.php';

use Facebook\WebDriver\WebDriverBy as By;
use MqttPlay\MqttPlay;

class LongCmdNameTest extends MqttTestCase {

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
        $eqptsRef->delete($eqptName);

        // Create the N equipment manually
        $msg = $mqttPlay->nextMessage(false);
        $topic = substr($msg[MqttPlay::S_TOPIC], 0, strrpos($msg[MqttPlay::S_TOPIC], '/')) . '/#';
        
        $this->waitElemIsClickable(By::xpath("//div[@data-action='add']"))->click();
        $this->waitElemIsVisible(By::xpath("//input[contains(@class,'bootbox-input-text')]"))->sendKeys($eqptName);
        $this->waitElemIsClickable(By::xpath("//button[@data-bb-handler='confirm']"))->click();
        
        $this->waitElemIsClickable(By::xpath("//input[@data-l1key='isEnable']"))->click();
        $this->waitElemIsVisible(By::xpath("//input[@data-l2key='topic']"))->sendKeys($topic);
        $this->waitElemIsClickable(By::xpath("//a[@data-action='save']"))->click();
        
        $eqptsRef->add($eqptName);
        $eqptsRef->setParameters($eqptName, array('logicalId' => $topic, 'configuration' => array('topic' => $topic)));
        
        $this->gotoPluginMngt();
        $eqptsRef->assert(true);
        
        while (($msg = $mqttPlay->nextMessage()) != null) {
            $eqptsRef->setCmd($eqptName, $msg[MqttPlay::S_TOPIC], $msg[MqttPlay::S_PAYLOAD]);
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
        $eqptsRef->delete($eqptName);
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
            
            $eqptsRef->setCmd($eqptName, $msg[MqttPlay::S_TOPIC], $msg[MqttPlay::S_PAYLOAD]);
            $eqptsRef->assert(false);
        }
        
        return $eqptsRef;
    }
    
}
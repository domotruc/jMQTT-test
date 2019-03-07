<?php

require_once (__DIR__ . '/../vendor/autoload.php');
include_once 'MqttTestCase.php';
include_once 'MqttEqpts.class.php';

use MqttGen\MqttGen;

class FirstEqptTest extends MqttTestCase {
   
    private const EQPT = 'ebusd';
    
    public function testActivateAPI() {
        $this->activateAndAssertAPI();
    }
    
    public function testNoEqpt() {
        $this->deleteAllEqpts();
        return new MqttEqpts($this, self::$apiClient);
    }
        
    /**
     * @depends testActivateAPI
     * @depends testNoEqpt
     */
    public function testEqpt($nu, MqttEqpts $eqptsRef) {
        $this->gotoPluginMngt();

        $mqttgen = new MqttGen(__DIR__ . '/' . self::EQPT . '.json');
        
        // Check eqpts at start : no equipment expected
        $eqptsRef->assert();
        
        // Check no eqpt is created if include mode is disable
        self::assertIncludeMode(false);
        $msg = $mqttgen->nextMessage();
        $eqptsRef->assert();
        
        // Enable include mode and check eqpts creation
        // 2 times to test that the request has created an equipment
        self::assertIncludeMode(false)->click();
        self::assertIncludeMode(true);
        $eqptsRef->addFromMqttBroker();
        self::$apiClient->setMqttEqpts($eqptsRef);
        $eqptsRef->setCmdInfo($_ENV['mosquitto_client_id'], $_ENV['mosquitto_client_id'] . '/status', 'online');

        // Do not check the html on purpose (because the API equipment is added between the 
        // the API answer is returned and the check)
        $eqptsRef->assert(false);
        
        // Add the API eqpt
        $eqptsRef->add(MqttApiClient::S_CLIENT_ID);

        // First ebusd message
        $eqptsRef->add(self::EQPT);
        $msg = $mqttgen->nextMessage();
        $eqptsRef->setCmdFromMsg(self::EQPT, $msg);
        $this->waitEquipmentInclusion(self::EQPT);
        $eqptsRef->assert();

        // Inhibit auto command adding and check next message is ignored
        $this->gotoEqptPage(self::EQPT);
        $eqptsRef->setAutoCmdAdding(self::EQPT, false);
        $msg = $mqttgen->nextMessage();
        $eqptsRef->assert(false);

        // Back to plugin equipment cards page to check card style depending on auto add command status
        $this->gotoPluginMngt();
        $eqptsRef->assert();
        
        // Enable auto command adding and check next message is taken into account
        $this->gotoEqptPage(self::EQPT);
        $eqptsRef->setAutoCmdAdding(self::EQPT, true);
        $msg = $mqttgen->nextMessage();
        $eqptsRef->setCmdFromMsg(self::EQPT, $msg);
        $eqptsRef->assert(false);
        
        // Back to plugin equipment cards page to disable equipement include mode
        $this->gotoPluginMngt();
        self::assertIncludeMode(true)->click();
        self::assertIncludeMode(false);
        
        for($i=0 ; $i<50 ; $i++) {
            $msg = $mqttgen->nextMessage();
            $eqptsRef->setCmdFromMsg(self::EQPT, $msg);
            $eqptsRef->assert(false);
        }
    }
}

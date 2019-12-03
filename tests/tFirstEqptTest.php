<?php
require_once (__DIR__ . '/../vendor/autoload.php');
include_once 'MqttTestCase.php';
include_once 'MqttEqpts.class.php';

use MqttGen\MqttGen;

class tFirstEqptTest extends MqttTestCase {

    private const EQPT = 'ebusd entrée n°1';

    public function testNoEqpt() {
        $this->gotoPluginMngt();
        $eqptsRef = new MqttEqpts($this, true);
        $eqptsRef->deleteAllEqpts();
        return $eqptsRef;
    }

    /**
     *
     * @depends testNoEqpt
     */
    public function testEqpt(MqttEqpts $eqptsRef) {

        $mqttgen = array();
        $brokers = array_keys($_ENV['brokers']);
        foreach ($brokers as $bname) {
            
            $mqttgen[$bname] = new MqttGen(__DIR__ . '/' . self::EQPT . '.json',
                array(
                    'host' => $_ENV['brokers'][$bname]['mosquitto_host'],
                    'port' => $_ENV['brokers'][$bname]['mosquitto_port']
                ));
            
            // Check eqpts at start
            $eqptsRef->assert();
           
            // Check no eqpt is created if include mode is disable
            self::assertIncludeMode($bname, false);
            $mqttgen[$bname]->nextMessage();
            $eqptsRef->assert();
    
            // Enable include mode and check eqpts creation
            $eqptsRef->setIncludeMode($bname, true);
            $eqptsRef->addFromMqttBroker($bname);
            $eqptsRef->add($bname, MqttApiClient::S_CLIENT_ID, true);
            $this->waitEquipmentInclusion($bname, MqttApiClient::S_CLIENT_ID);
            $eqptsRef->assert(MqttEqpts::API_MQTT, $bname, false);
    
            // First ebusd message
            $eqptsRef->add($bname, self::EQPT, true);
            $msg = $mqttgen[$bname]->nextMessage();
            $cmd = $eqptsRef->setCmdFromMsg($bname, self::EQPT, $msg);
            $this->assertDivAlertNewCmdMsg(self::EQPT, $cmd->getName());
            $this->waitEquipmentInclusion($bname, self::EQPT);
            $eqptsRef->assert();
    
            // Inhibit auto command adding and check next message is ignored
            $this->gotoEqptPage($bname, self::EQPT);
            $eqptsRef->setConfiguration_ui($bname, self::EQPT, MqttEqpts::CONF_AUTO_ADD_CMD, false);
            $msg = $mqttgen[$bname]->nextMessage();
            $eqptsRef->assert(MqttEqpts::API_MQTT, $bname, false);
    
            // // Back to plugin equipment cards page to check card style depending on auto add command status
            $this->gotoPluginMngt();
            $eqptsRef->assert();
    
            // Enable auto command adding and check next message is taken into account
            $this->gotoEqptPage($bname, self::EQPT);
            $eqptsRef->setConfiguration_ui($bname, self::EQPT, MqttEqpts::CONF_AUTO_ADD_CMD, true);
            $msg = $mqttgen[$bname]->nextMessage();
            $cmd = $eqptsRef->setCmdFromMsg($bname, self::EQPT, $msg);
            $this->assertDivAlertNewCmdMsg(self::EQPT, $cmd->getName());
            $eqptsRef->assert(MqttEqpts::API_MQTT, $bname, false);
    
            // // Back to plugin equipment cards page to disable equipement include mode
            $this->gotoPluginMngt();
            $eqptsRef->setIncludeMode($bname, false);
        }

        $broks = $brokers;
        for($i=0 ; $i<20 ; $i++, next($broks)) {
            foreach ($brokers as $bname) {
                $msg = $mqttgen[$bname]->nextMessage();
                $eqptsRef->setCmdFromMsg($bname, self::EQPT, $msg);
            }
            $eqptsRef->assert(MqttEqpts::API_MQTT,
                current($broks) === false ? reset($broks) : current($broks),
                false);
        }
    }
}

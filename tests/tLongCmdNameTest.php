<?php

require_once (__DIR__ . '/../vendor/autoload.php');
include_once 'MqttTestCase.php';
include_once 'MqttEqpts.class.php';

use MqttPlay\MqttPlay;

class tLongCmdNameTest extends MqttTestCase {

    private const EQPT = 'N';
    
    public function testEqptAtStart() {
        $this->gotoPluginMngt();
        
        /* @var MqttEqpts $eqptsRef */
        $eqptsRef = new MqttEqpts($this, true);
        $eqptsRef->assert();
       
        return $eqptsRef;
    }

    /**
     * @depends testEqptAtStart
     * @param array $eqptsRef
     */
    public function testShortCmdName($eqptsRef) {
        
        $bname = 'host';
        $mqttPlay = new MqttPlay(__DIR__ . '/long_cmd_name.txt', false, ' ',
            $_ENV['brokers'][$bname]['mosquitto_host'], $_ENV['brokers'][$bname]['mosquitto_port']);

        // Delete the equipment if it exists
        $this->assertIncludeMode($bname, false);
        $eqptsRef->deleteFromInterface($bname, self::EQPT);

        // Create the N equipment manually
        $msg = $mqttPlay->nextMessage(false);
        $topic = substr($msg[MqttPlay::S_TOPIC], 0, strrpos($msg[MqttPlay::S_TOPIC], '/')) . '/#';
        $eqptsRef->addFromInterface($bname, self::EQPT, true, $topic);
        
        $this->gotoPluginMngt();
        $eqptsRef->assert();
        
        while (($msg = $mqttPlay->nextMessage()) != null) {
            $eqptsRef->setCmdInfo($bname, self::EQPT, $msg[MqttPlay::S_TOPIC], $msg[MqttPlay::S_PAYLOAD]);
            $eqptsRef->assert(MqttEqpts::API_MQTT, $bname, false);
        }
        
        return $eqptsRef;
    }
    
    /**
     * @depends testShortCmdName
     * @param array $eqptsRef
     */
    public function testLongCmdName($eqptsRef) {
        
        $bname = 'local';
        $mqttPlay = new MqttPlay(__DIR__ . '/long_cmd_name.txt', false, ' ',
            $_ENV['brokers'][$bname]['mosquitto_host'], $_ENV['brokers'][$bname]['mosquitto_port']);
        
        // Delete the equipment if it exists
        $this->assertIncludeMode($bname, false);
        $eqptsRef->deleteFromInterface($bname, self::EQPT);
        
        $is_first = true;
        $eqptsRef->setIncludeMode($bname, true);
        //$eqptsRef->addFromMqttBroker();
        while (($msg = $mqttPlay->nextMessage()) != null) {
            if ($is_first) {
                $eqptsRef->add($bname, self::EQPT, true);
                $this->waitEquipmentInclusion($bname, self::EQPT);
                $eqptsRef->setIncludeMode($bname, false); // disabled here to avoid include the jmqtt_test equipment
                $eqptsRef->assert();
                $is_first = false;
            }
            
            $eqptsRef->setCmdInfo($bname, self::EQPT, $msg[MqttPlay::S_TOPIC], $msg[MqttPlay::S_PAYLOAD]);
            $eqptsRef->assert(MqttEqpts::API_MQTT, $bname, false);
        }
        
        return $eqptsRef;
    }  
}
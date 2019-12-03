<?php

require_once (__DIR__ . '/../vendor/autoload.php');
include_once 'MqttTestCase.php';
include_once 'MqttEqpts.class.php';
include_once 'MqttCapture.class.php';

use Facebook\WebDriver\WebDriverBy as By;
use Facebook\WebDriver\WebDriverSelect as Select;
use MqttPlay\MqttPlay;
use MqttGen\MqttGen;
use Bluerhinos\phpMQTT;

class tJsonTest extends MqttTestCase {

    // ugly name to test support of utf8 mqtt topics
    private const EQPT = 'json $£ê n°1';
    
    public function testEqptAtStart() {
        $this->gotoPluginMngt();
        
        /* @var MqttEqpts $eqptsRef */
        $eqptsRef = new MqttEqpts($this, true);
        $eqptsRef->assert(MqttEqpts::API_JSON_RPC);
        
        return $eqptsRef;
    }
    
    /**
     * @depends testEqptAtStart
     * @param MqttEqpts $eqptsRef
     */
    public function testJsonEqpt($eqptsRef) {
        
        //foreach (array_keys($_ENV['brokers']) as $bname) {
        foreach (array('host') as $bname) {
                
            $mqttButtonPlayer = new MqttPlay(
                array(
                    array('00:00:00.000', self::EQPT . '/button', '{"linkquality":80,"battery":90,"voltage":2990,"click":"single"}'),
                    array('00:00:00.000', self::EQPT . '/button', '{"linkquality":83,"battery":88,"voltage":2989}'),
                    array('00:00:00.000', self::EQPT . '/button', '{"linkquality":80,"battery":93,"voltage":2988,"click":"single"}'),
                    array('00:00:00.000', self::EQPT . '/button', '{"linkquality":80,"battery":93,"voltage":2988,"click":null}'),
                    array('00:00:00.000', self::EQPT . '/button', '{"linkquality":80,"battery":93,"voltage":2988,"click":true}'),
                    array('00:00:00.000', self::EQPT . '/button', '{"linkquality":80,"battery":93,"voltage":2988,"click":false}')
                ),
                false, ' ',
                $_ENV['brokers'][$bname]['mosquitto_host'], $_ENV['brokers'][$bname]['mosquitto_port']);
            
            $mqttLampPlayer = new MqttGen(__DIR__ . '/' . 'jsontest.json',
                array(
                    'host' => $_ENV['brokers'][$bname]['mosquitto_host'],
                    'port' => $_ENV['brokers'][$bname]['mosquitto_port']
                ));
            
            $lampJsonCmds = array(
                self::EQPT . '/0xd0cf5efffeeaa322{state}' => array('name' => 'lamp state', 'inds' => array('state')), 
                self::EQPT . '/0xd0cf5efffeeaa322{brightness}' => array('name' => 'lamp brightness', 'inds' => array('brightness')),
                self::EQPT . '/0xd0cf5efffeeaa322{color}{y}' => array('name' => 'color_y', 'inds' => array('color', 'y')) 
            );
            
            $buttonJsonCmds = array(
                self::EQPT . '/button{battery}' => array('name' => 'button battery', 'inds' => array('battery')),
                self::EQPT . '/button{click}' => array('name' => 'button click', 'inds' => array('click'))
            );
            
            // Delete the equipment if it exists
            $this->assertIncludeMode($bname, false);
            $eqptsRef->deleteFromInterface($bname, self::EQPT);
            
            // Create the equipement and check all equipments
            $eqptsRef->addFromInterface($bname, self::EQPT, true, self::EQPT . '/#');
            $this->gotoEqptTab(MqttTestCase::TAB_CMD);
            $eqptsRef->assert(MqttEqpts::API_JSON_RPC, null, false);

            // Add the first JSON info command
            $lampMsg = $mqttLampPlayer->nextMessage();
            $cmd = $eqptsRef->setCmdFromMsg($bname, self::EQPT, $lampMsg);
            $this->waitCmdInclusion(self::EQPT, $cmd->getName());
            $eqptsRef->assert(MqttEqpts::API_JSON_RPC, null, false);
            $eqptsRef->assertCmdPanel($bname, self::EQPT);
            
            // Add the second JSON info command
            $buttonMsg = $mqttButtonPlayer->nextMessage();
            $cmd = $eqptsRef->setCmdFromMsg($bname, self::EQPT, $buttonMsg);
            $this->waitCmdInclusion(self::EQPT, $cmd->getName());
            $eqptsRef->assert(MqttEqpts::API_JSON_RPC, null, false);
            $eqptsRef->assertCmdPanel($bname, self::EQPT);
            
            // Goto JSON tab and check the commands displayed
            $this->setCmdView(self::VIEW_JSON);
            $eqptsRef->assertCmdPanel($bname, self::EQPT);
            
            // Creates JSON lamp commands
            $cnames = array();
            foreach ($lampJsonCmds as $topic => $jsonCmd) {
                $eqptsRef->addJsonCmd_ui($bname, self::EQPT, $topic,
                    self::get_array_value(json_decode($lampMsg[MqttGen::S_PAYLOAD], true), $jsonCmd['inds']),
                    $jsonCmd['name'], false);
                $cnames[] = $jsonCmd['name'];
            }

            // Creates JSON button commands
            foreach ($buttonJsonCmds as $topic => $jsonCmd) {
                $save = self::endKey($buttonJsonCmds) == $topic ? true : false;
                $eqptsRef->addJsonCmd_ui($bname, self::EQPT, $topic,
                    self::get_array_value(json_decode($buttonMsg[MqttGen::S_PAYLOAD], true), $jsonCmd['inds']),
                    $jsonCmd['name'], $save);
                $cnames[] = $jsonCmd['name'];
            }
            $this->waitCmdInclusion(self::EQPT, $cnames, false);
            $eqptsRef->assert(MqttEqpts::API_JSON_RPC, null, false);
            $eqptsRef->assertCmdPanel($bname, self::EQPT);
            
            // Next messages
            $lampMsg = $mqttLampPlayer->nextMessage();
            $eqptsRef->setCmdFromJsonMsg($bname, self::EQPT, $lampMsg);
            $buttonMsg = $mqttButtonPlayer->nextMessage();
            $eqptsRef->setCmdFromJsonMsg($bname, self::EQPT, $buttonMsg);
            $eqptsRef->assert(MqttEqpts::API_JSON_RPC, null, false);
            $eqptsRef->assertCmdPanel($bname, self::EQPT);
            
            // Goto JSON view and check the commands displayed
            $this->setCmdView(self::VIEW_JSON);
            $eqptsRef->assertCmdPanel($bname, self::EQPT);
            
            // Next messages
            $lampMsg = $mqttLampPlayer->nextMessage();
            $eqptsRef->setCmdFromJsonMsg($bname, self::EQPT, $lampMsg);
            $buttonMsg = $mqttButtonPlayer->nextMessage();
            $eqptsRef->setCmdFromJsonMsg($bname, self::EQPT, $buttonMsg);
            $eqptsRef->assert(MqttEqpts::API_JSON_RPC, null, false);
            $eqptsRef->assertCmdPanel($bname, self::EQPT, false);
            
            // Move color{y}
            $this->setCmdView(self::VIEW_CLASSIC);
            $eqptsRef->assertCmdPanel($bname, self::EQPT);
            $eqptsRef->moveCmd_ui($bname, self::EQPT, 'color_y', '0xd0cf5efffeeaa322');
            $eqptsRef->assert(MqttEqpts::API_JSON_RPC, null, false);
            $eqptsRef->assertCmdPanel($bname, self::EQPT);
            
            // Check the JSON view
            $this->setCmdView(self::VIEW_JSON);
            $eqptsRef->assertCmdPanel($bname, self::EQPT);
            
            // Next message to test null payload
            $buttonMsg = $mqttButtonPlayer->nextMessage();
            $this->assertEquals(null, json_decode($buttonMsg[MqttPlay::S_PAYLOAD], true)['click']);
            $eqptsRef->setCmdFromJsonMsg($bname, self::EQPT, $buttonMsg);
            $eqptsRef->assert(MqttEqpts::API_JSON_RPC, null, false);
            $eqptsRef->assertCmdPanel($bname, self::EQPT);

            // Next message to test true payload
            $buttonMsg = $mqttButtonPlayer->nextMessage();
            $this->assertEquals(true, json_decode($buttonMsg[MqttPlay::S_PAYLOAD], true)['click']);
            $eqptsRef->setCmdFromJsonMsg($bname, self::EQPT, $buttonMsg);
            $eqptsRef->assert(MqttEqpts::API_JSON_RPC, null, false);
            $eqptsRef->assertCmdPanel($bname, self::EQPT);
            
            // Next message to test false payload
            $buttonMsg = $mqttButtonPlayer->nextMessage();
            $this->assertEquals(false, json_decode($buttonMsg[MqttPlay::S_PAYLOAD], true)['click']);
            $eqptsRef->setCmdFromJsonMsg($bname, self::EQPT, $buttonMsg);
            $eqptsRef->assert(MqttEqpts::API_JSON_RPC, null, false);
            $eqptsRef->assertCmdPanel($bname, self::EQPT);
            
            // Delete the 
//             $eqptsRef->deleteCmd_ui($bname, self::EQPT, $cmd->getName());
//             $eqptsRef->assert(MqttEqpts::API_JSON_RPC, null, false);
//             $eqptsRef->assertCmdPanel($bname, self::EQPT);
//             return;
            
            
        }
    }
    
    private static function get_array_value($array, $indexes) {
        if (count($array) == 0 || count($indexes) == 0) {
            return null;
        }
        
        $index = array_shift($indexes);
        if(!array_key_exists($index, $array)){
            return null;
        }
        
        $value = $array[$index];
        if (count($indexes) == 0) {
            return $value;
        }
        else {
            return self::get_array_value($value, $indexes);
        }
    }
    
    /**
     * Returns the key at the end of the array
     * @param array $array
     * @return mixed
     */
    private static function endKey(array $array){
        end($array);
        return key($array);
    }
}
            
<?php

require_once (__DIR__ . '/../vendor/autoload.php');
include_once 'MqttTestCase.php';
include_once 'MqttEqpts.class.php';
include_once 'MqttCapture.class.php';

use Facebook\WebDriver\WebDriverBy as By;
use Facebook\WebDriver\WebDriverSelect as Select;
use MqttPlay\MqttPlay;
use Bluerhinos\phpMQTT;

class tActionsTest extends MqttTestCase {

    private const EQPT = 'Actions';
    
    private $cmd_ref = array(
        'other_string' => array('subtype' => 'other','value' => 'online','expected_published_value' => 'online',
            'expected_internal_value' => null),
        'other_int' => array('subtype' => 'other','value' => 40,'expected_published_value' => 40,
            'expected_internal_value' => null),
        'other_json' => array('subtype' => 'other',
            'value' => '{"name": "setpoint", "value": #[Aucun][Actions][setpoint]#}',
            'expected_published_value' => '{"name": "setpoint", "value": 25}','expected_internal_value' => null),
        'slider' => array('subtype' => 'slider','value' => '#slider#','options' => array('slider' => 17),
            'expected_published_value' => 17,'expected_internal_value' => 17),
        'message' => array('subtype' => 'message','value' => '{"setpoint": "#title#", "value": #message#}',
            'options' => array('title' => 'ecs','message' => 50),
            'expected_published_value' => '{"setpoint": "ecs", "value": 50}',
            'expected_internal_value' => null));     
        
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
    public function testCreateActionEqpt($eqptsRef) {
        
        foreach (array_keys($_ENV['brokers']) as $bname) {
            
            $mqttPlay = new MqttPlay(array(array('00:00:00.000', 'actions/setpoint', 25)), false, ' ',
                $_ENV['brokers'][$bname]['mosquitto_host'], $_ENV['brokers'][$bname]['mosquitto_port']);
            
            // Delete the equipment if it exists
            $this->assertIncludeMode($bname, false);
            $eqptsRef->deleteFromInterface($bname, self::EQPT);
            
            // Create the equipement and check all equipments
            $eqptsRef->addFromInterface($bname, self::EQPT, true, 'actions/setpoint');
            $t = time();
            $this->gotoEqptTab(MqttTestCase::TAB_CMD);
            $eqptsRef->assert(MqttEqpts::API_JSON_RPC, null, false);
            $eqptsRef->assertLastCommunication($bname, self::EQPT, $t);
    
            // Add the setpoint info command
            sleep(2); // to check last communication date
            $eqptsRef->assertLastCommunication($bname, self::EQPT, $t);
            $msg = $mqttPlay->nextMessage();
            $t = time();
            $eqptsRef->setCmdInfo($bname, self::EQPT, $msg[MqttPlay::S_TOPIC], $msg[MqttPlay::S_PAYLOAD]);
            $eqptsRef->assert(MqttEqpts::API_JSON_RPC, null, false);
            $eqptsRef->assertLastCommunication($bname, self::EQPT, $t);
            
            // Wait for the command to be added on the page
            $by = By::xpath("//table[@id='table_cmd']/tbody/tr");
            for($i=0 ; $i<100 ; $i++) {
                $els = self::$wd->findElements($by);
                if (count($els) == 1)
                    break;
                    usleep(100000);
            }
            $this->assertCount(1, $els);
            
            // Refresh the page
            $this->waitElemIsClickable(By::xpath("//a[@data-action='refreshPage']"))->click();
            
            // Add all commands
            $is_first = true;
            foreach ($this->cmd_ref as $name => &$action) {
                
                $this->waitElemIsClickable(By::xpath("//a[@id='bt_addMQTTAction']"))->click();
                $this->waitElemIsClickable(By::xpath("//table[@id='table_cmd']/tbody/tr[last()]//input[@data-l1key='name']"))->sendKeys($name);
                (new Select($this->waitElemIsClickable(By::xpath("//table[@id='table_cmd']/tbody/tr[last()]//select[@data-l1key='subType']"))))->selectByValue($action['subtype']);
                $action['topic'] = 'actions/' . $action['subtype'];
                $this->waitElemIsClickable(By::xpath("//table[@id='table_cmd']/tbody/tr[last()]//textarea[@data-l2key='topic']"))->sendKeys($action['topic']);
                $this->waitElemIsClickable(By::xpath("//table[@id='table_cmd']/tbody/tr[last()]//textarea[@data-l2key='request']"))->sendKeys($action['value']);
                
                // Check that a confirmation dialog is shown if we ask for refreshing the page before saving
                if ($is_first) {
                    $this->waitElemIsClickable(By::xpath("//a[@data-action='refreshPage']"))->click();
                    $this->waitElemIsClickable(By::xpath("//button[@data-bb-handler='cancel']"))->click();
                    // sleep to let the time to the dialog box to disappear. Otherwise we get the error msg that
                    // it obscures the save button
                    usleep(200000);
                    $is_first = false;
                }
                
                $this->saveEqLogic();
                
                $action['id'] = $this->waitElemIsClickable(By::xpath("//table[@id='table_cmd']/tbody/tr[last()]"))->getAttribute('data-cmd_id');
                $eqptsRef->setCmdAction($bname, self::EQPT, $action['topic'], $name, $action['subtype']);
            }
            
            // Check all equipments (including related commands)
            $eqptsRef->assert(MqttEqpts::API_MQTT, $bname, false);
                           
            // Execute each command through the API and check the received messages
            foreach ($this->cmd_ref as $name => &$action) {
                $msg = array('topic' => $action['topic']);
                if (array_key_exists('options', $action)) {
                    $eqptsRef->sendMqttRequest($bname, 'cmd::execCmd', array('id' => $action['id'], 'options' => $action['options']), $msg);
                }
                else {
                    $eqptsRef->sendMqttRequest($bname, 'cmd::execCmd', array('id' => $action['id']), $msg);
                }
                $this->assertEquals($action['expected_published_value'], $msg['payload']);
                $eqptsRef->setCmdAction($bname, self::EQPT, $action['topic'], $name, $action['subtype'], $action['expected_internal_value']);
            }
            
            // Execute one command through the user interface to check last communication date update
            sleep(2);
            $action = $this->cmd_ref['other_int'];
            $mqttCapture = new MqttCapture($this, $bname, $action['topic']);
            $this->waitElemIsClickable(By::xpath("//table[@id='table_cmd']/tbody/tr[@data-cmd_id='" . $action['id'] . "']//a[@data-action='test']"))->click();
            $t = time();
            $mqttCapture->receive(10)->assertHasTopic($action['topic'])->reset();
            $eqptsRef->assertLastCommunication($bname, self::EQPT, $t);
            $eqptsRef->assertLastCommunication($bname, $bname, $t);
            
            // activate the retain mode
            $this->waitElemIsClickable(By::xpath("//table[@id='table_cmd']/tbody/tr[@data-cmd_id='" . $action['id'] . "']//input[@data-l2key='retain']"))->click();
            $this->saveEqLogic();
            $this->waitElemIsClickable(By::xpath("//table[@id='table_cmd']/tbody/tr[@data-cmd_id='" . $action['id'] . "']//a[@data-action='test']"))->click();
            $mqttCapture->receive(10)->assertHasTopic($action['topic'])->reset();
            
            // check retain mode is effective
            (new MqttCapture($this, $bname, $action['topic']))->receive(10)->assertHasTopic($action['topic']);
            
            // desactivate retain mode
            $this->waitElemIsClickable(By::xpath("//table[@id='table_cmd']/tbody/tr[@data-cmd_id='" . $action['id'] . "']//input[@data-l2key='retain']"))->click();
            $this->saveEqLogic();
            sleep(1);
            
            // check retain mode is desactivated
            (new MqttCapture($this, $bname, $action['topic']))->receive(10)->assertNotHasTopic($action['topic']);
                        
            // Return to the plugin eqpt page
            $this->waitElemIsClickable(By::xpath("//a[@data-action='returnToThumbnailDisplay']"))->click();
            
            // Check all equipments (including related commands)
            $eqptsRef->assert(MqttEqpts::API_MQTT, $bname, true);
        }
    }
}
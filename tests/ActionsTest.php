<?php

require_once (__DIR__ . '/../vendor/autoload.php');
include_once 'MqttTestCase.php';
include_once 'MqttEqpts.class.php';

use Facebook\WebDriver\WebDriverBy as By;
use Facebook\WebDriver\WebDriverSelect as Select;
use MqttPlay\MqttPlay;

class ActionsTest extends MqttTestCase {

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
    
//    private function addActionCommands(array $cmds)
    
    public static function setUpBeforeClass() {
        parent::setUpBeforeClass();
    }
    
    public function testActivateAPI() {
        $this->activateAndAssertAPI();
    }
    
    /**
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
    public function testCreateActionEqpt($eqptsRef) {
        
        $mqttPlay = new MqttPlay(array(array('00:00:00.000', 'actions/setpoint', 25)), false, ' ', $_ENV['mosquitto_host'], $_ENV['mosquitto_port']);
        
        $eqptName = 'Actions';
        
        // Delete the equipment if it exists
        $this->disableIncludeMode();
        $eqptsRef->deleteFromInterface($eqptName);
        
        // Create the equipement and check all equipments
        $eqptsRef->addFromInterface($eqptName, true, 'actions/setpoint');
        $this->waitElemIsClickable(By::xpath("//a[@href='#commandtab']"))->click();        
        $eqptsRef->assert(false);

        // Add the setpoint info command
        $msg = $mqttPlay->nextMessage();
        $eqptsRef->setCmdInfo($eqptName, $msg[MqttPlay::S_TOPIC], $msg[MqttPlay::S_PAYLOAD]);
        $eqptsRef->assert(false);
        
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
                $is_first = false;
            }
            
            $this->waitElemIsClickable(By::xpath("//a[@data-action='save']"))->click();
            $action['id'] = $this->waitElemIsClickable(By::xpath("//table[@id='table_cmd']/tbody/tr[last()]"))->getAttribute('data-cmd_id');
            $eqptsRef->setCmdAction($eqptName, $action['topic'], $name, $action['subtype']);
        }
        
        // Check all equipments (including related commands)
        $eqptsRef->assert(false);
                       
        // Execute each command and check received messages
        foreach ($this->cmd_ref as $name => &$action) {
            $msg = array('topic' => $action['topic']);
            if (array_key_exists('options', $action)) {
                self::$apiClient->sendRequest('cmd::execCmd', array('id' => $action['id'], 'options' => $action['options']), $msg);
            }
            else {
                self::$apiClient->sendRequest('cmd::execCmd', array('id' => $action['id']), $msg);
            }
            $this->assertEquals($action['expected_published_value'], $msg['payload']);
            $eqptsRef->setCmdAction($eqptName, $action['topic'], $name, $action['subtype'], $action['expected_internal_value']);
        }
        
        // Check all equipments (including related commands)
        $eqptsRef->assert(false);
    }
}
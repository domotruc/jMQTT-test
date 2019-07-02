<?php

require_once (__DIR__ . '/../vendor/autoload.php');
include_once 'MqttTestCase.php';
include_once 'MqttEqpts.class.php';
include_once 'MqttCapture.class.php';

class tRestartDaemonTest extends MqttTestCase {
    
    private const EQPT = 'R';
    
    private const ACTION_NONE = 0;
    private const ACTION_DELETE = 1;
    private const ACTION_SAVE = 2;
    private const ACTION_CREATE = 3;
    
    /**
     * @var MqttEqpts
     */
    private $eqptsRef;
    
    /**
     * @var MqttCapture
     */
    private $mqttCapture;
    
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
    public function testBroker($eqptsRef) {
                
        $this->eqptsRef = $eqptsRef;
        
        /* @var string $broker broker used */
        $broker = 'host';
        /* @var string $bname current name of the used broker */
        $bname = 'host';
        
        print_r('I.a Disable the broker' . PHP_EOL);
        $this->setBrokerEnableAndAssert($broker, $bname, false);
        
        print_r('I.b Save again (no change with broker disabled)' . PHP_EOL);
        $this->gotoBrokerPage($broker, $bname);
        $this->saveBrokerAndAssert($broker, $bname, false, false);
        
        print_r('I.c Enable the broker' . PHP_EOL);
        $this->setBrokerEnableAndAssert($broker, $bname, true);
        
        print_r('I.d Save again (no change with broker enabled)' . PHP_EOL);
        $this->gotoBrokerPage($broker, $bname);
        $this->saveBrokerAndAssert($broker, $bname, false, false);
        
        print_r('I.e Rename the broker' . PHP_EOL);
        $this->gotoBrokerPage($broker, $bname);
        $this->setBrokerName($broker, $bname, $broker . '2');
        $this->saveBrokerAndAssert($broker, $bname, false, true);
        
        print_r('I.f Disable the broker' . PHP_EOL);
        $this->setBrokerEnableAndAssert($broker, $bname, false);
        
        print_r('I.g Rename the broker' . PHP_EOL);
        $this->gotoBrokerPage($broker, $bname);
        $this->setBrokerName($broker, $bname, $broker);
        $this->saveBrokerAndAssert($broker, $bname, false, true, MqttEqpts::DAEMON_POK);
        
        print_r('I.h Rename and enable the broker at the same time' . PHP_EOL);
        $this->gotoBrokerPage($broker, $bname);
        $this->setBrokerName($broker, $bname, $broker . '2');
        $this->setBrokerEnable($broker, $bname, true);
        $this->saveBrokerAndAssert($broker, $bname, true, true);
        
        print_r('I.i Rename and disable the broker at the same time' . PHP_EOL);
        $this->gotoBrokerPage($broker, $bname);
        $this->setBrokerName($broker, $bname, $broker);
        $this->setBrokerEnable($broker, $bname, false);
        $this->saveBrokerAndAssert($broker, $bname, true, true);
        
        print_r('I.j Enable the broker' . PHP_EOL);
        // FIXME: add log level
        $this->setBrokerEnableAndAssert($broker, $bname, true);

        print_r('I.k Change the log level to info' . PHP_EOL);
        $this->gotoBrokerPage($broker, $bname);
        $this->setLogLevel('200');
        $this->saveBrokerAndAssert($broker, $bname, false, true);     
        
        print_r('I.l Back to log level debug' . PHP_EOL);
        $this->gotoBrokerPage($broker, $bname);
        $this->setLogLevel('100');
        $this->saveBrokerAndAssert($broker, $bname, false, true);
        
        $conf_tests = array(
            MqttEqpts::CONF_AUTO_ADD_CMD => array('def_val' => true, 'new_val' => false, 'brk_state' => MqttEqpts::DAEMON_OK, 'restart_daemon' => false),
            MqttEqpts::CONF_QOS => array('def_val' => '1', 'new_val' => '0', 'brk_state' => MqttEqpts::DAEMON_OK, 'restart_daemon' => true),
            MqttEqpts::CONF_MQTT_ADDRESS => array(
                'def_val' => $_ENV['brokers']['host']['mosquitto_host'],
                'new_val' => '192.100.100.100',
                'brk_state' => MqttEqpts::DAEMON_POK, 'restart_daemon' => true),
            MqttEqpts::CONF_MQTT_PORT => array(
                'def_val' => $_ENV['brokers']['host']['mosquitto_port'],
                'new_val' => '8138',
                'brk_state' => MqttEqpts::DAEMON_POK, 'restart_daemon' => true),
            MqttEqpts::CONF_MQTT_USER => array('def_val' => '', 'new_val' => 'test', 'brk_state' => MqttEqpts::DAEMON_OK, 'restart_daemon' => true),
            MqttEqpts::CONF_MQTT_PASS => array('def_val' => '', 'new_val' => 'test', 'brk_state' => MqttEqpts::DAEMON_OK, 'restart_daemon' => true),
            MqttEqpts::CONF_MQTT_INC_TOPIC => array('def_val' => '#', 'new_val' => 'test/#', 'brk_state' => MqttEqpts::DAEMON_OK, 'restart_daemon' => true),
            MqttEqpts::CONF_API => array('def_val' => 'enable', 'new_val' => 'disable', 'brk_state' => MqttEqpts::DAEMON_OK, 'restart_daemon' => true)
        );
        $i=1;
        foreach($conf_tests as $key => $conf) {
            print_r('I.m ' . $i . ' Testing configuration key ' . $key . PHP_EOL);
            
            // Change to the new value
            $this->gotoBrokerPage($broker, $bname);
            $this->eqptsRef->setConfiguration_ui($broker, $bname, $key, $conf['new_val'], false);
            $this->eqptsRef->setBrokerState($broker, $conf['brk_state']);
            $this->saveBrokerAndAssert($broker, $bname, false, $conf['restart_daemon']);
            
            // Back to the default value
            $this->gotoBrokerPage($broker, $bname);
            $this->eqptsRef->setConfiguration_ui($broker, $bname, $key, $conf['def_val'], false);
            $this->eqptsRef->setBrokerState($broker, MqttEqpts::DAEMON_OK);
            $this->saveBrokerAndAssert($broker, $bname, false, $conf['restart_daemon'], $conf['brk_state']);
            
            $i++;
        }
    }
    
    /**
     * @depends testEqptAtStart
     * @param array $eqptsRef
     */
    public function testEqpt($eqptsRef) {
        
        $bname = 'host';
        $this->eqptsRef = $eqptsRef;
        
        // Delete the equipment if it exists
        //$this->assertIncludeMode($bname, false);
        $eqptsRef->deleteFromInterface($bname, self::EQPT);
        
        // Done 2 times: first time broker is enabled, second time it is disabled
        $isBrokerEnabled = true;
        $isBrokerRestarted = true;
        for($i=0 ; $i<2 ; $i++) {
            
            $this->printEqptMsg('II.1 Create the equipement (let is disabled)', $isBrokerEnabled); 
            $this->saveEqptAndAssert($bname, false, self::ACTION_CREATE);
            
            $this->printEqptMsg('II.2 Delete the equipment', $isBrokerEnabled);
            $this->saveEqptAndAssert($bname, false, self::ACTION_DELETE);

            $this->printEqptMsg('II.3 Create the equipement and enable it', $isBrokerEnabled);
            $eqptsRef->addFromInterface($bname, self::EQPT, false);
            $eqptsRef->setIsEnable_ui($bname, self::EQPT, true, false);
            $this->saveEqptAndAssert($bname, $isBrokerRestarted);
            
            $isEqptEnabled = true;
            for ($j=0 ; $j<2 ; $j++) {
                
                $this->printEqptMsg('II.4 Set the equipment topic', $isBrokerEnabled, $isEqptEnabled);
                $this->gotoEqptPage($bname, self::EQPT);
                $eqptsRef->setTopic_ui($bname, self::EQPT, substr(md5(mt_rand()), 0, 7));
                $this->saveEqptAndAssert($bname, $isBrokerRestarted);
                
                $this->printEqptMsg('II.5 Set Qos', $isBrokerEnabled, $isEqptEnabled);
                $this->gotoEqptPage($bname, self::EQPT);
                $eqptsRef->setConfiguration_ui($bname, self::EQPT, MqttEqpts::CONF_QOS, $j ? '1' : '0', false);
                $this->saveEqptAndAssert($bname, $isBrokerRestarted);
                
                $this->printEqptMsg('II.6 Set auto command adding', $isBrokerEnabled, $isEqptEnabled);
                $this->gotoEqptPage($bname, self::EQPT);
                $eqptsRef->setConfiguration_ui($bname, self::EQPT, MqttEqpts::CONF_AUTO_ADD_CMD, !$isEqptEnabled, false);
                $this->saveEqptAndAssert($bname, false);
                
                if ($isEqptEnabled) {
                    $this->printEqptMsg('II.7 Disable the equipment for the second loop', $isBrokerEnabled, $isEqptEnabled);
                    $this->gotoEqptPage($bname, self::EQPT);
                    $eqptsRef->setIsEnable_ui($bname, self::EQPT, false, false);
                    $this->saveEqptAndAssert($bname, $isBrokerRestarted);
                }

                $isEqptEnabled = false;
            }

            $isBrokerRestarted = $isBrokerEnabled;
                
            $this->printEqptMsg('II.8 Enable the equipment again', $isBrokerEnabled);
            $this->gotoEqptPage($bname, self::EQPT);
            $eqptsRef->setIsEnable_ui($bname, self::EQPT, true, false);
            $this->saveEqptAndAssert($bname, $isBrokerRestarted);
            $isEqptEnabled = true;
            
            $this->printEqptMsg('II.9 Delete the equipment', $isBrokerEnabled);
            $this->saveEqptAndAssert($bname, $isBrokerRestarted, self::ACTION_DELETE);
            
            if ($isBrokerEnabled) {
                $this->printEqptMsg('II.10 Disable the broker for the second loop', $isBrokerEnabled);
                $this->setBrokerEnableAndAssert($bname, $bname, false);
                $isBrokerEnabled = false;
                $isBrokerRestarted = false;
            }
        }
        
        $this->printEqptMsg('II.11 Enable the broker', $isBrokerEnabled);
        $this->setBrokerEnableAndAssert($bname, $bname, true);
    }
    
    private function setBrokerEnable(string $broker, string $bname, bool $isEnable) {
        $this->eqptsRef->setIsEnable_ui($broker, $bname, $isEnable, false);
        $this->eqptsRef->setBrokerState($broker, $isEnable ? MqttEqpts::DAEMON_OK : MqttEqpts::DAEMON_NOK);
    }
    
    private function setBrokerEnableAndAssert(string $broker, string $bname, bool $isEnable) {
        $this->gotoBrokerPage($broker, $bname);
        $this->setBrokerEnable($broker, $bname, $isEnable);
        $this->saveBrokerAndAssert($broker, $bname, true, false);
    }
    
    private function setBrokerName(string $broker, string &$old_bname, string $new_name) {
        $this->eqptsRef->assertLogFiles();
        $this->eqptsRef->setEqptName_ui($broker, $old_bname, $new_name);
        $old_bname = $new_name;
    }
    
    private function printEqptMsg(string $msg, bool $isBrokerEnabled, $isEqptEnabled=null) {
        $msg = $msg . ' (brokerEnabled=' . json_encode($isBrokerEnabled) . 
        (isset($isEqptEnabled) ? ' / eqptEnabled=' . json_encode($isEqptEnabled) : '') . ')';
        print_r($msg . PHP_EOL);
    }
    
    /**
     * Save the given broker equipment (which shall be also the currently displayer eqlogic)
     * 
     * @param string $broker selected broker
     * @param string $bname broker equipment name
     * @param bool $isChanged if the broker state (enable/disable) has changed
     * @param bool $isRestarted if a parameter requiring a daemon restart has changed
     * @param string $previousState previous daemon state
     */
    private function saveBrokerAndAssert(string $broker, string $bname, bool $isChanged, bool $isRestarted, string $previousState=MqttEqpts::DAEMON_OK) {
        
        $isDaemonOk = ($this->eqptsRef->getBrokerState($broker) == MqttEqpts::DAEMON_OK);

        $expectedMsgs = array();
        $topic = $_ENV['brokers'][$broker]['mosquitto_client_id'] . '/status';
        
        if ($isChanged) {
            if ($isDaemonOk)
                $expectedMsgs[] = MqttCapture::createMessage($topic, 'online');
            else
                $expectedMsgs[] = MqttCapture::createMessage($topic, 'offline');
        }
        else {
            if ($isRestarted) {
                if ($previousState == MqttEqpts::DAEMON_OK) {
                    $expectedMsgs[] = MqttCapture::createMessage($topic, 'offline');
                }
                if ($isDaemonOk) {
                    $expectedMsgs[] = MqttCapture::createMessage($topic, 'online');
                }
            }
        }

        $this->mqttCapture = new MqttCapture($this, $broker, $this->eqptsRef->getLogicalId($broker, $bname));
        $this->mqttCapture->receive(5)->reset();
        
//         $fork = new Fork;
//         $fork->call(function () use ($expectedMsgs) {
//             print_r('I. Start capture:' . date('H:i:s.').gettimeofday()["usec"] . PHP_EOL);
//             $this->mqttCapture->receive(20);
//             print_r('I. End capture:' . date('H:i:s.').gettimeofday()["usec"] . PHP_EOL);
//             $this->mqttCapture->assertMessages($expectedMsgs)->reset();
//         });
//         $fork->call(function () {
//             usleep(500000);
//             print_r('I. Start save:' . date('H:i:s.').gettimeofday()["usec"] . PHP_EOL);
//             $this->saveEqLogic(false);
//             print_r('I. End save:' . date('H:i:s.').gettimeofday()["usec"] . PHP_EOL);
//         });
//         $fork->wait();
        
        $this->saveEqLogic(false);
        $this->mqttCapture->receive(20);
        $this->mqttCapture->assertMessages($expectedMsgs)->reset();
        $this->mqttCapture->close();        
        
        $this->gotoPluginMngt();
        $this->eqptsRef->assert(MqttEqpts::API_JSON_RPC);
        $this->eqptsRef->assertLogFiles();
    }
    
    /**
     * Save the currently displayed equipment (if $save is true) and check
     * 
     * @param string $broker broker the equipment belongs to
     * @param bool $isRestarted whether or not the broker has been restarted
     * @param bool $action action to execute, see constant self::ACTION_*
     */
    private function saveEqptAndAssert(string $broker, bool $isRestarted, int $action=self::ACTION_SAVE) {
        
        $expectedMsgs = array();
        $topic = $_ENV['brokers'][$broker]['mosquitto_client_id'] . '/status';
        if ($isRestarted) {
            $expectedMsgs[] = MqttCapture::createMessage($topic, 'offline');
            $expectedMsgs[] = MqttCapture::createMessage($topic, 'online');
        }
        
        $this->mqttCapture = new MqttCapture($this, $broker, $this->eqptsRef->getLogicalId($broker, $broker));
        $this->mqttCapture->receive(5)->reset();
        
        if ($action == self::ACTION_SAVE) {
            $this->saveEqLogic(false);
        }
        if ($action == self::ACTION_DELETE) {
            $this->eqptsRef->deleteFromInterface($broker, self::EQPT);            
        }
        if ($action == self::ACTION_CREATE) {
            $this->eqptsRef->addFromInterface($broker, self::EQPT, false);
        }
        
        $this->mqttCapture->receive(20);
        $this->mqttCapture->assertMessages($expectedMsgs)->reset();
        $this->mqttCapture->close();
        
        $this->gotoPluginMngt();
        $this->eqptsRef->assert(MqttEqpts::API_JSON_RPC);
    }
}
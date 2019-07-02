<?php
require_once (__DIR__ . '/../vendor/autoload.php');

use Bluerhinos\phpMQTT;
use PHPUnit\Framework\TestCase as TestCase;

class MqttCapture {
    
    const KEY_TOPIC = 'topic';
    const KEY_PAYLOAD = 'payload';
    
    /**
     * @var phpMQTT
     */
    private $client;
    
    /**
     * @var MqttTestCase $tc
     */
    private $tc;
    
    /**
     * @var string[]
     */
    private $messages;
           
    function __construct(TestCase $tc, string $bname, string $topic='#') {
        $this->tc = $tc;
        $this->client = new phpMQTT($_ENV['brokers'][$bname]['mosquitto_host'], $_ENV['brokers'][$bname]['mosquitto_port'],
            "MqttCapture_" . $bname);
        $this->messages = array();
        $this->client->connect();
        $this->client->subscribe(array($topic => array("qos" => 0, "function" => array($this, 'processMqttMsg'))), 0);
        echo 'Creating object MqttCapture' . PHP_EOL;
    }
    
    /**
     * Capture messages during the given number of iterations
     * @param int $iteration
     * @return MqttCapture
     */
    public function receive(int $iteration=20) {
        for ($i=0 ; $i<$iteration ; $i++) {
            print_r('Iteration ' . $i . ' (' . date('H:i:s.').gettimeofday()["usec"] . ')' . PHP_EOL);
            $this->client->proc();
        }
        
        return $this;
    }
    
    /**
     * Return the captured message array
     * @return string[]
     */
    public function getMessages() {
        return $this->messages;
    }
    
    /**
     * Check captured message array equals the given expected array
     * @param array $expected
     * @return MqttCapture this object
     */
    public function assertMessages(array $expected) {
        echo 'Expected:' . PHP_EOL;
        echo $expected;
        echo PHP_EOL . 'Actual:' . PHP_EOL;
        echo $this->messages;
        $this->tc->assertEquals($expected, $this->messages);
        return $this;
    }
    
    /**
     * Check captured message array does contain the given topic
     * @param string $topic
     * @return MqttCapture this object
     */
    public function assertHasTopic(string $topic) {
        $this->tc->assertArrayHasKey($topic, $this->getLastMessages());
        return $this;
    }
    
    /**
     * Check captured message array does not contain the given topic
     * @param string $topic
     * @return MqttCapture this object
     */
    public function assertNotHasTopic(string $topic) {
        $this->tc->assertArrayNotHasKey($topic, $this->getLastMessages());
        return $this;
    }
    
    /**
     * Reset the captured message array
     * @return MqttCapture this object
     */
    public function reset() {
        $this->messages = array();
        return $this;
    }
    
    public function close() {
        $this->client->close();
        $this->reset();
    }
    
    public static function createMessage($topic, $payload) {
        return array(self::KEY_TOPIC => $topic, self::KEY_PAYLOAD => $payload);
    }
    
    public function processMqttMsg($topic, $payload) {
        $this->messages[] = self::createMessage($topic, $payload);
        
        echo 'Receive message ' . $topic . ' ' . $payload . ' (' .
            date('H:i:s.').gettimeofday()["usec"] . ')' . PHP_EOL;
    }
    
    /**
     * Return an array with the last payload received. Array indexes are topics.
     * @return string[]
     */
    private function getLastMessages() {
        $msgs = array();
        foreach($this->messages as $msg) {
            $msgs[$msg[self::KEY_TOPIC]] = $msg[self::KEY_PAYLOAD];
        }
        return $msgs;
    }
}

    
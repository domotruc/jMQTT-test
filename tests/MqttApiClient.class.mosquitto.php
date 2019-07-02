<?php

//require_once (__DIR__ . '/../vendor/autoload.php');

use Mosquitto\Client;

class MqttApiClient {

    const S_CLIENT_ID = 'jmqtt_test';
    const S_RET_TOPIC = self::S_CLIENT_ID . '/req';
    
    /**
     * @var Mosquitto\Client
     */
    private $client;
    
    private $host;
    
    private $port;
    
    /**
     * @var bool
     */
    private $is_connected = false;
   
    /**
     * @var string $jeedom_id jeedom id the request shall be adressed to
     */
    private $jeedom_id;

    /**
     * @var integer api request id
     */
    private $req_id = 0;

    private $callback_ret = 0;
    
    /**
     * @var array
     */
    private $response;
    
    /**
     * @var array
     */
    private $prev_req;
    
    /**
     * @var array
     */
    private $prev_resp;
    
    /**
     * @var array &$add_msg
     */
    private $add_msg;
    
    /**
     * @var MqttEqpts reference equipment list
     */
    private $mqttEqpts;
      
    /**
     * @var string
     */
    private $jeedom_version;
    
    /**
     * Create a mosquitto client and connect to the broker
     * @var string $jeedom_id jeedom id the request shall be adressed to
     */
    function __construct($jeedom_id, $host, $port) {
        $this->jeedom_id = $jeedom_id;
        $this->host = $host;
        $this->port = $port;

        // Configure and connect MQTT client
        $this->client = new Mosquitto\Client();
        $this->client->onMessage(array($this, 'onMessage'));
        $this->client->onPublish(array($this, 'onPublish'));
        $this->client->onSubscribe(array($this, 'onSubscribe'));
        $this->client->onUnsubscribe(array($this, 'onUnsubscribe'));
        $this->client->onPublish(array($this, 'onPublish'));
    }
    
    
    /**
     * Set the reference eqpt list
     * So that this api object can refresh the api command in the $jeedom_id equipment
     * @param MqttEqpts $mqttEqpts
     */
    public function setMqttEqpts(MqttEqpts $mqttEqpts) {
        $this->mqttEqpts = $mqttEqpts;        
    }
    
    /**
     * Callback called each time a subscribed topic is dispatched by the broker.
     * Decode the received JSON response as an array
     * Add the response to the S_CLIENT_ID equipment if it exists
     * @param Mosquitto\Message $msg
     */
    public function onMessage($msg) {
        if (isset($this->add_msg) && $this->add_msg['topic'] == $msg->topic) {
            $this->add_msg['payload'] = $msg->payload;
        }
        else {
            $this->response = json_decode($msg->payload, true);
        
            // add the response to the S_CLIENT_ID equipment if it exists
            if (isset($this->mqttEqpts) && $this->mqttEqpts->existsByName(self::S_CLIENT_ID)) {
                $this->mqttEqpts->setCmdInfo(self::S_CLIENT_ID, $msg->topic, $this->prev_resp);
                $this->prev_resp = $msg->payload;
            }
        }
    }
    
    public function onSubscribe($mid, $qosCount) {
        $this->callback_ret++;
    }
    
    public function onUnsubscribe($mid) {
        $this->callback_ret++;
    }
    
    public function onPublish($mid) {
        $this->callback_ret++;
    }
    
    private function loopUntilCallbackRet($failed_msg) {
        for ($i = 0; $i <10; $i++) {
            $this->client->loop();
            if ($this->callback_ret)
                break;
        }
        if (!$this->callback_ret)
            throw new Exception($failed_msg);
        
        $this->callback_ret = 0;
    }

    /**
     * Publish an MQTT request, wait for response and return it.
     * If $add_msg is set, wait also for an additional message and return the received payload.
     *  
     * @param string $method
     * @param array $params request parameters (empty array by default)
     * @param array &$add_msg null by default
     *      $add_msg['topic']: topic to listen (shall be unique topic i.e. shall not contain any wilcards # or +)
     *      $add_msg['payload']: received payload 
     * @return NULL|string
     */
    public function sendRequest($method, array $params=array(), array &$add_msg=null) {
        
        $this->add_msg = &$add_msg;
        
        if (! $this->is_connected) {
            $this->client->connect($this->host, $this->port, 120);
            $this->client->subscribe(self::S_RET_TOPIC, 0);
            $this->loopUntilCallbackRet('Broker could not subscribe to ' . self::S_RET_TOPIC);
            $this->is_connected = true;
        }
            
            // Conversion between 3.2 and 3.3 version
        $conv = array('object::all' => 'jeeObject::all');
        
        if (array_key_exists($method, $conv)) {
            if ($this->getJeedomVersion() == '3.3')
                $method = $conv[$method];
        }

        if (isset($add_msg)) {
            $this->client->subscribe($add_msg['topic'], 0);
            $this->loopUntilCallbackRet('Broker could not subscribe to ' . $add_msg['topic']);            
            $add_msg['payload'] = null;
        }
        
        $this->response = null;
        
        return $this->processRequest($method, $params, $add_msg);
        
        if (isset($add_msg)) {
            $this->loopUntilCallbackRet('Broker could not unsubscribe to ' . $add_msg['topic']);
        }
    }
    
    /**
     * Private function : user shall always call MqttApiClient::sendRequest
     * @see MqttApiClient::sendRequest
     */
    private function processRequest($method, array $params=array(), array &$add_msg=null) {
        
        $req = array('method' => $method,
                     'id' => strval($this->req_id++),
                     'topic' => self::S_RET_TOPIC);
        
        if (!empty($params)) {
            $req['params'] = $params;
        }

        // send the request
        $topic = $this->jeedom_id . '/api';
        $req = json_encode($req);
        $this->client->publish($topic, $req);
        $this->loopUntilCallbackRet('Could not publish payload' . $req . ' to topic ' . $topic);
        
        // add the request to the jeedom_id equipment if it exists
        if (isset($this->mqttEqpts) && $this->mqttEqpts->existsByName($this->jeedom_id)) {
            $this->mqttEqpts->setCmdInfo($this->jeedom_id, $topic, $this->prev_req);
            $this->prev_req = $req;
        }

        // wait for the answer
        for ($i = 0; $i <= 50; $i++) {
            $this->client->loop();
            if (isset($this->response) && (!isset($this->add_msg) || isset($this->add_msg['payload'])))
                break;
        }
                    
        return $this->response;
    }
    
    public function getJeedomVersion() {
        if (! isset($this->jeedom_version)) {
            $resp = $this->sendRequest('version');
            $this->jeedom_version = substr($resp['result'], 0, 3);
        }
        return $this->jeedom_version;
    }
}

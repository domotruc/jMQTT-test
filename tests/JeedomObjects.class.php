<?php

/**
 * Gather the list of all Jeedom objects
 */
class JeedomObjects {
    
    
    /**
     * @var array list of all Jeedom objects
     */
    private static $objects;
    
    /**
     * @var MqttApiClient $api jMQTT API client
     */
    private static $api;
    
    
    /**
     * Initializes this static class
     * @param MqttApiClient $api
     */
    public static function init(MqttApiClient $_api) {
        self::$api = $_api;
        self::resetFromAPI();
    }
    
    /**
     * Get the requested object defined by its id
     * @param int $_id id of the searched object
     * @return array|NULL properties of the object, or null if not found
     */
    public static function getById(int $_id) {
        $obj = self::search($_id);
        if (!isset($obj)) {
            self::resetFromAPI();
            $obj = self::search($_id);
        }
        return $obj;
    }

    /**
     * Reset the list of all Jeedom objects with the one returned by the jMQTT Jeedom API 
     */
    private static function resetFromAPI() {
        $resp = self::$api->sendRequest('object::all');
        self::$objects = $resp['result'];
    }
    
    /**
     * Look for the object contained in this class
     * @param int $_id id of the searched object
     * @return array|NULL found object or null if not found
     */
    private static function search(int $_id) {
        if (!isset(self::$objects))
            return null;
        
        foreach (self::$objects as $i => $obj) {
            if ($obj['id'] == $_id)
                return $obj;
        }
        
        return null;
    }
}
<?php

//
// PluginTestCase parameters
//
$_ENV['jeedom_url'] = 'http://your_jeedom_url/';
$_ENV['jeedom_username'] = '';
$_ENV['jeedom_password'] = '';

// Plugin version to install (beta or stable)
$_ENV['plugin_source'] = 'market';  // github or market
$_ENV['plugin_version'] = 'beta';


//
// jMQTT test case parameters
//

// broker parameters
$_ENV['mosquitto_client_id'] = 'jeedom';
$_ENV['mosquitto_host'] = 'localhost';
$_ENV['mosquitto_port'] = 1883;

// ssh connexion to jeedom host (to uninstall plugin dependancies)
$_ENV['ssh_host'] = 'your_jeedom_host';
$_ENV['ssh_port'] = '22';
$_ENV['ssh_username'] = '';
$_ENV['ssh_password'] = '';

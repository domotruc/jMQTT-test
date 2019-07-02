<?php

require_once (__DIR__ . '/../vendor/autoload.php');

use Datto\JsonRpc\Http\Client;
use PHPUnit\Framework\TestCase as TestCase;
use Facebook\WebDriver\Exception;
use Facebook\WebDriver\WebDriverBy as By;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\WebDriverSelect as Select;
use Facebook\WebDriver\WebDriverWait;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\RemoteWebElement;

abstract class PluginTestCase extends TestCase {
    
    private const IMPLICIT_WAIT_DUR = 10;
    
    /**
     * @var RemoteWebDriver WebDriver object
     */
    protected static $wd;
    
    /**
     * @var WebDriverWait $wdWait
     */
    protected static $wdWait;
    
    
    /**
     * @var int $jsonRpcId JSON RPC request identifier
     */
    private static $jsonRpcId = 0;
    
    protected $plugin_id;
    
    public function __construct($name = null, array $data = [], $dataName = '', string $plugin_id = '') {
        parent::__construct($name, $data, $dataName);

        $this->plugin_id = $plugin_id;        
    }

    /**
     * Connect jeedom and point the browser to the plugin management page
     */
    public static function setUpBeforeClass() {
        $capabilities = DesiredCapabilities::firefox();
        self::$wd = RemoteWebDriver::create('http://localhost:4444/wd/hub', $capabilities);
        self::$wd->manage()->timeouts()->implicitlyWait(self::IMPLICIT_WAIT_DUR);
        self::$wdWait = new WebDriverWait(self::$wd, self::IMPLICIT_WAIT_DUR);
        
        self::$wd->get($_ENV['jeedom_url']);
        self::$wd->findElement(By::id('in_login_username'))->sendKeys($_ENV['jeedom_username']);
        self::$wd->findElement(By::id('in_login_password'))->sendKeys($_ENV['jeedom_password']);
        self::$wd->findElement(By::id('bt_login_validate'))->click();
    }
    
   /**
    * Load the plugins management page
    */
   public function gotoPluginsMngt() {
       self::$wd->get($_ENV['jeedom_url'] . 'index.php?v=d&p=plugin');
   }
   
   /**
    * Load the plugin configuration page
    */
   public function gotoPluginConfPanel() {
       $this->gotoPluginMngt();
       self::$wd->findElement(By::xpath("//div[contains(@data-action,'gotoPluginConf')]"))->click();
   }
   
   /**
    * Load the plugin under test equipment page
    */
   public function gotoPluginMngt() {
       self::$wd->get($_ENV['jeedom_url'] . 'index.php?v=d&m=' . $this->plugin_id . '&p=' . $this->plugin_id);
       usleep(200000); // To avoid time to time assert failure (actual eqpts name list empty)
   }
   
   /**
    * Load the Jeedom admin page
    */
   public function gotoJeedomAdmin() {
       self::$wd->get($_ENV['jeedom_url'] . 'index.php?v=d&p=administration');
   }
   
   /**
    * Wait the necessary time to full the 45s delay between 2 deamons start
    */
   public function waitForDeamonRestartDelay() {
       $el = self::$wd->findElement(By::xpath("//td[@class='td_lastLaunchDeamon']"));
       if ($el->getText() == 'Inconnue')
           return;
       $diff = (new DateTime())->getTimestamp() - (new DateTime($el->getText()))->getTimestamp();
       if ($diff <= 45) {
           print('Deamon was restarted recently: wait ' . $diff . 's' . PHP_EOL);
           sleep(46 - $diff);
       }
   }

    /**
     * Enable or disable the JSON RPC API
     * @param bool $isEnable
     */
    public function setJsonRpcApi(bool $isEnable) {
        $val = $isEnable ? 'enable' : 'disable';
        $this->gotoJeedomAdmin();
        $this->waitElemIsClickable(By::xpath("//a[@href='#apitab']"))->click();
        (new Select($this->waitElemIsClickable(By::xpath("//select[@data-l1key='api::core::jsonrpc::mode']"))))->selectByValue(
            $val);
        self::$wd->findElement(By::id('bt_saveGeneraleConfig'))->click();
        $this->waitElemIsVisible(By::id('div_alert'));
        
        if ($isEnable) {
            $this->assertEquals('pong', $this->sendJsonRpcRequestOK('ping'));
        }
        else {
            $this->assertEquals("Vous n'êtes pas autorisé à effectuer cette action (JSON-RPC disable)", $this->sendJsonRpcRequestNOK('ping')->getMessage());
        }
    }
    
    private function sendJsonRpcRequest(string $method, array $params=array()) {
        $client = new Client($_ENV['jeedom_url'] . 'core/api/jeeApi.php');
        if (! key_exists('apikey', $params)) {
            $params['apikey'] = $_ENV['jeedom_apikey'];
        }
        $client->query(self::$jsonRpcId++, $method, $params);
        return  $client->send();
    }
    
    /**
     * Send the JSON RPC request, check response status is OK and return the result
     * Note: apikey is added by this method if not already defined in $params
     * @param string $method
     * @param array $params
     * @return null|int|float|string|array
     */
    public function sendJsonRpcRequestOK(string $method, array $params=array()) {
        $reply = $this->sendJsonRpcRequest($method, $params);
        $this->assertFalse($reply[0]->isError(), "HTTP JSON API returns an error");
        return $reply[0]->getResult();
    }
   
    /**
     * Send the JSON RPC request, check response status is OK and return the result
     * Note: apikey is added by this method if not already defined in $params
     * @param string $method
     * @param array $params
     * @return Datto\JsonRpc\Error
     */
    public function sendJsonRpcRequestNOK(string $method, array $params=array()) {
        $reply = $this->sendJsonRpcRequest($method, $params);
        $this->assertTrue($reply[0]->isError(), "HTTP JSON API should have returned an error");
        return $reply[0]->getError();
    }
    
    /**
    * Check that an element is not found
    */
   public function assertElementNotFound(By $locator, string $msg, int $delay = self::IMPLICIT_WAIT_DUR) {
       self::$wd->manage()->timeouts()->implicitlyWait($delay);
       try {
           self::$wd->findElement($locator);
           $this->assertTrue(false, $msg);
       } catch ( Exception\NoSuchElementException $e ) {
           $this->assertTrue(true, $msg);
       }
       self::$wd->manage()->timeouts()->implicitlyWait(self::IMPLICIT_WAIT_DUR);
   }
   
   /**
    * Assert the given success message is shown in the alert panel on top of the page
    * Close the message
    * @param string $msg
    * @throws Exception
    */
   public function assertDivAlertSuccess(string $msg) {
       // Retry twice to avoid the message:
       // Facebook\WebDriver\Exception\UnrecognizedExceptionException: Element
       // <span class="btn_closeAlert pull-right cursor" href="#"> is not clickable at point (1255,68) because another element
       // <div class="modal-backdrop fade"> obscures it
       //
       // Occurs when a dialog box is closed just before entering here and had no time to be closed
       usleep(200000);
       $retry = false;
       do {
           try {
               //$close =$this->waitElemIsClickable(By::xpath("//div[@id='div_alert' and contains(@class,'alert-success')]//span[contains(@class,'btn_closeAlert')]"));
               $el = $this->waitElemIsVisible(By::xpath("//div[@id='div_alert' and contains(@class,'alert-success')]"));
               $this->assertEquals($msg, $el->findElement(By::xpath("./span[contains(@class,'displayError')]"))->getText());
               $el->findElement(By::xpath("//span[contains(@class,'btn_closeAlert')]"))->click();
           }
           catch (Exception $e) {
               if ($retry) {
                   throw $e;
               }
               usleep(500000);
               $retry = true;
           }
       } while ($retry);
   }
   
   public function assertDivAlertSuccessSave() {
       $this->assertDivAlertSuccess("Sauvegarde effectuée avec succès");
   }
   
   public function assertDivAlertSuccessDelete() {
       $this->assertDivAlertSuccess("Suppression effectuée avec succès");
   }
   
   /**
    * Save the current eqLogic.
    * 
    * Start page spec: any EqLogic page
    * End page: unchanged
    * @param bool $check if true check the success message
    */
   public function saveEqLogic(bool $check=true) {
       $this->waitElemIsClickable(By::xpath("//a[@data-action='save']"))->click();
       if ($check)
           $this->assertDivAlertSuccessSave();
   }
   
   /**
    * To handle following errors:
    *   - Element ... could not be scrolled into view
    *   - Element ... is not clickable at point (...)
    * @return RemoteWebElement
    */
   public function waitElemIsClickable(By $locator, string $message='', int $delay = self::IMPLICIT_WAIT_DUR) {
       $wait = new WebDriverWait(self::$wd, $delay);
       $wait->until(WebDriverExpectedCondition::elementToBeClickable($locator), $message);
       return self::$wd->findElement($locator);
   }
   
   public function waitElemIsVisible(By $locator, string $message='', int $delay = self::IMPLICIT_WAIT_DUR) {
       $wait = new WebDriverWait(self::$wd, $delay);
       $wait->until(WebDriverExpectedCondition::visibilityOfElementLocated($locator), $message);
       return self::$wd->findElement($locator);
   }
   
   public function waitElemsAreVisible(By $locator, string $message='', int $delay = self::IMPLICIT_WAIT_DUR) {
       $wait = new WebDriverWait(self::$wd, $delay);
       $wait->until(WebDriverExpectedCondition::visibilityOfElementLocated($locator), $message);
       return self::$wd->findElements($locator);
   }
   
   /**
    * Execute a command on a remote server and return the output
    * @link http://www.php.net/manual/en/function.ssh2-exec.php
    * @param session An SSH connection link identifier, obtained from a call to ssh2_connect.
    * @param command string
    * @param bool $sudo whether or not the command shall sudoed
    * @return string|false command output on success or false on failure.
    */
   
   public function ssh2_exec($session, string $command, bool $sudo=false) {
       if ($sudo)
           $command = "echo "  . $_ENV['ssh_password'] . " | sudo -S " . $command;
       
       $stream = ssh2_exec($session, $command);
       if ($stream === false)
           return false;
       if (stream_set_blocking($stream, true) === false)
           return false;
       return stream_get_contents($stream);
   }
}
    
<?php

require_once ('vendor/autoload.php');

use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverWait as WebDriverWait;
use Facebook\WebDriver\WebDriverBy as By;
use Facebook\WebDriver\WebDriverSelect as Select;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\Exception;
use Facebook\WebDriver\Remote\RemoteWebElement;

abstract class PluginTestCase extends \PHPUnit\Framework\TestCase {
    
    /**
     * @var RemoteWebDriver WebDriver object
     */
    protected static $wd;
    
    /**
     * @var WebDriverWait $wdWait
     */
    protected static $wdWait;
    protected static $implicit_wait_dur = 10;
    
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
        self::$wd->manage()->timeouts()->implicitlyWait(self::$implicit_wait_dur);
        self::$wdWait = new WebDriverWait(self::$wd, self::$implicit_wait_dur);
        
        self::$wd->get($_ENV['jeedom_url']);
        self::$wd->findElement(By::id('in_login_username'))->sendKeys($_ENV['jeedom_username']);
        self::$wd->findElement(By::id('in_login_password'))->sendKeys($_ENV['jeedom_password']);
        self::$wd->findElement(By::id('bt_login_validate'))->click();
        self::gotoPluginsMngt();
    }
    
   /**
    * Load the plugins management page
    */
   public function gotoPluginsMngt() {
       self::$wd->get($_ENV['jeedom_url'] . 'index.php?v=d&p=plugin');
   }
   
   /**
    * Load the plugin under test configuration page
    */
   public function gotoPluginConfPanel() {
       $this->gotoPluginMngt();
       self::$wd->findElement(By::xpath("//div[contains(@data-action,'gotoPluginConf')]"))->click();
   }
   
   /**
    * Load the plugin uder test equipment page
    */
   public function gotoPluginMngt() {
       self::$wd->get($_ENV['jeedom_url'] . 'index.php?v=d&m=' .
           $this->plugin_id . '&p=' . $this->plugin_id);
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
       $el = $this->waitElemIsVisible(By::className('td_lastLaunchDeamon'));
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
        $val = $isEnable ? 'whiteip' : 'disable';
        $this->gotoJeedomAdmin();
        $this->waitElemIsClickable(By::xpath("//a[@href='#apitab']"))->click();
        (new Select($this->waitElemIsClickable(By::xpath("//select[@data-l1key='api::core::jsonrpc::mode']"))))->selectByValue(
            $val);
        self::$wd->findElement(By::id('bt_saveGeneraleConfig'))->click();
        $this->waitElemIsVisible(By::id('div_alert'));
    }
   
   /**
    * Check that an element is not found
    */
   public function assertElementNotFound(By $locator, string $msg, int $delay = 1) {
       self::$wd->manage()->timeouts()->implicitlyWait($delay);
       try {
           $el = self::$wd->findElement($locator);
           $this->assertTrue(false, $msg);
       } catch ( Exception\NoSuchElementException $e ) {
           $this->assertTrue(true, $msg);
       }
       self::$wd->manage()->timeouts()->implicitlyWait(self::$implicit_wait_dur);
   }
   
   /**
    * To handle following errors:
    *   - Element ... could not be scrolled into view
    *   - Element ... is not clickable at point (...)
    * @return RemoteWebElement
    */
   public function waitElemIsClickable(By $locator) {
       // $wait = new WebDriverWait(self::$wd, 10);
       self::$wdWait->until(WebDriverExpectedCondition::elementToBeClickable($locator));
       usleep(200000);
       return self::$wd->findElement($locator);
   }
   
   public function waitElemIsVisible(By $locator) {
       // $wait = new WebDriverWait(self::$wd, 10);
       self::$wdWait->until(WebDriverExpectedCondition::visibilityOfElementLocated($locator));
       usleep(200000);
       return self::$wd->findElement($locator);
   }
   
   public function waitElemsAreVisible(By $locator) {
       // $wait = new WebDriverWait(self::$wd, 10);
       self::$wdWait->until(WebDriverExpectedCondition::visibilityOfElementLocated($locator));
       usleep(200000);
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
    
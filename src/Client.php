<?php

namespace Ixopay\Client;

use Ixopay\Client\Exception\ClientException;
use Ixopay\Client\Exception\InvalidValueException;
use Ixopay\Client\Exception\TimeoutException;
use Ixopay\Client\Http\CurlClient;
use Ixopay\Client\Http\Response;
use Ixopay\Client\Transaction\Base\AbstractTransaction;
use Ixopay\Client\Transaction\Capture;
use Ixopay\Client\Transaction\Debit;
use Ixopay\Client\Transaction\Deregister;
use Ixopay\Client\Transaction\Error;
use Ixopay\Client\Transaction\Preauthorize;
use Ixopay\Client\Transaction\Refund;
use Ixopay\Client\Transaction\Register;
use Ixopay\Client\Transaction\Result;
use Ixopay\Client\Transaction\VoidTransaction;
use Ixopay\Client\Xml\Generator;
use Ixopay\Client\Xml\Parser;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Class Client
 *
 * @package Ixopay\Client
 */
class Client {

    /**
     * The default url points to the IxoPay Gateway
     */
    const DEFAULT_IXOPAY_URL = 'https://gateway.ixopay.com/';

    const TRANSACTION_ROUTE = 'transaction';

    const OPTIONS_ROUTE = 'options';

    /**
     * @var string
     */
    protected static $ixopayUrl = 'https://gateway.ixopay.com/';

    /**
     * the api key given by the ixopay gateway
     *
     * @var string
     */
    protected $apiKey;

    /**
     * the shared secret belonging to the api key
     *
     * @var string
     */
    protected $sharedSecret;

    /**
     * authentication username of an API user
     *
     * @var string
     */
    protected $username;

    /**
     * authentication password of an API user
     *
     * @var string
     */
    protected $password;

    /**
     * language you want to use (optional)
     *
     * @var string
     */
    protected $language;

    /**
     * set to true if you want to perform a test transaction
     *
     * @deprecated
     * @var bool
     */
    protected $testMode;

	/**
	 * @var LoggerInterface
	 */
    protected $logger;

    /**
     * @param string $username
     * @param string $password
     * @param string $apiKey
     * @param string $sharedSecret
     * @param string $language
     * @param bool   $testMode - DEPRECATED
     */
    public function __construct($username, $password, $apiKey, $sharedSecret, $language = null, $testMode = false) {
        $this->username = $username;
        $this->setPassword($password);
        $this->apiKey = $apiKey;
        $this->sharedSecret = $sharedSecret;
        $this->language = $language;
        $this->testMode = $testMode;
    }

	/**
	 * Set a Logger instance
	 * @param LoggerInterface $logger
	 * @return $this
	 */
    public function setLogger(LoggerInterface $logger) {
    	$this->logger = $logger;
    	return $this;
    }

	/**
     * Logs with an arbitrary level if we have a logger set
     *
     * @param mixed $level
     * @param string $message
     * @param array $context
     * @return null
     */
    public function log($level, $message, array $context = array()) {
    	if ($this->logger && $this->logger instanceof LoggerInterface) {
    		$this->logger->log($level, $message, $context);
    	}
    	//dev/null
    }

    /**
     * build the xml out of the Transaction Object and sends it
     *
     * @param                     $transactionMethod
     * @param AbstractTransaction $transaction
     *
     * @return Result
     *
     * @throws ClientException
     * @throws Exception\InvalidValueException
     */
    protected function sendTransaction($transactionMethod, AbstractTransaction $transaction) {
        $dom = $this->getGenerator()->generateTransaction($transactionMethod, $transaction, $this->username,
            $this->password, $this->language);
        $xml = $dom->saveXML();

        $response = $this->signAndSendXml($xml, $this->apiKey, $this->sharedSecret, self::$ixopayUrl.self::TRANSACTION_ROUTE);

        if ($response->getErrorCode() || $response->getErrorMessage()) {
            throw new ClientException('Request failed: ' . $response->getErrorCode() . ' ' . $response->getErrorMessage());
        }
        if ($response->getStatusCode() == 504 || $response->getStatusCode() == 522) {
            throw new TimeoutException('Request timed-out');
        }

        $parser = $this->getParser();
        return $parser->parseResult($response->getBody());
    }


    /**
     * signs and send a well-formed transaction xml
     *
     * @param string $xml
     * @param string $apiKey
     * @param string $sharedSecret
     * @param string $url
     *
     * @return Response
     */
    public function signAndSendXml($xml, $apiKey, $sharedSecret, $url) {
		$this->log(LogLevel::DEBUG, "POST $url ",
			array(
				'url' => $url,
				'xml' => $xml,
				'apiKey' => $apiKey,
				'sharedSecret' => $sharedSecret,
			)
		);

        $curl = new CurlClient();
        $response = $curl
        	->sign($apiKey, $sharedSecret, $url, $xml)
            ->post($url, $xml);

		$this->log(LogLevel::DEBUG, "RESPONSE: " . $response->getBody(),
			array(
				'response' => $response
			)
		);

		return $response;
    }

    /**
     * register a new user vault
     *
     * NOTE: not all payment methods support this function
     *
     * @param Register $transactionData
     *
     * @return Result
     */
    public function register(Register $transactionData) {
        return $this->sendTransaction('register', $transactionData);
    }

    /**
     * complete a registration (or poll status)
     *
     * NOTE: not all payment methods support this function
     *
     * @param Register $transactionData
     *
     * @return Result
     */
    public function completeRegister(Register $transactionData) {
        return $this->sendTransaction('completeRegister', $transactionData);
    }

    /**
     * deregister a previously registered user vault
     *
     * NOTE: not all payment methods support this function
     *
     * @param Deregister $transactionData
     *
     * @return Result
     */
    public function deregister(Deregister $transactionData) {
        return $this->sendTransaction('deregister', $transactionData);
    }

    /**
     * preauthorize a transaction
     *
     * NOTE: not all payment methods support this function
     *
     * @param Preauthorize $transactionData
     *
     * @return Result
     */
    public function preauthorize(Preauthorize $transactionData) {
        return $this->sendTransaction('preauthorize', $transactionData);
    }

    /**
     * complete a preauthorize transaction (or poll status)
     *
     * @param Preauthorize $transactionData
     *
     * @return Result
     */
    public function completePreauthorize(Preauthorize $transactionData) {
        return $this->sendTransaction('completePreauthorize', $transactionData);
    }

    /**
     * void a previously preauthorized transaction
     *
     * @param \Ixopay\Client\Transaction\VoidTransaction $transactionData
     *
     * @return Result
     */
    public function void(VoidTransaction $transactionData) {
        return $this->sendTransaction('void', $transactionData);
    }

    /**
     * capture a previously preauthorized transaction
     *
     * @param Capture $transactionData
     *
     * @return Result
     */
    public function capture(Capture $transactionData) {
        return $this->sendTransaction('capture', $transactionData);
    }

    /**
     * refund a performed debit/capture
     *
     * @param Refund $transactionData
     *
     * @return Result
     */
    public function refund(Refund $transactionData) {
        return $this->sendTransaction('refund', $transactionData);
    }

    /**
     * perform a debit
     *
     * @param Debit $transactionData
     *
     * @return Result
     */
    public function debit(Debit $transactionData) {
        return $this->sendTransaction('debit', $transactionData);
    }

    /**
     * complete a debit (or poll status)
     *
     * @param Debit $transactionData
     *
     * @return Result
     */
    public function completeDebit(Debit $transactionData) {
        return $this->sendTransaction('completeDebit', $transactionData);
    }

    /**
     * parses the callback notification xml and returns a Result object
     * you SHOULD verify the callback first by using $this->validateCallback();
     *
     * @param string $requestBody
     *
     * @return Callback\Result
     * @throws Exception\InvalidValueException
     */
    public function readCallback($requestBody) {
        return $this->getParser()->parseCallback($requestBody);
    }

    /**
     * validates if the received callback notification is properly signed
     *
     * @param string $requestBody         - the raw xml body of the received request
     * @param string $requestQuery        - the query part of your receiving script, e.g.
     *                                    "/callback_receive.php?someId=0815" (without the hostname and with the
     *                                    leading slash and all query parameters)
     * @param        $dateHeader          - value of the header field "Date"
     * @param        $authorizationHeader - value of the header field "Authorization"
     *
     * @return bool - true if the signature is correct
     */
    public function validateCallback($requestBody, $requestQuery, $dateHeader, $authorizationHeader) {
        $curl = new CurlClient();
        $digest = $curl->createSignature($this->getSharedSecret(), 'POST', $requestBody, 'text/xml; charset=utf-8',
            $dateHeader, $requestQuery);
        $expectedSig = 'IxoPay ' . $this->getApiKey() . ':' . $digest;
        $expectedSig2 = 'Gateway '.$this->getApiKey() . ':' . $digest;

        if (strpos($authorizationHeader, 'Authorization:') !== false) {
            $authorizationHeader = trim(str_replace('Authorization:', '', $authorizationHeader));
        }

        if ($authorizationHeader === $expectedSig || $authorizationHeader === $expectedSig2) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * validate callback by retrieving parameters from PHP GLOBALS
     *
     * @return bool
     */
    public function validateCallbackWithGlobals() {
        $requestBody = file_get_contents('php://input');
        $requestQuery = $_SERVER['REQUEST_URI'].(!empty($_SERVER['QUERY_STRING']) ? '?'.$_SERVER['QUERY_STRING'] : '');
        if (!empty($_SERVER['HTTP_DATE'])) {
            $dateHeader = $_SERVER['HTTP_DATE'];
        } elseif (!empty($_SERVER['HTTP_X_DATE'])) {
            $dateHeader = $_SERVER['HTTP_X_DATE'];
        } else {
            $dateHeader = null;
        }

        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $authorizationHeader = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (!empty($_SERVER['HTTP_X_AUTHORIZATION'])) {
            $authorizationHeader = $_SERVER['HTTP_X_AUTHORIZATION'];
        } else {
            $authorizationHeader = null;
        }


        return $this->validateCallback($requestBody, $requestQuery, $dateHeader, $authorizationHeader);
    }

    /**
     * @return string
     */
    public function getApiKey() {
        return $this->apiKey;
    }

    /**
     * @param string $apiKey
     *
     * @return $this
     */
    public function setApiKey($apiKey) {
        $this->apiKey = $apiKey;
        return $this;
    }

    /**
     * @return string
     */
    public function getSharedSecret() {
        return $this->sharedSecret;
    }

    /**
     * @param string $sharedSecret
     *
     * @return $this
     */
    public function setSharedSecret($sharedSecret) {
        $this->sharedSecret = $sharedSecret;
        return $this;
    }

    /**
     * @return string
     */
    public function getUsername() {
        return $this->username;
    }

    /**
     * @param string $username
     *
     * @return $this
     */
    public function setUsername($username) {
        $this->username = $username;
        return $this;
    }

    /**
     * @return string
     */
    public function getPassword() {
        return $this->password;
    }

    /**
     * @param string $password
     *
     * @return $this
     */
    public function setPassword($password) {
        $this->password = $this->hashPassword($password);
        return $this;
    }

    /**
     * set the hashed password
     *
     * @param string $password
     * @return $this
     */
    public function setHashedPassword($password) {
        $this->password = $password;
        return $this;
    }

    /**
     * @return string
     */
    public function getLanguage() {
        return $this->language;
    }

    /**
     * @param string $language
     *
     * @return $this
     */
    public function setLanguage($language) {
        $this->language = $language;
        return $this;
    }

    /**
     * @return boolean
     * @deprecated
     */
    public function isTestMode() {
        return $this->testMode;
    }

    /**
     * @param boolean $testMode
     *
     * @return $this
     * @deprecated
     */
    public function setTestMode($testMode) {
        $this->testMode = $testMode;
        return $this;
    }

    /**
     * @param string $identifier
     * @param mixed $args [optional]
     * @param mixed $_ [optional]
     * @return mixed
     * @throws ClientException
     * @throws InvalidValueException
     */
    public function getOptions($identifier, $args = null, $_ = null) {
        if (func_num_args() > 1) {
            $args = func_get_args();
            array_shift($args);
        } else {
            $args = array();
        }

        $domDocument = $this->getGenerator()->generateOptions($this->getUsername(), $this->getPassword(), $identifier, $args);
        $xml = $domDocument->saveXML();

        $response = $this->signAndSendXml($xml, $this->apiKey, $this->sharedSecret, self::$ixopayUrl.self::OPTIONS_ROUTE);

        if ($response->getErrorCode() || $response->getErrorMessage()) {
            throw new ClientException('Request failed: ' . $response->getErrorCode() . ' ' . $response->getErrorMessage());
        }

        $return = $this->getParser()->parseOptionsResult($response->getBody());

        return $return;
    }

    /**
     * @return Generator
     */
    protected function getGenerator() {
        return new Generator();
    }

    /**
     * @return Parser
     */
    protected function getParser() {
        return new Parser();
    }

    /**
     * @param string $password
     *
     * @return string
     */
    private function hashPassword($password) {
        for ($i = 0; $i < 10; $i++) {
            $password = sha1($password);
        }
        return $password;
    }

    /**
     * Sets the IxoPay Gateway url (API URL) to the given one. This allows to set up a development/test environment.
     * The API url is already set to the proper value by default.
     *
     * Please note that setting the API URL affects all instances (including the existing ones) of this client.
     *
     * DO NOT MODIFY THE API URL IN PRODUCTION ENVIRONMENT IF IT IS NOT NECESSARY TO PREVENT UNEXPECTED BEHAVIOUR!
     *
     * @param string $url   The URL to use to send the requests to.
     *
     * @return void
     *
     * @throws InvalidValueException
     *
     * @internal
     */
    public static function setApiUrl($url) {
        if (empty($url)) {
            throw new InvalidValueException('The URL to the IxoPay Gateway can not be empty!');
        }

        if (!\filter_var($url, FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED | FILTER_FLAG_HOST_REQUIRED)) {
            throw new InvalidValueException('The URL to the IxoPay Gateway should be a valid URL!');
        }

        static::$ixopayUrl = $url;
    }

    /**
     * Retrieves the currently set API URL.
     *
     * @return string
     */
    public static function getApiUrl() {
        return static::$ixopayUrl;
    }

    /**
     * Retrieves the default API URL.
     *
     * @return string
     */
    public static function getDefaultUrl() {
        return static::DEFAULT_IXOPAY_URL;
    }

    /**
     * Resets the API URL to it's default value.
     *
     * Please note that setting the API URL affects all instances (including the existing ones) of this client.
     *
     * @return void
     */
    public static function resetApiUrl() {
        static::setApiUrl(static::DEFAULT_IXOPAY_URL);
    }
}
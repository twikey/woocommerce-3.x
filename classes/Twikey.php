<?php

class Twikey {

    const VERSION = '2.4.0';
    const TWIKEY_API_TOKEN = "twikey-api-token";

    public $templateId;
    public $websiteKey;
    public $endpoint = "https://api.twikey.com";
    protected $apiToken;
    protected $lang = 'en';

    public function setTestmode($testMode){
        if($testMode){
            $this->endpoint = "https://api.beta.twikey.com";
        }
        else {
            $this->endpoint = "https://api.twikey.com";
        }
    }

    public function getApiToken(){
        return $this->apiToken;
    }

    public function setApiToken($apiToken){
        $this->apiToken = trim($apiToken);
    }

    public function setTemplateId($templateId){
        $this->templateId = trim($templateId);
    }

    public function getTemplateId(){
        return $this->templateId;
    }

    public function setWebsiteKey($websiteKey){
        $this->websiteKey = trim($websiteKey);
    }

    public function setLang($lang){
        $this->lang = $lang;
    }

    /**
     * @throws TwikeyException
     */
    function authenticate() {
        $token = get_transient(self::TWIKEY_API_TOKEN);
        if(empty( $token )){
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, sprintf("%s/creditor", $this->endpoint));
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, sprintf("apiToken=%s", $this->apiToken));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, "twikey-wc/v".Twikey::VERSION);

            $server_output = curl_exec($ch);
            $this->checkResponse($ch, $server_output, "Authentication");
            curl_close($ch);

            $result = json_decode($server_output);
            if(isset($result->{'Authorization'})){
                $token = $result->{'Authorization'};
                $this->log("New Twikey token : $token");
                if(!set_transient( self::TWIKEY_API_TOKEN, $token, 60*60*23 /*23h in seconds*/ ))
                    throw new TwikeyException("Twikey: Could not set token : ".$token);
            }
            else if(isset($result->{'message'})){
                $this->log("Error getting new token $server_output");
                throw new TwikeyException("Twikey: ".$result->{'message'});
            }
            else {
                $this->log("Twikey unreachable  @ ".$this->endpoint."(Response=".$server_output.")");
                throw new TwikeyException("Twikey unreachable");
            }
        }
        else {
            if(TWIKEY_DEBUG)
                $this->log("Reusing token=$token");
        }
        return $token;
    }

    /**
     * @param $data array
     * @return array|mixed|object
     * @throws TwikeyException
     */
    public function createNew($data) {
        $payload = http_build_query($data);
        $this->debugRequest($payload);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, sprintf("%s/creditor/prepare", $this->endpoint));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $this->setCurlDefaults($ch);
        $server_output = curl_exec($ch);
        $this->checkResponse($ch, $server_output, "Creating a new mandate!");
        curl_close($ch);
        return json_decode($server_output);
    }

    /**
     * @param $data
     * @return array
     * @throws TwikeyException
     */
    public function updateMandate($data) {
        $payload = http_build_query($data);
        $this->debugRequest($payload);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, sprintf("%s/creditor/mandate/update", $this->endpoint));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $this->setCurlDefaults($ch);
        $server_output = curl_exec($ch);
        $this->checkResponse($ch, $server_output, "Update mandate");
        curl_close($ch);
        return json_decode($server_output);
    }

    /**
     * @param $mndtId
     * @return array|mixed|object
     * @throws TwikeyException
     */
    public function cancelMandate($mndtId) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, sprintf("%s/creditor/mandate?mndtId=".$mndtId, $this->endpoint));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $this->setCurlDefaults($ch);
        $server_output = curl_exec($ch);
        $this->checkResponse($ch, $server_output, "Cancelled mandate");
        curl_close($ch);
        return json_decode($server_output);
    }

    /**
     * @param $data
     * @return array|mixed|object
     * @throws TwikeyException
     */
    public function newTransaction($data)
    {
        $payload = http_build_query($data);

        $this->debugRequest($payload);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, sprintf("%s/creditor/transaction", $this->endpoint));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $this->setCurlDefaults($ch);
        $server_output = curl_exec($ch);
        $this->checkResponse($ch, $server_output, "Creating a new transaction!");
        curl_close($ch);
        return json_decode($server_output);
    }

    /**
     * @param $data
     * @return array|mixed|object
     * @throws TwikeyException
     */
    public function newLink($data) {
        $payload = http_build_query($data);
        $this->debugRequest($payload);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, sprintf("%s/creditor/payment/link", $this->endpoint));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $this->setCurlDefaults($ch);
        $server_output = curl_exec($ch);
        $this->checkResponse($ch, $server_output, "Creating a new paymentlink!");
        curl_close($ch);
        return json_decode($server_output);
    }

    /**
     * @param $linkid
     * @param $ref
     * @return array|mixed|object
     * @throws TwikeyException
     */
    public function verifyLink($linkid,$ref) {
        if(empty($ref)){
            $payload = http_build_query(array("id" => $linkid));
        }
        else {
            $payload = http_build_query(array("ref" => $ref));
        }
        $this->debugRequest($payload);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, sprintf("%s/creditor/payment/link?%s", $this->endpoint,$payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $this->setCurlDefaults($ch);
        $server_output = curl_exec($ch);
        $this->checkResponse($ch, $server_output, "Verifying a paymentlink ".$payload);
        curl_close($ch);
        return json_decode($server_output);
    }

    /**
     * @param $id
     * @param $detail
     * @return array|mixed|object
     * @throws TwikeyException
     */
    public function getPayments($id, $detail) {
        $payload = http_build_query(array(
            "id" => $id,
            "detail" => $detail
        ));
        $this->debugRequest($payload);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, sprintf("%s/creditor/payment?%s", $this->endpoint, $payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $this->setCurlDefaults($ch);
        $server_output = curl_exec($ch);
        $this->checkResponse($ch, $server_output, "Retrieving payments!");
        curl_close($ch);
        return json_decode($server_output);
    }

    /**
     * @param $txid
     * @param $ref
     * @return array|mixed|object
     * @throws TwikeyException
     */
    public function getPaymentStatus($txid,$ref) {
        if(empty($ref)){
            $payload = http_build_query(array("id" => $txid));
        }
        else {
            $payload = http_build_query(array("ref" => $ref));
        }
        $this->debugRequest($payload);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, sprintf("%s/creditor/transaction/detail?%s", $this->endpoint, $payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $this->setCurlDefaults($ch);
        $server_output = curl_exec($ch);
        $this->checkResponse($ch, $server_output, "Retrieving payments!");
        curl_close($ch);
        return json_decode($server_output);
    }

    /**
     * @throws TwikeyException
     */
    public function getTransactionFeed() {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, sprintf("%s/creditor/transaction", $this->endpoint));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $this->setCurlDefaults($ch);
        $server_output = curl_exec($ch);
        $this->checkResponse($ch, $server_output, "Retrieving transaction feed!");
        curl_close($ch);
        return json_decode($server_output);
    }

    private function setCurlDefaults($ch){
        $token = $this->authenticate();
        curl_setopt($ch, CURLOPT_USERAGENT, "twikey-wc/v".Twikey::VERSION);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Authorization: $token",
            "Accept-Language: $this->lang"
        ));
        if(TWIKEY_HTTP_DEBUG){
            curl_setopt($ch, CURLOPT_VERBOSE, true);
        }
    }

    /**
     * @param $mandateNumber
     * @param $status
     * @param $token
     * @param $signature
     * @return bool
     * @throws TwikeyException
     */
    public function validateSignature($mandateNumber,$status,$token,$signature){
        if(!$this->websiteKey){
            $this->log("No website_key set to validate the exit");
            throw new TwikeyException("No website_key set to validate the exit");
        }
        $calculated = hash_hmac('sha256', sprintf("%s/%s/%s",$mandateNumber,$status,$token), $this->websiteKey);
        $sig_valid = hash_equals($calculated,$signature);
        if(!$sig_valid){
            $this->log("Invalid signature : expected=".$calculated.' was='.$signature);
            throw new TwikeyException('Invalid signature');
        }
        return $sig_valid;
    }

    /**
     * @param $curlHandle
     * @param $server_output
     * @param string $context
     * @return mixed
     * @throws TwikeyException
     */
    private function checkResponse($curlHandle, $server_output, $context = "No context") {
        if (!curl_errno($curlHandle)) {
            $http_code = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
            if ($http_code == 400) { // normal user error
                try {
                    $jsonError = json_decode($server_output);
                    $translatedError = "Twikey: ".$jsonError->message;
                    $this->log(sprintf("%s : Error = %s [%d] (%s)", $context, $translatedError, $http_code, $this->endpoint));
                } catch (Exception $e) {
                    $translatedError = "Twikey: General error";
                    $this->log(sprintf("%s : Error = %s [%d] (%s)", $context, $server_output, $http_code, $this->endpoint));
                }
                throw new TwikeyException($translatedError,$http_code);
            }
            else if ($http_code > 400) {
                $this->log(sprintf("%s : Error = %s (%s)", $context, $server_output, $this->endpoint));
                throw new TwikeyException("Twikey: General error",$http_code);
            }
        }
        if (TWIKEY_HTTP_DEBUG) {
            $this->log(sprintf("Response %s : %s", $context, $server_output));
        }
        return $server_output;
    }

    public function debugRequest($msg){
        if (TWIKEY_HTTP_DEBUG) {
            $this->log('Request : '.$msg);
        }
    }

    /**
     * Override me :)
     * @param $msg
     */
    public function log($msg){
        error_log($msg);
    }
}

class TwikeyException extends Exception { }

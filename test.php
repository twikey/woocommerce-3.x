<?php
require_once( 'classes/Twikey.php' );

$abc = [
    "ct" => "248",
    "email" => "joe@doe.com",
    "firstname" => "Joen",
    "lastname" => "Doe",
    "l" => "en",
    "address" => "Abbey road",
    "city" => "Liverpool",
    "zip" => "1526",
    "country" => "BE",
    "mobile" => "",
    "companyName" => "",
    "form" => "",
    "vatno" => "",
    "iban" => "",
    "bic" => "",
    "mandateNumber" => "",
    "contractNumber" => "",
    "es" => ""
];


$tc = new Twikey();
$tc->setEndpoint('https://api.twikey.com');
$tc->setApiToken('4704F1A6E5F488A97A94BDA41300C0E2D907C048');
try{
    $payments = $tc->newTransaction( [ ]);
}
catch (Exception $e){
    echo 'Caught exception: ',  $e->getMessage(), "\n";
}

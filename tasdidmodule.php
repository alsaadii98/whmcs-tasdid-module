<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function gatewaymodule_MetaData()
{
    return array(
        'DisplayName' => 'Tasdid Payment Gateway Module',
        'APIVersion' => '1.0',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

function gatewaymodule_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Tasdid Payment Gateway Module',
        ),
        'username' => array(
            'FriendlyName' => 'Username',
            'Type' => 'text',
            'Size' => '256',
            'Default' => '',
            'Description' => 'Enter your username here',
        ),
        'password' => array(
            'FriendlyName' => 'Password',
            'Type' => 'password',
            'Size' => '256',
            'Default' => '',
            'Description' => 'Enter password here',
        ),
        /**
         * The Client should login to Tasdid portal and create a new 
         * Service for WHMCS and use the Service UUID here 
         * The Client can set the amount as he like because the 
         * Create Bill API will override it base on the invoice amount
         */
        'serviceUuid' => array(
            'FriendlyName' => 'Service UUID',
            'Type' => 'text',
            'Size' => '256',
            'Default' => '',
            'Description' => 'Enter your Service UUID here',
        ),
        'isProduction' => array(
            'FriendlyName' => 'Production Mode',
            'Type' => 'yesno',
            'Description' => 'Tick to enable production mode',
        ),
        'currency' => array(
            'FriendlyName' => 'Currency',
            'Type' => 'dropdown',
            'Options' => array(
                'IQD' => 'IQD',
                'USD' => 'USD',
            ),
            'Description' => 'Choose one',
        ),
    );
}

function gatewaymodule_link($params)
{
    // Gateway Configuration Parameters
    $username = $params['username'];
    $password = $params['password'];
    $isProduction = $params['isProduction'];
    $serviceUuid = $params['serviceUuid'];
    $currency = $params['currency'];


    // Invoice Parameters
    $invoiceId = $params['invoiceid'];
    $description = $params["description"];
    $amount = $params['amount'];
    $currencyCode = $params['currency'];

    // Client Parameters
    $firstname = $params['clientdetails']['firstname'];
    $lastname = $params['clientdetails']['lastname'];
    $phone = $params['clientdetails']['phonenumber'];



    // System Parameters
    $companyName = $params['companyname'];
    $systemUrl = $params['systemurl'];
    $returnUrl = $params['returnurl'];
    $langPayNow = $params['langpaynow'];
    $moduleDisplayName = $params['name'];
    $moduleName = $params['paymentmethod'];
    $whmcsVersion = $params['whmcsVersion'];



    $htmlOutput = '<form method="post" action="' . $url . '">';
    foreach ($postfields as $k => $v) {
        $htmlOutput .= '<input type="hidden" name="' . $k . '" value="' . urlencode($v) . '" />';
    }
    $htmlOutput .= '<input type="submit" value="' . $langPayNow . '" />';
    $htmlOutput .= '</form>';

    return $htmlOutput;
}


// 1. Login using the credentials to get a token 
function tasdid_login($username, $password, $isProduction)
{
    // Prepare the login payload
    $loginPayload = json_encode(array(
        "Username" => $username,
        "Password" => $password
    ));

    // Url
    $targetUrl = tasdid_get_target_url($isProduction) . "/v1/api/Auth/login";


    // Initialize cURL
    $ch = curl_init($targetUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Accept: application/json'
    ));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $loginPayload);

    // Execute the request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Handle the response
    if ($httpCode == 200) {
        $responseData = json_decode($response, true);
        if (isset($responseData['data']['token'])) {
            return $responseData['data']['token'];
        } else {
            throw new Exception("Login failed: Token not found in response");
        }
    } else {
        throw new Exception("Login failed with HTTP code $httpCode: $response");
    }
}

// 2. Create a new Invoice base on the Service ID and User info and Token 
function tasdid_create_invoice($token, $serviceId, $amount, $description, $firstname, $lastname, $phone, $invoiceId, $returnUrl, $isProduction)
{
    // {"amount":"150000","payId":"","customerName":"علي حسن ","dueDate":"2025-10-12","phoneNumber":"07802070454","serviceId":"fcecc97c-97f7-4f50-8f10-5e5c6a727704","note":""}

    // {"data":{"id":"56402ae7-b4d1-4244-b3b8-7fa6cfd76ddc","payId":"3101102509894087","phoneNumber":"07802070454","customerName":"علي حسن","service":{"id":"fcecc97c-97f7-4f50-8f10-5e5c6a727704","providerId":"851eb6dd-c94e-4731-b245-b5fc21074459","name":"Test adding new Service","note":"Test adding new service","amount":120000,"provider":{"name":"eSite","fileId":"placeholder.png","url":null}},"dueDate":"2025-10-12T23:59:59.9999999","payDate":null,"createDate":"2025-10-08T13:01:07.0975728+03:00","status":2,"amount":150000,"paidAmount":0,"remainingAmount":150000,"note":"","clientType":0},"message":"","succeeded":true,"error":null}

    ///v2/api/Bill/AddBill

    // Prepare the invoice payload
    $invoicePayload = json_encode(array(
        "amount" => $amount,
        "payId" => "",
        "customerName" => $firstname . " " . $lastname,
        "dueDate" => date('Y-m-d', strtotime('+7 days')),
        "phoneNumber" => reformatPhoneNumberFromInternationalToLocal($phone),
        "serviceId" => $serviceId,
        "note" => $invoiceId . " - " . $description
    ));


    // Url
    $targetUrl = tasdid_get_target_url($isProduction) . "/v2/api/Bill/AddBill";

    // Initialize cURL
    $ch = curl_init($targetUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $token
    ));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $invoicePayload);
    
    // Execute the request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Handle the response
    if ($httpCode == 200) {
        $responseData = json_decode($response, true);
        if (isset($responseData['data']['payId'])) {
            return $responseData['data']['payId'];
        } else {
            throw new Exception("Create Invoice failed: payId not found in response");
        }
    } else {
        throw new Exception("Create Invoice failed with HTTP code $httpCode: $response");
    }
}

// Function to return the target URL based on isProduction
function tasdid_get_target_url($isProduction)
{
    if ($isProduction) {
        return "https://api.tasdid.net";
    } else {
        return "https://api-uat.tasdid.net";
    }
}

function reformatPhoneNumberFromInternationalToLocal($phoneNumber)
{
    if (substr($phoneNumber, 0, 4) === '+964') {
        return '0' . substr($phoneNumber, 4);
    }
    return $phoneNumber;
}
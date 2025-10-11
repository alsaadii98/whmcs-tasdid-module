<?php
use Illuminate\Database\Capsule\Manager as Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

// Include RabbitMQ helper
require_once __DIR__ . '/tasdidmodule/rabbitmq_help.php';


function tasdidmodule_MetaData()
{
    return array(
        'DisplayName' => 'Tasdid Payment Gateway Module',
        'APIVersion' => '1.0',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}


function tasdidmodule_config()
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
        'rabbitmq_host' => array(
            'FriendlyName' => 'RabbitMQ Host',
            'Type' => 'text',
            'Size' => '256',
            'Default' => 'localhost',
            'Description' => 'RabbitMQ server hostname',
        ),
        'rabbitmq_port' => array(
            'FriendlyName' => 'RabbitMQ Port',
            'Type' => 'text',
            'Size' => '10',
            'Default' => '5672',
            'Description' => 'RabbitMQ server port',
        ),
        'rabbitmq_user' => array(
            'FriendlyName' => 'RabbitMQ User',
            'Type' => 'text',
            'Size' => '256',
            'Default' => 'whmcs',
            'Description' => 'RabbitMQ username',
        ),
        'rabbitmq_password' => array(
            'FriendlyName' => 'RabbitMQ Password',
            'Type' => 'password',
            'Size' => '256',
            'Default' => '',
            'Description' => 'RabbitMQ password',
        ),
    );
}

// Handle AJAX request for payment initialization
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'init_payment') {
    // Get parameters from the session
    session_start();
    $invoiceId = $_SESSION['invoiceId'] ?? null;
    $systemUrl = $_SESSION['systemUrl'] ?? null;
    $username = $_SESSION['username'] ?? null;
    $password = $_SESSION['password'] ?? null;
    $isProduction = $_SESSION['isProduction'] ?? 'false';
    $serviceUuid = $_SESSION['serviceUuid'] ?? null;
    $description = $_SESSION['description'] ?? '';
    $amount = $_SESSION['amount'] ?? 0;
    $firstname = $_SESSION['firstname'] ?? '';
    $lastname = $_SESSION['lastname'] ?? '';
    $phone = $_SESSION['phone'] ?? '';

    $params = array(
        'username' => $username,
        'password' => $password,
        'isProduction' => $isProduction === 'true' ? true : false,
        'serviceUuid' => $serviceUuid,
        'invoiceid' => $invoiceId,
        'description' => $description,
        'amount' => $amount,
        'clientdetails' => array(
            'firstname' => $firstname,
            'lastname' => $lastname,
            'phonenumber' => $phone
        ),
        'systemurl' => $systemUrl
    );

    $response = tasdidmodule_init_payment($params);

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

function tasdidmodule_link($params)
{
    // Start PHP session to store temporary data
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }

    $invoiceId = $params['invoiceid'];
    $systemUrl = $params['systemurl'];
    $langPayNow = $params['langpaynow'];
    $username = $params['username'];
    $password = $params['password'];
    $isProduction = $params['isProduction'] ? 'true' : 'false';
    $serviceUuid = $params['serviceUuid'];
    $description = $params['description'] ?? '';
    $amount = $params['amount'] ?? 0;
    $firstname = $params['clientdetails']['firstname'] ?? '';
    $lastname = $params['clientdetails']['lastname'] ?? '';
    $phone = $params['clientdetails']['phonenumber'] ?? '';


    // Store necessary data in session for AJAX request
    $_SESSION['invoiceId'] = $invoiceId;
    $_SESSION['systemUrl'] = $systemUrl;
    $_SESSION['username'] = $username;
    $_SESSION['password'] = $password;
    $_SESSION['isProduction'] = $isProduction;
    $_SESSION['serviceUuid'] = $serviceUuid;
    $_SESSION['description'] = $description;
    $_SESSION['amount'] = $amount;
    $_SESSION['firstname'] = $firstname;
    $_SESSION['lastname'] = $lastname;
    $_SESSION['phone'] = $phone;


    $htmlOutput = "
    <script  src=\"https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js\"></script>
    <script src=\"modules/gateways/tasdidmodule/tasdid_payment.js?version=1.0.0\"></script>
    
    <div x-data=\"tasdidPayment()\" class=\"tasdid-payment-container\">
        <button type=\"button\" class=\"btn btn-success btn-lg\" 
                :disabled=\"loading || paymentUrl\" 
                @click=\"initPayment()\">
            <span x-show=\"!loading && !paymentUrl\">$langPayNow</span>
            <span x-show=\"loading\">Processing...</span>
            <span x-show=\"paymentUrl\">Payment Ready</span>
        </button>

        <button type=\"button\" class=\"btn btn-primary btn-lg\" 
                x-show=\"paymentUrl\" 
                @click=\"goToPayment()\">
            Go to Payment
        </button>
    </div>";


    return $htmlOutput;
}

function tasdidmodule_init_payment($params)
{
    try {
        // Gateway Configuration Parameters
        $username = $params['username'];
        $password = $params['password'];
        $isProduction = $params['isProduction'];
        $serviceUuid = $params['serviceUuid'];

        // Invoice Parameters
        $invoiceId = $params['invoiceid'];
        $description = $params["description"];
        $amount = $params['amount'];

        // Client Parameters
        $firstname = $params['clientdetails']['firstname'];
        $lastname = $params['clientdetails']['lastname'];
        $phone = $params['clientdetails']['phonenumber'];

        // System Parameters
        $systemUrl = $params['systemurl'];

        // Get authentication token
        $token = tasdid_login($username, $password, $isProduction);

        // Create invoice in Tasdid
        $payId = tasdid_create_invoice(
            $token,
            $serviceUuid,
            $amount,
            $description,
            $firstname,
            $lastname,
            $phone,
            $invoiceId,
            $systemUrl,
            $isProduction
        );

        // // Store payId in invoice notes
        $pdo = Capsule::connection()->getPdo();
        $stmt = $pdo->prepare("UPDATE tblinvoices SET notes = ? WHERE id = ?");
        $stmt->execute([$payId, $invoiceId]);

        // Generate payment URL
        $paymentUrl = tasdid_get_payment_url($payId, $isProduction);

        return [
            'success' => true,
            'payId' => $payId,
            'paymentUrl' => $paymentUrl
        ];

    } catch (Exception $e) {
        logModuleCall('tasdidmodule', 'ajax_payment_init_error', $params, $e->getMessage(), [], []);
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
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
        "phoneNumber" => addZeroToPhoneNumber($phone),
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

function tasdid_get_payment_url($payId, $isProduction)
{
    $baseUrl = $isProduction ? "https://pay.tasdid.net" : "https:///pay-uat.tasdid.net";
    return $baseUrl . "?id=" . $payId;
}

function addZeroToPhoneNumber($phoneNumber)
{
    if (substr($phoneNumber, 0, 1) !== '0') {
        return '0' . $phoneNumber;
    }
    return $phoneNumber;
}
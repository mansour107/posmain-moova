

<?php

class ZKTFingerprintMachine {
    private $socket;
    private $ip;
    private $port;

    public function __construct($ip, $port) {
        $this->ip = $ip;
        $this->port = $port;
    }

    private function connect() {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->socket === false) {
            die("Error creating socket: " . socket_strerror(socket_last_error()));
        }

        $result = socket_connect($this->socket, $this->ip, $this->port);
        if ($result === false) {
            die("Error connecting to the server: " . socket_strerror(socket_last_error($this->socket)));
        }
    }

    private function sendCommand($command) {
        socket_write($this->socket, $command, strlen($command));
        $response = socket_read($this->socket, 2048);
        return $response;
    }

    public function getDeviceInfo() {
        $this->connect();
        $command = 'GetDeviceInfo';
        $response = $this->sendCommand($command);
        socket_close($this->socket);
        return $response;
    }

    // Other methods to interact with the fingerprint machine can be added here
}

// Example usage:

$machineIP = '192.168.1.100'; // Replace with your fingerprint machine's IP
$machinePort = 4370; // Default ZKTeco port

$zkMachine = new ZKTFingerprintMachine($machineIP, $machinePort);
$deviceInfo = $zkMachine->getDeviceInfo();
echo $deviceInfo;

?>


                                                                              

<?php
                                                                                // advision
// Replace these with your actual API credentials and endpoint
$apiBaseUrl = 'https://api.advision.com';
$apiKey = 'your-api-key';

// Function to make an API request
function makeApiRequest($url, $headers = [], $method = 'GET', $data = null) {
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response = curl_exec($curl);
    curl_close($curl);

    return $response;
}

// Example: Fetch data from the fingerprint machine's API
$endpoint = '/api/users'; // Replace with the actual endpoint provided by AdVision

$headers = [
    'Authorization: Bearer ' . $apiKey,
    'Content-Type: application/json',
];

// Make a GET request to retrieve users from the fingerprint machine
$response = makeApiRequest($apiBaseUrl . $endpoint, $headers);

// Process the API response
if ($response) {
    $responseData = json_decode($response, true);

    // Process the data as needed
    var_dump($responseData);
} else {
    echo "Failed to fetch data from the API.";
}

?>

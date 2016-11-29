<?php

require_once(__DIR__ . '/../PHPWebSocket.php.inc');

use \PHPWebSocket\Update\Read;

$address = 'tcp://127.0.0.1:9001';
$port = 9001;

$descriptorSpec = [['pipe', 'r'], STDOUT, STDERR];
$wstestProc = proc_open('wstest -m fuzzingserver -s Autobahn/fuzzingserver.json', $descriptorSpec, $pipes, __DIR__);

sleep(2);

$client = new \PHPWebSocket\Client();
if (!$client->connect($address, '/getCaseCount')) {
    echo 'Unable to connect to server: ' . $client->getLastError() . PHP_EOL;
    exit(1);
}

$caseCount = NULL;

while ($client->isOpen()) {
    foreach ($client->update() as $key => $value) {
        if ($value instanceof Read && $value->getCode() === Read::C_READ) {
            $caseCount = (int) $value->getMessage();
        }
    }
}

echo 'Will run ' . $caseCount . ' test cases' . PHP_EOL;

for ($i = 0; $i < $caseCount; $i++) {

    $client = new \PHPWebSocket\Client();
    $client->connect($address, '/runCase?case=' . ($i + 1) . '&agent=' . $client->getUserAgent());

    while ($client->isOpen()) {

        $updates = $client->update();
        foreach ($updates as $update) {

            if ($update instanceof Read && $update->getCode() === Read::C_READ) {
                $client->write($update->getMessage() ?? '', $update->getOpcode());
            }

        }

    }

}

echo 'All test cases ran, asking for report update' . PHP_EOL;

$client = new \PHPWebSocket\Client();
$client->connect($address, '/updateReports?agent=' . $client->getUserAgent());

while ($client->isOpen()) {
    foreach ($client->update() as $key => $value) {

    }
}

echo 'Reports finished, getting results..' . PHP_EOL;

$outputFile = '/tmp/reports/clients/index.json';
if (!file_exists($outputFile)) {
    echo 'File "' . $outputFile . '" doesn\'t exist!';
    exit(1);
}

$hasFailures = FALSE;
$testCases = json_decode(file_get_contents($outputFile), TRUE)[$client->getUserAgent()];
foreach ($testCases as $case => $data) {

    echo $case . ' => ' . $data['behavior'] . PHP_EOL;

    switch ($data['behavior']) {
        case 'OK':
        case 'NON-STRICT':
        case 'INFORMATIONAL':
        case 'UNIMPLEMENTED':
            break;
        default:
            $hasFailures = TRUE;
            break;
    }

}

echo 'Exiting' . PHP_EOL;

exit((int) $hasFailures);

exit();

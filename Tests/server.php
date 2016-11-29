<?php

require_once(__DIR__ . '/../PHPWebSocket.php.inc');

use \PHPWebSocket\Update\Read;

echo 'Starting test' . PHP_EOL . PHP_EOL;

$websocket = new \PHPWebSocket\Server('tcp://0.0.0.0:9001');

$descriptorSpec = [['pipe', 'r'], STDOUT, STDERR];
$wstestProc = proc_open('wstest -m fuzzingclient -s Autobahn/fuzzingclient.json', $descriptorSpec, $pipes, __DIR__);

while (proc_get_status($wstestProc)['running'] ?? FALSE) {

    $updates = $websocket->update(0.1);
    foreach ($updates as $update) {

        if ($update instanceof Read) {

            $sourceObj = $update->getSourceObject();
            $opcode = $update->getCode();
            switch ($opcode) {
                case Read::C_NEWCONNECTION:
                    $sourceObj->accept();
                    break;
                case Read::C_READ:

                    $opcode = $update->getOpcode();
                    switch ($opcode) {
                        case \PHPWebSocket::OPCODE_CONTINUE:
                        case \PHPWebSocket::OPCODE_FRAME_TEXT:
                        case \PHPWebSocket::OPCODE_FRAME_BINARY:

                            $msg = $update->getMessage();
                            if ($msg !== NULL && !$sourceObj->isDisconnecting()) {
                                $sourceObj->write($msg, $opcode);
                            }

                            break;
                    }

                    break;
            }

        }

    }

}

echo 'Test ended, closing websocket' . PHP_EOL;

$websocket->close();

echo 'Getting results..' . PHP_EOL;

$outputFile = '/tmp/reports/servers/index.json';
if (!file_exists($outputFile)) {
    echo 'File "' . $outputFile . '" doesn\'t exist!';
    exit(1);
}

$hasFailures = FALSE;
$testCases = json_decode(file_get_contents($outputFile), TRUE)[$websocket->getServerIdentifier()];
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

<?php

require_once(__DIR__ . '/../PHPWebSocket.php.inc');

$websocket = new \PHPWebSocket\Server('tcp://0.0.0.0:9001');

$descriptorSpec = [['pipe', 'r'], STDOUT, STDERR];
$wstestProc = proc_open('wstest -m fuzzingclient -s Autobahn/fuzzingclient.json', $descriptorSpec, $pipes, __DIR__);

while (proc_get_status($wstestProc)['running'] ?? FALSE) {

    $updates = $websocket->update(0.1);
    foreach ($updates as $update) {

        if ($update instanceof \PHPWebSocket\Update\Read) {

            if ($update->getCode() === \PHPWebSocket\Update\Read::C_NEWCONNECTION) {
                $update->getSourceObject()->accept();
            }

            if ($update->getMessage() !== NULL && ($update->getCode() === \PHPWebSocket::OPCODE_CONTINUE || $update->getCode() === \PHPWebSocket::OPCODE_FRAME_TEXT || $update->getCode() === \PHPWebSocket::OPCODE_FRAME_BINARY) && !$update->getSourceObject()->isDisconnecting()) {
                $update->getSourceObject()->write($update->getMessage(), $update->getOpcode());
            }

        }

        echo($update . PHP_EOL);

    }

}

$websocket->close();

$hasFailures = FALSE;
$testCases = json_decode(file_get_contents('/tmp/reports/servers/index.json'), TRUE)[$websocket->getServerIdentifier()];
foreach ($testCases as $case => $data) {

    echo($case . ' => ' . $data['behavior'] . PHP_EOL);

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

exit((int) $hasFailures);

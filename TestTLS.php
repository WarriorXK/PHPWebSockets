<?php

declare(strict_types = 1);

require_once __DIR__ . '/vendor/autoload.php';

spl_autoload_register([\PHPWebSockets::class, 'Autoload']);

//$client = new \PHPWebSockets\Client();
//
//if (!$client->connect('tcp://localhost:1337', '/ws')) {
//    \PHPWebSockets::Log('error', 'Unable to connect to server: ' . $client->getLastError());
//    exit(1);
//}
//
//$caseCount = NULL;
//
//while ($client->isOpen()) {
//    foreach ($client->update() as $key => $value) {
//
//        \PHPWebSockets::Log('info', $value . '');
//
//    }
//}
//
//var_dump($client->getHeaders());

$str = str_repeat('a', 6000);
$maskingKey = 'aaaaaa';

$start = microtime(TRUE);
for ($i = 0; $i < 1000000; $i++) {
    pack('nn', 1, strlen($str)) . $maskingKey . $str;
}
echo(microtime(TRUE) - $start . PHP_EOL);

$start = microtime(TRUE);
for ($i = 0; $i < 100000; $i++) {
    $len = strlen($str);
    chr(1) . chr(pack('n', $len)) . $len . $maskingKey . $str;
}
echo(microtime(TRUE) - $start . PHP_EOL);

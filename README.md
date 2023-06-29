# PHPWebSockets
[![Code documented](https://codedocs.xyz/WarriorXK/PHPWebSockets.svg)](https://codedocs.xyz/WarriorXK/PHPWebSockets/) Master: ![Build Status](https://github.com/WarriorXK/PHPWebSockets/actions/workflows/Main.yaml/badge.svg?branch=master) Develop: ![Build Status](https://github.com/WarriorXK/PHPWebSockets/actions/workflows/Main.yaml/badge.svg?branch=develop)

A PHP library to accept and create websocket connections, we aim to be 100% compliant with the websocket RFC and use the [Autobahn test suite](http://autobahn.ws/testsuite/) to ensure so.
Currently the server and the client are 100% compliant with the autobahn testsuite minus a few non-strict notices, the [compression extension](https://tools.ietf.org/html/rfc7692) for websockets will be implemented later

## Server
For websocket servers a new \PHPWebSockets\Server instance should be created with a bind address and a port to listen on.
For ease of use you can use the UpdatesWrapper class, this will trigger certain callables set on basic functions.

A basic websocket echo server would be:

```php
require_once __DIR__ . '/vendor/autoload.php';

$wrapper = new \PHPWebSockets\UpdatesWrapper();
$wrapper->setMessageHandler(function(\PHPWebSockets\AConnection $connection, string $message, int $opcode) {

    echo 'Got message with length ' . strlen($message) . PHP_EOL;
    $connection->write($message, $opcode);

});


$server = new \PHPWebSockets\Server('tcp://0.0.0.0:9001');

while (TRUE) {
    $wrapper->update(0.1, $server->getConnections(TRUE));
}
```

If more control is required you can manually call ```$server->update(0.1);``` instead of using the wrapper, this will yield update objects which can be responded to.

## Client
For connecting to a server the \PHPWebSockets\Client class should be constructed and the method connect($address, $port, $path) should be used to connect.
For ease of use you can again use the UpdatesWrapper class or use ```$server->update(0.1);``` for better control.

A basic websocket echo client would be:

```php
require_once __DIR__ . '/../vendor/autoload.php';

$wrapper = new \PHPWebSockets\UpdatesWrapper();
$wrapper->setMessageHandler(function(\PHPWebSockets\AConnection $connection, string $message, int $opcode) {

    echo 'Got message with length ' . strlen($message) . PHP_EOL;
    $connection->write($message, $opcode);

});


$client = new \PHPWebSockets\Client();
if (!$client->connect('tcp://localhost:9001/webSocket')) {
    die('Unable to connect to server: ' . $client->getLastError() . PHP_EOL);
}

while (TRUE) {
    $wrapper->update(0.1, [$client]);
}
```

# PHPWebSockets
[![Code documented](https://codedocs.xyz/WarriorXK/PHPWebSockets.svg)](https://codedocs.xyz/WarriorXK/PHPWebSockets/) [![Code Climate](https://codeclimate.com/github/WarriorXK/PHPWebSockets/badges/gpa.svg)](https://codeclimate.com/github/WarriorXK/PHPWebSockets) Master: [![Build Status](https://travis-ci.org/WarriorXK/PHPWebSockets.svg?branch=master)](https://travis-ci.org/WarriorXK/PHPWebSockets) Develop: [![Build Status](https://travis-ci.org/WarriorXK/PHPWebSockets.svg?branch=develop)](https://travis-ci.org/WarriorXK/PHPWebSockets)

A PHP 7.0+ library to accept and create websocket connections, we aim to be 100% compliant with the websocket RFC and use the [Autobahn test suite](http://autobahn.ws/testsuite/) to ensure so.
Currently the server and the client are 100% compliant with the autobahn testsuite minus a few non-strict notices, the [compression extension](https://tools.ietf.org/html/rfc7692) for websockets will be implemented later

## Server
For websocket servers a new \PHPWebSockets\Server instance should be created with a bind address and a port to listen on.
By calling ->update on the instance of the server the connection will be checked for updates, if there are any updates they will be yielded as a result of calling ->update

A basic websocket echo server would be:

```php
require('PHPWebSockets/PHPWebSocket.php.inc');

$websocket = new \PHPWebSockets\Server('tcp://0.0.0.0:9001');

while (TRUE) {

    $updates = $websocket->update();
    foreach ($updates as $update) {

        if ($update instanceof \PHPWebSockets\Update\Read) {

            if ($update->getCode() === \PHPWebSockets\Update\Read::C_NEWCONNECTION) {
                $update->getSourceObject()->accept();
            }

            if ($update->getMessage() !== NULL && ($update->getCode() === \PHPWebSocket::OPCODE_CONTINUE || $update->getCode() === \PHPWebSocket::OPCODE_FRAME_TEXT || $update->getCode() === \PHPWebSocket::OPCODE_FRAME_BINARY) && !$update->getSourceObject()->isDisconnecting()) {
                $update->getSourceObject()->write($update->getMessage(), $update->getOpcode());
            }

        }

        echo($update . PHP_EOL);

    }

}
```

## Client
For connecting to a server the \PHPWebSockets\Client class should be constructed and the method connect($address, $port, $path) should be used to connect.
By calling ->update on the client instance the websocket will be checked for updates, if there are any updates they will be yielded as a result of calling ->update

A basic websocket echo client would be:

```php
require('PHPWebSocket/PHPWebSocket.php.inc');

$client = new \PHPWebSockets\Client();
if (!$client->connect('tcp://localhost:9001/webSocket')) {
    die('Unable to connect to server: ' . $client->getLastError() . PHP_EOL);
}

while ($client->isOpen()) {

    foreach ($client->update() as $update) {

        if ($update instanceof \PHPWebSockets\Update\Read && $update->getCode() === \PHPWebSockets\Update\Read::C_READ) {
            $client->write($update->getMessage() ?? '', $update->getOpcode());
        }

        echo($update . PHP_EOL);

    }

}
```

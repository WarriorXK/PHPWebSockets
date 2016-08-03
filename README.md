# PHPWebSockets
A PHP 7.0+ library to accept and create websocket connections, we aim to be 100% compliant with the websocket RFC and use the [Autobahn test suite](http://autobahn.ws/testsuite/) to ensure so.
Currently the server and the client are 100% compliant with the autobahn testsuite minus a few non-strict notices, the [compression extension](https://tools.ietf.org/html/rfc7692) for websockets will be implemented later

## Server
For websocket servers a new \PHPWebSocket\Server instance should be created with a bind address and a port to listen on.
By calling ->update on the instance of the server the connection will be checked for updates, if there are any updates they will be yielded as a result of calling ->update

A basic websocket echo server would be:

```php
require('PHPWebSocket/PHPWebSocket.php.inc');

$websocket = new \PHPWebSocket\Server('0.0.0.0', 9001);

while (TRUE) {

    $updates = $websocket->update();
    foreach ($updates as $update) {

        if ($update instanceof \PHPWebSocket\Update\Read) {

            if ($update->getCode() === \PHPWebSocket\Update\Read::C_NEWCONNECTION) {
                $update->getSourceObject()->accept();
            }

            if ($update->getMessage() !== NULL && ($update->getCode() === \PHPWebSocket::OPCODE_CONTINUE || $update->getCode() === \PHPWebSocket::OPCODE_FRAME_TEXT || $update->getCode() === \PHPWebSocket::OPCODE_FRAME_BINARY) && !$update->getSourceObject()->isDisconnecting()) {
                $update->getSourceObject()->write($update->getMessage(), $update->getOpcode());
            }

            echo($update . PHP_EOL);
        } else {
            echo($update . '' . PHP_EOL);
        }

    }

}
```

## Client
For connecting to a server the \PHPWebSocket\Client class should be constructed and the method connect($address, $port, $path) should be used to connect.
By calling ->update on the client instance the websocket will be checked for updates, if there are any updates they will be yielded as a result of calling ->update

A basic websocket echo client would be:

```php
require('PHPWebSocket/PHPWebSocket.php.inc');

$client = new \PHPWebSocket\Client();
if (!$client->connect('localhost', 9001, '/webSocket')) {
    die('Unable to connect to server: ' . $client->getLastError() . PHP_EOL);
}

while ($client->isOpen()) {

    foreach ($client->update() as $key => $value) {

        if ($update instanceof \PHPWebSocket\Update\Read && $update->getCode() === \PHPWebSocket\Update\Read::C_READ) {
            $client->write($update->getMessage() ?? '', $update->getOpcode());
        }

        echo($update . PHP_EOL);

    }

}
```

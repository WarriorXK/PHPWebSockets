# PHPWebSockets
A PHP 7.0+ library to accept and create websocket connections

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

Currently the client is not finished yet, this is still a WIP

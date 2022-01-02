<?php

require_once __DIR__ . '/vendor/autoload.php';

use Clue\React\Sse\BufferedChannel;
use Clue\React\Sse\Encoder;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use React\Stream\ThroughStream;
use function React\Async\await;

$loop = React\EventLoop\Loop::get();
$channel = new BufferedChannel();
$redisClientFactory = new Clue\React\Redis\Factory($loop);

$dataClient = $redisClientFactory->createLazyClient("localhost:6379");

/**
 * @param $dataClient
 * @return mixed
 */
function getPopulation($dataClient)
{
    return $dataClient->lrange('countries', 0, -1)
        ->then(function ($countries) use ($dataClient) {
            $dataClient->multi();
            foreach ($countries as $countryAlias) {
                $dataClient->hgetall($countryAlias);
            }
            return $dataClient->exec()
                ->then(function ($countryPopulation) use ($countries) {
                    $transformed = [];
                    foreach ($countries as $index => $country) {
                        $population = $countryPopulation[$index];
                        $transformed[$country] = [
                            'slug' => $country,
                            'name' => $population[1],
                            'population' => (int)$population[3],
                            'landArea' => (int)$population[5],
                        ];
                    }
                    return $transformed;
                });
        });
}

$http = new React\Http\HttpServer($loop, function (ServerRequestInterface $request) use ($channel, $loop, $dataClient) {
    if ($request->getUri()->getPath() === '/') {
        return new Response(
            200,
            ['Content-Type' => 'text/html'],
            file_get_contents(__DIR__ . '/eventsource.html')
        );
    }

    echo 'connected' . PHP_EOL;

    $stream = new ThroughStream();

    $id = $request->getHeaderLine('Last-Event-ID');

    $loop->futureTick(function () use ($channel, $stream, $id, $dataClient, $loop) {
        $channel->connect($stream, $id);
        getPopulation($dataClient)->then(function ($populations) use ($stream) {
            $stream->write((new Encoder)->encodeMessage(json_encode($populations), 'initialUpdate'));
        });
    });

    $stream->on('close', function () use ($stream, $channel) {
        echo 'disconnected' . PHP_EOL;
        $channel->disconnect($stream);
    });

//    $stream->on('data', function () use ($stream, $channel) {
////        echo json_encode(func_get_args());
//    });

    return new Response(
        200,
        ['Content-Type' => 'text/event-stream'],
        $stream
    );
});

$socket = new SocketServer('0.0.0.0:9800');
$http->listen($socket);

echo 'Your server running on 0.0.0.0:9800' . PHP_EOL;

/** @var \Clue\React\Redis\Client $client */
try {
    $pubSubClient = await($redisClientFactory->createClient("localhost:6379"));
} catch (Throwable $e) {
    echo 'ERROR: Unable to connect to Redis: ' . $e;
}

$pubSubClient->on('message', function ($topic, $message) use ($channel) {
    $channel->writeMessage($message, 'update');
    echo 'Memory in use: ' . memory_get_usage() . ' (' . ((memory_get_usage() / 1024) / 1024) . 'M)' . PHP_EOL;
});

try {
    await($pubSubClient->subscribe('countryUpdate'));
} catch (Throwable $e) {
    echo 'ERROR: Unable to subscribe to Redis channel: ' . $e;
}

$loop->run();

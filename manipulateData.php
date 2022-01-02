<?php

require_once __DIR__ . '/vendor/autoload.php';

$loop = React\EventLoop\Loop::get();

$factory = new Clue\React\Redis\Factory($loop);
$redis = $factory->createLazyClient('localhost:6379');
$redisPublish = $factory->createLazyClient('localhost:6379');

$countries = React\Async\await($redis->lrange('countries', 0, -1));

$loop->addPeriodicTimer(1, function () use ($countries, $redis, $redisPublish) {
    $countrySlug = $countries[array_rand($countries)];
//    $countrySlug = "united-kingdom";
    $populationInc = rand(-200000, 200000);
    $redis->hincrby($countrySlug, 'population', $populationInc)
        ->then(function ($data) use ($redisPublish, $countrySlug) {
            $publishData = json_encode([
                'country' => $countrySlug,
                'population' => $data,
            ]);
            $redisPublish->publish('countryUpdate', $publishData);
        });
});

$loop->run();

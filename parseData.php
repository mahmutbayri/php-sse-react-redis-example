<?php

use Illuminate\Support\Str;

require_once __DIR__ . '/vendor/autoload.php';

$fileStream = fopen('data.txt', 'r');

$factory = new Clue\React\Redis\Factory();
$redis = $factory->createLazyClient('localhost:6379');

while (($line = fgetcsv($fileStream, 0, "\t")) !== false) {
    $name = $line[1];
    $keyName = Str::slug($name);
    $population = (int)str_replace(',', '', $line[2]);
    $landArea = (int)str_replace(',', '', $line[6]);

    $countryList[] = $keyName;

    $redis->hmset(
        $keyName,
        "name",
        $name,
        "population",
        $population,
        "land-area",
        $landArea
    );

    $redis->rpush('countries', $keyName);
}

fclose($fileStream);

$redis->end();

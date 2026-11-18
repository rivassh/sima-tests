<?php
require "/var/www/websites/sima/vendor/autoload.php";

$ref = new ReflectionClass("App\\Modules\\Chat\\Services\\ChatQueryService");
$method = $ref->getMethod("stripMonologue");
$method->setAccessible(true);
$instance = $ref->newInstanceWithoutConstructor();

echo "Test 1 - < think> tag with space:\n";
$input = "Here is the answer< think>hidden< /think> done";
echo "Input: $input\n";
$result = $method->invoke($instance, $input);
echo "Output: $result\n\n";

echo "Test 2 - thinking keyword:\n";
$input = "We are thinking about it.";
echo "Input: $input\n";
$result = $method->invoke($instance, $input);
echo "Output: $result\n\n";

echo "Test 3 - internal keyword:\n";
$input = "This is an internal thought.";
echo "Input: $input\n";
$result = $method->invoke($instance, $input);
echo "Output: $result\n";
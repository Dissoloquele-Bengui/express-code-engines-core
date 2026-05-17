# make_test.py - cria test_autoload.php
php = r"""<?php
require __DIR__ . '/vendor/autoload.php';

// Teste 1: ficheiro existe?
$path = 'packages/shared/src/DTOs/ConstraintContext.php';
echo 'File exists: ' . (file_exists($path) ? 'YES' : 'NO') . PHP_EOL;

// Teste 2: autoloader encontra o ficheiro?
$loader = require 'vendor/autoload.php';
$file = $loader->findFile('LaravelEngines\Shared\DTOs\ConstraintContext');
echo 'Autoloader finds: ' . ($file ?: 'NOT FOUND') . PHP_EOL;

// Teste 3: classe carrega?
$exists = class_exists('LaravelEngines\Shared\DTOs\ConstraintContext');
echo 'Class exists: ' . ($exists ? 'YES' : 'NO') . PHP_EOL;

// Teste 4: instanciar
if ($exists) {
    $obj = new \LaravelEngines\Shared\DTOs\ConstraintContext(entityClass: 'Test', data: []);
    echo 'entityClass: ' . $obj->entityClass . PHP_EOL;
} else {
    echo 'CANNOT INSTANTIATE - class not found' . PHP_EOL;
}
"""

with open("test_autoload.php", "w", encoding="utf-8", newline="\n") as f:
    f.write(php)
print("test_autoload.php criado. Corre: php test_autoload.php")
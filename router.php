<?php
declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';
(new QualitySystem\Http\CompatibilityRouter(__DIR__))->dispatch($_SERVER['REQUEST_URI'] ?? '/');

<?php
declare(strict_types=1);
namespace QualitySystem\Http;
final class CompatibilityRouter
{
    public function __construct(private readonly string $projectRoot) {}
    public function dispatch(string $requestUri): void
    {
        $path = rawurldecode((string)parse_url($requestUri, PHP_URL_PATH));
        if ($path === '/') $path = '/login.php';
        if ($path === '/router.php') { http_response_code(404); exit('Not found.'); }
        if ($path === '/tasks' || str_starts_with($path, '/tasks/')) {
            require_once $this->projectRoot . '/auth_check.php';
            require_once $this->projectRoot . '/role_check.php';
            require_once $this->projectRoot . '/db.php';
            (new \QualitySystem\Controllers\TaskController($conn, $this->projectRoot))->dispatch($path, $_SERVER['REQUEST_METHOD'] ?? 'GET');
            return;
        }
        if ($path === '/program-specifications' || str_starts_with($path, '/program-specifications/')) {
            require_once $this->projectRoot . '/auth_check.php';
            require_once $this->projectRoot . '/role_check.php';
            require_once $this->projectRoot . '/db.php';
            (new \QualitySystem\Controllers\ProgramSpecificationController($conn, $this->projectRoot))->dispatch($path, $_SERVER['REQUEST_METHOD'] ?? 'GET');
            return;
        }
        if ($path === '/program-manuals' || str_starts_with($path, '/program-manuals/')) {
            require_once $this->projectRoot . '/auth_check.php';
            require_once $this->projectRoot . '/role_check.php';
            require_once $this->projectRoot . '/db.php';
            (new \QualitySystem\Controllers\ProgramManualController($conn, $this->projectRoot))->dispatch($path, $_SERVER['REQUEST_METHOD'] ?? 'GET');
            return;
        }
        if ($path === '/course-reports' || str_starts_with($path, '/course-reports/')) {
            require_once $this->projectRoot . '/auth_check.php';require_once $this->projectRoot . '/role_check.php';require_once $this->projectRoot . '/db.php';
            (new \QualitySystem\Controllers\CourseReportController($conn,$this->projectRoot))->dispatch($path,$_SERVER['REQUEST_METHOD']??'GET');return;
        }
        if (str_contains($path, "\0") || str_contains($path, '..') || preg_match('#^/(?:database|tests|scripts|app|vendor)(?:/|$)#i', $path)) { http_response_code(404); exit('Not found.'); }
        $target = realpath($this->projectRoot . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR));
        $root = realpath($this->projectRoot);
        if ($target === false || $root === false || !str_starts_with(strtolower($target), strtolower($root . DIRECTORY_SEPARATOR))) { http_response_code(404); exit('Not found.'); }
        if (is_file($target) && strtolower(pathinfo($target, PATHINFO_EXTENSION)) !== 'php') return;
        if (!is_file($target) || strtolower(pathinfo($target, PATHINFO_EXTENSION)) !== 'php') { http_response_code(404); exit('Not found.'); }
        $_SERVER['SCRIPT_FILENAME'] = $target;
        $_SERVER['SCRIPT_NAME'] = $path;
        require $target;
    }
}

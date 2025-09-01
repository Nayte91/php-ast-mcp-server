#!/usr/bin/env php
<?php

use ast\Node;

if (!extension_loaded('ast')) {
    http_response_code(500);
    echo json_encode(["error" => "php-ast extension not loaded"]) . "\n";
    exit(1);
}

enum Filter {
    case ALL;
    case PUBLIC;
}

class Logger
{
    public static function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents('php://stdout', "[$timestamp] $message" . PHP_EOL);
    }
    
    public static function formatBytes(int $bytes): string
    {
        return round($bytes / 1024 / 1024, 2) . 'MB';
    }
    
    public static function formatDuration(float $seconds): string
    {
        return round($seconds, 3) . 's';
    }
}

class AstProvider
{
    private int $astVersion = 110;

    public function parseFile(string $filePath, Filter $filter): array
    {
        $this->validatePath($filePath);

        if (!is_file($filePath)) {
            throw new InvalidArgumentException("Path is not a file: $filePath");
        }

        try {
            $ast = ast\parse_file($filePath, $this->astVersion);
            $filteredAst = $this->applyDefaultFilters($ast, $filter);
            return [$filePath => $filteredAst];
        } catch (Throwable $e) {
            return [$filePath => ["error" => $e->getMessage()]];
        }
    }

    public function parseDirectory(string $dirPath, Filter $filter): array
    {
        $this->validatePath($dirPath);

        if (!is_dir($dirPath)) {
            throw new InvalidArgumentException("Path is not a directory: $dirPath");
        }

        $files = [];
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dirPath));
        foreach ($rii as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        $output = [];
        foreach ($files as $filePath) {
            $fileResult = $this->parseFile($filePath, $filter);
            $output = array_merge($output, $fileResult);
        }

        return $output;
    }

    private function validatePath(string $path): void
    {
        if (!file_exists($path)) {
            throw new InvalidArgumentException("Path not found: $path");
        }
    }

    private function applyDefaultFilters(Node $node, Filter $filter): ?array
    {
        $classNode = $this->findFirstNodeOfKind($node, ast\AST_CLASS);
        if (!$classNode) {
            return null; // No class found, return nothing
        }
        
        return $this->createClassSummary($classNode, $filter);
    }
    
    private function findFirstNodeOfKind(string|Node|null $node, $targetKind): ?Node
    {
        if (is_string($node) || is_null($node)) return null;

        if ($node->kind === $targetKind) {
            return $node;
        }
        
        if (is_array($node->children)) {
            foreach ($node->children as $child) {
                $result = $this->findFirstNodeOfKind($child, $targetKind);
                if ($result) {
                    return $result;
                }
            }
        }

        return null;
    }
    
    private function createClassSummary(Node $classNode, Filter $filter): ?array
    {
        if ($classNode->kind !== ast\AST_CLASS) {
            return null;
        }

        return [
            'type' => 'class_summary',
            'name' => $this->extractClassName($classNode),
            'interfaces' => $this->extractInterfaces($classNode),
            'properties' => $this->extractProperties($classNode, $filter),
            'methods' => $this->extractMethods($classNode, $filter),
        ];
    }
    
    private function extractClassName($classNode)
    {
        if (isset($classNode->children['name'])) {
            return $classNode->children['name'];
        }
        return 'UnknownClass';
    }
    
    private function extractInterfaces($classNode): array
    {
        $interfaces = [];
        if (isset($classNode->children['implements'])) {
            $implementsNode = $classNode->children['implements'];
            if ($implementsNode instanceof Node && is_array($implementsNode->children)) {
                foreach ($implementsNode->children as $interface) {
                    if ($interface instanceof Node && isset($interface->children['name'])) {
                        $interfaces[] = $interface->children['name'];
                    }
                }
            }
        }
        return $interfaces;
    }
    
    private function extractProperties($classNode, FIlter $filter): array
    {
        $properties = [];
        $this->walkNodeForProperties($classNode, $properties, $filter);
        return $properties;
    }

    private function walkNodeForProperties($node, array &$properties, Filter $filter): void
    {
        if (!($node instanceof Node)) return;

        if ($node->kind === ast\AST_PROP_GROUP) {
            $visibility = ($node->flags & ast\flags\MODIFIER_PUBLIC) ? 'public' :
                    (($node->flags & ast\flags\MODIFIER_PROTECTED) ? 'protected' : 'private');
            $isAbstract = (bool)($node->flags & ast\flags\MODIFIER_ABSTRACT);

            if ($filter === Filter::ALL || ($filter === Filter::PUBLIC && $visibility === 'public' && !$isAbstract)) {
                if (isset($node->children['props']) && $node->children['props'] instanceof Node) {
                    foreach ($node->children['props']->children as $propElem) {
                        if ($propElem instanceof Node && isset($propElem->children['name'])) {
                            $properties[] = [
                                    'name' => $propElem->children['name'],
                                    'visibility' => $visibility,
                            ];
                        }
                    }
                }
            }
        }

        if (is_array($node->children)) {
            foreach ($node->children as $child) {
                $this->walkNodeForProperties($child, $properties, $filter);
            }
        }
    }
    
    private function extractPropertyAttributes($propGroupNode): array
    {
        $attributes = [];
        if (isset($propGroupNode->children['attributes'])) {
            $attrList = $propGroupNode->children['attributes'];
            if ($attrList instanceof Node && is_array($attrList->children)) {
                foreach ($attrList->children as $attrGroup) {
                    if ($attrGroup instanceof Node && is_array($attrGroup->children)) {
                        foreach ($attrGroup->children as $attr) {
                            if ($attr instanceof Node && isset($attr->children['class'])) {
                                $className = $this->extractAttributeClassName($attr->children['class']);
                                if ($className) {
                                    $attributes[] = $className;
                                }
                            }
                        }
                    }
                }
            }
        }
        return $attributes;
    }
    
    private function extractAttributeClassName($classNode)
    {
        if (!($classNode instanceof Node)) {
            return null;
        }
        
        if (isset($classNode->children['name'])) {
            return $classNode->children['name'];
        }
        
        return null;
    }
    
    private function extractMethods($classNode, Filter $filter): array
    {
        $methods = [];
        $this->walkNodeForMethods($classNode, $methods, $filter);

        return $methods;
    }

    private function walkNodeForMethods($node, array &$methods, Filter $filter): void
    {
        if (!($node instanceof Node)) return;

        if ($node->kind === ast\AST_METHOD) {
            $visibility = ($node->flags & ast\flags\MODIFIER_PUBLIC) ? 'public' :
                    (($node->flags & ast\flags\MODIFIER_PROTECTED) ? 'protected' : 'private');
            $isAbstract = (bool)($node->flags & ast\flags\MODIFIER_ABSTRACT);

            if ($filter === Filter::ALL || ($filter === Filter::PUBLIC && $visibility === 'public' && !$isAbstract)) {
                $methods[] = [
                        'name' => $node->children['name'] ?? 'unknown',
                        'visibility' => $visibility,
                        'parameters' => $this->extractMethodParameters($node),
                        'return_type' => $this->extractReturnType($node),
                ];
            }
        }

        if (is_array($node->children)) {
            foreach ($node->children as $child) {
                $this->walkNodeForMethods($child, $methods, $filter);
            }
        }
    }
    
    private function extractMethodParameters($methodNode): int
    {
        if (isset($methodNode->children['params']) && $methodNode->children['params'] instanceof Node) {
            $paramsList = $methodNode->children['params'];
            if (is_array($paramsList->children)) {
                return count($paramsList->children);
            }
        }

        return 0;
    }
    
    private function extractReturnType(Node $methodNode): string
    {
        $returnType = $methodNode->children['returnType'] ?? null;

        if ($returnType === null) {
            return 'untyped';
        }

        return $this->stringifyType($returnType);
    }

    private function stringifyType($node): string
    {
        // Cas historique : directement un int
        if (is_int($node)) {
            return $this->mapTypeFlagToName($node);
        }

        if (is_string($node)) {
            return $node;
        }

        if ($node instanceof Node) {
            return match ($node->kind) {
                ast\AST_TYPE =>
                $this->mapTypeFlagToName($node->flags),

                ast\AST_NAME =>
                        $node->children['name'] ?? 'unknown',

                ast\AST_NULLABLE_TYPE =>
                        '?' . $this->stringifyType($node->children['type']),

                ast\AST_TYPE_UNION =>
                implode('|', array_map([$this, 'stringifyType'], $node->children)),

                ast\AST_TYPE_INTERSECTION =>
                implode('&', array_map([$this, 'stringifyType'], $node->children)),

                default => 'unknown',
            };
        }

        return 'unknown';
    }

    private function mapTypeFlagToName(int $flag): string
    {
        return match ($flag) {
            ast\flags\TYPE_VOID => 'void',
            ast\flags\TYPE_NULL => 'null',
            ast\flags\TYPE_FALSE => 'false',
            ast\flags\TYPE_BOOL => 'bool',
            ast\flags\TYPE_LONG => 'int',
            ast\flags\TYPE_DOUBLE => 'float',
            ast\flags\TYPE_STRING => 'string',
            ast\flags\TYPE_ARRAY => 'array',
            ast\flags\TYPE_OBJECT => 'object',
            ast\flags\TYPE_CALLABLE => 'callable',
            ast\flags\TYPE_ITERABLE => 'iterable',
            ast\flags\TYPE_MIXED => 'mixed',
            default => 'unknown',
        };
    }

    private function serialize_ast_node(Node $node): array
    {
        return [
            "kind" => ast\get_kind_name($node->kind),
            "flags" => $node->flags,
            "children" => array_map([$this, 'serialize_ast_node'], $node->children),
        ];
    }
}

function handleHttpRequest(): void
{
    $memStart = memory_get_usage();
    $timeStart = microtime(true);
    
    header('Content-Type: application/json');

    $requestMethod = $_SERVER['REQUEST_METHOD'];
    if ($requestMethod !== 'GET') {
        http_response_code(405);
        $response = json_encode(["error" => "Only GET requests are supported"]);
        echo $response;
        
        $tokens = strlen($response);
        $memUsed = Logger::formatBytes(memory_get_usage() - $memStart);
        $duration = Logger::formatDuration(microtime(true) - $timeStart);
        Logger::log("[HTTP] Error Response: status=405, tokens=$tokens, memory=$memUsed, duration=$duration");
        exit;
    }

    $filter = filter_var($_GET['public'] ?? false, FILTER_VALIDATE_BOOL) ? Filter::PUBLIC : Filter::ALL;
    $filterStr = $filter === Filter::PUBLIC ? 'PUBLIC' : 'ALL';

    $path = $_GET['path'] ?? null;
    
    // Log de la requête entrante
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    Logger::log("[HTTP] Request from $clientIp: path=" . ($path ?? 'none') . ", filter=$filterStr");
    
    if (!$path) {
        $response = json_encode(["error" => "Missing 'path' query parameter"]);
        echo $response;
        
        $tokens = strlen($response);
        $memUsed = Logger::formatBytes(memory_get_usage() - $memStart);
        $duration = Logger::formatDuration(microtime(true) - $timeStart);
        Logger::log("[HTTP] Error Response: status=400, tokens=$tokens, memory=$memUsed, duration=$duration");
        exit;
    }

    $provider = new AstProvider();

    try {
        if (is_dir($path)) {
            $result = $provider->parseDirectory($path, $filter);
        } else {
            $result = $provider->parseFile($path, $filter);
        }

        $response = json_encode($result, JSON_PRETTY_PRINT);
        echo $response;
        
        // Log de la réponse avec métriques
        $tokens = strlen($response);
        $memUsed = Logger::formatBytes(memory_get_usage() - $memStart);
        $duration = Logger::formatDuration(microtime(true) - $timeStart);
        Logger::log("[HTTP] Response: status=200, tokens=$tokens, memory=$memUsed, duration=$duration");
        
    } catch (Throwable $e) {
        http_response_code(400);
        $response = json_encode(["error" => $e->getMessage()]);
        echo $response;
        
        $tokens = strlen($response);
        $memUsed = Logger::formatBytes(memory_get_usage() - $memStart);
        $duration = Logger::formatDuration(microtime(true) - $timeStart);
        Logger::log("[HTTP] Error Response: status=400, error=\"{$e->getMessage()}\", tokens=$tokens, memory=$memUsed, duration=$duration");
    }
}

function handleCliRequest(): void
{
    $memStart = memory_get_usage();
    $timeStart = microtime(true);
    
    global $argc, $argv;

    if ($argc < 2) {
        echo "Usage: php ast_unified.php <file_or_directory_path>\n";
        echo "Example: php ast_unified.php /app/src/User/Entity/User.php\n";
        exit(1);
    }

    $filter = in_array('--public', $argv, true) ? Filter::PUBLIC : Filter::ALL;
    $filterStr = $filter === Filter::PUBLIC ? 'PUBLIC' : 'ALL';
    $path = $argv[1];
    
    // Log de la requête CLI
    Logger::log("[CLI] Request: path=$path, filter=$filterStr");
    
    $provider = new AstProvider();

    try {
        if (is_dir($path)) {
            $result = $provider->parseDirectory($path, $filter);
        } else {
            $result = $provider->parseFile($path, $filter);
        }

        $response = json_encode($result, JSON_PRETTY_PRINT);
        echo $response . "\n";
        
        // Log de la réponse avec métriques
        $tokens = strlen($response);
        $memUsed = Logger::formatBytes(memory_get_usage() - $memStart);
        $duration = Logger::formatDuration(microtime(true) - $timeStart);
        Logger::log("[CLI] Response: tokens=$tokens, memory=$memUsed, duration=$duration");
        
    } catch (Throwable $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        
        $memUsed = Logger::formatBytes(memory_get_usage() - $memStart);
        $duration = Logger::formatDuration(microtime(true) - $timeStart);
        Logger::log("[CLI] Error Response: error=\"{$e->getMessage()}\", memory=$memUsed, duration=$duration");
        
        exit(1);
    }
}

$isHttp = php_sapi_name() === 'cli-server' || isset($_SERVER['REQUEST_METHOD']);

match (true) {
    $isHttp => handleHttpRequest(),
    php_sapi_name() === 'cli' => handleCliRequest(),
    default => throw new RuntimeException("Unknown mode.")
};
<?php
/**
 * PHP Namespace Validator
 * Checks all namespaces and use statements in a PHP project
 */

class NamespaceValidator
{
    private $projectRoot;
    private $composerConfig;
    private $declaredNamespaces = [];
    private $errors = [];
    private $warnings = [];

    public function __construct($projectRoot)
    {
        $this->projectRoot = rtrim($projectRoot, '/');
        $this->loadComposerConfig();
    }

    private function loadComposerConfig()
    {
        $composerPath = $this->projectRoot . '/composer.json';
        if (file_exists($composerPath)) {
            $this->composerConfig = json_decode(file_get_contents($composerPath), true);
            foreach ($this->composerConfig['autoload']['psr-4'] as $namespace => $folder) {
                $this->declaredNamespaces[$namespace] = $folder;
            };

            krsort($this->declaredNamespaces);
        }
    }

    public function validate()
    {
        echo "🔍 Starting namespace validation...\n\n";
        
        $phpFiles = [];

        foreach ($this->declaredNamespaces as $folder) {
            $phpFiles = array_merge(
                $phpFiles,
                $this->findPhpFiles(trim($folder, '/'))
            );
        }

        echo "Found " . count($phpFiles) . " PHP files\n\n";

        foreach ($phpFiles as $file) {
            $this->validateFile($file);
        }

        $this->printResults();
        return empty($this->errors);
    }

    private function findPhpFiles($folder)
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(rtrim($this->projectRoot, '/') . '/' . $folder)
        );

        $phpFiles = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                // Skip vendor directory
                if (strpos($file->getPathname(), '/vendor/') !== false) {
                    continue;
                }
                $phpFiles[] = $file->getPathname();
            }
        }

        return $phpFiles;
    }

    private function validateFile($filePath)
    {
        $content = file_get_contents($filePath);
        $relativePath = str_replace($this->projectRoot . '/', '', $filePath);

        // Parse namespace declaration
        $declaredNamespace = $this->extractNamespace($content);
        
        // Parse use statements
        $useStatements = $this->extractUseStatements($content);

        // Validate namespace against file path
        $this->validateNamespaceLocation($declaredNamespace, $filePath, $relativePath);
        
        // Validate use statements
        $this->validateUseStatements($useStatements, $filePath, $relativePath);

        echo "✓ Checked: $relativePath\n";
    }

    private function extractNamespace($content)
    {
        if (preg_match('/^namespace\s+([^;]+);/m', $content, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    private function extractUseStatements($content)
    {
        $uses = [];
        
        // Match use statements (simple and aliased)
        if (preg_match_all('/^use\s+([^;]+);/m', $content, $matches)) {
            foreach ($matches[1] as $useStatement) {
                $useStatement = trim($useStatement);
                
                // Handle aliases (use Foo\Bar as Baz)
                if (strpos($useStatement, ' as ') !== false) {
                    [$className, $alias] = explode(' as ', $useStatement, 2);
                    $uses[] = [
                        'full' => trim($className),
                        'alias' => trim($alias),
                        'original' => $useStatement
                    ];
                } else {
                    $uses[] = [
                        'full' => $useStatement,
                        'alias' => basename(str_replace('\\', '/', $useStatement)),
                        'original' => $useStatement
                    ];
                }
            }
        }

        return $uses;
    }

    private function validateNamespaceLocation($namespace, $filePath, $relativePath)
    {
        if (!$namespace) {
            return; // Files without namespace are okay
        }

        // Check against composer.json autoload configuration
        if ($this->composerConfig && isset($this->composerConfig['autoload']['psr-4'])) {
            $found = false;
            
            foreach ($this->declaredNamespaces as $prefix => $path) {
                $prefix = rtrim($prefix, '\\');
                
                if (strpos($namespace, $prefix) === 0) {
                    // Calculate expected path
                    $expectedPath = $path . str_replace('\\', '/', substr($namespace, strlen($prefix)));
                    $actualDir = dirname($relativePath);
                    
                    if (strpos($actualDir, rtrim($expectedPath, '/')) !== 0) {
                        $this->errors[] = [
                            'file' => $relativePath,
                            'type' => 'namespace_location',
                            'message' => "Namespace '$namespace' doesn't match file location. Expected in: $expectedPath"
                        ];
                    }
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $this->warnings[] = [
                    'file' => $relativePath,
                    'type' => 'namespace_not_autoloaded',
                    'message' => "Namespace '$namespace' not covered by composer autoload configuration"
                ];
            }
        }
    }

    private function validateUseStatements($useStatements, $filePath, $relativePath)
    {
        foreach ($useStatements as $use) {
            $className = $use['full'];

            // Try to resolve the class
            if (!$this->classExists($className)) {
                // Check if it's a PHP built-in
                if (!$this->isBuiltinClass($className) && !$this->isVendorClass($className)) {
                    $this->errors[] = [
                        'file' => $relativePath,
                        'type' => 'missing_class',
                        'message' => "Class '$className' not found (from use statement: {$use['original']})"
                    ];
                }
            }
        }
    }

    private function classExists($className)
    {
        // First try class_exists (works for autoloaded classes)
        if (class_exists($className, true) || interface_exists($className, true) || trait_exists($className, true)) {
            return true;
        }

        // Manual check by converting namespace to file path
        return $this->findClassFile($className) !== null;
    }

    private function findClassFile($className)
    {
        if (!$this->composerConfig || !isset($this->composerConfig['autoload']['psr-4'])) {
            return null;
        }

        foreach ($this->composerConfig['autoload']['psr-4'] as $prefix => $path) {
            $prefix = rtrim($prefix, '\\');
            
            if (strpos($className, $prefix) === 0) {
                $relativePath = str_replace('\\', '/', substr($className, strlen($prefix)));
                $filePath = $this->projectRoot . '/' . rtrim($path, '/') . '/' . ltrim($relativePath, '/') . '.php';
                
                if (file_exists($filePath)) {
                    return $filePath;
                }
            }
        }

        return null;
    }

    private function isBuiltinClass($className)
    {
        $builtins = [
            'Exception', 'Error', 'DateTime', 'DateTimeImmutable', 'DateInterval',
            'PDO', 'PDOStatement', 'PDOException', 'SplFileInfo', 'RecursiveDirectoryIterator',
            'RecursiveIteratorIterator', 'ArrayObject', 'stdClass'
        ];
        
        $classBaseName = basename(str_replace('\\', '/', $className));
        return in_array($classBaseName, $builtins) || class_exists($className, false);
    }

    private function isVendorClass($className)
    {
        foreach (array_keys($this->declaredNamespaces) as $namespace) {
            if (str_starts_with(ltrim($className, '\\'), $namespace)) {
                return false;
            }
        }

        return true;
    }

    private function printResults()
    {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "VALIDATION RESULTS\n";
        echo str_repeat("=", 60) . "\n\n";

        if (empty($this->errors) && empty($this->warnings)) {
            echo "🎉 All namespaces and use statements are valid!\n";
            return;
        }

        if (!empty($this->errors)) {
            echo "❌ ERRORS (" . count($this->errors) . "):\n";
            echo str_repeat("-", 40) . "\n";
            
            foreach ($this->errors as $error) {
                echo "File: {$error['file']}\n";
                echo "Type: {$error['type']}\n";
                echo "Issue: {$error['message']}\n\n";
            }
        }

        if (!empty($this->warnings)) {
            echo "⚠️  WARNINGS (" . count($this->warnings) . "):\n";
            echo str_repeat("-", 40) . "\n";
            
            foreach ($this->warnings as $warning) {
                echo "File: {$warning['file']}\n";
                echo "Type: {$warning['type']}\n";
                echo "Issue: {$warning['message']}\n\n";
            }
        }

        echo "Summary: " . count($this->errors) . " errors, " . count($this->warnings) . " warnings\n";
    }
}

// Usage
if ($argc < 2) {
    echo "Usage: php namespace_validator.php /path/to/your/project\n";
    exit(1);
}

$projectPath = $argv[1];
if (!is_dir($projectPath)) {
    echo "Error: Directory '$projectPath' does not exist.\n";
    exit(1);
}

$validator = new NamespaceValidator($projectPath);
$success = $validator->validate();

exit($success ? 0 : 1);
?>

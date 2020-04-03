#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

const DOCUSIGN_SRC_DIR = __DIR__ . '/../vendor/docusign/esign-client/src';
const DOCUSIGN_NAMESPACE = '\\DocuSign\\eSign';
const DOCUSIGN_API_NAMESPACE = DOCUSIGN_NAMESPACE . '\\Api';

const SRC_DIR = __DIR__ . '/../src';
const API_DIR = SRC_DIR . '/Api';

function createApiClassDocblock($classname)
{
    $vendorClassname = DOCUSIGN_API_NAMESPACE . '\\' . $classname . 'Api';
    $reflectionClass = new ReflectionClass($vendorClassname);
    $publicMethods = $reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC);
    $methods = [];
    foreach ($publicMethods as $method) {
        if (strpos($method->name, 'WithHttpInfo') !== false || strpos($method->name, '_') !== false) {
            continue;
        }

        $params = $method->getParameters();
        if (count($params) > 0 && $params[0]->name === 'account_id') {
            array_shift($params);
        }

        $description = '';
        if (preg_match_all('/\*\s+([\w\s\(\)]+)/', $method->getDocComment(), $textMatches)) {
            $description = implode(' ', array_filter(array_map('trim', $textMatches[1]), static function ($param) {
                return !empty($param) && stripos($param, 'operation') !== 0;
            }));
        }

        $paramDoc = [];
        if (preg_match_all('/@param\s+([^\$]\S+)?\s*(\$\w+)?(.*)/', $method->getDocComment(), $matches)) {
            [$allMatches, $types, $varnames, $descriptions] = $matches;

            foreach ($allMatches as $k => $m) {
                if ($varnames[$k] !== '$account_id') {
                    // if there is no type this will be empty and we try to fetch from reflection
                    $type = $types[$k] ?: getParamType($params, $varnames[$k]);
                    // If this returns an option type then we need to add that method
                    if (stripos($type, $vendorClassname . '\\' . ucfirst($method->name) . 'Options') !== false) {
                        $setters = array_filter((new ReflectionClass($type))->getMethods(), static function (ReflectionMethod $method) {
                            // Only want setters because that's what the class supports
                            return strpos($method->name, 'set') === 0;
                        });
                        // Create the array values with null defaults
                        $arrayKeys = array_map(static function (ReflectionMethod $setter) {
                            return "'" . strtolower(preg_replace('/[A-Z]([A-Z](?![a-z]))*/', '_$0', lcfirst($setter->name))) . "' => null";
                        }, $setters);

                        $methods[] = ' * @method ' . $type . ' ' . $method->name . 'Options(array $options = [' . implode(', ', $arrayKeys) . '])';
                    }
                    // If there is still no type we skip it
                    $paramDoc[] = ($type ? $type . ' ' : '')
                        . $varnames[$k]
                        // Default to null when optional
                        . (strpos($descriptions[$k], '(optional)') !== false ? ' = null' : '');
                }
            }
        }

        $returnType = '';
        if ($method->hasReturnType()) {
            $returnType = (string)$method->getReturnType();
        } elseif (preg_match('/@return\s+(\S+)/', $method->getDocComment(), $returnMatch)) {

            if ($returnMatch[1] === $classname . 'Api') {
                // If this returns itself we need to give the FQCN
                $returnMatch[1] = DOCUSIGN_API_NAMESPACE . '\\' . $returnMatch[1];
            }

            $returnType = $returnMatch[1];
        }

        $methods[] = ' * @method ' . $returnType . ' ' . $method->name . '(' . implode(', ', $paramDoc) . ') ' . $description;
    }

    $methodBlock = implode("\n", $methods);

    return <<<DOCBLOC
/**
 * Class $classname
$methodBlock
 */
DOCBLOC;
}

/**
 * @param ReflectionParameter[] $parameters
 * @param $param
 * @return string|null
 */
function getParamType(array $parameters, string $param)
{
    foreach ($parameters as $p) {
        if ($p->name === preg_replace('/^\$/', '', $param) && $p->hasType()) {
            return "\\" . ((string)$p->getType());
        }
    }

    return '';
}

function generateApiClasses()
{
    // Remove old Api classes EXCEPT for the BaseApi class
    foreach (glob(API_DIR . '/*.php') as $apiFile) {
        if ($apiFile !== API_DIR . '/BaseApi.php') {
            unlink($apiFile);
        }
    }

    // Only generate a 1:1 with docusign
    foreach (glob(DOCUSIGN_SRC_DIR . '/Api/*.php') as $apiFile) {
        $classname = str_replace('Api.php', '', basename($apiFile));
        $docBlock = createApiClassDocblock($classname);
        $template = <<<TEMPLATE
<?php

namespace DocuSign\Rest\Api;

$docBlock
class $classname extends BaseApi
{

}
TEMPLATE;

        file_put_contents(API_DIR . '/' . $classname . '.php', $template);
    }
}

function generateClientDocBlocks()
{
    $docblock = ['/**', ' * Class Client'];
    foreach (glob(API_DIR . '/*.php') as $file) {
        if (strpos($file, 'BaseApi') === false) {
            $filename = basename($file);
            $classname = str_replace('.php', '', $filename);
            $varName = '$' . lcfirst($classname);
            $docblock[] = ' * @property-read Api\\' . $classname . ' ' . $varName;
        }
    }

    foreach (glob(DOCUSIGN_SRC_DIR . '/Model/*.php') as $modelFile) {
        $modelBaseClass = str_replace('.php', '', basename($modelFile));
        $modelClass = '\\DocuSign\\eSign\\Model\\' . $modelBaseClass;
        $constructorArray = implode(', ', array_map(static function ($prop) {
            return "'$prop' => null";
        }, array_keys($modelClass::setters())));
        $docblock[] = ' * @method ' . $modelClass . ' ' . lcfirst($modelBaseClass) . '(array $props = [' . $constructorArray . '])';
    }

    $docblock[] = ' */';

    $clientReflection = new ReflectionClass(\DocuSign\Rest\Client::class);

    $client = SRC_DIR . '/Client.php';
    $content = str_replace($clientReflection->getDocComment(), implode("\n", $docblock), file_get_contents($client));
    echo 'Writing Client';
    file_put_contents($client, $content);
}

// These come first because client docblocks rely on them
generateApiClasses();
// Generates docblocks for our API implementation
generateClientDocBlocks();
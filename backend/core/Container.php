<?php

class Container {
    private array $instances = [];

    public function get(string $class) {
        if (isset($this->instances[$class])) {
            return $this->instances[$class];
        }
        
        $instance = $this->resolve($class);
        $this->instances[$class] = $instance;
        return $instance;
    }

    private function resolve(string $class) {
        if (!class_exists($class)) {
            throw new Exception("Class {$class} does not exist.");
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new Exception("Class {$class} is not instantiable.");
        }

        $constructor = $reflection->getConstructor();
        if (!$constructor) {
            return new $class();
        }

        $parameters = $constructor->getParameters();
        $dependencies = [];

        foreach ($parameters as $param) {
            $type = $param->getType();
            if (!$type || $type->isBuiltin()) {
                if ($param->isDefaultValueAvailable()) {
                    $dependencies[] = $param->getDefaultValue();
                } else {
                    throw new Exception("Cannot resolve parameter {$param->getName()} in {$class}");
                }
            } else {
                $dependencies[] = $this->get($type->getName());
            }
        }

        return $reflection->newInstanceArgs($dependencies);
    }
}

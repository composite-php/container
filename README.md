# DI container

Yet another implementation of [PSR-11](https://www.php-fig.org/psr/psr-11/) with support of automatic constructor injection.
Requires PHP `8.1` or higher.

## Features

- Implements [PSR-11](http://www.php-fig.org/psr/psr-11/).
- Supports automatic constructor injection ((via `Reflection`).
- Detects cyclic dependencies.

## Installation

Install with composer:

```shell
composer require composite/container
```

## Usage

### Automatic resolution

The container can do constructor injection automatically.
Assume you have the following classes in your project:

```php
<?php

// Your simple logger.
class Logger
{
    public function log(string $message): void
    {
        echo $message;
    }
}

// Your users store: responsible for containing usernames.
class UsersStore
{
    private array $users = [];
    
    public function add(string $username): void
    {
        $this->users[$username] = true;
    }
}

// Your service that is responsible for registering user: persisting username and writing something to log.
class UserRegistrationService
{
    public function __construct(
        private Logger $logger,
        private UsersStore $store
    ) {
    }
    
    public function registerUser(string $name): void
    {
        $this->store->add($name);
        $this->logger->log('User was registered.');
    }
}
```

In order to create an instance of `UserRegistrationService`, you should pass its dependencies to the constructor:

```php
<?php

$regService = new UserRegistrationService(
    new Logger(),
    new UsersStore()
);

$regService->register('Foo');
```

The container is capable of doing it on its own:

```php
<?php

use Traso\Container\Container;

$container = new Container();
// Ask the container to get instance of UserRegistrationService.
// The container will create instances of Logger and UsersStore,
// then it will return the UserRegistrationService with the required dependencies.
$regService = $container->get(UserRegistrationService::class);

$regService->register('Foo');
```

The container is capable of automatic injection of arguments, as long as they are
concrete classes or built-in types with default values:

```php
<?php

// Instance of this class can be instantiated automatically, because there are no constructor arguments.
class A
{
    
}

// Instance of this class can be instantiated automatically, because the parameter is an instance of a concrete class.
class B
{
    public function __construct(
        public A $a
    ) {
    }
}

// Instance of this class can be instantiated automatically, because the parameter is an instance of concrete class.
// When B (being an argument) is instantiated, it gets injected with A.
// So, resolution of dependencies is recursive.
class C
{
    public function __construct(
        public B $b
    ) {
    }
}

// The following will be resolved with default "/tmp/default" value.
class FileLogger
{
    public function __construct(
        private string $targetFile = '/tmp/default'
    ) {}
}

// The following cannot be instantiated automatically,
// because the container doesn't know what to pass as constructor argument.
class FileLogger
{
    public function __construct(
        private string $targetFile
    ) {
    }
}

interface LoggerInterface
{

}

// Instance of this service cannot be instantiated automatically,
// because constructor argument is type hinted against interface, which cannot be instantiated.
class MyService
{
    public function __construct(
        public LoggerInterface $logger
    )
}
```

### Entries are reused once resolved

The container caches resolved entries (it also means, that the container retains references to resolved entries),
so, be careful when writing stateful code:

```php
<?php

use Traso\Container\Container;

$container = new Container();
// Resolve your service from the container.
$regService = $container->get(UserRegistrationService::class);
// Register user.
$regService->register('Foo');
// Resolve your service from the container again - the container will return previously created instance.
$regService = $container->get(UserRegistrationService::class);
$regService->register('Foo'); // Will throw an exception, because of the logic in UserStore::add.
```

Another example that demonstrates this:

```php
<?php

class MyLogger
{
    public function __construct() {
        echo 'Constructor invoked!';
    }
}

$container = new Container();

// The following line outputs 'Constructor invoked!'
$container->get(MyLogger::class);

// The following line outputs nothing, because the container will return instance of `MyLogger` that was created before (and constructor of which had been called).
$container->get(MyLogger::class);

```

### Custom Definitions

You can specify definitions for entries by passing them to the constructor of the container.
Each definition must be a callable identified by the entry ID. The simplest case would be an array:

```php
<?php

use Traso\Container\Container;

// Define that whenever an instance of LoggerInterface is required,
// the container should return instance of FileLogger.
$definitions = [
    LoggerInterface::class => static function (Container $container) {
        return $container->get(FileLogger::class);
    }
];
// Create container instance, passing the definitions.
$container = new Traso\Container\Container($definitions);

// Returns instance of FileLogger.
$hotelsProvider = $container->get(LoggerInterface::class);
```

However, any `iterable` is accepted by the constructor. Some may find this notation better:

```php
<?php

use Psr\Container\ContainerInterface;
use Traso\Container\Container;

$definitions = static function (): iterable {
    yield ContainerInterface::class
        => static fn (Container $container)
        => $container;
    yield UsersRepository::class
        => static fn (Container $container)
        => $container->get(DatabaseUserRepository::class);
    yield Config::class
        => static fn ()
        => new Config();
};

$container = new Container($definitions());

```

You are not limited to classes/objects only, of course:

```php
<?php

use Traso\Container\Container;

$definitions = [
    'projectRoot' => static function () {
        return '/opt/xres';
    }
];

$container = new Container($definitions);

// Returns '/opt/xres' string.
$projectRoot = $container->get('projectRoot');
```

Once container is instantiated, its definitions cannot be modified.

**Custom entries are prioritized over existing classes.**

Usage is straightforward: create container instance, optionally passing your definitions.

```php
<?php

declare(strict_types=1);

use Composite\Container;

// Create container instance without custom definitions.
$container = new Container();

```

The constructor accepts your definitions in form of an iterable (`array`/`Generator`/`Traversable`):

```php
<?php

use Psr\Container\ContainerInterface;

class ContainerDefinitions implements IteratorAggregate
{
    public function getIterator() : Traversable
    {
        // When someone needs instance of `ContainerInterface`, return the container itself.
        yield ContainerInterface::class
            => fn (Container $container) => $container;
    
        // When someone gets FactoryInterface, return concrete factory implementation.
        yield FactoryInterface::class 
            => fn (Container $container) 
            => $container->get(MyConcreteFactory::class);
    }
}

// Create container with the definitions.
$container = new Container(new ContainerDefitions());

// When requested for FactoryInterface instance, container will return MyConcreteFactory according
// to your definition.
/** @var MyConcreteFactory $myFactory */
$myFactory = $container->get(FactoryInterface::class);

```

They key must be *item name* and the value must be a `callable` which returns the *item*.
The callable argument will be the `Container` instance.

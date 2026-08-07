<?php

declare(strict_types=1);

$autoloadCandidates = [
    getcwd() . '/server_vendor/autoload.php',
    getcwd() . '/vendor/autoload.php',
];

foreach ($autoloadCandidates as $candidate) {
    if (is_file($candidate)) {
        require_once $candidate;
        break;
    }
}

if (class_exists('Illuminate\Support\Str') && !Illuminate\Support\Str::hasMacro('humanize')) {
    Illuminate\Support\Str::macro('humanize', function (string $value, bool $title = true): string {
        $humanized = str_replace(['-', '_'], ' ', Illuminate\Support\Str::snake($value));

        return $title ? Illuminate\Support\Str::title($humanized) : $humanized;
    });
}

if (!function_exists('config')) {
    function config(?string $key = null, mixed $default = null): mixed
    {
        if (class_exists('Illuminate\Container\Container')) {
            $container = Illuminate\Container\Container::getInstance();

            if ($container->bound('config')) {
                $repository = $container->make('config');

                return $key === null ? $repository : $repository->get($key, $default);
            }
        }

        return $default;
    }
}

if (class_exists('Illuminate\Container\Container') && class_exists('Illuminate\Support\Facades\Facade')) {
    $app = Illuminate\Container\Container::getInstance();

    if (!method_exists($app, 'environment')) {
        if (!class_exists('Fleetbase\TestSupport\TestContainer')) {
            eval('namespace Fleetbase\TestSupport; class TestContainer extends \Illuminate\Container\Container { public array $registeredProviders = []; public function environment(array|string ...$environments): bool|string { return $environments === [] ? "testing" : in_array("testing", is_array($environments[0] ?? null) ? $environments[0] : $environments, true); } public function runningUnitTests(): bool { return true; } public function runningInConsole(): bool { return true; } public function register($provider, $force = false) { $this->registeredProviders[] = $provider; return $provider; } }');
        }

        $app = new Fleetbase\TestSupport\TestContainer();
        Illuminate\Container\Container::setInstance($app);
    }

    Illuminate\Support\Facades\Facade::setFacadeApplication($app);

    if (!$app->bound('http') && class_exists('Illuminate\Http\Client\Factory')) {
        $app->singleton('http', fn () => new Illuminate\Http\Client\Factory());
    }

    if (!$app->bound('cache') && class_exists('Illuminate\Cache\Repository') && class_exists('Illuminate\Cache\ArrayStore')) {
        $app->singleton('cache', fn () => new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
    }

    if (!$app->bound('responsecache')) {
        if (!class_exists('Fleetbase\TestSupport\ResponseCacheManager')) {
            eval('namespace Fleetbase\TestSupport; class ResponseCacheManager { public function clear(): bool { return true; } }');
        }

        $app->singleton('responsecache', fn () => new Fleetbase\TestSupport\ResponseCacheManager());
    }

    if (!$app->bound('config') && class_exists('Illuminate\Config\Repository')) {
        $app->singleton('config', fn () => new Illuminate\Config\Repository([
            'app'       => ['url' => 'https://api.example.test'],
            'api'       => ['cache' => ['enabled' => false]],
            'fleetbase' => ['connection' => ['db' => 'testing']],
        ]));
    }

    if (!$app->bound('log') && class_exists('Psr\Log\NullLogger')) {
        if (!class_exists('Fleetbase\TestSupport\LoggerManager')) {
            eval('namespace Fleetbase\TestSupport; class LoggerManager extends \Psr\Log\NullLogger { public static array $records = []; public function channel(?string $name = null): self { return $this; } public function log($level, string|\Stringable $message, array $context = []): void { self::$records[] = compact("level", "message", "context"); } }');
        }

        $app->singleton('log', fn () => new Fleetbase\TestSupport\LoggerManager());
    }

    if (!$app->bound('router')) {
        if (!class_exists('Fleetbase\TestSupport\RouteRegistrar')) {
            eval('namespace Fleetbase\TestSupport; class RouteRegistrar { public static array $routes = []; public static function reset(): void { self::$routes = []; } public function prefix(string $prefix): self { return $this; } public function namespace(string $namespace): self { return $this; } public function group(array|\Closure $attributes, ?\Closure $callback = null): self { ($callback ?? $attributes)($this); return $this; } public function get(string $uri, mixed $action): self { self::$routes[] = ["GET", $uri, $action]; return $this; } public function post(string $uri, mixed $action): self { self::$routes[] = ["POST", $uri, $action]; return $this; } public function fleetbaseRoutes(string $resource, ?\Closure $callback = null): self { self::$routes[] = ["RESOURCE", $resource, null]; if ($callback) { $callback($this, fn (string $method): string => $resource . "Controller@" . $method); } return $this; } }');
        }

        $app->singleton('router', fn () => new Fleetbase\TestSupport\RouteRegistrar());
    }
}

if (!function_exists('url')) {
    function url(?string $path = null, mixed $parameters = [], ?bool $secure = null): string
    {
        $base = $secure === false ? 'http://api.example.test' : 'https://api.example.test';

        return rtrim($base, '/') . '/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('response')) {
    function response(): object
    {
        return new class {
            public function json(mixed $data = [], int $status = 200, array $headers = []): Illuminate\Http\JsonResponse
            {
                return new Illuminate\Http\JsonResponse($data, $status, $headers);
            }
        };
    }
}

if (!function_exists('abort')) {
    function abort(int $code, string $message = '', array $headers = []): never
    {
        throw new Symfony\Component\HttpKernel\Exception\HttpException($code, $message, null, $headers);
    }
}

if (!function_exists('event')) {
    function event(object $event): object
    {
        if (class_exists('Fleetbase\TestSupport\EventRecorder')) {
            Fleetbase\TestSupport\EventRecorder::record($event);
        }

        return $event;
    }
}

if (!function_exists('app')) {
    function app(?string $abstract = null, array $parameters = []): mixed
    {
        if (class_exists('Illuminate\Container\Container')) {
            $container = Illuminate\Container\Container::getInstance();

            return $abstract === null ? $container : $container->make($abstract, $parameters);
        }

        return $abstract === null ? null : new $abstract(...array_values($parameters));
    }
}

if (!function_exists('request')) {
    function request(?string $key = null, mixed $default = null): mixed
    {
        $request = class_exists('Illuminate\Http\Request') ? Illuminate\Http\Request::create('/') : new stdClass();

        return $key === null ? $request : $default;
    }
}

if (!function_exists('session')) {
    function session(array|string|null $key = null, mixed $default = null): mixed
    {
        static $values = [];

        if (is_array($key)) {
            $values = array_merge($values, $key);

            return null;
        }

        if ($key !== null) {
            return $values[$key] ?? $default;
        }

        return new class($values) {
            public function __construct(private array $values)
            {
            }

            public function missing(string $key): bool
            {
                return !array_key_exists($key, $this->values);
            }

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->values);
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }
        };
    }
}

if (!function_exists('now') && class_exists('Illuminate\Support\Carbon')) {
    function now($tz = null): Illuminate\Support\Carbon
    {
        return Illuminate\Support\Carbon::now($tz);
    }
}

if (!trait_exists('Illuminate\Foundation\Auth\Access\AuthorizesRequests')) {
    eval('namespace Illuminate\Foundation\Auth\Access; trait AuthorizesRequests {}');
}

if (!class_exists('Illuminate\Foundation\Auth\User')) {
    eval('namespace Illuminate\Foundation\Auth; class User extends \Illuminate\Database\Eloquent\Model {}');
}

if (!class_exists('Fleetbase\Models\Customer') && class_exists('Illuminate\Database\Eloquent\Model')) {
    eval('namespace Fleetbase\Models; class Customer extends \Illuminate\Database\Eloquent\Model { protected $table = "customers"; protected $primaryKey = "uuid"; public $incrementing = false; protected $keyType = "string"; }');
}

if (!class_exists('Illuminate\Pagination\Paginator')) {
    eval('namespace Illuminate\Pagination; class Paginator implements \JsonSerializable { protected $items; public function __construct($items, protected int $perPage, protected ?int $currentPage = null, protected array $options = []) { $this->items = $items instanceof \Illuminate\Support\Collection ? $items : collect($items); } public static function resolveCurrentPage($pageName = "page", $default = 1): int { return $default; } public static function resolveCurrentPath($default = "/"): string { return $default; } public function first() { return $this->items->first(); } public function mapInto(string $class) { return $this->items->mapInto($class); } public function toBase() { return $this->items->toBase(); } public function jsonSerialize(): mixed { return $this->toArray(); } public function toArray(): array { return ["data" => $this->items->values()->all(), "per_page" => $this->perPage, "current_page" => $this->currentPage ?? 1]; } } class LengthAwarePaginator extends Paginator { public function __construct($items, protected int $total, int $perPage, ?int $currentPage = null, array $options = []) { parent::__construct($items, $perPage, $currentPage, $options); } public function toArray(): array { return array_merge(parent::toArray(), ["total" => $this->total, "last_page" => max(1, (int) ceil($this->total / $this->perPage))]); } }');
}

if (!trait_exists('Illuminate\Foundation\Bus\Dispatchable')) {
    eval('namespace Illuminate\Foundation\Bus; trait Dispatchable {}');
}

if (!trait_exists('Illuminate\Foundation\Bus\DispatchesJobs')) {
    eval('namespace Illuminate\Foundation\Bus; trait DispatchesJobs {}');
}

if (!trait_exists('Illuminate\Foundation\Events\Dispatchable')) {
    if (!class_exists('Fleetbase\TestSupport\EventRecorder')) {
        eval('namespace Fleetbase\TestSupport; class EventRecorder { public static array $events = []; public static function record(object $event): object { self::$events[] = $event; return $event; } public static function reset(): void { self::$events = []; } }');
    }

    eval('namespace Illuminate\Foundation\Events; trait Dispatchable { public static function dispatch(...$arguments): object { return \Fleetbase\TestSupport\EventRecorder::record(new static(...$arguments)); } }');
}

if (!trait_exists('Illuminate\Foundation\Validation\ValidatesRequests')) {
    eval('namespace Illuminate\Foundation\Validation; trait ValidatesRequests {}');
}

if (!class_exists('Illuminate\Foundation\Http\FormRequest') && class_exists('Illuminate\Http\Request')) {
    eval('namespace Illuminate\Foundation\Http; class FormRequest extends \Illuminate\Http\Request { public function authorize(): bool { return true; } public function rules(): array { return []; } public function responseWithErrors(\Illuminate\Contracts\Validation\Validator $validator) { return $validator; } }');
}

if (!interface_exists('Fleetbase\Ai\Contracts\AIContextCapabilityInterface')) {
    eval('namespace Fleetbase\Ai\Contracts; interface AIContextCapabilityInterface {}');
}

if (!interface_exists('Fleetbase\Ai\Contracts\AIActionCapabilityInterface')) {
    eval('namespace Fleetbase\Ai\Contracts; interface AIActionCapabilityInterface {}');
}

if (!class_exists('Fleetbase\Ai\Models\AiTask')) {
    eval('namespace Fleetbase\Ai\Models; class AiTask { public function __construct(array $attributes = []) { foreach ($attributes as $key => $value) { $this->{$key} = $value; } } }');
}

if (!class_exists('Fleetbase\Ai\Support\Capabilities\AbstractAICapability')) {
    eval('namespace Fleetbase\Ai\Support\Capabilities; abstract class AbstractAICapability {}');
}

if (!class_exists('Fleetbase\Ai\Support\AiQueryableResource')) {
    eval('namespace Fleetbase\Ai\Support; class AiQueryableResource { public string $key; public array $fields; public array $aliases; public function __construct(string $key, string $label = "", string $module = "", string $modelClass = "", string $permission = "", array $aliases = [], array $fields = [], array $sampleFields = [], ?string $locationField = null, ?string $directivePermission = null, int $maxLimit = 100) { $this->key = $key; $this->fields = $fields; $this->aliases = $aliases; } public function hasField(string $field): bool { return array_key_exists($field, $this->fields); } }');
}

if (!class_exists('Fleetbase\Ai\Support\AiQueryRegistry')) {
    eval('namespace Fleetbase\Ai\Support; class AiQueryRegistry { private array $resources = []; public function register(AiQueryableResource $resource): void { $this->resources[$resource->key] = $resource; foreach ($resource->aliases as $alias) { $this->resources[$alias] = $resource; } } public function find(string $key): ?AiQueryableResource { return $this->resources[$key] ?? null; } }');
}

if (!class_exists('Fleetbase\Ai\Support\AiRelativeDateResolver') && class_exists('Illuminate\Support\Carbon')) {
    eval('namespace Fleetbase\Ai\Support; class AiRelativeDateResolver { public function __construct($parser = null) {} public function resolveDateTime(string $prompt, ?string $timezone = null): ?\Illuminate\Support\Carbon { if (preg_match("/(\d+)\s+days?\s+from\s+now/i", $prompt, $matches)) { return \Illuminate\Support\Carbon::now($timezone)->addDays((int) $matches[1]); } return null; } public function resolveWindow(string $prompt, ?string $timezone = null): ?array { $timezone = $timezone ?: date_default_timezone_get(); $now = \Illuminate\Support\Carbon::now($timezone); if (str_contains(strtolower($prompt), "last week")) { $start = $now->copy()->subWeek()->startOfWeek(); $end = $now->copy()->subWeek()->endOfWeek(); return ["label" => "last week", "timezone" => $timezone, "start" => $start, "end" => $end]; } if (str_contains(strtolower($prompt), "yesterday")) { $start = $now->copy()->subDay()->startOfDay(); $end = $now->copy()->subDay()->endOfDay(); return ["label" => "yesterday", "timezone" => $timezone, "start" => $start, "end" => $end]; } return null; } }');
}

set_error_handler(function (int $severity, string $message): bool {
    if (str_contains($message, '/pestphp/pest/vendor/autoload.php')) {
        return true;
    }

    return false;
}, E_WARNING);

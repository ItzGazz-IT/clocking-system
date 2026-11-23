<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\AuthMiddleware;
use App\Middleware\ApiAuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\PermissionMiddleware;
use App\Middleware\ApiRateLimitMiddleware;
use Throwable;

final class Application
{
    private Router $router;

    /** @var array<string, class-string> */
    private static array $middleware = [
        'auth' => AuthMiddleware::class,
        'api.auth' => ApiAuthMiddleware::class,
        'api.throttle' => ApiRateLimitMiddleware::class,
        'guest' => GuestMiddleware::class,
        'csrf' => CsrfMiddleware::class,
        'permission' => PermissionMiddleware::class,
    ];

    public function __construct(private readonly string $basePath)
    {
        $this->router = new Router();
    }

    public function bootstrap(): void
    {
        Config::load($this->basePath . '/config');
        ProductionGuard::validate();
        date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));
        Session::start();

        (require $this->basePath . '/routes/web.php')($this->router);
        (require $this->basePath . '/routes/api.php')($this->router);
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request);
        } catch (HttpException $exception) {
            return $request->expectsJson()
                ? Response::json(['error' => $exception->getMessage()], $exception->status)
                : new Response(View::render('errors/http', [
                    'status' => $exception->status,
                    'message' => $exception->getMessage(),
                ]), $exception->status);
        } catch (Throwable $exception) {
            error_log(sprintf("[%s] %s\n%s\n", date(DATE_ATOM), $exception->getMessage(), $exception->getTraceAsString()), 3, $this->basePath . '/logs/application.log');
            $message = Config::get('app.debug', false) ? $exception->getMessage() : 'An unexpected error occurred.';
            return $request->expectsJson()
                ? Response::json(['error' => $message], 500)
                : new Response(View::render('errors/http', ['status' => 500, 'message' => $message]), 500);
        }
    }

    /** @return class-string */
    public static function middleware(string $alias): string
    {
        return self::$middleware[$alias] ?? throw new \LogicException("Unknown middleware alias: {$alias}");
    }
}

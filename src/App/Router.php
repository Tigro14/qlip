<?php

declare(strict_types=1);

namespace Qlip\App;

/**
 * Petit routeur : methodes + patron avec {parametres}.
 */
final class Router
{
    /** @var list<array{method: string, pattern: string, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => strtoupper($method), 'pattern' => $pattern, 'handler' => $handler];
    }

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            $regex = self::compile($route['pattern']);

            if (preg_match($regex, $request->path, $matches) !== 1) {
                continue;
            }

            $pathMatched = true;

            if ($route['method'] !== $request->method) {
                continue;
            }

            $params = [];

            foreach ($matches as $key => $value) {
                if (!is_int($key)) {
                    $params[$key] = rawurldecode((string) $value);
                }
            }

            $request->params = $params;

            return ($route['handler'])($request);
        }

        if ($pathMatched) {
            return Response::html('405 Methode non autorisee', 405);
        }

        return Response::html('404 Page introuvable', 404);
    }

    /**
     * Patron -> expression reguliere : {param} devient un groupe nomme, le reste
     * est litteral et echappe.
     */
    private static function compile(string $pattern): string
    {
        $regex = '#^';

        foreach (preg_split('/(\{[a-z_]+\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
            if (preg_match('/^\{([a-z_]+)\}$/', $part, $match) === 1) {
                $regex .= '(?P<' . $match[1] . '>[^/]+)';
            } else {
                $regex .= preg_quote($part, '#');
            }
        }

        return $regex . '$#';
    }

    /** @return list<array{method: string, pattern: string}> */
    public function routes(): array
    {
        return array_map(
            static fn (array $route): array => ['method' => $route['method'], 'pattern' => $route['pattern']],
            $this->routes
        );
    }
}

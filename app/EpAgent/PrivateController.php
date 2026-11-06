<?php

namespace App\EpAgent;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Catch inside the controller dispatcher, before Laravel logs exceptions containing SQL bindings. */
abstract class PrivateController extends Controller
{
    public function callAction($method, $parameters)
    {
        try {
            return $this->{$method}(...array_values($parameters));
        } catch (\Throwable $exception) {
            $status = match (true) {
                $exception instanceof ModelNotFoundException => 404,
                $exception instanceof ValidationException => 422,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => 503,
            };

            return PrivateResponse::error($status);
        }
    }
}

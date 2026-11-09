<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\DataSources;

use ExeQue\Lumberjack\Contracts\DataSource;
use ExeQue\Lumberjack\DataSources\Results\ListResult;
use ExeQue\Lumberjack\DataSources\Results\NumberResult;
use ExeQue\Lumberjack\DataSources\Results\SeriesResult;
use ExeQue\Lumberjack\DataSources\Results\TableResult;
use ExeQue\Lumberjack\Exceptions\InvalidDataSourceException;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The forms a data source can answer in, and so the widgets that can show it. A source
 * never states its own: it follows from the result class resolve() declares it returns.
 */
enum Shape: string
{
    case Number = 'number';
    case Series = 'series';
    case List = 'list';
    case Table = 'table';

    public const int MAX_POINTS = 1000;

    public const int MAX_ROWS = 100;

    public const int MAX_COLUMNS = 20;

    /**
     * @param  DataSource|class-string<DataSource>  $source
     *
     * @throws InvalidDataSourceException when resolve() does not declare one result class
     */
    public static function of(DataSource|string $source): self
    {
        return $source instanceof CallbackDataSource ? $source->shape : self::returnedBy(new ReflectionMethod($source, 'resolve'));
    }

    /**
     * @throws InvalidDataSourceException when the function does not declare one result class
     */
    public static function returnedBy(ReflectionFunctionAbstract $function): self
    {
        $type = $function->getReturnType();
        $name = $type instanceof ReflectionNamedType && ! $type->allowsNull() ? $type->getName() : '';

        return in_array($name, [NumberResult::class, SeriesResult::class, ListResult::class, TableResult::class], true)
            ? $name::shape()
            : throw InvalidDataSourceException::noResultType($type === null ? 'nothing' : (string) $type);
    }
}

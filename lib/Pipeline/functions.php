<?php


namespace Amp\Pipeline;

use Amp\Future;
use Amp\AsyncGenerator;
use Amp\Pipeline;
use Amp\PipelineSource;
use function Amp\Future\all;
use function Amp\Future\spawn;
use function Amp\Internal\createTypeError;
use function Revolt\EventLoop\defer;
use function Revolt\EventLoop\delay;

/**
 * Creates a pipeline from the given iterable, emitting each value. The iterable may contain promises. If any
 * promise fails, the returned pipeline will fail with the same reason.
 *
 * @template TValue
 *
 * @param iterable $iterable Elements to emit.
 * @param float    $delay Delay between elements emitted in seconds.
 *
 * @psalm-param iterable<array-key, TValue> $iterable
 *
 * @return Pipeline<TValue>
 *
 * @throws \TypeError If the argument is not an array or instance of \Traversable.
 */
function fromIterable(iterable $iterable, float $delay = 0): Pipeline
{
    return new AsyncGenerator(static function () use ($iterable, $delay): \Generator {
        foreach ($iterable as $value) {
            if ($delay) {
                delay($delay);
            }

            if ($value instanceof Future) {
                $value = $value->join();
            }

            yield $value;
        }
    });
}

/**
 * @template TValue
 * @template TReturn
 *
 * @param Pipeline<TValue>                $pipeline
 * @param callable(TValue $value):TReturn $onEmit
 *
 * @psalm-param Pipeline<TValue> $pipeline
 *
 * @return Pipeline<TReturn>
 */
function map(Pipeline $pipeline, callable $onEmit): Pipeline
{
    return new AsyncGenerator(static function () use ($pipeline, $onEmit): \Generator {
        while (null !== $value = $pipeline->continue()) {
            yield $onEmit($value);
        }
    });
}

/**
 * @template TValue
 *
 * @param Pipeline<TValue>             $pipeline
 * @param callable(TValue $value):bool $filter
 *
 * @return Pipeline<TValue>
 */
function filter(Pipeline $pipeline, callable $filter): Pipeline
{
    return new AsyncGenerator(static function () use ($pipeline, $filter): \Generator {
        while (null !== $value = $pipeline->continue()) {
            if ($filter($value)) {
                yield $value;
            }
        }
    });
}

/**
 * Creates a pipeline that emits values emitted from any pipeline in the array of pipelines.
 *
 * @template TValue
 *
 * @param Pipeline<TValue>[] $pipelines
 *
 * @return Pipeline<TValue>
 */
function merge(array $pipelines): Pipeline
{
    $source = new PipelineSource;

    $futures = [];
    foreach ($pipelines as $pipeline) {
        if (!$pipeline instanceof Pipeline) {
            throw createTypeError([Pipeline::class], $pipeline);
        }

        $futures[] = spawn(static function () use (&$source, $pipeline): void {
            while ((null !== $value = $pipeline->continue()) && $source !== null) {
                $source->yield($value);
            }
        });
    }

    defer(static function () use (&$source, $futures): void {
        try {
            all($futures);
            $source->complete();
        } catch (\Throwable $exception) {
            $source->error($exception);
        } finally {
            $source = null;
        }
    });

    return $source->pipe();
}

/**
 * Concatenates the given pipelines into a single pipeline, emitting from a single pipeline at a time. The
 * prior pipeline must complete before values are emitted from any subsequent pipelines. Streams are concatenated
 * in the order given (iteration order of the array).
 *
 * @template TValue
 *
 * @param Pipeline<TValue>[] $pipelines
 *
 * @return Pipeline<TValue>
 */
function concat(array $pipelines): Pipeline
{
    foreach ($pipelines as $pipeline) {
        if (!$pipeline instanceof Pipeline) {
            throw createTypeError([Pipeline::class], $pipeline);
        }
    }

    return new AsyncGenerator(function () use ($pipelines): \Generator {
        foreach ($pipelines as $stream) {
            while ($value = $stream->continue()) {
                yield $value;
            }
        }
    });
}

/**
 * Discards all remaining items and returns the number of discarded items.
 *
 * @template TValue
 *
 * @param Pipeline<TValue> $pipeline
 *
 * @return Future<int>
 */
function discard(Pipeline $pipeline): Future
{
    return spawn(static function () use ($pipeline): int {
        $count = 0;

        while (null !== $pipeline->continue()) {
            $count++;
        }

        return $count;
    });
}

/**
 * Collects all items from a pipeline into an array.
 *
 * @template TValue
 *
 * @param Pipeline<TValue> $pipeline
 *
 * @return array<int, TValue>
 */
function toArray(Pipeline $pipeline): array
{
    return \iterator_to_array($pipeline);
}

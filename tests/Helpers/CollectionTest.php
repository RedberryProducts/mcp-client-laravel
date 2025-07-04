<?php

use Redberry\MCPClient\Collection;

$sampleItems = [
    ['name' => 'alpha', 'value' => 1],
    ['name' => 'beta', 'value' => 2],
    ['noName' => 'gamma', 'value' => 3],
];

test('count method and Countable interface return correct item count', function () use ($sampleItems) {
    $collection = new Collection($sampleItems);
    expect($collection->count())->toBe(3);
    expect(count($collection))->toBe(3);
});

test('count on empty collection returns zero', function () {
    $collection = new Collection([]);
    expect($collection->count())->toBe(0);
    expect(count($collection))->toBe(0);
});

test('getIterator returns ArrayIterator and iterates correctly', function () use ($sampleItems) {
    $collection = new Collection($sampleItems);
    $iterator = $collection->getIterator();
    expect($iterator)->toBeInstanceOf(\ArrayIterator::class);

    $results = [];
    foreach ($collection as $item) {
        $results[] = $item;
    }
    expect($results)->toBe($sampleItems);
});

test('getIterator on empty collection yields no items', function () {
    $collection = new Collection([]);
    $results = iterator_to_array($collection->getIterator());
    expect($results)->toBe([]);
});

test('all and toArray return reindexed array of items', function () use ($sampleItems) {
    $collection = new Collection($sampleItems);
    $expected = array_values($sampleItems);
    expect($collection->all())->toBe($expected);
    expect($collection->toArray())->toBe($expected);
});

test('all and toArray on empty collection return empty array', function () {
    $collection = new Collection([]);
    expect($collection->all())->toBe([]);
    expect($collection->toArray())->toBe([]);
});

test('only filters items by variadic names', function () use ($sampleItems) {
    $collection = new Collection($sampleItems);
    $filtered = $collection->only('alpha', 'beta')->all();
    expect($filtered)->toBe([
        ['name' => 'alpha', 'value' => 1],
        ['name' => 'beta', 'value' => 2],
    ]);
});

test('only filters items by array of names', function () use ($sampleItems) {
    $collection = new Collection($sampleItems);
    $filtered = $collection->only(['beta'])->all();
    expect($filtered)->toBe([
        ['name' => 'beta', 'value' => 2],
    ]);
});

test('only with non-existent keys returns empty array', function () use ($sampleItems) {
    $collection = new Collection($sampleItems);
    $filtered = $collection->only('delta', 'epsilon')->all();
    expect($filtered)->toBe([]);
});

test('only with null or empty keys returns empty array', function () use ($sampleItems) {
    $collection = new Collection($sampleItems);
    expect($collection->only(null)->all())->toBe([]);
    expect($collection->only([])->all())->toBe([]);
});

test('except filters out items by variadic names', function () use ($sampleItems) {
    $collection = new Collection($sampleItems);
    $filtered = $collection->except('alpha')->all();
    expect($filtered)->toBe([
        ['name' => 'beta', 'value' => 2],
        ['noName' => 'gamma', 'value' => 3],
    ]);
});

test('except filters out items by array of names', function () use ($sampleItems) {
    $collection = new Collection($sampleItems);
    $filtered = $collection->except(['beta'])->all();
    expect($filtered)->toBe([
        ['name' => 'alpha', 'value' => 1],
        ['noName' => 'gamma', 'value' => 3],
    ]);
});

test('except with non-existent keys returns all items', function () use ($sampleItems) {
    $collection = new Collection($sampleItems);
    $filtered = $collection->except('delta', 'epsilon')->all();
    expect($filtered)->toBe(array_values($sampleItems));
});

test('except with null or empty keys returns all items', function () use ($sampleItems) {
    $collection = new Collection($sampleItems);
    expect($collection->except(null)->all())->toBe(array_values($sampleItems));
    expect($collection->except([])->all())->toBe(array_values($sampleItems));
});

test('chaining only and except filters correctly', function () use ($sampleItems) {
    $collection = new Collection($sampleItems);
    $result = $collection->only('alpha', 'beta')->except('beta')->all();
    expect($result)->toBe([
        ['name' => 'alpha', 'value' => 1],
    ]);
});

test('map applies callback to each item and maintains chainability', function () use ($sampleItems) {
    $collection = new Collection($sampleItems);
    $mapped = $collection
        ->map(function ($item) {
            return [
                'name' => $item['name'] ?? null,
                'value' => $item['value'] * 10,
            ];
        })
        ->all();
    expect($mapped)->toBe([
        ['name' => 'alpha', 'value' => 10],
        ['name' => 'beta', 'value' => 20],
        ['name' => null, 'value' => 30],
    ]);
});

test('map on empty collection returns empty array', function () {
    $collection = new Collection([]);
    $mapped = $collection->map(fn ($item) => $item)->all();
    expect($mapped)->toBe([]);
});

test('map with invalid callback throws TypeError', function () use ($sampleItems) {
    $collection = new Collection($sampleItems);
    expect(fn () => $collection->map('invalid_callback')->all())
        ->toThrow(TypeError::class);
});

test('chaining multiple operations maintains correct state', function () use ($sampleItems) {
    $collection = new Collection($sampleItems);
    $result = $collection
        ->only('alpha', 'beta')
        ->map(function ($item) {
            return ['name' => $item['name'], 'value' => $item['value'] + 10];
        })
        ->except('alpha')
        ->all();
    expect($result)->toBe([
        ['name' => 'beta', 'value' => 12],
    ]);
});

test('items with missing name key are handled correctly in only and except', function () {
    $collection = new Collection([
        ['value' => 1], // No 'name' key
        ['name' => 'beta', 'value' => 2],
    ]);
    expect($collection->only('beta')->all())->toBe([
        ['name' => 'beta', 'value' => 2],
    ]);
    expect($collection->except('beta')->all())->toBe([
        ['value' => 1],
    ]);
});

test('immutability of original collection after operations', function () use ($sampleItems) {
    $collection = new Collection($sampleItems);
    $collection->only('alpha')->map(fn ($item) => $item);
    expect($collection->all())->toBe(array_values($sampleItems)); // Original unchanged
});

test('constructor accepts non-associative arrays', function () {
    $collection = new Collection([1, 2, 3]);
    expect($collection->all())->toBe([1, 2, 3]);
});

test('constructor handles null values in array', function () {
    $collection = new Collection([null, ['name' => 'alpha', 'value' => 1]]);
    expect($collection->all())->toBe([null, ['name' => 'alpha', 'value' => 1]]);
});

<?php

declare(strict_types=1);

namespace StatusLights;

final class RouteParser
{
    private const DEFAULT_COLORS = [
        WorkflowState::SUCCESS => '1a7f37',
        WorkflowState::FAILURE => 'cf222e',
        WorkflowState::RUNNING => 'bf8700',
        WorkflowState::UNKNOWN => '6e7781',
    ];

    /** @var list<string> */
    private const OPTION_NAMES = [
        'size',
        'width',
        'font',
        'font-size',
        'radius',
        'text',
        'success-color',
        'failure-color',
        'running-color',
        'unknown-color',
    ];

    public function parse(string $requestUri): GeneratorRequest
    {
        $path = parse_url($requestUri, PHP_URL_PATH);

        if (!is_string($path)) {
            throw new InvalidRoute('The request path is invalid.');
        }

        if (strlen($path) > 2048) {
            throw new InvalidRoute('The request path may not exceed 2048 bytes.');
        }

        $rawSegments = array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn (string $segment): bool => $segment !== '',
        ));

        if (count($rawSegments) < 4 || $rawSegments[0] !== 'github') {
            throw new InvalidRoute('Expected /github/{owner}/{repository}/{workflow}.svg.', 404);
        }

        $lastIndex = array_key_last($rawSegments);
        $lastSegment = $rawSegments[$lastIndex];

        if (!str_ends_with(strtolower($lastSegment), '.svg')) {
            throw new InvalidRoute('Status light URLs must end in .svg.', 404);
        }

        $rawSegments[$lastIndex] = substr($lastSegment, 0, -4);
        $segments = array_map('rawurldecode', $rawSegments);

        $owner = $segments[1];
        $repository = $segments[2];
        $workflow = $segments[3];

        $this->assertMatches(
            $owner,
            '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?$/',
            'GitHub owner',
        );
        $this->assertMatches($repository, '/^[A-Za-z0-9._-]{1,100}$/', 'repository');
        $this->assertMatches($workflow, '/^[A-Za-z0-9._-]{1,100}$/', 'workflow');

        $optionSegments = array_slice($segments, 4);

        if (count($optionSegments) % 2 !== 0) {
            throw new InvalidRoute('Every option name must be followed by a value.');
        }

        $options = [];

        for ($index = 0; $index < count($optionSegments); $index += 2) {
            $name = strtolower($optionSegments[$index]);
            $value = $optionSegments[$index + 1];

            if (!in_array($name, self::OPTION_NAMES, true)) {
                throw new InvalidRoute(sprintf('Unknown option: %s.', $name));
            }

            if (array_key_exists($name, $options)) {
                throw new InvalidRoute(sprintf('Option %s may only appear once.', $name));
            }

            $options[$name] = $value;
        }

        $height = $this->integerOption($options, 'size', 40, 16, 100);
        $width = array_key_exists('width', $options)
            ? $this->integerOption($options, 'width', $height, 16, 1000)
            : null;
        $font = strtolower($options['font'] ?? 'sans');

        if (!in_array($font, ['sans', 'mono', 'serif'], true)) {
            throw new InvalidRoute('Font must be sans, mono, or serif.');
        }

        $fontSize = $this->integerOption($options, 'font-size', 16, 8, min(96, $height - 2));
        $radius = $this->integerOption($options, 'radius', 6, 0, intdiv($height, 2));
        $text = $this->textOption($options['text'] ?? '');
        $colors = self::DEFAULT_COLORS;

        foreach (WorkflowState::all() as $state) {
            $name = $state . '-color';

            if (array_key_exists($name, $options)) {
                $colors[$state] = $this->colorOption($options[$name], $name);
            }
        }

        return new GeneratorRequest(
            owner: $owner,
            repository: $repository,
            workflow: $workflow,
            height: $height,
            width: $width,
            font: $font,
            fontSize: $fontSize,
            radius: $radius,
            text: $text,
            colors: $colors,
        );
    }

    /** @param array<string, string> $options */
    private function integerOption(
        array $options,
        string $name,
        int $default,
        int $minimum,
        int $maximum,
    ): int {
        if (!array_key_exists($name, $options)) {
            return min(max($default, $minimum), $maximum);
        }

        $value = filter_var($options[$name], FILTER_VALIDATE_INT);

        if ($value === false || $value < $minimum || $value > $maximum) {
            throw new InvalidRoute(sprintf(
                'Option %s must be an integer from %d to %d.',
                $name,
                $minimum,
                $maximum,
            ));
        }

        return $value;
    }

    private function textOption(string $value): string
    {
        // The browser builder double-encodes slashes so Apache cannot treat them as path separators.
        $value = rawurldecode($value);

        if (preg_match('//u', $value) !== 1) {
            throw new InvalidRoute('Text must be valid UTF-8.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidRoute('Text may not contain control characters.');
        }

        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        if ($characters === false || count($characters) > 80) {
            throw new InvalidRoute('Text may not exceed 80 characters.');
        }

        return $value;
    }

    private function colorOption(string $value, string $name): string
    {
        $normalized = strtolower(ltrim($value, '#'));

        if (preg_match('/^[0-9a-f]{6}$/', $normalized) !== 1) {
            throw new InvalidRoute(sprintf('Option %s must be a six-digit hexadecimal colour.', $name));
        }

        return $normalized;
    }

    private function assertMatches(string $value, string $pattern, string $name): void
    {
        if (preg_match($pattern, $value) !== 1) {
            throw new InvalidRoute(sprintf('Invalid %s.', $name));
        }
    }
}

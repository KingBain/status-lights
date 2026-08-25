<?php

declare(strict_types=1);

namespace StatusLights;

final class SvgRenderer
{
    private const FONTS = [
        'sans' => 'Arial, Helvetica, sans-serif',
        'mono' => 'ui-monospace, SFMono-Regular, Consolas, monospace',
        'serif' => "Georgia, 'Times New Roman', serif",
    ];

    public function render(GeneratorRequest $request, StatusResult $result): string
    {
        $statusLabel = WorkflowState::label($result->state);
        $label = str_replace('{status}', $statusLabel, $request->text);
        $width = $request->width ?? $this->automaticWidth($label, $request->height, $request->fontSize);
        $background = '#' . $request->colors[$result->state];
        $foreground = $this->contrastColor($request->colors[$result->state]);
        $title = sprintf(
            '%s/%s %s status: %s',
            $request->owner,
            $request->repository,
            $request->workflow,
            $statusLabel,
        );

        $svg = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            sprintf(
                '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" role="img" aria-labelledby="title" data-state="%s">',
                $width,
                $request->height,
                $width,
                $request->height,
                $result->state,
            ),
            '<title id="title">' . $this->escape($title) . '</title>',
            sprintf(
                '<rect width="%d" height="%d" rx="%d" fill="%s"/>',
                $width,
                $request->height,
                $request->radius,
                $background,
            ),
        ];

        if ($label !== '') {
            $svg[] = sprintf(
                '<text x="50%%" y="50%%" fill="%s" font-family="%s" font-size="%d" text-anchor="middle" dominant-baseline="central">%s</text>',
                $foreground,
                $this->escape(self::FONTS[$request->font]),
                $request->fontSize,
                $this->escape($label),
            );
        }

        $svg[] = '</svg>';

        return implode('', $svg);
    }

    public function renderError(string $message): string
    {
        $safeMessage = $this->escape($message);

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="40" viewBox="0 0 240 40" role="img" aria-labelledby="title">'
            . '<title id="title">' . $safeMessage . '</title>'
            . '<rect width="240" height="40" rx="6" fill="#6e7781"/>'
            . '<text x="120" y="20" fill="#ffffff" font-family="Arial, Helvetica, sans-serif" font-size="14" text-anchor="middle" dominant-baseline="central">'
            . $safeMessage
            . '</text></svg>';
    }

    private function automaticWidth(string $label, int $height, int $fontSize): int
    {
        if ($label === '') {
            return $height;
        }

        $characters = preg_split('//u', $label, -1, PREG_SPLIT_NO_EMPTY);
        $characterCount = is_array($characters) ? count($characters) : strlen($label);
        $padding = (int) ceil($height * 0.28);

        return max($height, (int) ceil(($characterCount * $fontSize * 0.64) + ($padding * 2)));
    }

    private function contrastColor(string $hex): string
    {
        $channels = [];

        foreach ([0, 2, 4] as $offset) {
            $channel = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $channel <= 0.04045
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4;
        }

        $luminance = (0.2126 * $channels[0]) + (0.7152 * $channels[1]) + (0.0722 * $channels[2]);
        $whiteContrast = 1.05 / ($luminance + 0.05);
        $blackContrast = ($luminance + 0.05) / 0.05;

        return $whiteContrast >= $blackContrast ? '#ffffff' : '#000000';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}


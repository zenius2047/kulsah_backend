<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class CloudinaryTransformationBuilder
{
    public function buildRenderTransformations(array $timeline): array
    {
        $timeline = $this->normalizeTimeline($timeline);
        $layers = $timeline['layers'];

        $videoTransformation = $this->buildVideoTransformation($timeline, $layers);
        $posterTransformation = $this->buildPosterTransformation($timeline, $layers);

        return [
            'timeline' => $timeline,
            'layers' => $layers,
            'video_transformation' => $videoTransformation,
            'poster_transformation' => $posterTransformation,
            'render_hash' => sha1(json_encode($timeline, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
        ];
    }

    private function buildVideoTransformation(array $timeline, array $layers): string
    {
        $segments = [];

        if (($trim = $timeline['trim']) !== []) {
            if (isset($trim['start'])) {
                $segments[] = 'so_'.$this->formatNumber((float) $trim['start']);
            }

            if (isset($trim['end'])) {
                $segments[] = 'eo_'.$this->formatNumber((float) $trim['end']);
            }
        }

        $filterSegment = $this->buildFilterSegment($timeline['filters']);
        if ($filterSegment !== null) {
            $segments[] = $filterSegment;
        }

        foreach ($layers as $layer) {
            $segments[] = $this->buildLayerSegment($layer);
        }

        $segments[] = $this->buildOutputSegment($timeline['output']);

        return implode('/', array_values(array_filter($segments, static fn ($segment) => $segment !== null && $segment !== '')));
    }

    private function buildPosterTransformation(array $timeline, array $layers): string
    {
        $segments = ['so_0'];

        $filterSegment = $this->buildFilterSegment($timeline['filters']);
        if ($filterSegment !== null) {
            $segments[] = $filterSegment;
        }

        if ($layers !== []) {
            $segments[] = $this->buildLayerSegment($layers[0]);
        }

        $poster = $timeline['output']['poster'] ?? [];
        $width = (int) ($poster['width'] ?? $timeline['output']['width'] ?? 720);
        $height = (int) ($poster['height'] ?? $timeline['output']['height'] ?? 1280);

        $segments[] = 'c_fill';
        $segments[] = 'w_'.$width;
        $segments[] = 'h_'.$height;
        $segments[] = 'f_jpg';
        $segments[] = 'q_auto';

        return implode('/', array_values(array_filter($segments, static fn ($segment) => $segment !== null && $segment !== '')));
    }

    private function buildFilterSegment(array $filters): ?string
    {
        $parts = [];

        foreach ([
            'brightness' => 'e_brightness',
            'contrast' => 'e_contrast',
            'saturation' => 'e_saturation',
            'hue' => 'e_hue',
            'gamma' => 'e_gamma',
        ] as $key => $prefix) {
            if (array_key_exists($key, $filters) && $filters[$key] !== null && $filters[$key] !== '') {
                $parts[] = $prefix.':'.(int) $filters[$key];
            }
        }

        if (($filters['grayscale'] ?? false) === true) {
            $parts[] = 'e_grayscale';
        }

        if (($filters['sepia'] ?? false) === true) {
            $parts[] = 'e_sepia';
        }

        return $parts === [] ? null : implode(',', $parts);
    }

    private function buildLayerSegment(array $layer): string
    {
        $type = (string) ($layer['type'] ?? '');
        $start = $this->formatNumber((float) ($layer['start'] ?? 0));
        $end = Arr::get($layer, 'end');
        $placement = $this->buildPlacementSegment($layer);

        if ($type === 'text') {
            $font = $this->normalizeFont((string) ($layer['font'] ?? 'Arial'));
            $size = max(8, (int) ($layer['size'] ?? $layer['font_size'] ?? 42));
            $text = $this->escapeLayerValue((string) ($layer['text'] ?? ''));
            $color = $this->normalizeColor((string) ($layer['color'] ?? '#FFFFFF'));

            $parts = [
                'l_text:'.$font.'_'.$size.':'.$text,
                'co_rgb:'.$color,
            ];

            if (($box = $layer['box'] ?? true) !== false) {
                $parts[] = 'bo_'.($this->normalizeColor((string) ($layer['box_color'] ?? '000000'), true));
            }

            $parts = array_merge($parts, $placement);
            $parts[] = 'fl_layer_apply';

            if ($start !== '0') {
                array_unshift($parts, 'so_'.$start);
            }

            if ($end !== null && $end !== '') {
                $parts[] = 'eo_'.$this->formatNumber((float) $end);
            }

            return implode(',', array_values(array_filter($parts, static fn ($value) => $value !== null && $value !== '')));
        }

        $source = $this->buildLayerSource($layer);
        $parts = [
            $source,
        ];

        if (($width = $layer['width'] ?? null) !== null) {
            $parts[] = 'w_'.max(1, (int) $width);
        }

        if (($height = $layer['height'] ?? null) !== null) {
            $parts[] = 'h_'.max(1, (int) $height);
        }

        $parts = array_merge($parts, $placement);

        if ($type === 'audio') {
            if ($start !== '0') {
                $parts[] = 'so_'.$start;
            }

            if ($end !== null && $end !== '') {
                $parts[] = 'eo_'.$this->formatNumber((float) $end);
            }
        }

        $parts[] = 'fl_layer_apply';

        return implode(',', array_values(array_filter($parts, static fn ($value) => $value !== null && $value !== '')));
    }

    private function buildLayerSource(array $layer): string
    {
        $type = (string) ($layer['type'] ?? '');
        $publicId = trim((string) ($layer['public_id'] ?? $layer['asset_public_id'] ?? ''), '/');

        if ($publicId !== '') {
            return match ($type) {
                'audio' => 'l_audio:'.$this->normalizePublicId($publicId),
                default => 'l_'.$this->normalizePublicId($publicId),
            };
        }

        $assetUrl = (string) ($layer['asset_url'] ?? $layer['source_url'] ?? '');

        if ($assetUrl === '') {
            $disk = (string) ($layer['asset_disk'] ?? '');
            $assetKey = (string) ($layer['asset_key'] ?? '');

            if ($disk !== '' && $assetKey !== '') {
                throw new RuntimeException('Timeline layer is missing a renderable URL.');
            }

            throw new RuntimeException('Timeline layer is missing a public_id or source URL.');
        }

        if ($type === 'audio') {
            return 'l_audio:'.$this->normalizePublicId((string) ($layer['public_id'] ?? $layer['asset_public_id'] ?? $assetUrl));
        }

        return 'l_fetch:'.$this->encodeRemoteUrl($assetUrl);
    }

    private function buildPlacementSegment(array $layer): array
    {
        $parts = [];
        $gravity = (string) ($layer['gravity'] ?? 'north_west');

        if ($gravity !== '') {
            $parts[] = 'g_'.$this->normalizeGravity($gravity);
        }

        if (array_key_exists('x', $layer)) {
            $parts[] = 'x_'.(int) round((float) $layer['x']);
        }

        if (array_key_exists('y', $layer)) {
            $parts[] = 'y_'.(int) round((float) $layer['y']);
        }

        return $parts;
    }

    private function buildOutputSegment(array $output): string
    {
        $parts = [];

        if (($format = $output['format'] ?? null) !== null && $format !== '') {
            $parts[] = 'f_'.$this->normalizeFormat((string) $format);
        }

        if (($quality = $output['quality'] ?? null) !== null && $quality !== '') {
            $parts[] = 'q_'.$this->normalizeQuality((string) $quality);
        }

        if (($width = $output['width'] ?? null) !== null) {
            $parts[] = 'w_'.max(1, (int) $width);
        }

        if (($height = $output['height'] ?? null) !== null) {
            $parts[] = 'h_'.max(1, (int) $height);
        }

        if (($crop = $output['crop'] ?? null) !== null && $crop !== '') {
            $parts[] = 'c_'.$this->normalizeCrop((string) $crop);
        }

        if (($fps = $output['fps'] ?? null) !== null && $fps !== '') {
            $parts[] = 'fps_'.$this->formatNumber((float) $fps);
        }

        if (($bitRate = $output['bit_rate'] ?? null) !== null && $bitRate !== '') {
            $parts[] = 'br_'.$this->normalizeBitRate((string) $bitRate);
        }

        if (($audio = $output['audio'] ?? null) !== null && $audio !== '') {
            $parts[] = 'ac_'.$this->normalizeAudio((string) $audio);
        }

        return implode(',', array_values(array_filter($parts, static fn ($value) => $value !== null && $value !== '')));
    }

    private function normalizeTimeline(array $timeline): array
    {
        $layers = array_values(array_map(
            fn (array $layer): array => $this->normalizeLayer($layer),
            array_values((array) ($timeline['layers'] ?? []))
        ));

        return [
            'video_id' => isset($timeline['video_id']) ? (int) $timeline['video_id'] : null,
            'layers' => $layers,
            'filters' => (array) ($timeline['filters'] ?? []),
            'audio' => (array) ($timeline['audio'] ?? []),
            'trim' => (array) ($timeline['trim'] ?? []),
            'output' => (array) ($timeline['output'] ?? []),
        ];
    }

    private function normalizeLayer(array $layer): array
    {
        $type = (string) ($layer['type'] ?? '');

        if (! in_array($type, ['text', 'drawing', 'image', 'sticker', 'watermark', 'audio'], true)) {
            throw new RuntimeException('Unsupported timeline layer type: '.$type);
        }

        $normalized = [
            'type' => $type,
            'x' => max(0, (int) round((float) ($layer['x'] ?? 0))),
            'y' => max(0, (int) round((float) ($layer['y'] ?? 0))),
            'start' => max(0, (float) ($layer['start'] ?? 0)),
            'end' => array_key_exists('end', $layer) && $layer['end'] !== null ? max(0, (float) $layer['end']) : null,
            'gravity' => (string) ($layer['gravity'] ?? 'north_west'),
            'public_id' => isset($layer['public_id']) ? trim((string) $layer['public_id'], '/') : null,
            'asset_public_id' => isset($layer['asset_public_id']) ? trim((string) $layer['asset_public_id'], '/') : null,
            'asset_url' => isset($layer['asset_url']) ? (string) $layer['asset_url'] : null,
            'asset_disk' => isset($layer['asset_disk']) ? (string) $layer['asset_disk'] : null,
            'asset_key' => isset($layer['asset_key']) ? (string) $layer['asset_key'] : null,
            'width' => isset($layer['width']) ? max(1, (int) $layer['width']) : null,
            'height' => isset($layer['height']) ? max(1, (int) $layer['height']) : null,
        ];

        if ($type === 'text') {
            $normalized['font'] = (string) ($layer['font'] ?? 'Arial');
            $normalized['size'] = max(8, (int) ($layer['size'] ?? $layer['font_size'] ?? 42));
            $normalized['color'] = (string) ($layer['color'] ?? '#FFFFFF');
            $normalized['box'] = filter_var($layer['box'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $normalized['box_color'] = (string) ($layer['box_color'] ?? '000000');
            $normalized['text'] = trim((string) ($layer['text'] ?? ''));

            if ($normalized['text'] === '') {
                throw new RuntimeException('Text layers require text.');
            }
        }

        return array_filter($normalized, static fn ($value) => $value !== null && $value !== '');
    }

    private function normalizeFont(string $font): string
    {
        $font = trim($font);
        $font = $font === '' ? 'Arial' : $font;

        return str_replace(['/', ' '], ['_', '_'], $font);
    }

    private function normalizeFormat(string $format): string
    {
        $format = strtolower(trim($format));

        return preg_replace('/[^a-z0-9]+/', '', $format) ?: 'mp4';
    }

    private function normalizeQuality(string $quality): string
    {
        $quality = strtolower(trim($quality));

        return $quality === ''
            ? 'auto'
            : (preg_replace('/[^a-z0-9_:-]+/', '', $quality) ?: 'auto');
    }

    private function normalizeCrop(string $crop): string
    {
        return preg_replace('/[^a-z_]+/', '', strtolower(trim($crop))) ?: 'fill';
    }

    private function normalizeAudio(string $audio): string
    {
        return preg_replace('/[^a-z0-9_:-]+/', '', strtolower(trim($audio))) ?: 'auto';
    }

    private function normalizeGravity(string $gravity): string
    {
        return preg_replace('/[^a-z_]+/', '', strtolower(trim($gravity))) ?: 'north_west';
    }

    private function normalizePublicId(string $publicId): string
    {
        $publicId = trim($publicId);

        return str_replace('/', ':', $publicId);
    }

    private function escapeLayerValue(string $value): string
    {
        return rawurlencode($value);
    }

    private function normalizeColor(string $color, bool $allowAlpha = false): string
    {
        $color = trim($color);
        if (str_starts_with($color, '#')) {
            $color = substr($color, 1);
        }

        $pattern = $allowAlpha ? '/^[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/' : '/^[0-9A-Fa-f]{6}$/';

        return preg_match($pattern, $color) === 1 ? strtoupper($color) : 'FFFFFF';
    }

    private function encodeRemoteUrl(string $url): string
    {
        return rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
    }

    private function formatNumber(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    private function buildLayerSourceForUpload(array $layer): ?string
    {
        return null;
    }
}

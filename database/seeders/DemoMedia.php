<?php

namespace Database\Seeders;

final class DemoMedia
{
    private const CLOUDINARY_VIDEO_BASE = 'https://res.cloudinary.com/demo/video/upload';

    private const CLOUDINARY_IMAGE_BASE = 'https://res.cloudinary.com/demo/image/upload';

    /**
     * Public Cloudinary demo assets documented at:
     * https://cloudinary.com/documentation/video_manipulation_and_delivery
     *
     * @return array<string, array<string, mixed>>
     */
    public static function videos(): array
    {
        return [
            'coastal-dance' => self::video('glide-over-coastal-beach', 0, 15, 'Dance by the coast', ['dance', 'travel']),
            'coastal-style' => self::video('glide-over-coastal-beach', 5, 15, 'Golden-hour style', ['fashion', 'travel']),
            'dog-reaction' => self::video('dog', 0, 15, 'The look when the beat drops', ['comedy', 'pets']),
            'dog-remix' => self::video('dog', 2, 15, 'A wholesome remix', ['comedy', 'music']),
            'ski-freestyle' => self::video('ski_jump', 0, 15, 'Freestyle in the snow', ['sports', 'adventure']),
            'ski-battle' => self::video('ski_jump', 3, 12, 'Battle-ready freestyle', ['sports', 'challenge']),
            'ship-travel' => self::video('ship', 0, 15, 'Ocean views', ['travel', 'lifestyle']),
            'ship-story' => self::video('ship', 4, 15, 'A story from the water', ['storytelling', 'travel']),
            'bathroom-design' => self::video('docs/bathroom', 0, 15, 'Small-space design ideas', ['design', 'lifestyle']),
            'bathroom-tips' => self::video('docs/bathroom', 4, 12, 'Three details that change a room', ['design', 'education']),
            'challenge-instructions' => self::video('glide-over-coastal-beach', 1, 20, 'Challenge instructions', ['challenge', 'dance']),
            'battle-instructions' => self::video('ski_jump', 1, 18, 'Creator battle instructions', ['challenge', 'sports']),
            'ama-dance-tutorial' => self::video('glide-over-coastal-beach', 2, 12, 'Dance tutorial', ['dance', 'education']),
            'zuri-warmup' => self::video('glide-over-coastal-beach', 0, 10, 'Movement warm-up', ['dance', 'fitness']),
            'tunde-behind-scenes' => self::video('dog', 1, 10, 'Comedy behind the scenes', ['comedy', 'storytelling']),
            'naledi-mobility' => self::video('ski_jump', 0, 10, 'Mobility session', ['fitness', 'education']),
            'kwame-color-grade' => self::video('ship', 1, 10, 'Travel color grade', ['travel', 'education']),
            'amina-moodboard' => self::video('docs/bathroom', 1, 10, 'Room mood board', ['design', 'education']),
        ];
    }

    public static function image(string $publicId, int $width = 1200, int $height = 675): string
    {
        return sprintf(
            '%s/c_fill,g_auto,q_auto,w_%d,h_%d/%s.jpg',
            self::CLOUDINARY_IMAGE_BASE,
            $width,
            $height,
            $publicId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function video(
        string $publicId,
        int $start,
        int $duration,
        string $title,
        array $contentTypes,
    ): array {
        $encodedPublicId = implode('/', array_map('rawurlencode', explode('/', $publicId)));
        $transform = sprintf(
            'c_fill,h_1280,w_720/so_%d,du_%d',
            $start,
            $duration,
        );
        $videoUrl = sprintf('%s/%s/%s.mp4', self::CLOUDINARY_VIDEO_BASE, $transform, $encodedPublicId);
        $posterUrl = sprintf(
            '%s/c_fill,h_1280,w_720/so_%d/%s.jpg',
            self::CLOUDINARY_VIDEO_BASE,
            $start + 1,
            $encodedPublicId,
        );

        return [
            'public_id' => $publicId,
            'title' => $title,
            'content_types' => $contentTypes,
            'duration' => $duration,
            'video_url' => $videoUrl,
            'poster_url' => $posterUrl,
            'source_page' => 'https://cloudinary.com/documentation/video_manipulation_and_delivery',
            'provider' => 'Cloudinary public demo cloud',
        ];
    }
}
